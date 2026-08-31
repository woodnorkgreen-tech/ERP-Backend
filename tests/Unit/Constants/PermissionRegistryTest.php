<?php

namespace Tests\Unit\Constants;

use App\Constants\Permissions;
use App\Constants\RolePermissions;
use ReflectionClass;
use Tests\TestCase;

/**
 * The registry has to be complete, or authorization drifts per environment.
 *
 * Permissions::all() is what the seeder turns into rows. A constant missing
 * from it therefore exists only where some one-off migration happened to run:
 * present on a live database, absent on a fresh one, and impossible for an
 * administrator to assign. Three finance constants were in exactly that state —
 * finance.requisition_types.manage among them, which is how the Requisition
 * Types screen came to be reachable only by a Super Admin — and six petty-cash
 * permission rows existed with no constant at all.
 *
 * These assertions are cheap and hold the invariants that were actually broken.
 */
class PermissionRegistryTest extends TestCase
{
    /** @return array<string, string> constant name => permission string */
    private function constants(): array
    {
        return (new ReflectionClass(Permissions::class))->getConstants();
    }

    public function test_every_constant_is_registered_in_all(): void
    {
        $registered = Permissions::all();

        foreach ($this->constants() as $name => $value) {
            $this->assertContains(
                $value,
                $registered,
                "Permissions::{$name} is defined but missing from Permissions::all(), so the seeder "
                ."will never create it and a fresh database will not have it.",
            );
        }
    }

    public function test_every_constant_is_registered_in_grouped(): void
    {
        $grouped = array_merge(...array_values(Permissions::grouped()));

        foreach ($this->constants() as $name => $value) {
            $this->assertContains(
                $value,
                $grouped,
                "Permissions::{$name} is defined but missing from Permissions::grouped().",
            );
        }
    }

    public function test_all_and_grouped_agree(): void
    {
        $all = Permissions::all();
        $grouped = array_merge(...array_values(Permissions::grouped()));

        $this->assertEqualsCanonicalizing($all, $grouped);
    }

    public function test_registry_has_no_duplicates(): void
    {
        $all = Permissions::all();

        $this->assertSame(
            array_values(array_unique($all)),
            array_values($all),
            'Permissions::all() lists the same permission more than once.',
        );
    }

    public function test_matrix_only_grants_permissions_that_exist(): void
    {
        $known = array_values($this->constants());

        foreach (RolePermissions::matrix() as $role => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertContains(
                    $permission,
                    $known,
                    "Role [{$role}] is granted [{$permission}], which is not a Permissions constant.",
                );
            }
        }
    }

    public function test_matrix_covers_exactly_the_declared_roles(): void
    {
        $this->assertEqualsCanonicalizing(
            RolePermissions::ROLES,
            array_keys(RolePermissions::matrix()),
            'RolePermissions::ROLES and the matrix disagree about which roles exist.',
        );
    }

    public function test_declared_roles_are_unique(): void
    {
        $this->assertSame(
            array_values(array_unique(RolePermissions::ROLES)),
            array_values(RolePermissions::ROLES),
        );
    }

    /**
     * The alias map is what keeps "Finance Manager" from being written again.
     * A target that is not a real role would reintroduce the silent skip.
     */
    public function test_retired_aliases_point_at_real_roles(): void
    {
        foreach (RolePermissions::RETIRED_ROLE_ALIASES as $alias => $target) {
            $this->assertContains(
                $target,
                RolePermissions::ROLES,
                "Retired alias [{$alias}] maps to [{$target}], which is not a real role.",
            );
            $this->assertNotContains(
                $alias,
                RolePermissions::ROLES,
                "[{$alias}] is listed as retired but also as a real role.",
            );
        }
    }

    public function test_every_role_has_a_description(): void
    {
        foreach (RolePermissions::ROLES as $role) {
            $this->assertArrayHasKey($role, RolePermissions::DESCRIPTIONS);
        }
    }
}
