<?php

namespace Tests\Feature\CostCollector;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostVerificationService;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Notifications\Models\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The verification design rests on "query, not reject" — a query keeps the cost
 * alive and routes it back to whoever can answer. Without notification it routed
 * it back to nobody: the reporter had to remember to go looking, and the queue
 * had the same problem in reverse.
 */
class CostNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $reporter;
    private User $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        // Verifying posts a journal, and posting needs somewhere to post to.
        // Without the chart, both legs resolve to null and every verify in this
        // class dies on the posting side before reaching what it came to assert.
        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);

        Permission::findOrCreate(Permissions::FINANCE_COSTS_VERIFY, 'web');

        $this->reporter = User::factory()->create(['is_active' => true]);
        $this->verifier = User::factory()->create(['is_active' => true]);
        $this->verifier->givePermissionTo(Permissions::FINANCE_COSTS_VERIFY);
    }

    private function line(array $overrides = []): CostLine
    {
        return CostLine::create(array_merge([
            'ref' => 'CL-' . str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT),
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_SUBMITTED,
            'job_number' => 'WNG-NOTIF-001',
            'amount' => '4500.00', 'tax_amount' => '0.00',
            'net_amount' => '4500.00', 'base_net_amount' => '4500.00',
            'fx_rate' => '1',
            'submitted_by_user_id' => $this->reporter->id,
            'submitted_by_name' => 'Rita',
        ], $overrides));
    }

    private function service(): CostVerificationService
    {
        return app(CostVerificationService::class);
    }

    public function test_a_query_reaches_the_person_who_reported_it(): void
    {
        $line = $this->line();

        $this->service()->query($line, $this->verifier, 'Receipt is unreadable.');

        $notification = AppNotification::where('user_id', $this->reporter->id)
            ->where('type', 'cost_queried')
            ->firstOrFail();

        $this->assertStringContainsString('Receipt is unreadable.', $notification->message);
        $this->assertSame($line->id, $notification->data['cost_line_id']);
        $this->assertSame('WNG-NOTIF-001', $notification->data['job_number']);
    }

    public function test_verifying_and_rejecting_both_reach_the_reporter(): void
    {
        $this->service()->verify($this->line(), $this->verifier);
        $this->service()->reject($this->line(), $this->verifier, 'Not a project cost.');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->reporter->id, 'type' => 'cost_verified',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->reporter->id, 'type' => 'cost_rejected',
        ]);
    }

    public function test_answering_a_query_puts_it_back_in_front_of_verifiers(): void
    {
        $line = $this->line(['status' => CostLine::STATUS_QUERIED]);

        $this->service()->resubmit($line, $this->reporter, 'A clearer receipt has now been attached.');

        // Resolved from the permission rather than a named person — the queue
        // belongs to whoever can act on it. An answered query that told nobody
        // would sit waiting. The verifier here holds the permission but NO
        // Finance role, which is exactly the case a role-gated broadcast drops.
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->verifier->id, 'type' => 'cost_submitted',
        ]);
        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $this->reporter->id, 'type' => 'cost_submitted',
        ]);
    }

    public function test_a_producer_posted_cost_notifies_nobody(): void
    {
        // Landed verified with no human reporter; there is nothing to say and
        // nobody to say it to.
        $this->line(['status' => CostLine::STATUS_VERIFIED, 'submitted_by_user_id' => null]);

        $this->assertSame(0, AppNotification::count());
    }

    public function test_a_failed_notification_does_not_undo_the_verification(): void
    {
        $line = $this->line();

        // Verifying is the real work; telling someone is a courtesy that must be
        // allowed to fail on its own.
        DB::statement('DROP TABLE app_notifications');

        $result = $this->service()->verify($line, $this->verifier);

        $this->assertSame(CostLine::STATUS_VERIFIED, $result->status);
    }
}
