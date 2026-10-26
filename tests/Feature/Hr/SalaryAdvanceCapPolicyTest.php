<?php

namespace Tests\Feature\Hr;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\HrSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The salary advance cap: off by default, 30% when HR switches it on.
 */
class SalaryAdvanceCapPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function user(?string $permission, ?float $salary = null): User
    {
        $employeeId = null;
        if ($salary !== null) {
            $dept = Department::firstOrCreate(['name' => 'Workshop']);
            $employeeId = Employee::create([
                'employee_id' => 'WNG'.random_int(1000, 9999), 'first_name' => 'Amina', 'last_name' => 'Otieno',
                'department_id' => $dept->id, 'position' => 'Technician', 'hire_date' => '2025-01-01',
                'status' => 'active', 'salary' => $salary, 'payment_method' => 'bank',
            ])->id;
        }
        $user = User::create(['name' => 'U'.uniqid(), 'email' => uniqid().'@test.local', 'password' => bcrypt('x'),
            'is_active' => true, 'employee_id' => $employeeId]);
        if ($permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    private function apply(User $user, float $amount)
    {
        return $this->actingAs($user)->postJson('/api/hr/my-advances', [
            'amount' => $amount, 'reason' => 'School fees', 'target_payroll_month' => '2026-11',
        ]);
    }

    public function test_cap_ships_off_at_thirty_percent_and_any_amount_is_accepted(): void
    {
        $staff = $this->user(null, 40000);

        $this->actingAs($staff)->getJson('/api/hr/advances/policy')
            ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.percent', 30);
        $this->apply($staff, 35000)->assertCreated();

        // Missing salaries must not block anyone while the cap is off.
        $this->apply($this->user(null, 0), 5000)->assertCreated();
    }

    public function test_when_hr_switches_it_on_the_cap_is_thirty_percent(): void
    {
        $hr = $this->user(Permissions::HR_MANAGE_PAYROLL);
        $this->actingAs($hr)->putJson('/api/hr/advances/policy', ['enabled' => true, 'percent' => 30])
            ->assertOk()->assertJsonPath('data.enabled', true);

        $staff = $this->user(null, 40000);
        $this->apply($staff, 12001)->assertStatus(422)->assertJsonFragment([
            'message' => 'Advance request exceeds the safety cap of 30% of your salary (Max: KES 12,000.00)',
        ]);
        $this->apply($staff, 12000)->assertCreated();

        $this->apply($this->user(null, 0), 100)->assertStatus(422);

        // And off again.
        $this->actingAs($hr)->putJson('/api/hr/advances/policy', ['enabled' => false, 'percent' => 30])->assertOk();
        $this->apply($staff, 30000)->assertCreated();
    }

    public function test_only_hr_can_change_the_cap(): void
    {
        $this->actingAs($this->user(null, 40000))
            ->putJson('/api/hr/advances/policy', ['enabled' => true, 'percent' => 30])->assertForbidden();

        $this->assertFalse(HrSetting::advanceCap()['enabled']);
    }
}
