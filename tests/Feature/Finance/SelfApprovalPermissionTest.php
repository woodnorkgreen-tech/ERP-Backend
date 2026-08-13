<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Support\SelfApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Separation of duties and its one exception.
 *
 * Whoever raises a requisition, records a receipt, reports a cost or creates a
 * budget addition may not be the one who signs it off. That rule is unchanged
 * and is still the default. What changed is who may set it aside: it used to be
 * either nobody at all, or a hard-coded `hasRole('Super Admin')` repeated in
 * each module, so a Finance Manager could not be granted the right without a
 * code change. It is now one assignable permission, consulted through
 * App\Support\SelfApproval.
 *
 * The important assertion in here is the first one: the default must still be
 * "blocked". A permission that is accidentally granted by default would quietly
 * remove the control that stops one person inventing and approving a payment.
 */
class SelfApprovalPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function plainUser(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function userWithSelfApproval(): User
    {
        Permission::findOrCreate(Permissions::APPROVALS_SELF_APPROVE, 'web');

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::APPROVALS_SELF_APPROVE);

        return $user;
    }

    private function superAdmin(): User
    {
        Role::findOrCreate('Super Admin', 'web');

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Super Admin');

        return $user;
    }

    public function test_an_ordinary_user_may_not_self_approve(): void
    {
        $this->assertFalse(
            SelfApproval::allowedFor($this->plainUser()),
            'Separation of duties must remain the default for everyone without the permission.'
        );
    }

    public function test_the_permission_grants_self_approval(): void
    {
        $this->assertTrue(
            SelfApproval::allowedFor($this->userWithSelfApproval()),
            'The whole point of the permission is that it can be granted through a role.'
        );
    }

    /**
     * Super Admin passes through the global Gate::before bypass in
     * AppServiceProvider, so the permission never has to be granted to them —
     * and the migration deliberately does not.
     */
    public function test_super_admin_may_self_approve_without_holding_the_permission(): void
    {
        $superAdmin = $this->superAdmin();

        $this->assertFalse(
            $superAdmin->hasPermissionTo(Permissions::APPROVALS_SELF_APPROVE),
            'Super Admin should not need the permission granted explicitly.'
        );
        $this->assertTrue(
            SelfApproval::allowedFor($superAdmin),
            'Super Admin is exempt from every self-approval block.'
        );
    }

    public function test_a_null_actor_is_never_allowed(): void
    {
        $this->assertFalse(
            SelfApproval::allowedFor(null),
            'An unauthenticated or console actor must not slip past the check.'
        );
    }

    /**
     * The helper answers "is this a self-approval that is permitted?", which is
     * what callers use to decide whether to demand a written reason and flag
     * the audit entry. Acting on someone else's record is not a self-approval
     * at all, however privileged the actor.
     */
    public function test_acting_on_another_persons_record_is_not_a_self_approval(): void
    {
        $actor = $this->userWithSelfApproval();
        $someoneElse = $this->plainUser();

        $this->assertFalse(
            SelfApproval::isPermittedSelfApproval($actor, $someoneElse->id),
            'Approving another person\'s submission is ordinary approval, not a self-approval.'
        );
        $this->assertTrue(
            SelfApproval::isPermittedSelfApproval($actor, $actor->id),
            'Approving your own submission with the permission held is a permitted self-approval.'
        );
    }

    public function test_an_unowned_record_is_not_a_self_approval(): void
    {
        $this->assertFalse(
            SelfApproval::isPermittedSelfApproval($this->userWithSelfApproval(), null),
            'A record with no owner cannot be self-approved.'
        );
    }
}
