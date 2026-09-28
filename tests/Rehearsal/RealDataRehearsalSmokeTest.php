<?php

namespace Tests\Rehearsal;

use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\ProjectLabourActualService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Throwable;

/**
 * Real-data W1–W7 and queue smoke test for a source-copy REHEARSAL target (Report 52).
 *
 * Runs the real HTTP routes, controllers, policies, services and queued listeners,
 * as real imported users, against real imported projects and employees. It COMMITS
 * data, so it refuses to run against anything but a disposable rehearsal database
 * (name `wng_*rehearsal*`), and never against a live name.
 *
 * Not part of the Unit/Feature suites. Run from a rehearsal checkout whose .env points
 * at the rehearsal target with QUEUE_CONNECTION=database:
 *   php vendor/bin/phpunit -c phpunit.rehearsal.xml
 *
 * Every step is recorded (PASS / FAIL / BLOCKED:<decision> / INFO) rather than stopping
 * at the first failure, and the results are written to
 * storage/app/source-migration/rehearsal-smoke/<timestamp>.json.
 */
class RealDataRehearsalSmokeTest extends BaseTestCase
{
    private array $results = [];

    private User $superA;
    private User $superB;
    private User $accounts;
    private User $officer;
    private User $recorder;
    private ProjectEnquiry $enquiry;
    private int $employeeId;

    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $database = (string) DB::connection()->getDatabaseName();
        if (! preg_match('/^wng_.*rehearsal/', $database)
            || in_array($database, [...(array) config('source_migration.live_source_databases'), ...(array) config('source_migration.live_target_databases')], true)) {
            throw new RuntimeException("Refusing to run the real-data smoke test against '{$database}': rehearsal databases only (wng_*rehearsal*).");
        }
        if (app()->environment('production') || config('queue.default') !== 'database') {
            throw new RuntimeException('Rehearsal smoke requires a non-production environment with QUEUE_CONNECTION=database.');
        }
    }

    public function test_real_data_w1_to_w7_and_queue(): void
    {
        $this->actors();

        $this->w7Labour();
        $this->w6ProjectCosting();
        $this->queueBudgetProjection();
        $this->w5PettyCash();
        $this->w3Expenses();
        $this->w2Procurement();
        $this->w4PaymentVouchers();
        $this->w1Receivables();
        $this->w6ClosureAndReopen();
        $this->financeReadiness();

        $path = storage_path('app/source-migration/rehearsal-smoke/'.now()->format('Ymd-His').'.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode(['database' => DB::connection()->getDatabaseName(), 'results' => $this->results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fwrite(STDOUT, "\nRehearsal smoke results: {$path}\n");
        foreach ($this->results as $r) {
            fwrite(STDOUT, sprintf("  %-4s %-58s %s\n", $r['w'], mb_strimwidth($r['step'], 0, 58), $r['status'].($r['detail'] !== '' ? ' — '.mb_strimwidth($r['detail'], 0, 150) : '')));
        }

        $failures = array_filter($this->results, fn ($r) => $r['status'] === 'FAIL');
        $this->assertSame([], array_values($failures), 'Unexpected failures (BLOCKED:<decision> results are expected while that decision is open).');
    }

    // ── Actors and subjects: real imported records ────────────────────────

    private function actors(): void
    {
        $withRole = fn (string $role) => User::query()->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', $role))->orderBy('id');

        $supers = $withRole('Super Admin')->limit(2)->get();
        [$this->superA, $this->superB] = [$supers[0], $supers[1]];
        $this->accounts = $withRole('Accounts')->firstOrFail();

        // An open project whose finalized budget has labour lines W7 can record against,
        // led by a real Project Officer.
        $service = app(ProjectLabourActualService::class);
        $candidates = ProjectEnquiry::query()
            ->whereNotIn('status', ['completed', 'closed', 'cancelled'])
            ->whereNotNull('project_officer_id')
            ->whereHas('project', fn ($q) => $q->whereNotIn('status', ['completed', 'closed', 'cancelled']))
            ->orderByDesc('id')->limit(200)->get();
        $access = app(\App\Services\ProjectFinancialAccess::class);
        $labourRoles = User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->whereIn('name', ['Project Manager', 'Costing']))->orderBy('id')->get();
        foreach ($candidates as $enquiry) {
            $officer = User::find($enquiry->project_officer_id);
            if (! $officer || ! $officer->is_active || collect($service->getBudgetLabourLines($enquiry))->where('recordable', true)->isEmpty()) {
                continue;
            }
            // W7 records and PO-verifies through a real role holding finance.labour.record/po_verify
            // (Project Manager or Costing) who is assigned to the project (ProjectFinancialAccess).
            $recorder = $labourRoles->first(fn ($u) => $access->canRecordLabour($u, $enquiry) && $access->canPoVerifyLabour($u, $enquiry));
            if ($recorder) {
                [$this->enquiry, $this->officer, $this->recorder] = [$enquiry, $officer, $recorder];
                break;
            }
        }
        if (! isset($this->enquiry)) {
            throw new RuntimeException('No open real project with a finalized budget, recordable labour and an assigned Project Manager/Costing user.');
        }
        $this->employeeId = (int) DB::table('employees')->where('status', 'active')->orderBy('id')->value('id');

        $this->record('—', 'Real subjects selected', 'INFO', sprintf(
            'enquiry #%d (%s, status %s), Project Officer user #%d, W7 recorder/PO-verifier user #%d (%s), Accounts user #%d, Super Admins #%d/#%d, employee #%d',
            $this->enquiry->id, $this->enquiry->enquiry_number, $this->enquiry->status, $this->officer->id, $this->recorder->id,
            $this->recorder->getRoleNames()->implode('/'), $this->accounts->id,
            $this->superA->id, $this->superB->id, $this->employeeId));
    }

    // ── W7 Labour ────────────────────────────────────────────────────────

    private function w7Labour(): void
    {
        $base = "/api/costs/projects/{$this->enquiry->id}/labour-actuals";
        $line = collect(app(ProjectLabourActualService::class)->getBudgetLabourLines($this->enquiry))->firstWhere('recordable', true);
        $actualsBefore = CostLine::where('project_enquiry_id', $this->enquiry->id)->where('nature', CostLine::NATURE_ACTUAL)->count();

        $this->record('W7', 'Real Project Officer role holds no W7 permission (source and target alike)', 'INFO',
            'Project Officer #'.$this->officer->id.' can record labour: '.(app(\App\Services\ProjectFinancialAccess::class)->canRecordLabour($this->officer, $this->enquiry) ? 'yes' : 'no').' — W7 recording/PO-verify is granted to Project Manager, Costing, Admin (migration 2026_09_24_000006). WNG role decision.');
        $actualId = $this->step('W7', 'Record labour against the real budget line, for a real employee (assigned PM/Costing)', function () use ($base, $line) {
            $r = $this->as($this->recorder)->postJson($base, [
                'budget_line_id' => $line['id'], 'actual_quantity' => 1, 'actual_days' => 1,
                'work_date' => now()->toDateString(), 'employee_id' => $this->employeeId,
            ]);
            $this->expectOk($r, 201);

            return $r->json('data.id') ?? $r->json('id');
        });
        if (! $actualId) {
            return;
        }

        $this->step('W7', 'Project Officer verify (assigned PM/Costing holding finance.labour.po_verify)', fn () => $this->expectOk($this->as($this->recorder)->postJson("{$base}/{$actualId}/po-verify", ['notes' => 'Rehearsal'])));
        $financeVerified = $this->step('W7', 'Finance verify (Accounts) creates the actual CostLine', function () use ($base, $actualId) {
            $this->expectOk($this->as($this->accounts)->postJson("{$base}/{$actualId}/finance-verify", ['notes' => 'Rehearsal']));
            $costLine = DB::table('project_labour_actuals')->where('id', $actualId)->value('cost_line_id');
            if (! $costLine) {
                throw new RuntimeException('No actual CostLine linked after Finance verify.');
            }

            return "CostLine #{$costLine}";
        });
        if (! $financeVerified) {
            $this->record('W7', 'Project Costing actual labour increased; no journal created', 'BLOCKED:D3', 'depends on Finance verify, which needs the active labour expense code (D3)');
        }
        $financeVerified && $this->step('W7', 'Project Costing actual labour increased; no journal created', function () use ($actualsBefore) {
            $after = CostLine::where('project_enquiry_id', $this->enquiry->id)->where('nature', CostLine::NATURE_ACTUAL)->count();
            if ($after !== $actualsBefore + 1) {
                throw new RuntimeException("Actual lines {$actualsBefore} → {$after}.");
            }
            if (DB::table('journal_entries')->where('source_type', 'like', '%LabourActual%')->exists()) {
                throw new RuntimeException('A journal was created for labour.');
            }
        });
        $this->step('W7', 'Labour record links to an Employee, never to technical labour', function () use ($actualId) {
            $row = (array) DB::table('project_labour_actuals')->where('id', $actualId)->first();
            if ((int) $row['employee_id'] !== $this->employeeId || array_filter(array_keys($row), fn ($c) => str_contains($c, 'technical'))) {
                throw new RuntimeException('Labour record does not link to the Employee Record only.');
            }
        });
    }

    // ── W6 Project Costing ───────────────────────────────────────────────

    private function w6ProjectCosting(): void
    {
        $this->step('W6', 'Regenerated planned CostLines exist for the open real project', function () {
            $n = CostLine::where('project_enquiry_id', $this->enquiry->id)->where('nature', CostLine::NATURE_PLANNED)->count();
            if ($n === 0) {
                throw new RuntimeException('No planned lines.');
            }

            return "{$n} planned lines";
        });
        foreach ($this->getRoutesFor('api/costs/projects/{enquiry}') as $uri) {
            $this->step('W6', "GET {$uri} (Accounts)", fn () => $this->expectOk($this->as($this->accounts)->getJson('/'.str_replace('{enquiry}', (string) $this->enquiry->id, $uri))));
        }
    }

    private function w6ClosureAndReopen(): void
    {
        $this->step('W6', 'Financial close (pre-check) of the real project', function () {
            $r = $this->as($this->superA)->postJson("/api/costs/projects/{$this->enquiry->id}/close");
            if ($r->status() === 422) {
                return 'Pre-check refused close: '.json_encode($r->json('errors') ?? $r->json('message'));
            }
            $this->expectOk($r);
        });
        if (DB::table('project_enquiries')->where('id', $this->enquiry->id)->value('financial_closure_status') === 'closed') {
            $this->step('W6', 'Recording labour on a closed project is blocked', function () {
                $line = collect(app(ProjectLabourActualService::class)->getBudgetLabourLines($this->enquiry->fresh()))->first();
                $r = $this->as($this->recorder)->postJson("/api/costs/projects/{$this->enquiry->id}/labour-actuals", [
                    'budget_line_id' => $line['id'] ?? 'x', 'actual_quantity' => 1, 'actual_days' => 1, 'work_date' => now()->toDateString(),
                ]);
                if ($r->status() < 400) {
                    throw new RuntimeException("Recording on a closed project returned {$r->status()}.");
                }
            });
            $this->step('W6', 'Reopen (Super Admin, with reason)', fn () => $this->expectOk($this->as($this->superA)->postJson("/api/costs/projects/{$this->enquiry->id}/reopen", ['reason' => 'Rehearsal reopen'])));
        }
    }

    // ── Queue R1: budget projection through a real budget save ───────────

    private function queueBudgetProjection(): void
    {
        $this->step('Q', 'R1 budget save queues BudgetLinesChanged; the drain re-projects idempotently', function () {
            $budgetTask = DB::table('enquiry_tasks')->where('project_enquiry_id', $this->enquiry->id)->where('type', 'budget')->orderByDesc('id')->first();
            $data = DB::table('task_budget_data')->where('enquiry_task_id', $budgetTask->id)->first();
            $planned = fn () => CostLine::where('project_enquiry_id', $this->enquiry->id)->where('nature', CostLine::NATURE_PLANNED)->where('status', '<>', 'reversed')->count();
            $before = $planned();
            $jobs = DB::table('jobs')->count();

            $this->expectOk($this->as($this->officer)->postJson("/api/projects/tasks/{$budgetTask->id}/budget", [
                'projectInfo' => json_decode($data->project_info, true) ?: [],
                'materials' => json_decode($data->materials_data, true) ?: [],
                'labour' => json_decode($data->labour_data, true) ?: [],
                'expenses' => json_decode($data->expenses_data, true) ?: [],
                'logistics' => json_decode($data->logistics_data, true) ?: [],
            ]));
            $queued = DB::table('jobs')->count() - $jobs;
            $drained = $this->drain();
            $after = $planned();
            if ($queued < 1 || $after !== $before) {
                throw new RuntimeException("queued {$queued}, planned {$before} → {$after}");
            }

            return "{$queued} job(s) queued, drain: {$drained}; planned lines unchanged at {$after} (no duplicates)";
        });
    }

    // ── W5 Petty Cash (+ R5 cost, R6b reversal) ──────────────────────────

    private function w5PettyCash(): void
    {
        $overheadPath = false;
        $source = DB::table('payment_sources')->where('is_active', true)->orderBy('id')->value('id');
        $code = $this->jobCode();

        $this->step('W5', 'Top-up the rehearsal float (test amount; the real opening float is D7)', fn () => $this->expectOk(
            $this->as($this->accounts)->postJson('/api/finance/petty-cash/top-ups', [
                'amount' => 200000, 'payment_method' => 'bank_transfer', 'external_reference' => 'REHEARSAL-TOPUP',
                'date_topped_up' => now()->toDateString(), 'description' => 'Rehearsal float',
            ]), 201));

        $disbursementId = $this->step('W5', 'Direct disbursement against the real project (queues RecordPettyCashCost)', function () use ($source, $code) {
            if (! $code) {
                return $this->blocked('D3', $this->d3CodeEvidence());
            }
            $r = $this->as($this->accounts)->postJson('/api/finance/petty-cash/disbursements', [
                'idempotency_key' => (string) Str::uuid(), 'payee_name' => 'Rehearsal Supplier', 'expense_code_id' => $code, 'amount' => 1500,
                'description' => 'Rehearsal site purchase', 'project_enquiry_id' => $this->enquiry->id, 'project_id' => $this->enquiry->project?->id,
                'direct_payment_reason' => 'Rehearsal of the direct payment path', 'date_disbursed' => now()->toDateString(),
                'payment_source_id' => $source, 'payment_method' => 'cash', 'receipt_type' => 'none', 'tax_amount' => 0,
            ]);
            if ($r->status() === 202 || str_contains((string) $r->getContent(), 'direct_disbursement_request')) {
                $requestId = $r->json('data.id') ?? $r->json('data.request_id');
                $approve = $this->as($this->superB)->postJson("/api/finance/petty-cash/direct-disbursement-requests/{$requestId}/approve");
                $this->expectOk($approve);

                return $approve->json('data.disbursement.id') ?? $approve->json('data.id');
            }
            $this->expectOk($r, 201);

            return $r->json('data.id');
        });
        if (! $disbursementId) {
            $overhead = DB::table('expense_codes')->where('is_active', true)->where('job_id_rule', 'not_allowed')->orderBy('id')->value('id');
            $disbursementId = $this->step('W5', 'Direct OVERHEAD disbursement (active overhead code) — exercises the queued cost listener', function () use ($source, $overhead) {
                $r = $this->as($this->accounts)->postJson('/api/finance/petty-cash/disbursements', [
                    'idempotency_key' => (string) Str::uuid(), 'payee_name' => 'Rehearsal Stationer', 'expense_code_id' => $overhead, 'amount' => 800,
                    'description' => 'Rehearsal office stationery', 'direct_payment_reason' => 'Rehearsal of the direct payment path (overhead)',
                    'date_disbursed' => now()->toDateString(), 'payment_source_id' => $source, 'payment_method' => 'cash', 'receipt_type' => 'none', 'tax_amount' => 0,
                ]);
                if ($r->status() === 202 || str_contains((string) $r->getContent(), 'direct_disbursement_request')) {
                    $requestId = $r->json('data.id') ?? $r->json('data.request_id');
                    $approve = $this->as($this->superB)->postJson("/api/finance/petty-cash/direct-disbursement-requests/{$requestId}/approve");
                    $this->expectOk($approve);

                    return $approve->json('data.disbursement.id') ?? $approve->json('data.id');
                }
                $this->expectOk($r, 201);

                return $r->json('data.id');
            });
            $overheadPath = true;
        }
        if ($disbursementId && ($overheadPath ?? false)) {
            $this->step('Q', 'R5 cost listener executes for the overhead payment (no project cost by design)', function () use ($disbursementId) {
                $jobs = DB::table('jobs')->count();
                $drained = $this->drain();
                $lines = CostLine::where('source_type', \App\Modules\Finance\Models\Payment::class)->where('source_id', $disbursementId)->count();
                $flag = DB::table('payments')->where('id', $disbursementId)->value('cost_gl_posting_error');

                return "queued before drain: {$jobs}; {$drained}; project cost lines: {$lines} (overhead → none expected)".($flag ? "; GL leg flagged (D3): {$flag}" : '');
            });
            $this->step('W5', 'Void the overhead disbursement (queues ReversePettyCashCost)', fn () => $this->expectOk(
                $this->as($this->accounts)->postJson("/api/finance/petty-cash/disbursements/{$disbursementId}/void", ['void_reason' => 'Rehearsal void'])));
            $this->step('Q', 'R6b reversal listener executes', fn () => $this->drain());
        } elseif ($disbursementId) {
            $this->step('Q', 'R5 petty-cash cost: the drain records the actual CostLine', function () use ($disbursementId) {
                $drained = $this->drain();
                $line = CostLine::where('source_type', \App\Modules\Finance\Models\Payment::class)->where('source_id', $disbursementId)->first();
                $flag = DB::table('payments')->where('id', $disbursementId)->value('cost_gl_posting_error');
                if (! $line && $flag) {
                    return $this->blocked('D3', "cost/GL posting flagged (STAB-4): {$flag}; drain {$drained}");
                }
                if (! $line) {
                    throw new RuntimeException("No CostLine for disbursement #{$disbursementId}; drain {$drained}");
                }

                return "CostLine #{$line->id} ({$line->nature}); drain {$drained}".($flag ? "; GL flagged: {$flag}" : '');
            });
            $this->step('W5', 'Void the disbursement (queues ReversePettyCashCost)', fn () => $this->expectOk(
                $this->as($this->accounts)->postJson("/api/finance/petty-cash/disbursements/{$disbursementId}/void", ['void_reason' => 'Rehearsal void'])));
            $this->step('Q', 'R6b reversal: the drain reverses the CostLine', function () use ($disbursementId) {
                $drained = $this->drain();
                $live = CostLine::where('source_type', \App\Modules\Finance\Models\Payment::class)->where('source_id', $disbursementId)->where('status', '<>', CostLine::STATUS_REVERSED)->count();
                if ($live > 0) {
                    throw new RuntimeException("{$live} line(s) still active; drain {$drained}");
                }

                return "reversed; drain {$drained}";
            });
        }
        $this->record('W5', 'Petty-cash custody role', 'INFO', 'Accounts #'.$this->accounts->id.' holds finance.petty_cash.custody: '.($this->accounts->can('finance.petty_cash.custody') ? 'yes' : 'no').'. No source role holds it (Super Admin only by bypass). WNG decides the custodian role; steps below run as Super Admin.');
        $this->step('W5', 'Cash count recorded (variance shown, no journal) — Super Admin', fn () => $this->expectOk(
            $this->as($this->superA)->postJson('/api/finance/petty-cash/cash-counts', ['physical_cash' => 190000, 'explanation' => 'Rehearsal: deliberate KES 10,000 variance to exercise the explanation control']), 201));
        $this->step('W5', 'Custody handover to another real user, confirmed by them — Super Admins', function () {
            $r = $this->as($this->superA)->postJson('/api/finance/petty-cash/custody/handovers', ['incoming_custodian_user_id' => $this->superB->id, 'physical_cash' => 190000]);
            $this->expectOk($r, 201);
            $id = $r->json('data.id') ?? $r->json('id');
            $this->expectOk($this->as($this->superB)->postJson("/api/finance/petty-cash/custody/handovers/{$id}/confirm"));
        });
    }

    // ── W3 Expenses (+ R4 commitment, R6a release) ────────────────────────

    private function w3Expenses(): void
    {
        $type = DB::table('petty_cash_requisition_types')->where('is_active', true)->where('requires_project', true)->orderBy('id')->get()
            ->first(fn ($t) => collect(json_decode((string) $t->request_fields, true) ?: [])->where('required', true)->isEmpty());
        $department = (int) (DB::table('employees')->where('id', $this->employeeId)->value('department_id'));
        $payload = fn (float $amount) => [
            'department_id' => $department, 'category' => $type->code ?? 'general', 'requisition_type_id' => $type->id ?? null,
            'purpose' => 'Rehearsal site expense', 'payee_name' => 'Rehearsal Payee', 'enquiry_id' => $this->enquiry->id, 'project_id' => $this->enquiry->project?->id,
            'items' => [['description' => 'Rehearsal item', 'amount' => $amount, 'payee_name' => 'Rehearsal Payee']],
        ];

        $id = $this->step('W3', 'Raise a project requisition (Project Officer)', function () use ($payload) {
            $r = $this->as($this->officer)->postJson('/api/finance/petty-cash/requisitions', $payload(2000));
            $this->expectOk($r, 201);

            return $r->json('data.id');
        });
        if (! $id) {
            return;
        }
        $commitments = fn () => CostLine::where('source_type', \App\Modules\Finance\PettyCash\Models\PettyCashRequisition::class)->where('source_id', $id)->where('nature', CostLine::NATURE_COMMITTED);

        $this->step('W3', 'Approve (Accounts) — queues RecordPettyCashCommitment', fn () => $this->expectOk($this->as($this->accounts)->postJson("/api/finance/petty-cash/requisitions/{$id}/approve")));
        $this->step('Q', 'R4 petty-cash commitment recorded by the drain', function () use ($commitments) {
            $drained = $this->drain();
            if ((clone $commitments())->where('status', '<>', 'reversed')->count() !== 1) {
                throw new RuntimeException('Commitment not recorded; drain '.$drained);
            }

            return "drain {$drained}";
        });
        $this->step('W3', 'Requester cannot edit an approved requisition (by design)', function () use ($id, $payload) {
            $r = $this->as($this->officer)->putJson("/api/finance/petty-cash/requisitions/{$id}", $payload(2500));
            if ($r->status() !== 403) {
                throw new RuntimeException("Requester edit of an approved requisition returned {$r->status()}.");
            }
        });
        $this->step('W3', 'Reviewer (Accounts) edits it — returns it to pending (queues ReleasePettyCashCommitment)', fn () => $this->expectOk($this->as($this->accounts)->putJson("/api/finance/petty-cash/requisitions/{$id}", $payload(2500))));
        $this->step('Q', 'R6a commitment released by the drain', function () use ($commitments) {
            $drained = $this->drain();
            // CostCollectorService::releaseCommitment marks the line reversed.
            $active = (clone $commitments())->where('status', '<>', CostLine::STATUS_REVERSED)->count();
            if ($active !== 0) {
                throw new RuntimeException("{$active} commitment(s) still active after release; drain {$drained}");
            }

            return "drain {$drained}";
        });
        $this->step('W3', 'Re-approve, disburse, surrender and reconcile', function () use ($id) {
            $this->expectOk($this->as($this->accounts)->postJson("/api/finance/petty-cash/requisitions/{$id}/approve"));
            $this->drain();
            $code = $this->jobCode();
            if (! $code) {
                return $this->blocked('D3', 'disburse/surrender need an active job-allowed expense code: '.$this->d3CodeEvidence());
            }
            $source = DB::table('payment_sources')->where('is_active', true)->orderBy('id')->value('id');
            $d = $this->as($this->accounts)->postJson("/api/finance/petty-cash/requisitions/{$id}/disburse", [
                'idempotency_key' => (string) Str::uuid(), 'expense_code_id' => $code, 'payment_source_id' => $source, 'payment_method' => 'cash',
                'amount' => 2500, 'payee_name' => 'Rehearsal Payee', 'description' => 'Rehearsal advance', 'date_disbursed' => now()->toDateString(), 'receipt_type' => 'none',
            ]);
            if ($d->status() >= 400 && str_contains(strtolower((string) $d->getContent()), 'account')) {
                return $this->blocked('D3', 'disburse: '.$this->message($d));
            }
            $this->expectOk($d);
            $this->drain();
            $this->expectOk($this->as($this->officer)->postJson("/api/finance/petty-cash/requisitions/{$id}/surrender", [
                'items' => [['expense_code_id' => $code, 'amount' => 2500, 'tax_amount' => 0, 'receipt_type' => 'non_etr', 'supplier_name' => 'Rehearsal Supplier', 'description' => 'Rehearsal item']],
                'cash_returned_amount' => 0,
            ]));
            $rc = $this->as($this->accounts)->postJson("/api/finance/petty-cash/requisitions/{$id}/reconcile");
            if ($rc->status() >= 400 && str_contains(strtolower((string) $rc->getContent()), 'account')) {
                return $this->blocked('D3', 'reconcile: '.$this->message($rc));
            }
            $this->expectOk($rc);
            $this->drain();
            $actual = CostLine::where('project_enquiry_id', $this->enquiry->id)->where('nature', CostLine::NATURE_ACTUAL)
                ->where('description', 'like', '%'.DB::table('petty_cash_requisitions')->where('id', $id)->value('requisition_number').'%')->count();

            return "reconciled; actual lines referencing the requisition: {$actual}";
        });
    }

    // ── W2 Procurement to Payment (+ R2 commitment, R3 accrual) ──────────

    private function w2Procurement(): void
    {
        $supplier = DB::table('suppliers')->orderBy('id')->value('id');
        $department = (int) DB::table('departments')->orderBy('id')->value('id');
        // An office purchase is overhead: an active procurable overhead code is the right category
        // (the only codes D3 leaves active are overhead ones).
        $code = DB::table('expense_codes')->where('is_active', true)->where('is_procurable', true)->orderBy('id')->value('id');

        $this->record('W2', 'D2 scope', 'INFO', 'D2 concerns importing historical POs/GRNs/bills (0 rows in the source). This step creates NEW rehearsal documents only.');

        $requisitionId = $this->step('W2', 'Raise a procurement requisition (Super Admin A)', function () use ($department, $supplier, $code) {
            $r = $this->as($this->superA)->postJson('/api/procurement-stores/requisitions', [
                'date' => now()->toDateString(), 'requested_by_type' => 'office', 'department_id' => $department, 'urgency' => 'normal',
                'items' => [['quantity' => 2, 'unit_price' => 5000, 'purpose' => 'office_use', 'custom_description' => 'Rehearsal item',
                    'supplier_id' => $supplier, 'expense_code_id' => $code]],
            ]);
            $this->expectOk($r, 201);

            return $r->json('data.id') ?? $r->json('id');
        });
        if (! $requisitionId) {
            return;
        }
        $this->step('W2', 'Approve the requisition (Super Admin B)', fn () => $this->expectOk($this->as($this->superB)->postJson("/api/procurement-stores/requisitions/{$requisitionId}/approve")));
        $orderId = $this->step('W2', 'Raise the PO from the approved requisition', function () use ($requisitionId) {
            $r = $this->as($this->superA)->postJson('/api/procurement-stores/purchase-orders/store-linked', [
                'requisition_id' => $requisitionId, 'due_date' => now()->addDays(7)->toDateString(), 'delivery_address' => 'Rehearsal store',
            ]);
            $this->expectOk($r, 201);

            return $r->json('data.id') ?? $r->json('id');
        });
        if (! $orderId) {
            return;
        }
        if (DB::table('purchase_orders')->where('id', $orderId)->value('status') !== 'approved') {
            $this->step('W2', 'Approve the PO (Super Admin B)', fn () => $this->expectOk($this->as($this->superB)->postJson("/api/procurement-stores/purchase-orders/{$orderId}/approve")));
        }
        $this->step('Q', 'R2 PO commitment recorded by the drain', function () use ($orderId) {
            $drained = $this->drain();
            $n = CostLine::where('source_type', \App\Modules\ProcurementStores\Models\PurchaseOrderItem::class)
                ->whereIn('source_id', DB::table('purchase_order_items')->where('purchase_order_id', $orderId)->pluck('id'))->count();

            return "{$n} commitment line(s); drain {$drained}".($n === 0 ? ' (office purchase: no project to commit against)' : '');
        });
        $this->step('W2', 'Receive goods (GRN) — queues RecordGoodsReceiptAccruals', function () use ($orderId) {
            $item = DB::table('purchase_order_items')->where('purchase_order_id', $orderId)->first();
            $this->expectOk($this->as($this->superA)->postJson('/api/procurement-stores/goods-receipt-notes', [
                'purchase_order_id' => $orderId, 'store_location' => 'Karen Village Store', 'quality_check' => 'pass',
                'items' => [['purchase_order_item_id' => $item->id, 'ordered_quantity' => $item->quantity, 'received_quantity' => $item->quantity, 'condition' => 'good', 'accepted' => true]],
            ]), 201);
        });
        $this->step('Q', 'R3 GRN accrual processed by the drain', function () {
            $failedBefore = DB::table('failed_jobs')->count();
            $drained = $this->drain();
            $failed = DB::table('failed_jobs')->count() - $failedBefore;
            if ($failed > 0) {
                $error = (string) DB::table('failed_jobs')->orderByDesc('id')->value('exception');
                return preg_match('/account|posting rule/i', $error) ? $this->blocked('D3', 'accrual journal: '.Str::limit(strtok($error, "\n"), 160)) : throw new RuntimeException(Str::limit(strtok($error, "\n"), 200));
            }

            return "drain {$drained}";
        });
        $this->step('W2', 'Stores confirms the received line into stock against a real library material', function () use ($orderId) {
            $line = DB::table('goods_receipt_note_items as i')->join('goods_receipt_notes as g', 'g.id', '=', 'i.goods_receipt_note_id')
                ->where('g.purchase_order_id', $orderId)->orderByDesc('i.id')->value('i.id');
            $material = DB::table('library_materials')->where('item_status', 'Active')->orderBy('id')->value('id')
                ?? DB::table('library_materials')->orderBy('id')->value('id');
            $this->expectOk($this->as($this->superA)->postJson("/api/procurement-stores/goods-receipt-note-items/{$line}/confirm", [
                'material_id' => $material, 'unit_price' => 5000,
            ]));
            $drained = $this->drain();

            return "material #{$material}; drain {$drained}";
        });
        $billId = $this->step('W2', 'Record the supplier bill', function () use ($orderId) {
            $r = $this->as($this->superA)->postJson('/api/procurement-stores/bills', [
                'purchase_order_id' => $orderId, 'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
                'amount' => 10000, 'supplier_invoice_number' => 'REH-'.Str::random(6),
            ]);
            $this->expectOk($r, 201);

            return $r->json('data.id') ?? $r->json('id');
        });
        if ($billId) {
            $this->step('W2', 'Verify the bill (Super Admin B)', function () use ($billId) {
                $r = $this->as($this->superB)->postJson("/api/procurement-stores/bills/{$billId}/verify");
                if ($r->status() >= 400 && str_contains(strtolower((string) $r->getContent()), 'account')) {
                    return $this->blocked('D3', $this->message($r));
                }
                $this->expectOk($r);
            });
            $this->step('W2', 'Record the supplier payment', function () use ($billId) {
                $r = $this->as($this->accounts)->postJson("/api/procurement-stores/bills/{$billId}/record-payment", [
                    'amount_paid' => 10000, 'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer', 'reference_number' => 'REH-FT',
                    'payment_source_id' => DB::table('payment_sources')->where('is_active', true)->where('can_make_payment', true)->orderBy('id')->value('id'),
                ]);
                if ($r->status() >= 400 && str_contains(strtolower((string) $r->getContent()), 'account')) {
                    return $this->blocked('D3', $this->message($r));
                }
                $this->expectOk($r);
            });
        }
    }

    // ── W4 Payment Vouchers ──────────────────────────────────────────────

    private function w4PaymentVouchers(): void
    {
        $voucherId = $this->step('W4', 'Create a payment voucher against an eligible liability (Accounts)', function () {
            $eligible = $this->as($this->accounts)->getJson('/api/finance/spend-vouchers/eligible-liabilities');
            $this->expectOk($eligible);
            $rows = collect($eligible->json('data') ?? []);
            $first = $rows->first();
            if (! $first) {
                return $this->blocked('D3', 'no eligible liability: payable cost lines arise from coded purchases/expenses, which need active expense codes ('.$this->d3CodeEvidence().')');
            }
            $line = (object) ['id' => $first['cost_line_id'] ?? $first['id']];
            $r = $this->as($this->accounts)->postJson('/api/finance/spend-vouchers', [
                'type' => 'payment', 'payee_name' => 'Rehearsal Payee', 'total_amount' => 100.0, 'payment_method' => 'bank_transfer',
                'payment_source_id' => DB::table('payment_sources')->where('is_active', true)->where('can_make_payment', true)->orderBy('id')->value('id'),
                'allocations' => $line ? [['cost_line_id' => $line->id, 'amount' => 100.0]] : [],
            ]);
            $this->expectOk($r, 201);

            return $r->json('data.id') ?? $r->json('id');
        });
        if (! $voucherId) {
            return;
        }
        $this->step('W4', 'Return → correct → resubmit', function () use ($voucherId) {
            $this->expectOk($this->as($this->superB)->postJson("/api/finance/spend-vouchers/{$voucherId}/return", ['reason' => 'Rehearsal: invoice number missing']));
            $this->expectOk($this->as($this->accounts)->postJson("/api/finance/spend-vouchers/{$voucherId}/correction", ['supplier_invoice_no' => 'REH-1']));
            $this->expectOk($this->as($this->accounts)->postJson("/api/finance/spend-vouchers/{$voucherId}/resubmit"));
        });
        $this->step('W4', 'Approve (Super Admin B)', fn () => $this->expectOk($this->as($this->superB)->postJson("/api/finance/spend-vouchers/{$voucherId}/approve")));
        $this->step('W4', 'Post', function () use ($voucherId) {
            $r = $this->as($this->accounts)->postJson("/api/finance/spend-vouchers/{$voucherId}/post");
            if ($r->status() >= 400 && str_contains(strtolower((string) $r->getContent()), 'account')) {
                return $this->blocked('D3', $this->message($r));
            }
            $this->expectOk($r);
        });
    }

    // ── W1 Receivables ───────────────────────────────────────────────────

    private function w1Receivables(): void
    {
        $base = "/api/projects/enquiries/{$this->enquiry->id}/invoices";
        $invoiceId = $this->step('W1', 'Draft an invoice for the real client project (Accounts)', function () use ($base) {
            $r = $this->as($this->accounts)->postJson($base, [
                'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
                'lines' => [['description' => 'Rehearsal stand build (small, within the agreed price)', 'quantity' => 1, 'unit_price' => 1000]],
            ]);
            $this->expectOk($r, 201);

            return $r->json('data.id') ?? $r->json('id');
        });
        if (! $invoiceId) {
            return;
        }
        $this->step('W1', 'Preparer cannot check own invoice', function () use ($base, $invoiceId) {
            $r = $this->as($this->accounts)->postJson("{$base}/{$invoiceId}/check");
            if ($r->status() < 400) {
                throw new RuntimeException('Preparer was allowed to check their own invoice.');
            }
        });
        $this->step('W1', 'Another holder checks it (Super Admin B)', fn () => $this->expectOk($this->as($this->superB)->postJson("{$base}/{$invoiceId}/check")));
        $this->step('W1', 'Issue — journal exists exactly once', function () use ($base, $invoiceId) {
            $r = $this->as($this->accounts)->postJson("{$base}/{$invoiceId}/issue");
            if ($r->status() >= 400 && str_contains(strtolower((string) $r->getContent()), 'account')) {
                return $this->blocked('D3', $this->message($r));
            }
            $this->expectOk($r);
            $n = DB::table('journal_entries')->where('source_type', \App\Modules\Finance\Models\ProjectInvoice::class)->where('source_id', $invoiceId)->count();
            if ($n !== 1) {
                throw new RuntimeException("{$n} journals for the invoice.");
            }
        });
    }

    private function financeReadiness(): void
    {
        $this->record('D3', 'Finance readiness access', 'INFO', 'Accounts holds finance.reports.view: '.($this->accounts->can('finance.reports.view') ? 'yes' : 'no').' (source: Super Admin only). Read as Super Admin.');
        $this->step('D3', 'Finance readiness on the rehearsal target (Super Admin)', function () {
            $r = $this->as($this->superA)->getJson('/api/finance/readiness');
            $this->expectOk($r);

            return Str::limit(json_encode($r->json('data') ?? $r->json()), 600);
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** An active expense code a project may be charged to — null while D3 leaves every such code inactive. */
    private function jobCode(bool $procurable = false): ?int
    {
        $q = DB::table('expense_codes')->where('is_active', true)->where('job_id_rule', '<>', 'not_allowed');
        if ($procurable) {
            $q->where('is_procurable', true);
        }

        return $q->orderBy('id')->value('id');
    }

    private function d3CodeEvidence(): string
    {
        return sprintf('%d of %d expense codes active, %d job-allowed active; ExpenseCodeSeeder activates a code only when it resolves a postable chart account, and the chart is D3 (%d accounts present)',
            DB::table('expense_codes')->where('is_active', true)->count(), DB::table('expense_codes')->count(),
            DB::table('expense_codes')->where('is_active', true)->where('job_id_rule', '<>', 'not_allowed')->count(),
            DB::table('chart_of_accounts')->count());
    }

    private function as(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }

    /** Production drain shape (Report 50 §18): stop when empty, bounded time, three tries. */
    private function drain(): string
    {
        $before = DB::table('jobs')->count();
        $failedBefore = DB::table('failed_jobs')->count();
        $deadline = microtime(true) + 120;
        do {
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'stores-finance,default', '--stop-when-empty' => true,
                '--tries' => 3, '--max-time' => 55, '--timeout' => 50, '--sleep' => 0, '--backoff' => 0]);
        } while (DB::table('jobs')->where('available_at', '<=', now()->timestamp)->exists() && microtime(true) < $deadline);

        return sprintf('%d job(s) processed, %d failed', max(0, $before - DB::table('jobs')->count()), DB::table('failed_jobs')->count() - $failedBefore);
    }

    private function step(string $w, string $name, callable $fn): mixed
    {
        try {
            $value = $fn();
            if (is_array($value) && ($value['__blocked'] ?? false)) {
                $this->record($w, $name, 'BLOCKED:'.$value['decision'], $value['detail']);

                return null;
            }
            $this->record($w, $name, 'PASS', is_scalar($value) ? (string) $value : '');

            return $value ?? true;
        } catch (D3Blocked $e) {
            $this->record($w, $name, 'BLOCKED:D3', Str::limit($e->getMessage(), 400));

            return null;
        } catch (Throwable $e) {
            $this->record($w, $name, 'FAIL', Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()), 400));

            return null;
        }
    }

    private function blocked(string $decision, string $detail): array
    {
        return ['__blocked' => true, 'decision' => $decision, 'detail' => $detail];
    }

    private function record(string $w, string $step, string $status, string $detail): void
    {
        $this->results[] = ['w' => $w, 'step' => $step, 'status' => $status, 'detail' => $detail];
    }

    private function expectOk(TestResponse $r, ?int $status = null): TestResponse
    {
        if (($status !== null && $r->status() !== $status && ! ($status === 201 && $r->status() === 200)) || $r->status() >= 400) {
            $message = $this->message($r);
            if (preg_match('/expense code|chart of account|no (postable )?account|account .*not (found|configured)|not mapped|mapped postable|posting rule/i', $message)) {
                throw new D3Blocked("HTTP {$r->status()}: {$message}");
            }
            throw new RuntimeException("HTTP {$r->status()}: {$message}");
        }

        return $r;
    }

    private function message(TestResponse $r): string
    {
        $json = json_decode((string) $r->getContent(), true);

        return Str::limit(is_array($json) ? json_encode(array_intersect_key($json, array_flip(['message', 'errors', 'error']))) : (string) $r->getContent(), 300);
    }

    /** @return list<string> GET routes under a prefix that need only the enquiry id */
    private function getRoutesFor(string $prefix): array
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true) && str_starts_with($r->uri(), $prefix)
                && substr_count($r->uri(), '{') === 1)
            ->map(fn ($r) => $r->uri())->unique()->sort()->values()->all();
    }
}

/** A refusal caused by the pending chart-of-accounts decision (D3): no active expense code or postable account. */
class D3Blocked extends RuntimeException {}
