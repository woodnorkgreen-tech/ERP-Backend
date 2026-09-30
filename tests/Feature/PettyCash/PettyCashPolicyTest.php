<?php

namespace Tests\Feature\PettyCash;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BE-0: authorization consolidated onto PettyCashPolicy.
 *
 * The behaviour that matters is the widening — Accounts already held
 * `void_disbursement` and `delete_disbursement` and could not use them, because
 * every endpoint asked for the Super Admin role instead. These assert the grant
 * is now honoured, and that `clearAll` deliberately was not widened with it.
 */
class PettyCashPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function superAdmin(): User
    {
        Role::findOrCreate('Super Admin', 'web');

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Super Admin');

        return $user;
    }

    public function test_the_permission_grant_is_now_honoured_without_the_super_admin_role(): void
    {
        $accounts = $this->userWith([
            Permissions::FINANCE_PETTY_CASH_VOID,
            Permissions::FINANCE_PETTY_CASH_UPDATE,
        ]);

        $this->assertTrue($accounts->can('void', Payment::class));
        $this->assertTrue($accounts->can('reviewRequisition', Payment::class));
        $this->assertTrue($accounts->can('archive', Payment::class));
    }

    /**
     * The cash ledger is append-only, so no permission and no role — Super Admin
     * included — may edit or delete a disbursement. A missing policy ability
     * denies, which is the behaviour being pinned here: the module must not grow
     * an `update` or `delete` back without this test being changed on purpose.
     */
    public function test_a_disbursement_can_never_be_edited_or_deleted(): void
    {
        $accounts = $this->userWith([
            Permissions::FINANCE_PETTY_CASH_VOID,
            Permissions::FINANCE_PETTY_CASH_UPDATE,
        ]);

        $this->assertFalse($accounts->can('update', Payment::class));
        $this->assertFalse($accounts->can('delete', Payment::class));
    }

    public function test_holding_one_petty_cash_permission_does_not_grant_the_others(): void
    {
        $viewer = $this->userWith([Permissions::FINANCE_PETTY_CASH_VIEW]);

        $this->assertTrue($viewer->can('viewAny', Payment::class));
        $this->assertFalse($viewer->can('void', Payment::class));
        $this->assertFalse($viewer->can('reviewRequisition', Payment::class));
        $this->assertFalse($viewer->can('archive', Payment::class));
    }

    /**
     * Critical Risk C6 (finance-redesign/current-state/10_FINANCE_RISK_REGISTER.md):
     * clearAll() no longer exists as a policy ability at all — the
     * full-data-wipe capability was removed from the API entirely per the
     * confirmed STAB-5 decision, not merely re-gated. No user, Super Admin
     * included, can reach it through this policy; the only surviving path
     * is the local/testing-only console command.
     */
    public function test_clear_all_is_no_longer_an_ability_anyone_can_be_granted(): void
    {
        $withAdmin = $this->userWith([Permissions::FINANCE_PETTY_CASH_ADMIN]);

        $this->assertFalse(method_exists(\App\Modules\Finance\PettyCash\Policies\PettyCashPolicy::class, 'clearAll'));
        $this->assertFalse($withAdmin->can('clearAll', Payment::class));
    }

    public function test_super_admin_still_passes_every_remaining_ability(): void
    {
        $admin = $this->superAdmin();

        foreach (['viewAny', 'reviewRequisition', 'void', 'archive', 'viewActivityLogs'] as $ability) {
            $this->assertTrue(
                $admin->can($ability, Payment::class),
                "Super Admin should pass {$ability}",
            );
        }
    }

    /**
     * The route itself is gone (Critical Risk C6), not just re-guarded —
     * this pins that "removed from the API" actually means removed, for
     * every caller regardless of permission.
     */
    public function test_the_clear_all_route_no_longer_exists(): void
    {
        $nobody = User::factory()->create(['is_active' => true]);

        $this->actingAs($nobody, 'sanctum')
            ->deleteJson('/api/finance/petty-cash/clear-all')
            ->assertNotFound();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->deleteJson('/api/finance/petty-cash/clear-all')
            ->assertNotFound();
    }

    public function test_a_user_without_create_permission_cannot_submit_a_disbursement(): void
    {
        $nobody = User::factory()->create(['is_active' => true]);

        // An empty body would produce 422 if request validation ran before the
        // policy. The expected 403 proves the create boundary is enforced.
        $this->actingAs($nobody, 'sanctum')
            ->postJson('/api/finance/petty-cash/disbursements', [])
            ->assertForbidden();
    }

    /** The requisition queue scopes to your own unless you may see them all. */
    public function test_requisition_visibility_follows_the_policy(): void
    {
        $reporter = User::factory()->create(['is_active' => true]);
        $this->assertFalse($reporter->can('viewAllRequisitions', Payment::class));

        $finance = $this->userWith([Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS]);
        $this->assertTrue($finance->can('viewAllRequisitions', Payment::class));
    }
}
