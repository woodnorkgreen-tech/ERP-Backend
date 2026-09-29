<?php

namespace Tests\Feature\Finance;

use App\Constants\EnquiryConstants;
use App\Constants\Permissions;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Report 65 — GET api/finance/overview. Each section is permission-gated on
 * its own, reads an existing authority, and writes nothing.
 */
class FinanceOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceReferenceSeeder::class);
        foreach ([Permissions::FINANCE_REPORTS_VIEW, Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_PAYABLES_READ,
            Permissions::FINANCE_COSTS_PORTFOLIO, Permissions::FINANCE_SPEND_VOUCHERS_READ, Permissions::FINANCE_PETTY_CASH_VIEW,
            Permissions::FINANCE_PETTY_CASH_VIEW_BALANCE] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->finance = User::factory()->create(['is_active' => true]);
        $this->finance->givePermissionTo([Permissions::FINANCE_REPORTS_VIEW, Permissions::FINANCE_RECEIVABLES_READ,
            Permissions::FINANCE_PAYABLES_READ, Permissions::FINANCE_COSTS_PORTFOLIO]);
    }

    private function section(string $section, ?User $as = null, array $query = [])
    {
        return $this->actingAs($as ?? $this->finance, 'sanctum')->getJson('/api/finance/overview?'.http_build_query(['section' => $section] + $query));
    }

    private function cost(string $ref, string $amount, string $code, ?ProjectEnquiry $project = null): CostLine
    {
        $line = CostLine::create([
            'ref' => $ref, 'nature' => CostLine::NATURE_ACTUAL, 'status' => CostLine::STATUS_VERIFIED,
            'amount' => $amount, 'tax_amount' => '0.00', 'net_amount' => $amount, 'base_net_amount' => $amount, 'fx_rate' => '1.00',
            'accounting_period_id' => AccountingPeriod::forDate(now())->id, 'submitted_by_user_id' => $this->finance->id,
            'expense_code_id' => ExpenseCode::where('code', $code)->value('id'),
            'job_number' => $project?->job_number, 'project_enquiry_id' => $project?->id,
        ]);
        app(JournalPostingService::class)->postCostLine($line);

        return $line->fresh();
    }

    private function project(string $name, string $closure = 'open'): ProjectEnquiry
    {
        return ProjectEnquiry::create([
            'date_received' => '2026-09-01', 'expected_delivery_date' => '2026-09-30',
            'client_id' => Client::factory()->create(['company_name' => $name, 'full_name' => $name])->id,
            'title' => "Stand for {$name}", 'description' => 'Report 65 test', 'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED, 'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-65-'.uniqid(), 'job_number' => 'JOB-65-'.uniqid(), 'created_by' => $this->finance->id,
            'selected_workflow_tasks' => ['design'], 'workflow_preset_type' => 'external_project',
            'financial_closure_status' => $closure,
        ]);
    }

    public function test_each_section_is_gated_on_its_own_permission(): void
    {
        $nobody = User::factory()->create(['is_active' => true]);
        $nobody->assignRole(Role::findOrCreate('Accounts', 'web'));
        $this->section('controls', $nobody)->assertForbidden();

        $voucherReader = User::factory()->create(['is_active' => true]);
        $voucherReader->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_READ);
        $controls = $this->section('controls', $voucherReader)->assertOk()->json('data');
        $this->assertNull($controls['ledger'], 'Ledger totals leaked to someone without reports.view.');
        foreach (['cash', 'receivables', 'payables', 'projects'] as $section) {
            $this->section($section, $voucherReader)->assertForbidden();
        }
        $this->section('everything')->assertStatus(422);
    }

    public function test_controls_report_period_currency_policy_and_ledger_equality_from_the_trial_balance(): void
    {
        $this->cost('CL-OV-1', '1200.00', 'OE-OFF-001');
        $controls = $this->section('controls')->assertOk()->json('data');
        $period = AccountingPeriod::forDate(now());

        $this->assertSame($period->status, $controls['period']['status']);
        $this->assertSame($period->starts_on->toDateString(), $controls['period']['starts_on']);
        $this->assertSame('KES', $controls['currency']);
        $this->assertSame(config('finance_accounts.wip_policy') ?: null, $controls['wip_policy']);

        $trial = $this->actingAs($this->finance, 'sanctum')->getJson('/api/finance/journals/trial-balance')->assertOk()->json('data.totals');
        $this->assertSame($trial['debit'], $controls['ledger']['debit']);
        $this->assertSame($trial['credit'], $controls['ledger']['credit']);
        $this->assertSame($trial['is_balanced'], $controls['ledger']['balanced']);
        $this->assertSame('0.00', $controls['ledger']['difference']);
    }

    public function test_cash_is_the_ledger_balance_of_each_linked_account_counted_once(): void
    {
        PaymentSource::query()->update(['is_active' => false]);
        $bank = ChartOfAccount::where('code', '1010')->value('id');
        PaymentSource::create(['name' => 'Bank A', 'code' => 'OV-A', 'type' => 'bank', 'gl_account_id' => $bank, 'is_active' => true]);
        PaymentSource::create(['name' => 'Bank B', 'code' => 'OV-B', 'type' => 'bank', 'gl_account_id' => $bank, 'is_active' => true]);
        PaymentSource::create(['name' => 'M-Pesa', 'code' => 'OV-M', 'type' => 'mobile_money', 'gl_account_id' => null, 'is_active' => true]);
        $entry = DB::table('journal_entries')->insertGetId(['entry_no' => 'JE-OV-1', 'posting_date' => now()->toDateString(),
            'description' => 'Test', 'total_debit' => '700.00', 'total_credit' => '700.00', 'status' => 'posted', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$bank, 'debit'], [ChartOfAccount::where('code', '2100')->value('id'), 'credit']] as [$account, $side]) {
            DB::table('journal_lines')->insert(['journal_entry_id' => $entry, 'account_id' => $account, 'entry_type' => $side, 'amount' => '700.00',
                'base_amount' => '700.00', 'currency' => 'KES', 'fx_rate' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $balances = DB::table('petty_cash_balances')->count();

        $cash = $this->section('cash')->assertOk()->json('data');

        $this->assertSame('ledger', $cash['basis']);
        $this->assertSame('700.00', $cash['total'], 'Two sources on one account were counted twice.');
        $this->assertCount(1, $cash['accounts']);
        $this->assertSame(['Bank A', 'Bank B'], $cash['accounts'][0]['sources']);
        $this->assertSame('M-Pesa', $cash['unlinked_sources'][0]['name']);
        $this->assertNull($cash['petty_cash_float'], 'The float leaked to someone without view_balance.');
        $this->assertSame($balances, DB::table('petty_cash_balances')->count(), 'Reading the Overview wrote a row.');
    }

    public function test_receivables_and_payables_equal_their_own_workspaces(): void
    {
        $receivables = $this->section('receivables')->assertOk()->json('data');
        $receipts = $this->actingAs($this->finance, 'sanctum')->getJson('/api/finance/receipts')->assertOk()->json('summary');
        $this->assertSame($receipts, $receivables['receipts']);
        $ageing = $this->actingAs($this->finance, 'sanctum')->getJson('/api/finance/reports/receivables-ageing')->json('data');
        if ($ageing !== null) {
            $this->assertSame((int) $ageing['totals']['count'], $receivables['outstanding']['count']);
        }

        $payables = $this->section('payables')->assertOk()->json('data');
        $this->assertSame($this->actingAs($this->finance, 'sanctum')->getJson('/api/finance/payables/position')->json('data.summary'), $payables);
    }

    public function test_projects_exclude_closed_work_and_never_claim_a_fully_loaded_margin(): void
    {
        $open = $this->project('Open Co');
        $closed = $this->project('Closed Co', 'closed');
        $this->cost('CL-OV-P1', '9000.00', 'DM-WD-001', $open);
        $this->cost('CL-OV-P2', '4000.00', 'DM-WD-001', $closed);

        $projects = $this->section('projects')->assertOk()->json('data');

        $this->assertSame(1, $projects['active_projects']);
        $this->assertSame('9000.00', $projects['direct_cost']);
        $this->assertSame('direct', $projects['margin_type']);
        $this->assertSame('provisional', $projects['margin_status']);
        $this->assertSame($open->id, $projects['snapshot']['project']['id']);
        $this->assertFalse($projects['snapshot']['position']['project_margin']['fully_loaded_available']);
        $this->assertSame('not_included', $projects['snapshot']['cost_completeness']['overhead']);
        $this->assertGreaterThanOrEqual(1, $projects['incomplete_costing']);

        // An explicitly chosen project is the one shown.
        $this->assertSame($closed->id, $this->section('projects', null, ['project' => $closed->id])->json('data.snapshot.project.id'));
    }
}
