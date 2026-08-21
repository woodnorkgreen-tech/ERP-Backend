<?php

namespace Tests\Feature\PettyCash;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The reporting endpoints that PettyCashReportService had been waiting for.
 *
 * The service shipped with seven generators and no caller; the client shipped a
 * ReportsPanel calling `/analytics` and `/reports/projects`, neither routed. The
 * two things these assert are the ones that make the panel trustworthy rather
 * than merely present: the figures must exclude what the rest of the module
 * excludes, and the project total must equal the analytics total for the same
 * period — they sit on one screen.
 */
class PettyCashReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;
    private User $outsider;
    private int $topUpId;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate(Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS, 'web');

        $this->reader = User::factory()->create(['is_active' => true]);
        $this->reader->givePermissionTo(Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS);

        $this->outsider = User::factory()->create(['is_active' => true]);

        // Disbursements are drawn against a float top-up; the FK is required.
        $this->topUpId = PettyCashTopUp::create([
            'amount' => 500000.00,
            'payment_method' => 'cash',
            'date_topped_up' => now()->subMonths(7)->toDateString(),
            'created_by' => $this->reader->id,
        ])->id;
    }

    private function disbursement(array $overrides = []): PettyCashDisbursement
    {
        return PettyCashDisbursement::create(array_merge([
            'top_up_id' => $this->topUpId,
            'amount' => 1000.00,
            'receiver' => 'Bolt',
            'account' => 'Cost of Sales:Transport & Delivery',
            'description' => 'Site transport',
            'classification' => 'operations',
            'payment_method' => 'cash',
            'status' => 'active',
            'is_archived' => false,
            'date_disbursed' => now()->toDateString(),
            'created_by' => $this->reader->id,
        ], $overrides));
    }

    public function test_analytics_requires_the_reports_permission(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->getJson('/api/finance/petty-cash/analytics')
            ->assertForbidden();

        $this->actingAs($this->outsider, 'sanctum')
            ->getJson('/api/finance/petty-cash/reports/projects')
            ->assertForbidden();
    }

    public function test_analytics_returns_the_shape_the_client_declares(): void
    {
        $this->disbursement(['classification' => 'operations', 'amount' => 1000]);
        $this->disbursement(['classification' => 'admin', 'amount' => 3000, 'payment_method' => 'mpesa']);

        $response = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/petty-cash/analytics')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'by_classification' => [['classification', 'total_amount', 'transaction_count', 'percentage']],
                    'by_payment_method' => [['payment_method', 'total_amount', 'transaction_count', 'percentage']],
                ],
            ]);

        // SpendingAnalytics is `by_*`; the service speaks `spending_by_*`. If the
        // mapping is ever dropped the panel renders blank rather than erroring,
        // so the key names are worth pinning.
        $data = $response->json('data');
        $this->assertArrayNotHasKey('spending_by_classification', $data);

        $shares = collect($data['by_classification'])->pluck('percentage', 'classification');
        $this->assertEquals(25.0, $shares['operations']);
        $this->assertEquals(75.0, $shares['admin']);
    }

    public function test_voided_and_archived_spend_is_excluded_from_both_reports(): void
    {
        $this->disbursement(['amount' => 1000, 'project_name' => 'Expo Stand']);
        $this->disbursement(['amount' => 5000, 'project_name' => 'Expo Stand', 'status' => 'voided']);
        $this->disbursement(['amount' => 7000, 'project_name' => 'Expo Stand', 'is_archived' => true]);

        $analytics = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/petty-cash/analytics')->assertOk();
        $projects = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/petty-cash/reports/projects')->assertOk();

        $this->assertEquals(1000.0, collect($analytics->json('data.by_classification'))->sum('total_amount'));
        $this->assertEquals(1000.0, $projects->json('data.summary.total_amount'));
    }

    public function test_the_project_total_agrees_with_the_analytics_total(): void
    {
        // The raw query this replaced summed `amount` while the summary summed
        // `amount + transaction_cost`, and filtered on created_at rather than
        // date_disbursed — so the same screen showed two different totals.
        $this->disbursement(['amount' => 1000, 'transaction_cost' => 50, 'project_name' => 'Expo Stand']);
        $this->disbursement(['amount' => 2000, 'transaction_cost' => 30, 'project_name' => 'Roadshow']);

        $analytics = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/petty-cash/analytics')->assertOk();
        $projects = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/petty-cash/reports/projects')->assertOk();

        $this->assertEquals(3080.0, collect($analytics->json('data.by_classification'))->sum('total_amount'));
        $this->assertEquals(3080.0, $projects->json('data.summary.total_amount'));
    }

    public function test_both_reports_cover_the_same_period_when_none_is_given(): void
    {
        // Left to their own defaults the two services disagree: the summary falls
        // back to the current month, the project report to all time. Unfiltered,
        // last quarter's spend must be absent from both or present in both.
        $this->disbursement(['amount' => 1000, 'project_name' => 'Expo Stand']);
        $this->disbursement([
            'amount' => 9999,
            'project_name' => 'Last Quarter',
            'date_disbursed' => now()->subMonths(4)->toDateString(),
        ]);

        $analytics = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/petty-cash/analytics')->assertOk();
        $projects = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/petty-cash/reports/projects')->assertOk();

        $this->assertEquals(1000.0, collect($analytics->json('data.by_classification'))->sum('total_amount'));
        $this->assertEquals(1000.0, $projects->json('data.summary.total_amount'));
        $this->assertNotContains('Last Quarter', collect($projects->json('data.projects'))->pluck('project_name'));
    }

    public function test_an_explicit_range_overrides_the_default_month(): void
    {
        $this->disbursement([
            'amount' => 9999,
            'project_name' => 'Last Quarter',
            'date_disbursed' => now()->subMonths(4)->toDateString(),
        ]);

        $projects = $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/petty-cash/reports/projects?' . http_build_query([
                'start_date' => now()->subMonths(6)->toDateString(),
                'end_date' => now()->toDateString(),
            ]))
            ->assertOk();

        $this->assertEquals(9999.0, $projects->json('data.summary.total_amount'));
    }

    public function test_the_export_is_a_real_spreadsheet_from_the_server(): void
    {
        $this->disbursement(['amount' => 1000, 'project_name' => 'Expo Stand']);

        $response = $this->actingAs($this->reader, 'sanctum')
            ->get('/api/finance/petty-cash/export?type=disbursements');

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $response->headers->get('content-type'),
        );
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));
    }

    public function test_the_export_rejects_an_unknown_type_and_requires_permission(): void
    {
        $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/petty-cash/export?type=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);

        $this->actingAs($this->outsider, 'sanctum')
            ->getJson('/api/finance/petty-cash/export?type=disbursements')
            ->assertForbidden();
    }

    public function test_an_inverted_range_is_refused(): void
    {
        $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/finance/petty-cash/analytics?' . http_build_query([
                'start_date' => now()->toDateString(),
                'end_date' => now()->subMonth()->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }
}
