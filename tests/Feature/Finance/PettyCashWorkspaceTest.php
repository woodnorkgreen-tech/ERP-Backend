<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsMatrixPermissions;
use Tests\TestCase;

/**
 * Stream D / W3 (Report 61): the petty-cash workspace projections, the Stream A
 * carry-overs D-1..D-3, STAB-4 failure visibility with idempotent retry, and
 * evidence safety. Roles carry exactly their production grants
 * (RolePermissions::matrix()), so these tests prove permission gates, not names.
 */
class PettyCashWorkspaceTest extends TestCase
{
    use GrantsMatrixPermissions;
    use RefreshDatabase;
    use \Tests\Support\VerifiedFinancialRequisitionFixture;

    private User $requester;
    private User $finance;
    private User $outsider;
    private int $departmentId;
    private int $expenseCodeId;
    private int $paymentSourceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\PaymentSourceSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder::class);

        $this->grantMatrixPermissions('Accounts', 'Employee');
        $this->finance = User::factory()->create(['is_active' => true]);
        $this->finance->assignRole('Accounts');
        $this->requester = User::factory()->create(['is_active' => true]);
        $this->requester->assignRole('Employee');
        $this->outsider = User::factory()->create(['is_active' => true]);
        $this->outsider->assignRole('Employee');

        $this->departmentId = DB::table('departments')->insertGetId(['name' => 'Operations', 'created_at' => now(), 'updated_at' => now()]);
        $this->expenseCodeId = (int) DB::table('expense_codes')->where('job_id_rule', '!=', 'not_allowed')->value('id');
        $this->paymentSourceId = (int) DB::table('payment_sources')->where('code', 'PC-MAIN')->value('id');

        PettyCashTopUp::create([
            'amount' => 500000.00, 'payment_method' => 'cash',
            'date_topped_up' => now()->subMonth()->toDateString(), 'created_by' => $this->finance->id,
        ]);
        PettyCashBalance::current()->update(['current_balance' => 500000.00]);
    }

    private function requisition(string $status = 'approved', ?int $enquiryId = null): PettyCashRequisition
    {
        $type = PettyCashRequisitionType::create([
            'code' => 'MAT-'.uniqid(), 'name' => 'Site Materials '.uniqid(),
            'default_expense_code_id' => $this->expenseCodeId, 'is_active' => true,
        ]);

        return $this->verifiedRequisitionFixture(PettyCashRequisition::create([
            'requisition_number' => 'PCR-D-'.uniqid(), 'user_id' => $this->requester->id,
            'department_id' => $this->departmentId, 'category' => 'Site Materials',
            'requisition_type_id' => $type->id, 'purpose' => 'Site spend',
            'total_amount' => 2000.00, 'status' => $status, 'enquiry_id' => $enquiryId,
            'payee_name' => 'Field Worker',
        ]));
    }

    private function enquiry(string $jobNumber): int
    {
        return DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(),
            'client_id' => DB::table('clients')->insertGetId([
                'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000', 'address' => 'Nairobi',
                'city' => 'Nairobi', 'county' => 'Nairobi', 'customer_type' => 'company', 'lead_source' => 'test',
                'preferred_contact' => 'email', 'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ]),
            'title' => 'Activation', 'contact_person' => 'Contact', 'enquiry_number' => 'ENQ-'.uniqid(),
            'job_number' => $jobNumber, 'created_by' => $this->finance->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function disburse(PettyCashRequisition $requisition): void
    {
        $this->actingAs($this->finance)->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/disburse", [
            'idempotency_key' => (string) Str::uuid(), 'expense_code_id' => $this->expenseCodeId,
            'payment_source_id' => $this->paymentSourceId, 'payment_method' => 'cash', 'amount' => 2000.00,
            'payee_name' => 'Field Worker', 'description' => 'Site float',
            'date_disbursed' => now()->toDateString(), 'receipt_type' => 'none',
        ])->assertOk();
    }

    // ── D-1: the float and the register are Finance reads ──────────────────

    public function test_balance_and_register_reads_require_petty_cash_permissions(): void
    {
        $this->actingAs($this->outsider);
        $this->getJson('/api/finance/petty-cash/balance')->assertForbidden();
        $this->getJson('/api/finance/petty-cash/disbursements')->assertForbidden();
        $this->getJson('/api/finance/petty-cash/top-ups')->assertForbidden();
        $this->getJson('/api/finance/petty-cash/finance/overview')->assertForbidden();
        $this->getJson('/api/finance/petty-cash/finance/requisitions')->assertForbidden();
        $this->postJson('/api/finance/petty-cash/balance/recalculate')->assertForbidden();

        $this->actingAs($this->finance);
        $this->getJson('/api/finance/petty-cash/balance')->assertOk();
        $this->getJson('/api/finance/petty-cash/disbursements')->assertOk();
        $this->getJson('/api/finance/petty-cash/top-ups')->assertOk();
        // Recalculation is a Super Admin control; Accounts may read, not rewrite.
        $this->postJson('/api/finance/petty-cash/balance/recalculate')->assertForbidden();
    }

    public function test_overview_hides_the_float_from_a_reader_without_view_balance(): void
    {
        // A reader of the register who is not trusted with the float figure.
        $role = \Spatie\Permission\Models\Role::findOrCreate('Register Reader', 'web');
        foreach (['finance.petty_cash.view', 'finance.petty_cash.view_reports'] as $p) {
            \Spatie\Permission\Models\Permission::findOrCreate($p, 'web');
        }
        $role->givePermissionTo(['finance.petty_cash.view', 'finance.petty_cash.view_reports']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole('Register Reader');

        $this->actingAs($manager)->getJson('/api/finance/petty-cash/finance/overview')
            ->assertOk()->assertJsonPath('data.float', null);
        $this->actingAs($this->finance)->getJson('/api/finance/petty-cash/finance/overview')
            ->assertOk()->assertJsonPath('data.float.balance', '500000.00')
            // No threshold configured: shown as not configured, never a built-in default.
            ->assertJsonPath('data.float.low_threshold', null)
            ->assertJsonPath('data.custody.configured', false);
    }

    public function test_the_reset_route_no_longer_exists(): void
    {
        $this->actingAs($this->finance)->deleteJson('/api/finance/petty-cash/clear-all')->assertStatus(404);
    }

    public function test_a_voucher_cannot_be_downloaded_for_someone_elses_requisition(): void
    {
        $requisition = $this->requisition('pending');

        $this->actingAs($this->outsider)
            ->get("/api/finance/petty-cash/requisitions/{$requisition->id}/voucher")
            ->assertForbidden();
    }

    // ── D-3 and the action projection ──────────────────────────────────────

    public function test_received_is_funds_received_awaiting_surrender_not_complete(): void
    {
        $requisition = $this->requisition('received');

        $row = $this->actingAs($this->finance)->getJson("/api/finance/petty-cash/finance/requisitions/{$requisition->id}")
            ->assertOk()->json('data');

        $this->assertSame('funds_received', $row['state']);
        $this->assertSame('overhead', $row['classification']);
        $this->assertTrue($row['actions']['reconcile_surrender']['allowed']);
        $this->assertFalse($row['actions']['confirm_receipt']['allowed']);

        $this->actingAs($this->finance)->getJson('/api/finance/petty-cash/finance/requisitions?state=outstanding')
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_the_requester_cannot_approve_disburse_or_review_their_own_requisition(): void
    {
        // The requester here holds Finance authority too — separation of duties still applies.
        $this->requester->assignRole('Accounts');
        $pending = $this->requisition('pending');
        $surrendered = $this->requisition('surrender_pending');

        $this->actingAs($this->requester);
        $actions = $this->getJson("/api/finance/petty-cash/finance/requisitions/{$pending->id}")->json('data.actions');
        $this->assertFalse($actions['approve']['allowed']);
        $this->assertStringContainsString('someone else', $actions['approve']['reason']);

        $actions = $this->getJson("/api/finance/petty-cash/finance/requisitions/{$surrendered->id}")->json('data.actions');
        $this->assertFalse($actions['return_surrender']['allowed']);
        $this->assertSame('You cannot review your own surrender.', $actions['return_surrender']['reason']);

        // The projection matches the enforced rule.
        $this->postJson("/api/finance/petty-cash/requisitions/{$pending->id}/approve")->assertForbidden();
    }

    public function test_a_requester_sees_only_their_own_requisition_detail(): void
    {
        $requisition = $this->requisition('pending');

        $this->actingAs($this->requester)->getJson("/api/finance/petty-cash/finance/requisitions/{$requisition->id}")->assertOk()
            ->assertJsonPath('data.actions.approve.allowed', false);
        $this->actingAs($this->outsider)->getJson("/api/finance/petty-cash/finance/requisitions/{$requisition->id}")->assertForbidden();
    }

    // ── Accounting: payment is not expense ─────────────────────────────────

    public function test_a_disbursement_is_a_staff_advance_not_a_project_cost(): void
    {
        $enquiryId = $this->enquiry('WNG-D-1');
        $requisition = $this->requisition('approved', $enquiryId);

        $this->disburse($requisition);

        $this->assertSame(0, CostLine::query()->where('nature', 'actual')->count(), 'Paying cash out must not recognise cost.');
        $staffAdvance = (int) ChartOfAccount::where('code', '1300')->value('id');
        $this->assertSame('2000.00', (string) DB::table('journal_lines')->where('account_id', $staffAdvance)->where('entry_type', 'debit')->sum('amount'));

        $this->actingAs($this->finance)->getJson("/api/finance/petty-cash/finance/requisitions/{$requisition->id}")->assertOk()
            ->assertJsonPath('data.classification', 'project')
            ->assertJsonPath('data.project.job_number', 'WNG-D-1')
            ->assertJsonPath('data.disbursement.amount', '2000.00');
    }

    // ── STAB-4: a failed journal is visible and the retry is idempotent ────

    public function test_a_failed_advance_posting_is_visible_and_the_retry_is_idempotent(): void
    {
        $requisition = $this->requisition('approved', $this->enquiry('WNG-D-2'));
        // Make the Staff Advance account unpostable so the journal fails after the cash has left.
        ChartOfAccount::where('code', '1300')->update(['is_active' => false]);

        $this->disburse($requisition);
        $requisition->refresh();
        $this->assertSame('disbursed', $requisition->status, 'STAB-4: the disbursement stays recorded.');
        $this->assertNotNull($requisition->advance_gl_posting_failed_at);

        $this->actingAs($this->finance);
        $overview = $this->getJson('/api/finance/petty-cash/finance/overview')->assertOk()->json('data.gl_posting_failures.advances');
        $this->assertCount(1, $overview);
        $this->assertSame($requisition->id, $overview[0]['requisition_id']);
        $this->assertStringNotContainsString('SQLSTATE', (string) $overview[0]['error']);

        $row = $this->getJson("/api/finance/petty-cash/finance/requisitions/{$requisition->id}")->json('data');
        $this->assertSame('gl_posting_failed', $row['state']);
        $this->assertSame('retry_posting', $row['next_action']);
        $this->assertStringContainsString('petty_cash_posting_failed', $this->getJson('/api/finance/work-queue')->assertOk()->getContent());

        // Retry while the cause remains: still failed, nothing posted.
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/retry-advance-posting")->assertStatus(422);

        ChartOfAccount::where('code', '1300')->update(['is_active' => true]);
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/retry-advance-posting")->assertOk();
        $entries = JournalEntry::count();
        $this->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/retry-advance-posting")->assertOk();
        $this->assertSame($entries, JournalEntry::count(), 'A second retry must not post again.');

        $this->assertNull($requisition->fresh()->advance_gl_posting_failed_at);
        $this->assertSame([], $this->getJson('/api/finance/petty-cash/finance/overview')->json('data.gl_posting_failures.advances'));
        $this->assertStringNotContainsString('petty_cash_posting_failed', $this->getJson('/api/finance/work-queue')->getContent());
    }

    public function test_only_a_reviewer_may_retry_a_posting(): void
    {
        $requisition = $this->requisition('disbursed');
        $requisition->forceFill(['advance_gl_posting_failed_at' => now(), 'advance_gl_posting_error' => 'Period closed'])->save();

        $this->actingAs($this->requester)->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/retry-advance-posting")
            ->assertForbidden();
        $this->actingAs($this->requester)->getJson("/api/finance/petty-cash/finance/requisitions/{$requisition->id}")
            ->assertJsonPath('data.actions.retry_posting.allowed', false);
    }

    // ── Evidence ───────────────────────────────────────────────────────────

    public function test_evidence_is_attached_listed_and_downloaded_without_exposing_storage_paths(): void
    {
        Storage::fake('local');
        $requisition = $this->requisition('received');

        $this->actingAs($this->requester)->post("/api/finance/petty-cash/requisitions/{$requisition->id}/attachments", [
            'evidence_type' => 'receipt', 'file' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.downloadable', true);

        $list = $this->actingAs($this->finance)->getJson("/api/finance/petty-cash/requisitions/{$requisition->id}/attachments")->assertOk();
        $this->assertStringNotContainsString('file_path', $list->getContent());
        $id = $list->json('data.0.id');

        $this->actingAs($this->finance)->get("/api/finance/petty-cash/requisitions/{$requisition->id}/attachments/{$id}/download")->assertOk();

        // Someone who may not see the requisition may neither add nor read its evidence.
        $this->actingAs($this->outsider)->getJson("/api/finance/petty-cash/requisitions/{$requisition->id}/attachments")->assertForbidden();
        $this->actingAs($this->outsider)->get("/api/finance/petty-cash/requisitions/{$requisition->id}/attachments/{$id}/download")->assertForbidden();
        $this->actingAs($this->outsider)->postJson("/api/finance/petty-cash/requisitions/{$requisition->id}/attachments", [
            'evidence_type' => 'other', 'reference' => 'ETR 123',
        ])->assertForbidden();

        // No evidence threshold is configured: the policy says so rather than inventing one.
        $this->actingAs($this->finance)->getJson("/api/finance/petty-cash/finance/requisitions/{$requisition->id}")
            ->assertJsonPath('data.evidence_policy.threshold', null);
    }
}
