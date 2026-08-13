<?php

namespace Tests\Feature\PettyCash;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
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
            Permissions::FINANCE_PETTY_CASH_DELETE,
            Permissions::FINANCE_PETTY_CASH_UPDATE,
        ]);

        $this->assertTrue($accounts->can('void', PettyCashDisbursement::class));
        $this->assertTrue($accounts->can('delete', PettyCashDisbursement::class));
        $this->assertTrue($accounts->can('update', PettyCashDisbursement::class));
        $this->assertTrue($accounts->can('archive', PettyCashDisbursement::class));
    }

    public function test_holding_one_petty_cash_permission_does_not_grant_the_others(): void
    {
        $viewer = $this->userWith([Permissions::FINANCE_PETTY_CASH_VIEW]);

        $this->assertTrue($viewer->can('viewAny', PettyCashDisbursement::class));
        $this->assertFalse($viewer->can('void', PettyCashDisbursement::class));
        $this->assertFalse($viewer->can('delete', PettyCashDisbursement::class));
        $this->assertFalse($viewer->can('update', PettyCashDisbursement::class));
    }

    /**
     * The deliberate exception. `finance.petty_cash.admin` is granted to
     * Accounts, so gating a full-data-wipe on it would hand the wipe to a second
     * role as a side effect of tidying authorization.
     */
    public function test_clear_all_is_not_widened_by_the_admin_permission(): void
    {
        $withAdmin = $this->userWith([Permissions::FINANCE_PETTY_CASH_ADMIN]);

        $this->assertFalse($withAdmin->can('clearAll', PettyCashDisbursement::class));
        $this->assertTrue($this->superAdmin()->can('clearAll', PettyCashDisbursement::class));
    }

    public function test_super_admin_still_passes_every_ability(): void
    {
        $admin = $this->superAdmin();

        foreach (['viewAny', 'update', 'void', 'delete', 'archive', 'viewActivityLogs', 'clearAll'] as $ability) {
            $this->assertTrue(
                $admin->can($ability, PettyCashDisbursement::class),
                "Super Admin should pass {$ability}",
            );
        }
    }

    public function test_a_user_with_no_permissions_is_refused_at_the_endpoint(): void
    {
        $nobody = User::factory()->create(['is_active' => true]);

        $this->actingAs($nobody, 'sanctum')
            ->deleteJson('/api/finance/petty-cash/clear-all')
            ->assertForbidden();
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
        $this->assertFalse($reporter->can('viewAllRequisitions', PettyCashDisbursement::class));

        $finance = $this->userWith([Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS]);
        $this->assertTrue($finance->can('viewAllRequisitions', PettyCashDisbursement::class));
    }
}
