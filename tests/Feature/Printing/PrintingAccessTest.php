<?php

namespace Tests\Feature\Printing;

use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Printing\Support\PrintingAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\GrantsMatrixPermissions;
use Tests\TestCase;

class PrintingAccessTest extends TestCase
{
    use GrantsMatrixPermissions;
    use RefreshDatabase;

    private function printer(?Department $department = null): User
    {
        $this->grantMatrixPermissions('Printing');
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Printing');

        if ($department) {
            $employee = Employee::create([
                'employee_id' => 'PRINT-'.uniqid(),
                'first_name' => $user->name,
                'last_name' => 'Printer',
                'email' => $user->email,
                'department_id' => $department->id,
                'position' => 'Printer',
                'hire_date' => today(),
            ]);
            $user->update(['employee_id' => $employee->id, 'department_id' => $department->id]);
            $department->update(['manager_id' => $employee->id]);
        }

        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    public function test_printing_staff_can_read_jobs_but_cannot_import_history(): void
    {
        $this->printer();

        $this->getJson('/api/printing/jobs')->assertOk();
        $this->postJson('/api/printing/history-imports/preview')->assertForbidden();
    }

    public function test_printing_manager_gets_lead_authority_and_other_manager_does_not(): void
    {
        $printing = Department::create(['name' => 'Printing']);
        $other = Department::create(['name' => 'Production']);

        $otherManager = $this->printer($other);
        $this->assertFalse(PrintingAccess::isLead($otherManager));
        $this->postJson('/api/printing/history-imports/preview')->assertForbidden();

        $printingManager = $this->printer($printing);
        $this->assertTrue(PrintingAccess::isLead($printingManager));
        $this->postJson('/api/printing/history-imports/preview')->assertUnprocessable();
    }

    public function test_department_membership_without_permission_does_not_open_printing(): void
    {
        $department = Department::create(['name' => 'Printing']);
        $user = User::factory()->create(['department_id' => $department->id, 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/printing/jobs')->assertForbidden();
    }

    public function test_removing_one_printing_permission_blocks_only_its_action(): void
    {
        $this->grantMatrixPermissions('Printing');
        Role::findByName('Printing', 'web')->revokePermissionTo('printing.materials.request');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Printing');
        Sanctum::actingAs($user->fresh());

        $this->postJson('/api/printing/material-requests', [])->assertForbidden();
        $this->getJson('/api/printing/jobs')->assertOk();
        $this->getJson('/api/printing/material-requests')->assertOk();
    }

    public function test_stores_can_read_the_request_queue_without_printing_permissions(): void
    {
        Role::findOrCreate('Stores', 'web');
        $stores = User::factory()->create(['is_active' => true]);
        $stores->assignRole('Stores');
        Sanctum::actingAs($stores->fresh());

        $this->getJson('/api/printing/material-requests')->assertOk();
    }
}
