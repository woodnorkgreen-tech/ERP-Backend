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
        $this->storesIssueToProject();
        $this->wipReleaseOnARealProject();
        $this->payrollFinancePosting();
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
            // R-1 (Report 53): the project's own Project Officer records and PO-verifies.
            $recorder = ($access->canRecordLabour($officer, $enquiry) && $access->canPoVerifyLabour($officer, $enquiry))
                ? $officer
                : $labourRoles->first(fn ($u) => $access->canRecordLabour($u, $enquiry) && $access->canPoVerifyLabour($u, $enquiry));
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

        $access = app(\App\Services\ProjectFinancialAccess::class);
        $this->step('W7', 'R-1: the project\'s real Project Officer may record and PO-verify here, never Finance-verify', function () use ($access) {
            if (! $access->canRecordLabour($this->officer, $this->enquiry) || ! $access->canPoVerifyLabour($this->officer, $this->enquiry)) {
                throw new RuntimeException('Project Officer #'.$this->officer->id.' cannot record/PO-verify on their own project.');
            }
            if ($this->officer->can('finance.labour.finance_verify')) {
                throw new RuntimeException('Project Officer holds finance.labour.finance_verify.');
            }
            $other = ProjectEnquiry::query()->whereNotNull('project_officer_id')->where('project_officer_id', '<>', $this->officer->id)
                ->orderByDesc('id')->limit(50)->get()->first(fn ($e) => ! $access->canRecordLabour($this->officer, $e));

            return 'recorder is user #'.$this->recorder->id.($this->recorder->is($this->officer) ? ' (the Project Officer)' : '')
                .'; scoped: refused on another officer\'s project #'.($other?->id ?? '—');
        });
        $actualId = $this->step('W7', 'Record labour against the real budget line, for a real employee (Project Officer)', function () use ($base, $line) {
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

        $this->step('W7', 'The Project Officer cannot Finance-verify (403)', function () use ($base, $actualId) {
            $r = $this->as($this->officer)->postJson("{$base}/{$actualId}/finance-verify", ['notes' => 'Rehearsal']);
            if ($r->status() !== 403) {
                throw new RuntimeException("Project Officer finance-verify returned {$r->status()}.");
            }
        });
        $this->step('W7', 'Project Officer verify (the project\'s Project Officer)', fn () => $this->expectOk($this->as($this->recorder)->postJson("{$base}/{$actualId}/po-verify", ['notes' => 'Rehearsal'])));
        $financeVerified = $this->step('W7', 'Finance verify (Accounts) creates the actual CostLine', function () use ($base, $actualId) {
            $this->expectOk($this->as($this->accounts)->postJson("{$base}/{$actualId}/finance-verify", ['notes' => 'Rehearsal']));
            $costLine = DB::table('project_labour_actuals')->where('id', $actualId)->value('cost_line_id');
            if (! $costLine) {
                throw new RuntimeException('No actual CostLine linked after Finance verify.');
            }

            return "CostLine #{$costLine}";
        });
        if (! $financeVerified) {
            $this->record('W7', 'Project Costing actual labour increased; no journal created', 'FAIL', 'Finance verify did not complete (see above)');
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
                throw new RuntimeException($this->d3CodeEvidence());
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
                    throw new RuntimeException("cost/GL posting flagged (STAB-4): {$flag}; drain {$drained}");
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
        $this->record('W5', 'R-2 petty-cash custody (open)', 'BLOCKED:R-2', 'finance.petty_cash.manage_custody is held by no role but Super Admin (Accounts #'.$this->accounts->id.': '
            .($this->accounts->can(\App\Constants\Permissions::FINANCE_PETTY_CASH_CUSTODY) ? 'yes' : 'no').'). WNG names the custodian; the custody steps below run as Super Admin.');
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
                throw new RuntimeException('disburse/surrender need an active job-allowed expense code: '.$this->d3CodeEvidence());
            }
            $source = DB::table('payment_sources')->where('is_active', true)->orderBy('id')->value('id');
            $d = $this->as($this->accounts)->postJson("/api/finance/petty-cash/requisitions/{$id}/disburse", [
                'idempotency_key' => (string) Str::uuid(), 'expense_code_id' => $code, 'payment_source_id' => $source, 'payment_method' => 'cash',
                'amount' => 2500, 'payee_name' => 'Rehearsal Payee', 'description' => 'Rehearsal advance', 'date_disbursed' => now()->toDateString(), 'receipt_type' => 'none',
            ]);
            if ($d->status() >= 400 && str_contains(strtolower((string) $d->getContent()), 'account')) {
                throw new RuntimeException('disburse: '.$this->message($d));
            }
            $this->expectOk($d);
            $this->drain();
            $this->expectOk($this->as($this->officer)->postJson("/api/finance/petty-cash/requisitions/{$id}/surrender", [
                'items' => [['expense_code_id' => $code, 'amount' => 2500, 'tax_amount' => 0, 'receipt_type' => 'non_etr', 'supplier_name' => 'Rehearsal Supplier', 'description' => 'Rehearsal item']],
                'cash_returned_amount' => 0,
            ]));
            $rc = $this->as($this->accounts)->postJson("/api/finance/petty-cash/requisitions/{$id}/reconcile");
            if ($rc->status() >= 400 && str_contains(strtolower((string) $rc->getContent()), 'account')) {
                throw new RuntimeException('reconcile: '.$this->message($rc));
            }
            $this->expectOk($rc);
            $this->drain();
            $actual = CostLine::where('project_enquiry_id', $this->enquiry->id)->where('nature', CostLine::NATURE_ACTUAL)
                ->where('status', '<>', CostLine::STATUS_REVERSED)->whereRaw("JSON_EXTRACT(details, '$.requisition_id') = ?", [$id])->count();
            $status = DB::table('petty_cash_requisitions')->where('id', $id)->value('status');
            if ($actual < 1) {
                throw new RuntimeException("Reconciled (status {$status}) but no project actual cost line carries requisition #{$id}.");
            }

            return "status {$status}; {$actual} actual project cost line(s) for the requisition";
        });
    }

    // ── W2 Procurement to Payment (+ R2 commitment, R3 accrual) ──────────

    private function w2Procurement(): void
    {
        $supplier = DB::table('suppliers')->orderBy('id')->value('id');
        $department = (int) DB::table('departments')->orderBy('id')->value('id');
        // An office purchase is overhead: an active procurable overhead code is the right category
        // (the only codes D3 leaves active are overhead ones).
        $code = DB::table('expense_codes')->where('is_active', true)->where('is_procurable', true)->where('job_id_rule', 'not_allowed')->orderBy('id')->value('id');

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
            $id = $r->json('data.0.id') ?? $r->json('data.id');
            if (! is_int($id)) {
                throw new RuntimeException('No purchase order id in the store-linked response.');
            }

            return $id;
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
                throw new RuntimeException('accrual job failed: '.Str::limit(strtok($error, "\n"), 200));
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
                'amount' => 10000, 'wht_amount' => 200, 'supplier_invoice_number' => 'REH-'.Str::random(6),
            ]);
            $this->expectOk($r, 201);

            return $r->json('data.id') ?? $r->json('id');
        });
        if ($billId) {
            $this->step('W2', 'Verify the bill (Super Admin B)', function () use ($billId) {
                $r = $this->as($this->superB)->postJson("/api/procurement-stores/bills/{$billId}/verify");
                if ($r->status() >= 400 && str_contains(strtolower((string) $r->getContent()), 'account')) {
                    throw new RuntimeException($this->message($r));
                }
                $this->expectOk($r);
            });
            $this->step('W2', 'WHT: the bill journal retains withholding on the WHT payable account', function () use ($billId) {
                $journal = DB::table('journal_entries')->where('source_type', \App\Modules\ProcurementStores\Models\Bill::class)->where('source_id', $billId)->value('id');
                if (! $journal) {
                    throw new RuntimeException('The verified bill has no journal.');
                }
                $legs = DB::table('journal_lines as l')->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
                    ->where('l.journal_entry_id', $journal)->get(['a.code as account_code', 'l.entry_type', 'l.amount']);
                $wht = $legs->first(fn ($l) => $l->account_code === 'WHT-001' && $l->entry_type === 'credit');
                if (! $wht || (float) $wht->amount !== 200.0) {
                    throw new RuntimeException('No WHT credit of 200 on the bill journal: '.$legs->map(fn ($l) => "{$l->entry_type[0]}:{$l->account_code} {$l->amount}")->implode(', '));
                }
                if ((float) DB::table('bills')->where('id', $billId)->value('balance') !== 9800.0) {
                    throw new RuntimeException('The supplier balance is not the invoice less withholding.');
                }

                return "journal #{$journal}: ".$legs->map(fn ($l) => "{$l->entry_type[0]}:{$l->account_code} {$l->amount}")->implode(', ').'; supplier owed 9800.00';
            });
            $this->step('W2', 'Record the supplier payment (the net: invoice less WHT)', function () use ($billId) {
                $r = $this->as($this->accounts)->postJson("/api/procurement-stores/bills/{$billId}/record-payment", [
                    'amount_paid' => 9800, 'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer', 'reference_number' => 'REH-FT',
                    'payment_source_id' => DB::table('payment_sources')->where('is_active', true)->where('can_make_payment', true)->orderBy('id')->value('id'),
                ]);
                if ($r->status() >= 400 && str_contains(strtolower((string) $r->getContent()), 'account')) {
                    throw new RuntimeException($this->message($r));
                }
                $this->expectOk($r);
            });
        }
    }

    // ── W4 Payment Vouchers ──────────────────────────────────────────────

    private function w4PaymentVouchers(): void
    {
        // A payment voucher settles a verified liability that is NOT a goods-received
        // accrual: those are paid through their supplier bill after the three-way match
        // (Report 63 §6; the voucher-first GRN double payment). So W4 raises its own
        // supplier invoice on credit through the Cost Collector (funding_mode
        // unpaid_invoice), has a second person verify it (Dr expense / Cr AP), and pays
        // exactly that liability. Earlier rehearsals paid an unbilled GRN accrual here,
        // which Stream E now correctly refuses.
        $liabilityId = $this->step('W4', 'Capture a supplier invoice on credit (Cost Collector, Super Admin A) and verify it (Super Admin B)', function () {
            $code = DB::table('expense_codes')->where('is_active', true)->where('is_procurable', true)->where('job_id_rule', 'not_allowed')->orderBy('id')->value('code');
            $supplier = DB::table('suppliers')->whereNotNull('kra_pin')->where('kra_pin', '!=', '')->orderBy('id')->first(['id', 'supplier_name']);
            if (! $code || ! $supplier) {
                throw new RuntimeException('no active overhead expense code or no supplier with a KRA PIN ('.$this->d3CodeEvidence().')');
            }
            // The invoice is uploaded first, as the capture form does, under each key the code requires.
            $evidence = [];
            foreach (\App\Modules\Finance\CostCollector\Models\ExpenseCode::where('code', $code)->firstOrFail()->requiredEvidenceKeys() as $key) {
                $upload = $this->expectOk($this->as($this->superA)->post('/api/costs/evidence', [
                    'key' => $key, 'file' => \Illuminate\Http\UploadedFile::fake()->create("rehearsal-{$key}.pdf", 20, 'application/pdf'),
                ]), 201);
                $evidence[] = ['key' => $key, 'path' => $upload->json('data.path')];
            }
            $r = $this->expectOk($this->as($this->superA)->postJson('/api/costs', [
                'evidence' => $evidence,
                'expense_code' => $code, 'amount' => 100, 'description' => 'Rehearsal supplier invoice on credit',
                'funding_mode' => 'unpaid_invoice', 'payee_type' => 'SUPPLIER', 'payee_id' => $supplier->id, 'payee_name' => $supplier->supplier_name,
            ]), 201);
            $costId = $r->json('data.id');
            $this->expectOk($this->as($this->superB)->postJson("/api/costs/verification/{$costId}/verify"));

            return $costId;
        });
        $voucherId = $liabilityId ? $this->step('W4', 'Create a payment voucher against that verified supplier liability (Accounts)', function () use ($liabilityId) {
            $eligible = $this->as($this->accounts)->getJson('/api/finance/spend-vouchers/eligible-liabilities');
            $this->expectOk($eligible);
            if (! collect($eligible->json('data') ?? [])->contains('id', $liabilityId)) {
                throw new RuntimeException("the verified supplier liability #{$liabilityId} is not offered as eligible");
            }
            $r = $this->as($this->accounts)->postJson('/api/finance/spend-vouchers', [
                'type' => 'payment', 'payee_name' => 'Rehearsal Payee', 'total_amount' => 100.0, 'payment_method' => 'bank_transfer',
                'payment_source_id' => DB::table('payment_sources')->where('is_active', true)->where('can_make_payment', true)->where('type', 'bank')->whereNotNull('gl_account_id')->orderBy('id')->value('id'),
                'allocations' => [['cost_line_id' => $liabilityId, 'amount' => 100.0]],
            ]);
            $this->expectOk($r, 201);

            return $r->json('data.id') ?? $r->json('id');
        }) : null;
        $this->step('W4', 'A received-but-not-billed GRN accrual is never offered to a voucher', function () {
            $grnAccruals = DB::table('cost_lines')->where('source_type', \App\Modules\Finance\CostCollector\Models\CostLine::GRN_ACCRUAL_SOURCE)
                ->where('source_ref', 'accrual')->pluck('id');
            $offered = collect($this->as($this->accounts)->getJson('/api/finance/spend-vouchers/eligible-liabilities')->json('data') ?? [])->pluck('id');
            if ($offered->intersect($grnAccruals)->isNotEmpty()) {
                throw new RuntimeException('a GRN accrual was offered as a voucher liability');
            }
        });
        if (! $voucherId) {
            return;
        }
        $this->step('W4', 'Return → correct → resubmit', function () use ($voucherId) {
            $this->expectOk($this->as($this->superB)->postJson("/api/finance/spend-vouchers/{$voucherId}/return", ['reason' => 'Rehearsal: invoice number missing']));
            $this->expectOk($this->as($this->accounts)->putJson("/api/finance/spend-vouchers/{$voucherId}/correction", ['supplier_invoice_no' => 'REH-1']));
            $this->expectOk($this->as($this->accounts)->postJson("/api/finance/spend-vouchers/{$voucherId}/resubmit"));
        });
        $this->step('W4', 'Approve (Super Admin B)', fn () => $this->expectOk($this->as($this->superB)->postJson("/api/finance/spend-vouchers/{$voucherId}/approve")));
        // Three-way segregation: neither the requester (Accounts) nor the approver (B) may post.
        $this->step('W4', 'Post (a third user: Super Admin A)', function () use ($voucherId) {
            $r = $this->as($this->superA)->postJson("/api/finance/spend-vouchers/{$voucherId}/post");
            if ($r->status() >= 400 && str_contains(strtolower((string) $r->getContent()), 'account')) {
                throw new RuntimeException($this->message($r));
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
                throw new RuntimeException($this->message($r));
            }
            $this->expectOk($r);
            $entries = DB::table('journal_entries')->where('source_type', \App\Modules\Finance\Models\ProjectInvoice::class)->where('source_id', $invoiceId)->get(['id', 'description']);
            $invoiceJournal = (int) DB::table('project_invoices')->where('id', $invoiceId)->value('journal_entry_id');
            $releases = $entries->filter(fn ($e) => str_starts_with((string) $e->description, 'Cost of sales released'))->count();
            if (! $invoiceJournal || $entries->where('id', $invoiceJournal)->count() !== 1 || $entries->count() !== 1 + $releases || $releases > 1) {
                throw new RuntimeException("{$entries->count()} journals for the invoice ({$releases} WIP release).");
            }

            return "invoice journal #{$invoiceJournal} once".($releases ? '; plus one WIP → cost-of-sales release (the job carried WIP)' : '');
        });

        $payments = "/api/projects/enquiries/{$this->enquiry->id}/payments";
        $bank = DB::table('payment_sources')->where('code', 'BANK-MAIN')->where('is_active', true)->value('id');
        $receiptId = $this->step('W1', 'Record a client receipt by bank transfer (Accounts)', function () use ($payments, $bank) {
            $r = $this->as($this->accounts)->postJson($payments, [
                'amount' => 1000, 'received_amount' => 1000, 'payment_date' => now()->toDateString(),
                'payment_method' => 'bank_transfer', 'payment_source_id' => $bank, 'transaction_reference' => 'REH-RCPT-'.Str::random(6),
            ]);
            $this->expectOk($r, 201);

            return $r->json('data.id');
        });
        if ($receiptId) {
            $this->step('W1', 'Verify the receipt (Super Admin B) — posts the receipt journal once', function () use ($payments, $receiptId) {
                $this->expectOk($this->as($this->superB)->postJson("{$payments}/{$receiptId}/verify"));
                $entries = DB::table('journal_entries')->where('source_type', \App\Models\EnquiryPayment::class)->where('source_id', $receiptId)->pluck('id');
                if ($entries->count() !== 1) {
                    throw new RuntimeException("{$entries->count()} journals for the receipt.");
                }
                $legs = DB::table('journal_lines as l')->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
                    ->where('l.journal_entry_id', $entries->first())->get(['a.code as account_code', 'l.entry_type', 'l.amount']);
                if (! $legs->contains(fn ($l) => $l->account_code === 'EQB-001' && $l->entry_type === 'debit' && (float) $l->amount === 1000.0)) {
                    throw new RuntimeException('The receipt did not debit BANK-MAIN (EQB-001).');
                }

                return "journal #{$entries->first()}: ".$legs->map(fn ($l) => "{$l->entry_type[0]}:{$l->account_code} {$l->amount}")->implode(', ');
            });
        }
        $this->step('W1', 'M-Pesa receipt while MPESA is unlinked (MIG-P1)', function () use ($payments) {
            $mpesa = DB::table('payment_sources')->where('code', 'MPESA')->value('id');
            $r = $this->as($this->accounts)->postJson($payments, [
                'amount' => 500, 'received_amount' => 500, 'payment_date' => now()->toDateString(),
                'payment_method' => 'mpesa', 'payment_source_id' => $mpesa, 'transaction_reference' => 'REH-MP',
            ]);
            if ($r->status() !== 422) {
                throw new RuntimeException('Expected a 422 refusal, got HTTP '.$r->status().'.');
            }

            return $this->blocked('MIG-P1', 'refused (HTTP '.$r->status().'): the MPESA paying account is disabled until WNG links it, so M-Pesa receipts (63 of 160 in the source) cannot be recorded as M-Pesa until then');
        });
    }

    private function financeReadiness(): void
    {
        $this->step('R-3', 'Accounts holds finance.reports.view and opens the Finance reports', function () {
            if (! $this->accounts->can('finance.reports.view')) {
                throw new RuntimeException('Accounts lacks finance.reports.view.');
            }
            $opened = [];
            foreach (collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => in_array('GET', $r->methods(), true)
                && str_starts_with($r->uri(), 'api/finance/reports/') && ! str_contains($r->uri(), '{'))->map(fn ($r) => $r->uri())->unique() as $uri) {
                $this->expectOk($this->as($this->accounts)->getJson('/'.$uri.'?from='.now()->startOfMonth()->toDateString().'&to='.now()->toDateString()));
                $opened[] = $uri;
            }

            return count($opened).' report endpoint(s) opened: '.implode(', ', $opened);
        });
        $this->step('READY', 'Finance readiness on the rehearsal target (Accounts)', function () {
            $r = $this->expectOk($this->as($this->accounts)->getJson('/api/finance/readiness'));
            $checks = collect($r->json('data.checks'))->map(fn ($c) => ($c['ready'] ? 'OK ' : 'NO ').$c['key'])->implode('; ');
            $resolved = collect($r->json('data.account_functions'))->where('resolved', true)->count();

            return 'ready='.json_encode($r->json('data.ready'))."; functions {$resolved}/37; integrity ".json_encode($r->json('data.integrity'))."; {$checks}";
        });
    }

    // ── Stores: receive and issue to a real project (inventory → WIP) ────

    private function storesIssueToProject(): void
    {
        $requirement = DB::table('element_materials as em')
            ->join('project_deliverables as pe', 'pe.id', '=', 'em.project_element_id')
            ->join('task_materials_data as tmd', 'tmd.id', '=', 'pe.task_materials_data_id')
            ->join('enquiry_tasks as et', 'et.id', '=', 'tmd.enquiry_task_id')
            ->where('et.project_enquiry_id', $this->enquiry->id)->whereNotNull('em.library_material_id')->where('em.quantity', '>', 0)
            ->orderBy('em.id')->first(['em.id', 'em.library_material_id']);
        $projectId = $this->enquiry->project?->id;
        if (! $requirement || ! $projectId) {
            $this->record('W5-Stores', 'Issue to a real project', 'INFO', 'The selected project has no catalogued material requirement to issue against.');

            return;
        }
        $inventory = (int) DB::table('chart_of_accounts')->where('code', \App\Modules\Finance\Support\ChartAccountMap::local('1200'))->value('id');
        $wip = (int) DB::table('chart_of_accounts')->where('code', \App\Modules\Finance\Support\ChartAccountMap::local('1211'))->value('id');

        $this->step('W5-Stores', 'Receive the required material into stock (Stores)', function () use ($requirement, $inventory) {
            $this->expectOk($this->as($this->superA)->postJson('/api/procurement-stores/movements', [
                'type' => 'receive', 'lines' => [['material_id' => $requirement->library_material_id, 'quantity' => 2, 'receipt_unit_cost' => 750, 'reference_no' => 'REH-RCV']],
            ]), 201);
            $drained = $this->drain();

            return 'inventory asset '.DB::table('chart_of_accounts')->where('id', $inventory)->value('code')." debit balance now {$this->money($this->balanceOn($inventory))}; drain {$drained}";
        });
        $this->step('W5-Stores', 'Issue it to the real project against its approved requirement (queues the project cost)', function () use ($requirement, $projectId, $wip) {
            $before = $this->balanceOn($wip, $this->enquiry->id);
            $this->expectOk($this->as($this->superA)->postJson('/api/procurement-stores/movements', [
                'type' => 'issue', 'lines' => [['material_id' => $requirement->library_material_id, 'quantity' => 1, 'project_id' => $projectId,
                    'project_material_id' => $requirement->id, 'recipient_name' => 'Rehearsal site lead']],
            ]), 201);
            $drained = $this->drain();
            $after = $this->balanceOn($wip, $this->enquiry->id);
            if (bccomp($after, $before, 2) <= 0) {
                throw new RuntimeException("Materials WIP on the job did not increase ({$before} → {$after}); drain {$drained}");
            }

            return 'materials WIP on the job '.$this->money($before).' → '.$this->money($after)."; drain {$drained}";
        });
    }

    // ── WIP release on a second real project (capitalise policy) ─────────

    private function wipReleaseOnARealProject(): void
    {
        $families = ['1211' => '5100', '1212' => '5200', '1213' => '5300', '1214' => '5400', '1215' => '5500',
            '1216' => '5600', '1217' => '5700', '1218' => '5800', '1219' => '5900'];
        $id = fn (string $ref) => (int) DB::table('chart_of_accounts')->where('code', \App\Modules\Finance\Support\ChartAccountMap::local($ref))->value('id');
        $code = fn (int $accountId) => (string) DB::table('chart_of_accounts')->where('id', $accountId)->value('code');

        $subject = ProjectEnquiry::query()
            ->where('id', '<>', $this->enquiry->id)->whereNotIn('status', ['completed', 'closed', 'cancelled'])
            ->whereHas('project', fn ($q) => $q->whereNotIn('status', ['completed', 'closed', 'cancelled']))
            ->whereIn('id', DB::table('quote_approvals')->where('approval_status', 'approved')->where('quote_amount', '>=', 50000)->select('enquiry_id'))
            ->whereNotIn('id', DB::table('project_invoices')->select('project_enquiry_id'))
            ->orderByDesc('id')->first();
        if (! $subject) {
            $this->record('WIP', 'Real project for the release proof', 'FAIL', 'No open real project with an approved quote and no invoices.');

            return;
        }
        $agreed = (string) DB::table('quote_approvals')->where('enquiry_id', $subject->id)->where('approval_status', 'approved')->orderByDesc('updated_at')->value('quote_amount');
        $this->record('WIP', 'Real project for the release proof', 'INFO', "enquiry #{$subject->id} ({$subject->enquiry_number}); policy ".(config('finance_accounts.wip_policy') ?: 'profile default'));

        $this->expectOk($this->as($this->accounts)->postJson('/api/finance/petty-cash/top-ups', [
            'amount' => 100000, 'payment_method' => 'bank_transfer', 'external_reference' => 'REHEARSAL-WIP',
            'date_topped_up' => now()->toDateString(), 'description' => 'Rehearsal float for the WIP proof',
        ]), 201);

        // 1. Actual cost into each family, through a real coded petty-cash payment.
        $charged = [];
        $n = 0;
        foreach ($families as $wipRef => $cosRef) {
            $n++;
            $wip = $id($wipRef);
            $amount = (string) (1000 * $n);
            $ok = $this->step('WIP', "Actual cost → {$code($wip)}", function () use ($wip, $amount, $subject, $families, $id) {
                $codes = DB::table('expense_codes')->where('is_active', true)->where('default_debit_account_id', $wip)
                    ->where('job_id_rule', '<>', 'not_allowed')->orderBy('id')->pluck('id', 'code');
                $allWip = fn () => collect(array_keys($families))->sum(fn ($r) => (float) $this->balanceOn($id($r), $subject->id));
                $totalBefore = $allWip();
                $errors = [];
                foreach ($codes as $expenseCode => $codeId) {
                    $payment = $this->directDisbursement($subject, $codeId, (float) $amount, $error);
                    if (! $payment) {
                        $errors[] = "{$expenseCode}: {$error}";

                        continue;
                    }
                    $drained = $this->drain();
                    $balance = $this->balanceOn($wip, $subject->id);
                    if (bccomp($balance, $amount, 2) !== 0) {
                        throw new RuntimeException("expense code {$expenseCode}: family balance {$balance}, expected {$amount}; drain {$drained}");
                    }
                    if (abs($allWip() - $totalBefore - (float) $amount) > 0.001) {
                        throw new RuntimeException("another family moved (all WIP {$totalBefore} → {$allWip()})");
                    }

                    return "expense code {$expenseCode}, KES {$amount}, only this family moved; drain {$drained}";
                }
                throw new RuntimeException('no active job code for this family could be paid: '.implode(' | ', array_slice($errors, 0, 3)));
            });
            if ($ok) {
                $charged[$wipRef] = $amount;
            }
        }
        if ($charged === []) {
            return;
        }

        // 2. Partial invoice: 40% of the agreed price.
        $first = (string) round((float) $agreed * 0.4, 2);
        $invoice1 = $this->step('WIP', "Invoice 40% of the agreed price (KES {$first} of {$agreed}) — releases pro rata", function () use ($subject, $first, $agreed, $charged, $families, $id) {
            $invoiceId = $this->issueInvoice($subject, $first);
            $fraction = bcdiv($first, $agreed, 6);
            $lines = [];
            foreach ($charged as $wipRef => $amount) {
                $expected = $this->money(bcmul($amount, $fraction, 6));
                $cos = $this->balanceOn($id($families[$wipRef]), $subject->id);
                $wip = $this->balanceOn($id($wipRef), $subject->id);
                if (abs((float) $cos - (float) $expected) > 0.011 || abs((float) bcadd($cos, $wip, 2) - (float) $amount) > 0.001) {
                    throw new RuntimeException("{$wipRef}: COS {$cos} (expected {$expected}), WIP {$wip}, cost {$amount}");
                }
                $lines[] = "{$wipRef}→{$families[$wipRef]} ".$this->money($cos);
            }

            return ['id' => $invoiceId, 'detail' => "fraction {$fraction}; ".implode(', ', $lines)];
        });
        if (! $invoice1) {
            return;
        }
        $this->step('WIP', 'Repeated release of the same invoice is a no-op (idempotent)', function () use ($invoice1, $subject) {
            $before = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')->where('jl.project_enquiry_id', $subject->id)->count();
            $again = app(\App\Modules\Finance\Services\WorkInProgressReleaseService::class)
                ->releaseForInvoice(\App\Modules\Finance\Models\ProjectInvoice::findOrFail($invoice1['id']), $this->accounts->id);
            $after = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')->where('jl.project_enquiry_id', $subject->id)->count();
            if ($again !== null || $after !== $before) {
                throw new RuntimeException('A second release posted again.');
            }

            return 'no journal, no lines';
        });

        // 3. Invoice the rest: full release.
        $rest = bcsub($this->money($agreed), $this->money($first), 2);
        $invoice2 = $this->step('WIP', "Invoice the remaining KES {$rest} — completes the release", function () use ($subject, $rest, $charged, $families, $id) {
            $invoiceId = $this->issueInvoice($subject, $rest);
            foreach ($charged as $wipRef => $amount) {
                $wip = $this->balanceOn($id($wipRef), $subject->id);
                $cos = $this->balanceOn($id($families[$wipRef]), $subject->id);
                if (bccomp($wip, '0', 2) !== 0 || bccomp($cos, $amount, 2) !== 0) {
                    throw new RuntimeException("{$wipRef}: WIP {$wip} (expected 0), COS {$cos} (expected {$amount})");
                }
            }

            return ['id' => $invoiceId, 'detail' => 'every family: WIP 0, COS = full cost'];
        });

        // 4. Void the second invoice: its release is reversed, the first one's stands.
        $invoice2 && $this->step('WIP', 'Void the second invoice — its release reverses; the first stands', function () use ($subject, $invoice2, $charged, $families, $id, $first, $agreed) {
            $this->expectOk($this->as($this->superA)->postJson("/api/projects/enquiries/{$subject->id}/invoices/{$invoice2['id']}/void", ['reason' => 'Rehearsal: void to prove the release reverses']));
            $fraction = bcdiv($first, $agreed, 6);
            foreach ($charged as $wipRef => $amount) {
                $cos = $this->balanceOn($id($families[$wipRef]), $subject->id);
                $expected = $this->money(bcmul($amount, $fraction, 6));
                if (abs((float) $cos - (float) $expected) > 0.011) {
                    throw new RuntimeException("{$wipRef}: COS {$cos} after void, expected {$expected}");
                }
            }

            return 'every family back to the 40% position';
        });
    }

    private function issueInvoice(ProjectEnquiry $subject, string $net): int
    {
        $base = "/api/projects/enquiries/{$subject->id}/invoices";
        $r = $this->expectOk($this->as($this->accounts)->postJson($base, [
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [['description' => 'Rehearsal progress billing', 'quantity' => 1, 'unit_price' => (float) $net]],
        ]), 201);
        $invoiceId = (int) ($r->json('data.id') ?? $r->json('id'));
        $this->expectOk($this->as($this->superB)->postJson("{$base}/{$invoiceId}/check"));
        $this->expectOk($this->as($this->accounts)->postJson("{$base}/{$invoiceId}/issue"));

        return $invoiceId;
    }

    // ── W6 Payroll Finance posting ───────────────────────────────────────

    private function payrollFinancePosting(): void
    {
        $month = now()->format('Y-m');
        [$first, $last] = [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];
        // Pay comes from a salary history valid for the month (PayrollEmployeeDTO); an
        // employee without one earns 0 and their statutory deductions are uncovered, so
        // the run cannot lock. That is WNG's HR master data, reported here, not a defect.
        $validHistory = fn ($q) => $q->from('employee_salary_histories as h')->whereColumn('h.employee_id', 'e.id')
            ->where('h.valid_from', '<=', $last)->where(fn ($w) => $w->whereNull('h.valid_to')->orWhere('h.valid_to', '>=', $first));
        $withHistory = DB::table('employees as e')->where('e.status', 'active')->whereExists($validHistory)->count();
        // Paid = a non-zero salary for the month: a valid history row above zero, or (no history) a base salary.
        $paid = DB::table('employees as e')->where('e.status', 'active')
            ->where(fn ($w) => $w->whereExists(fn ($q) => $validHistory($q)->where('h.salary', '>', 0))
                ->orWhere(fn ($n) => $n->whereNotExists($validHistory)->where('e.salary', '>', 0)))
            ->orderBy('e.id')->pluck('e.id');
        $this->record('W6-Payroll', 'Payroll master data (WNG HR)', 'INFO', sprintf('%d of %d active employees have a salary history for %s; %d have a NON-ZERO salary for the month (source data, identical in the pristine copy). A real run of the rest earns 0, leaves statutory deductions uncovered and cannot lock.',
            $withHistory, DB::table('employees')->where('status', 'active')->count(), $month, $paid->count()));
        $employees = $paid->take(4)->all();
        if ($employees === []) {
            $this->record('W6-Payroll', 'Payroll Finance posting', 'BLOCKED:HR-DATA', 'no active employee has a non-zero salary for the month');

            return;
        }
        $run = $this->step('W6-Payroll', "Create and process a payroll run for {$month} (real salaried employees; amounts not printed)", function () use ($month, $employees) {
            $created = $this->expectOk($this->as($this->superA)->postJson('/api/hr/payroll/runs', ['payroll_month' => $month]));
            $runId = (int) $created->json('data.id');
            $this->expectOk($this->as($this->superA)->postJson("/api/hr/payroll/runs/{$runId}/process", ['employee_ids' => $employees]));

            return ['id' => $runId, 'detail' => "run #{$runId}, ".DB::table('payslips')->where('payroll_run_id', $runId)->count().' payslip(s)'];
        });
        if (! $run) {
            return;
        }
        $legs = fn (?int $entryId) => DB::table('journal_lines as jl')->join('chart_of_accounts as c', 'c.id', '=', 'jl.account_id')
            ->where('jl.journal_entry_id', $entryId)->get(['c.code', 'jl.entry_type'])->map(fn ($l) => "{$l->entry_type[0]}:{$l->code}")->unique()->sort()->implode(' ');
        $balanced = fn (?int $entryId) => DB::table('journal_entries')->where('id', $entryId)->whereColumn('total_debit', 'total_credit')->exists();

        $accrued = $this->step('W6-Payroll', 'Lock by another user posts the payroll accrual journal', function () use ($run, $legs, $balanced) {
            $this->expectOk($this->as($this->superB)->postJson("/api/hr/payroll/runs/{$run['id']}/lock"));
            $entry = DB::table('payroll_runs')->where('id', $run['id'])->value('accrual_journal_entry_id');
            if (! $entry || ! $balanced($entry)) {
                throw new RuntimeException('No balanced accrual journal.');
            }

            return 'balanced; accounts '.$legs($entry).' (no department is labour-classified yet (D6), so all gross pay is office salaries)';
        });
        $accrued && $this->step('W6-Payroll', 'Mark paid (a third user) posts the payment journal from the bank', function () use ($run, $legs, $balanced) {
            $source = DB::table('payment_sources')->where('code', 'BANK-MAIN')->value('id');
            $this->expectOk($this->as($this->superA)->postJson("/api/hr/payroll/runs/{$run['id']}/mark-paid", [
                'payment_source_id' => $source, 'payment_date' => now()->toDateString(), 'payment_reference' => 'REH-PAYROLL',
            ]));
            $entry = DB::table('payroll_runs')->where('id', $run['id'])->value('payment_journal_entry_id');
            if (! $entry || ! $balanced($entry)) {
                throw new RuntimeException('No balanced payment journal.');
            }

            return 'balanced; accounts '.$legs($entry);
        });
    }

    private function directDisbursement(ProjectEnquiry $subject, int $codeId, float $amount, ?string &$error = null): ?int
    {
        $source = DB::table('payment_sources')->where('code', 'PC-MAIN')->value('id');
        $r = $this->as($this->accounts)->postJson('/api/finance/petty-cash/disbursements', [
            'idempotency_key' => (string) Str::uuid(), 'payee_name' => 'Rehearsal Supplier', 'expense_code_id' => $codeId, 'amount' => $amount,
            'description' => 'Rehearsal WIP proof', 'project_enquiry_id' => $subject->id, 'project_id' => $subject->project?->id,
            'direct_payment_reason' => 'Rehearsal of the WIP release', 'date_disbursed' => now()->toDateString(),
            'payment_source_id' => $source, 'payment_method' => 'cash', 'receipt_type' => 'none', 'tax_amount' => 0,
        ]);
        if ($r->status() === 202 || str_contains((string) $r->getContent(), 'direct_disbursement_request')) {
            $requestId = $r->json('data.id') ?? $r->json('data.request_id');
            $r = $this->as($this->superB)->postJson("/api/finance/petty-cash/direct-disbursement-requests/{$requestId}/approve");
            if ($r->status() >= 400) {
                $error = $this->message($r);

                return null;
            }

            return (int) ($r->json('data.disbursement.id') ?? $r->json('data.id'));
        }
        if ($r->status() >= 400) {
            $error = $this->message($r);

            return null;
        }

        return (int) $r->json('data.id');
    }

    /** Debit minus credit on one account (optionally for one job), over every posted or reversed entry. */
    private function balanceOn(int $accountId, ?int $enquiryId = null): string
    {
        $q = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('je.status', ['posted', 'reversed'])->where('jl.account_id', $accountId);
        if ($enquiryId !== null) {
            $q->where('jl.project_enquiry_id', $enquiryId);
        }
        $row = $q->selectRaw("COALESCE(SUM(CASE WHEN jl.entry_type = 'debit' THEN jl.amount ELSE -jl.amount END), 0) AS b")->first();

        return $this->money((string) $row->b);
    }

    private function money(string|float $v): string
    {
        return number_format(round((float) $v, 2), 2, '.', '');
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
            $this->record($w, $name, 'PASS', is_scalar($value) ? (string) $value : (string) ($value['detail'] ?? ''));

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
