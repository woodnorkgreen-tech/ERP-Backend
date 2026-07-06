<?php

namespace Tests\Feature\Hr;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\OffboardingCard;
use App\Modules\HR\Models\OffboardingCase;
use App\Modules\HR\Services\OffboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Pins the offboarding lifecycle rules: card seeding, the settlement lock,
 * closed-case guards, the settlement status chain, and the final HR gate.
 */
class OffboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private OffboardingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = Department::create(['name' => 'Production']);
        $this->service = app(OffboardingService::class);
        $this->actingAs($this->userWith([]), 'sanctum');
    }

    private function userWith(array $permissions): User
    {
        $u = User::create([
            'name'     => uniqid('user_'),
            'email'    => uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        foreach ($permissions as $permission) {
            // Explicit guard: actingAs(..., 'sanctum') switches the default guard,
            // which would otherwise create these under 'sanctum' instead of 'web'.
            Permission::findOrCreate($permission, 'web');
            $u->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_id'   => uniqid('EMP'),
            'first_name'    => 'Out',
            'last_name'     => 'Going',
            'department_id' => $this->dept->id,
            'position'      => 'Technician',
            'hire_date'     => now()->subYear()->toDateString(),
            'status'        => 'active',
        ]);
    }

    private function openCase(): OffboardingCase
    {
        return $this->service->initiateOffboarding([
            'employee_id'      => $this->employee()->id,
            'termination_type' => 'resignation',
            'last_working_day' => now()->addWeeks(2)->toDateString(),
        ]);
    }

    /** Drive a case through everything the final gate requires, except the gate itself. */
    private function fulfilPrerequisites(OffboardingCase $case): void
    {
        foreach ($case->assetReturns as $item) {
            $this->service->toggleAssetReturn($item->id, 'good');
        }
        foreach ($case->clearances as $clearance) {
            $this->service->updateClearanceStatus($clearance->id, 'cleared');
        }
        foreach ($case->cards->firstWhere('card_type', 'documentation')->tasks as $task) {
            $this->service->completeTaskById($task->id);
        }
        $this->service->recordExitInterview($case->id, ['rating' => 4]);
        $this->service->updateFinalSettlement($case->id, ['outstanding_salary' => 1000, 'deductions' => 100]);
        $this->service->approveFinalSettlement($case->id);
    }

    private function assertAbortsWith(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected HttpException({$status}) was not thrown.");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_initiation_seeds_cards_defaults_and_locks_settlement(): void
    {
        $case = $this->openCase();

        $this->assertCount(5, $case->cards);
        $this->assertCount(5, $case->assetReturns);
        $this->assertCount(4, $case->clearances);
        $this->assertCount(5, $case->cards->firstWhere('card_type', 'documentation')->tasks);

        $settlement = $case->cards->firstWhere('card_type', 'final_settlement');
        $this->assertTrue((bool) $settlement->is_locked);
        $this->assertSame('locked', $settlement->status);
    }

    public function test_settlement_stays_locked_until_assets_and_clearances_complete(): void
    {
        $case = $this->openCase();

        foreach ($case->assetReturns as $item) {
            $this->service->toggleAssetReturn($item->id, 'good');
        }
        $this->assertTrue((bool) $this->settlementCard($case)->is_locked, 'Assets alone must not unlock settlement.');

        foreach ($case->clearances as $clearance) {
            $this->service->updateClearanceStatus($clearance->id, 'cleared');
        }
        $this->assertFalse((bool) $this->settlementCard($case)->is_locked);
    }

    public function test_settlement_cannot_be_edited_or_approved_while_locked(): void
    {
        $case = $this->openCase();

        $this->assertAbortsWith(422, fn () => $this->service->updateFinalSettlement($case->id, ['outstanding_salary' => 500]));
        $this->assertAbortsWith(422, fn () => $this->service->approveFinalSettlement($case->id));
    }

    public function test_settlement_must_be_approved_before_it_can_be_paid(): void
    {
        $case = $this->openCase();
        $this->fulfilPrerequisites($case);

        // fulfilPrerequisites approves it; a fresh calculation resets to 'calculated'
        $this->service->updateFinalSettlement($case->id, ['outstanding_salary' => 2000]);
        $this->assertAbortsWith(422, fn () => $this->service->markSettlementPaid($case->id));

        $this->service->approveFinalSettlement($case->id);
        $this->assertSame('paid', $this->service->markSettlementPaid($case->id)->status);
    }

    public function test_final_gate_rejects_incomplete_case(): void
    {
        $case = $this->openCase();
        $this->assertAbortsWith(422, fn () => $this->service->approveFinalGate($case->id));
    }

    public function test_final_gate_completes_case_and_terminates_employee(): void
    {
        $case = $this->openCase();
        $this->fulfilPrerequisites($case);

        $result = $this->service->approveFinalGate($case->id);

        $this->assertSame('completed', $result['case']->status);

        $employee = Employee::withTrashed()->find($case->employee_id);
        $this->assertSame('terminated', $employee->status);
        $this->assertSame('resignation', $employee->termination_type);
        $this->assertTrue($employee->trashed());
    }

    public function test_closed_cases_reject_further_mutations(): void
    {
        $cancelled = $this->openCase();
        $this->service->cancelOffboarding($cancelled->id, 'Employee retracted resignation');

        $task = $cancelled->cards->firstWhere('card_type', 'documentation')->tasks->first();
        $this->assertAbortsWith(422, fn () => $this->service->completeTaskById($task->id));
        $this->assertAbortsWith(422, fn () => $this->service->toggleAssetReturn($cancelled->assetReturns->first()->id));
        $this->assertAbortsWith(422, fn () => $this->service->updateClearanceStatus($cancelled->clearances->first()->id, 'cleared'));
        $this->assertAbortsWith(422, fn () => $this->service->recordExitInterview($cancelled->id, []));
        $this->assertAbortsWith(422, fn () => $this->service->cancelOffboarding($cancelled->id));
    }

    public function test_completed_case_cannot_be_cancelled(): void
    {
        $case = $this->openCase();
        $this->fulfilPrerequisites($case);
        $this->service->approveFinalGate($case->id);

        $this->assertAbortsWith(422, fn () => $this->service->cancelOffboarding($case->id));
    }

    public function test_employee_with_cancelled_case_is_eligible_again(): void
    {
        $case = $this->openCase();
        $this->service->cancelOffboarding($case->id, 'Retracted');

        $this->assertTrue(
            $this->service->getEligibleEmployees()->contains('id', $case->employee_id),
            'A cancelled case must not block re-offboarding.'
        );
    }

    public function test_routes_require_offboarding_permissions(): void
    {
        $case = $this->openCase();

        $this->actingAs($this->userWith([]), 'sanctum');
        $this->getJson('/api/hr/offboarding')->assertForbidden();
        $this->postJson('/api/hr/offboarding', ['employee_id' => $this->employee()->id])->assertForbidden();
        $this->postJson("/api/hr/offboarding/{$case->id}/approve")->assertForbidden();

        $this->actingAs($this->userWith([Permissions::OFFBOARDING_VIEW]), 'sanctum');
        $this->getJson('/api/hr/offboarding')->assertOk();
        $this->getJson("/api/hr/offboarding/{$case->id}")->assertOk();
    }

    public function test_flag_reason_is_required_when_flagging_a_clearance(): void
    {
        $case = $this->openCase();
        $this->actingAs($this->userWith([Permissions::OFFBOARDING_CLEARANCE]), 'sanctum');

        $clearance = $case->clearances->first();

        $this->patchJson("/api/hr/offboarding/clearances/{$clearance->id}/status", ['status' => 'flagged'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('flag_reason');

        $this->patchJson("/api/hr/offboarding/clearances/{$clearance->id}/status", [
            'status'      => 'flagged',
            'flag_reason' => 'Laptop charger missing',
        ])->assertOk();
    }

    public function test_settlement_rejects_negative_amounts(): void
    {
        $case = $this->openCase();
        $this->actingAs($this->userWith([Permissions::OFFBOARDING_SETTLEMENT]), 'sanctum');

        $this->patchJson("/api/hr/offboarding/{$case->id}/settlement", ['deductions' => -50])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deductions');
    }

    private function settlementCard(OffboardingCase $case): OffboardingCard
    {
        return OffboardingCard::where('offboarding_case_id', $case->id)
            ->where('card_type', 'final_settlement')
            ->first();
    }
}
