<?php

namespace Tests\Feature\Printing;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Database\Seeders\PrintingAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PrintingAccessSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_rerun_creates_one_role_and_department_and_keeps_the_manager(): void
    {
        $this->seed(PrintingAccessSeeder::class);

        $employee = Employee::create([
            'employee_id' => 'PRINT-LEAD-1',
            'first_name' => 'Pat',
            'last_name' => 'Lead',
            'email' => 'pat.lead@example.test',
            'department_id' => Department::where('name', 'Printing')->value('id'),
            'position' => 'Printing Lead',
            'hire_date' => today(),
        ]);
        Department::where('name', 'Printing')->update(['manager_id' => $employee->id]);

        $this->seed(PrintingAccessSeeder::class);

        $this->assertSame(1, Role::where('name', 'Printing')->count());
        $this->assertSame(1, Department::where('name', 'Printing')->count());
        $this->assertSame(17, Permission::where('name', 'like', 'printing.%')->count());
        $this->assertSame(
            $employee->id,
            Department::where('name', 'Printing')->value('manager_id'),
        );
    }

    public function test_legacy_creative_grants_are_removed_but_custom_grants_survive(): void
    {
        $role = Role::firstOrCreate(['name' => 'Printing', 'guard_name' => 'web']);
        $legacy = [
            Permissions::CREATIVES_VIEW,
            Permissions::CREATIVES_DESIGN_CREATE,
            Permissions::CREATIVES_DESIGN_UPDATE,
            Permissions::CREATIVES_DESIGN_APPROVE,
            Permissions::CREATIVES_MATERIALS_MANAGE,
        ];
        foreach ($legacy as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $role->givePermissionTo($legacy);

        $this->seed(PrintingAccessSeeder::class);

        $this->assertSame(0, $role->fresh()->permissions->where('name', 'like', 'creatives.%')->count());

        // A later custom grant on the same role must not be pruned by a rerun.
        $role->fresh()->givePermissionTo(Permissions::CREATIVES_VIEW);
        $this->seed(PrintingAccessSeeder::class);

        $this->assertTrue($role->fresh()->hasPermissionTo(Permissions::CREATIVES_VIEW));
    }

    public function test_admin_override_reaches_lead_actions_without_printing_permissions(): void
    {
        $this->seed(PrintingAccessSeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::findOrCreate('Admin', 'web'));
        Sanctum::actingAs($admin->fresh());

        // Validation fails (422) instead of authorization (403), so the override passed the middleware.
        $this->postJson('/api/printing/history-imports/preview')->assertUnprocessable();
    }
}
