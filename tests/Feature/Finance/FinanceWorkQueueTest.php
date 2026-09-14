<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\PettyCash\Models\DirectDisbursementRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FinanceWorkQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_only_returns_work_the_user_may_action(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        DirectDisbursementRequest::create([
            'idempotency_key' => 'queue-test-1', 'status' => 'pending_approval',
            'payload' => ['payee_name' => 'Test Supplier', 'amount' => 1250], 'requested_by' => $user->id,
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/finance/work-queue')
            ->assertOk()->assertJsonPath('data.summary.total', 0);

        Permission::findOrCreate(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, 'web');
        $user->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);

        $this->actingAs($user, 'sanctum')->getJson('/api/finance/work-queue')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.items.0.work_type', 'direct_disbursement');
    }

    public function test_completed_work_disappears_from_queue_count(): void
    {
        Permission::findOrCreate(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, 'web');
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);
        $request = DirectDisbursementRequest::create([
            'idempotency_key' => 'queue-test-2', 'status' => 'pending_approval',
            'payload' => ['amount' => 500], 'requested_by' => $user->id,
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/finance/work-queue/count')
            ->assertOk()->assertJsonPath('data.total', 1);

        $request->update(['status' => 'approved']);

        $this->actingAs($user, 'sanctum')->getJson('/api/finance/work-queue/count')
            ->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_queue_supports_server_side_search_and_pagination(): void
    {
        Permission::findOrCreate(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, 'web');
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);
        foreach (range(1, 12) as $number) {
            DirectDisbursementRequest::create([
                'idempotency_key' => "queue-page-{$number}", 'status' => 'pending_approval',
                'payload' => ['payee_name' => $number === 12 ? 'Unique Vendor' : "Vendor {$number}", 'amount' => $number],
                'requested_by' => $user->id,
            ]);
        }

        $this->actingAs($user, 'sanctum')->getJson('/api/finance/work-queue?per_page=10&page=2')
            ->assertOk()->assertJsonPath('data.meta.total', 12)->assertJsonCount(2, 'data.items');

        $this->actingAs($user, 'sanctum')->getJson('/api/finance/work-queue?search=Unique%20Vendor')
            ->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.counterparty', 'Unique Vendor');
    }

    public function test_user_can_claim_and_release_an_eligible_item(): void
    {
        Permission::findOrCreate(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, 'web');
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);
        $request = DirectDisbursementRequest::create([
            'idempotency_key' => 'queue-claim', 'status' => 'pending_approval',
            'payload' => ['amount' => 500], 'requested_by' => $user->id,
        ]);

        $url = "/api/finance/work-queue/direct_disbursement/{$request->id}/claim";
        $this->actingAs($user, 'sanctum')->postJson($url)->assertOk()
            ->assertJsonPath('data.assigned_to', $user->id);
        $this->assertDatabaseHas('finance_work_assignments', ['source_id' => $request->id, 'assigned_to' => $user->id]);
        $this->assertDatabaseHas('finance_work_assignment_events', ['source_id' => $request->id, 'event' => 'claimed']);

        $this->actingAs($user, 'sanctum')->deleteJson($url)->assertOk();
        $this->assertDatabaseMissing('finance_work_assignments', ['source_id' => $request->id]);
        $this->assertDatabaseHas('finance_work_assignment_events', ['source_id' => $request->id, 'event' => 'released']);
    }

    public function test_a_second_officer_cannot_take_an_existing_claim(): void
    {
        Permission::findOrCreate(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE, 'web');
        $first = User::factory()->create(['is_active' => true]);
        $second = User::factory()->create(['is_active' => true]);
        $first->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);
        $second->givePermissionTo(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE);
        $request = DirectDisbursementRequest::create([
            'idempotency_key' => 'queue-conflict', 'status' => 'pending_approval',
            'payload' => ['amount' => 500], 'requested_by' => $first->id,
        ]);
        $url = "/api/finance/work-queue/direct_disbursement/{$request->id}/claim";

        $this->actingAs($first, 'sanctum')->postJson($url)->assertOk();
        $this->actingAs($second, 'sanctum')->postJson($url)->assertStatus(409);
        $this->assertDatabaseHas('finance_work_assignments', ['source_id' => $request->id, 'assigned_to' => $first->id]);
    }
}
