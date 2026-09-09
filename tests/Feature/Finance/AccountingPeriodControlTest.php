<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Stage 0 of the general ledger plan: Finance can operate a month.
 *
 * Closing a month is what stops a transaction appearing in a month already
 * reported on. The checklist has existed since August inside a console command,
 * so in practice no month had ever been closed and the posting guard had never
 * fired. These tests cover the checks themselves and the authority to act on
 * them, which are deliberately separate.
 */
class AccountingPeriodControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 8)->startOfDay());
        $this->seed(FinanceReferenceSeeder::class);
    }

    private function manager(): User
    {
        Permission::findOrCreate(Permissions::FINANCE_PERIODS_MANAGE, 'web');
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::FINANCE_PERIODS_MANAGE);

        return $user;
    }

    private function period(): AccountingPeriod
    {
        return AccountingPeriod::forDate(now());
    }

    public function test_a_clean_month_reports_that_it_can_be_closed(): void
    {
        $response = $this->actingAs($this->manager(), 'sanctum')
            ->getJson("/api/finance/accounting-periods/{$this->period()->id}/checklist")
            ->assertOk();

        $this->assertTrue($response->json('data.can_close'));
        $this->assertSame([], $response->json('data.blockers'));
        $this->assertNotEmpty($response->json('data.checks'));
    }

    public function test_a_cost_still_awaiting_a_decision_blocks_the_close(): void
    {
        CostLine::create([
            'ref' => 'CL-PENDING-1',
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_SUBMITTED,
            'amount' => '5000.00',
            'net_amount' => '5000.00',
            'base_net_amount' => '5000.00',
            'tax_amount' => '0.00',
            'fx_rate' => '1',
            'incurred_at' => '2026-09-03',
        ]);

        $period = $this->period();

        $response = $this->actingAs($this->manager(), 'sanctum')
            ->postJson("/api/finance/accounting-periods/{$period->id}/close")
            ->assertStatus(422);

        $this->assertContains('pending_costs', $response->json('data.blockers'));
        $this->assertSame(
            AccountingPeriod::STATUS_OPEN,
            $period->fresh()->status,
            'A refused close must leave the month exactly as it was.',
        );
    }

    public function test_a_blocked_month_can_still_be_closed_deliberately_and_says_so(): void
    {
        CostLine::create([
            'ref' => 'CL-PENDING-2',
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_QUERIED,
            'amount' => '5000.00',
            'net_amount' => '5000.00',
            'base_net_amount' => '5000.00',
            'tax_amount' => '0.00',
            'fx_rate' => '1',
            'incurred_at' => '2026-09-03',
        ]);

        $period = $this->period();

        $response = $this->actingAs($this->manager(), 'sanctum')
            ->postJson("/api/finance/accounting-periods/{$period->id}/close", ['force' => true])
            ->assertOk();

        $this->assertStringContainsString('outstanding', $response->json('message'));
        $this->assertSame(AccountingPeriod::STATUS_CLOSED, $period->fresh()->status);
    }

    public function test_closing_a_month_stops_anything_more_being_posted_into_it(): void
    {
        $period = $this->period();

        $this->actingAs($this->manager(), 'sanctum')
            ->postJson("/api/finance/accounting-periods/{$period->id}/close")
            ->assertOk();

        $this->assertSame(AccountingPeriod::STATUS_CLOSED, $period->fresh()->status);

        $cost = CostLine::create([
            'ref' => 'CL-AFTER-CLOSE',
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => '1000.00',
            'net_amount' => '1000.00',
            'base_net_amount' => '1000.00',
            'tax_amount' => '0.00',
            'fx_rate' => '1',
            'incurred_at' => '2026-09-04',
            'accounting_period_id' => $period->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(JournalPostingService::class)->postCostLine($cost);
    }

    public function test_a_closed_month_can_be_reopened_only_with_a_recorded_reason(): void
    {
        $period = $this->period();
        $manager = $this->manager();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/finance/accounting-periods/{$period->id}/close")
            ->assertOk();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/finance/accounting-periods/{$period->id}/reopen", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(AccountingPeriod::STATUS_CLOSED, $period->fresh()->status);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/finance/accounting-periods/{$period->id}/reopen", [
                'reason' => 'Supplier invoice arrived after the close meeting',
            ])->assertOk();

        $reopened = $period->fresh();
        $this->assertSame(AccountingPeriod::STATUS_OPEN, $reopened->status);
        $this->assertSame('Supplier invoice arrived after the close meeting', $reopened->reopen_reason);
        $this->assertSame($manager->id, $reopened->reopened_by);
    }

    public function test_locking_freezes_a_month_without_declaring_it_final(): void
    {
        $period = $this->period();

        $this->actingAs($this->manager(), 'sanctum')
            ->postJson("/api/finance/accounting-periods/{$period->id}/lock")
            ->assertOk();

        $this->assertSame(AccountingPeriod::STATUS_LOCKED, $period->fresh()->status);
        $this->assertFalse($period->fresh()->isOpen());
    }

    public function test_a_month_cannot_be_closed_twice(): void
    {
        $period = $this->period();
        $manager = $this->manager();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/finance/accounting-periods/{$period->id}/close")
            ->assertOk();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/finance/accounting-periods/{$period->id}/close")
            ->assertStatus(422);
    }

    public function test_reading_periods_is_a_different_authority_from_closing_one(): void
    {
        Permission::findOrCreate(Permissions::FINANCE_REPORTS_VIEW, 'web');
        $reader = User::factory()->create(['is_active' => true]);
        $reader->givePermissionTo(Permissions::FINANCE_REPORTS_VIEW);

        $period = $this->period();

        // A reader may see which months are final...
        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/finance/accounting-periods')
            ->assertOk()
            ->assertJsonPath('data.0.status', AccountingPeriod::STATUS_OPEN);

        // ...but may not make one final.
        $this->actingAs($reader, 'sanctum')
            ->postJson("/api/finance/accounting-periods/{$period->id}/close")
            ->assertForbidden();

        $this->assertSame(AccountingPeriod::STATUS_OPEN, $period->fresh()->status);
    }

    public function test_the_listing_marks_the_month_we_are_currently_in(): void
    {
        $response = $this->actingAs($this->manager(), 'sanctum')
            ->getJson('/api/finance/accounting-periods?year=2026')
            ->assertOk();

        $current = collect($response->json('data'))->firstWhere('is_current', true);

        $this->assertNotNull($current);
        $this->assertSame(9, $current['month']);
        $this->assertSame(2026, $current['year']);
    }
}
