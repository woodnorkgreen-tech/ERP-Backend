<?php
namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Services\FinanceControlCentreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceControlCentreTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_passes_do_not_close_policy_or_data_gates(): void
    {
        $checks = collect(['required_accounts','chart_profile','expense_codes','expense_code_mapping','cost_centres','activities','vat_treatments','wht_categories'])->map(fn ($key) => ['key' => $key, 'ready' => true])->all();
        $report = app(FinanceControlCentreService::class)->report($checks, []);
        $domains = collect($report['domains'])->keyBy('key');
        $this->assertSame('POLICY_REQUIRED', $domains['wip']['state']);
        $this->assertSame('DATA_REQUIRED', $domains['history']['state']);
        $this->assertSame('NOT_SUPPORTED', $domains['cash-flow']['state']);
        $this->assertSame('BLOCKED', $domains['period']['state']);
        $this->assertSame(11, count($report['policies']));
        $this->assertSame('POLICY_REQUIRED', $domains['petty-cash']['state']);
    }

    public function test_a_channel_with_a_liability_account_is_not_ready(): void
    {
        $account = ChartOfAccount::create(['code' => 'TEST-LIAB', 'name' => 'Test liability', 'category' => 'liability', 'is_postable' => true, 'is_active' => true]);
        PaymentSource::create(['code' => 'MPESA', 'name' => 'Mobile money', 'type' => 'mobile_money', 'gl_account_id' => $account->id, 'is_active' => true]);
        $report = app(FinanceControlCentreService::class)->report([], []);
        $channels = collect($report['channels'])->keyBy('code');
        $this->assertSame('CONFIGURATION_REQUIRED', $channels['MPESA']['state']);
        $this->assertSame('CONFIGURATION_REQUIRED', $channels['CARD']['state']);
        $this->assertNull($channels['CARD']['account']);
    }

    public function test_closed_period_is_not_reference_ready(): void
    {
        \App\Modules\Finance\CostCollector\Models\AccountingPeriod::create([
            'year' => now()->year, 'month' => now()->month, 'starts_on' => now()->startOfMonth(),
            'ends_on' => now()->endOfMonth(), 'status' => 'closed',
        ]);
        $checks = collect(app(\App\Modules\Finance\Support\FinanceReadiness::class)->checks())->keyBy('check');
        $this->assertFalse($checks['Accounting period']['ok']);
    }

    public function test_unlinked_active_source_is_not_a_ready_paying_account(): void
    {
        PaymentSource::create(['code' => 'UNLINKED', 'name' => 'Unconfigured bank', 'type' => 'bank', 'is_active' => true, 'can_make_payment' => true]);
        $checks = collect(app(\App\Modules\Finance\Support\FinanceReadiness::class)->checks())->keyBy('check');
        $this->assertFalse($checks['Paying accounts']['ok']);
    }

    public function test_readiness_still_requires_reporting_permission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/finance/readiness')->assertForbidden();
    }
    public function test_policy_projection_uses_recorded_setting_approval_and_governance_evidence_without_closing_open_decisions(): void
    {
        $approver = User::factory()->create(['name' => 'Finance approver']);
        $setting = \App\Modules\Finance\Models\FinanceSetting::create([
            'key' => 'petty_cash_max_per_transaction', 'label' => 'Petty cash cap', 'value' => '1000',
            'approved_by' => $approver->id, 'approved_at' => now(), 'effective_from' => now()->subDay()->toDateString(),
        ]);
        $report = app(FinanceControlCentreService::class)->report([], []);
        $recorded = collect($report['settings'])->firstWhere('key', $setting->key);
        $this->assertSame($approver->name, $recorded['approved_by']['name']);
        $this->assertSame('finance_settings#'.$setting->id, $recorded['reference']);
        $this->assertSame(['W3', 'W5'], $recorded['workflows']);
        $decision = collect($report['policies'])->firstWhere('key', 'W1-10');
        $this->assertSame('POLICY_REQUIRED', $decision['state']);
        $this->assertStringContainsString('AWAITING', $decision['decision_status']);
        $this->assertNull($decision['approved_by']);
        $this->assertSame('Finance/Accounts Lead', $decision['authority']);
        $this->assertDatabaseHas('finance_settings', ['id' => $setting->id, 'approved_by' => $approver->id]);
    }

}
