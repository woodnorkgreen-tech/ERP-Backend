<?php

namespace Tests\Feature\PettyCash;

use App\Constants\Permissions;
use App\Models\GovernanceAuditLog;
use App\Models\User;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Services\RequisitionControlProjection;
use App\Modules\Finance\PettyCash\Services\RequisitionVerificationService;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RequisitionVerificationControlTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;
    private User $verifier;
    private RequisitionVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creator = User::factory()->create(['is_active' => true]);
        $this->verifier = User::factory()->create(['is_active' => true]);
        $this->verifier->givePermissionTo(RequisitionVerificationService::PERMISSION);
        $this->service = app(RequisitionVerificationService::class);
    }

    private function request(): PettyCashRequisition
    {
        $r = PettyCashRequisition::create([
            'requisition_number' => 'REQ-TEST-'.uniqid(), 'user_id' => $this->creator->id,
            'responsible_verifier_id' => $this->verifier->id,
            'department_id' => Department::firstOrCreate(['name' => 'Test Department'])->id,
            'category' => 'Operations', 'purpose' => 'Event transport', 'total_amount' => '30000.00',
            'status' => 'pending', 'payee_name' => 'Timothy',
        ]);
        $r->items()->create(['description' => 'Transport', 'amount' => '30000.00', 'payee_name' => 'Timothy']);
        $this->service->submit($r, $this->creator->id);

        return $r->fresh();
    }

    public function test_verification_authority_is_registered_but_not_inherited_from_a_default_role(): void
    {
        $this->assertContains(RequisitionVerificationService::PERMISSION, Permissions::all());
        $this->assertSame('Verify Assigned Financial Requisitions', Permissions::getLabel(RequisitionVerificationService::PERMISSION));
        foreach (\App\Constants\RolePermissions::matrix() as $name => $permissions) {
            $this->assertNotContains(RequisitionVerificationService::PERMISSION, $permissions, $name);
        }
    }

    public function test_creation_requires_verifier_and_returns_the_persisted_submission_state(): void
    {
        $type = \App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType::create([
            'code' => 'verification-test', 'name' => 'Verification test', 'recipient_mode' => 'single',
            'is_active' => true, 'requires_project' => false, 'request_fields' => [], 'item_fields' => [],
        ]);
        $payload = ['department_id' => Department::firstOrCreate(['name' => 'Operations'])->id,
            'category' => $type->name, 'requisition_type_id' => $type->id, 'purpose' => 'Event transport',
            'payee_name' => 'Timothy', 'items' => [['description' => 'Transport', 'amount' => '30000.00']]];
        $this->actingAs($this->creator, 'sanctum')->postJson('/api/finance/petty-cash/requisitions', $payload)->assertUnprocessable();
        $payload['responsible_verifier_id'] = $this->verifier->id;
        $created = $this->postJson('/api/finance/petty-cash/requisitions', $payload)->assertCreated()
            ->assertJsonPath('data.verification_status', 'pending_verification')
            ->assertJsonPath('data.total_amount', '30000.00')->json('data');
        $this->assertMatchesRegularExpression('/^REQ-\d{4}-\d+$/', $created['requisition_number']);
        $next = $this->postJson('/api/finance/petty-cash/requisitions', $payload)->assertCreated()->json('data');
        $this->assertNotSame($created['requisition_number'], $next['requisition_number']);
    }

    public function test_only_assigned_authorised_user_can_verify(): void
    {
        $r = $this->request();
        $other = User::factory()->create(['is_active' => true]);
        $other->givePermissionTo(RequisitionVerificationService::PERMISSION);
        $this->actingAs($other, 'sanctum')->postJson("/api/finance/petty-cash/requisitions/{$r->id}/verify", ['decision' => 'verified'])->assertForbidden();
        $this->actingAs($this->verifier, 'sanctum')->postJson("/api/finance/petty-cash/requisitions/{$r->id}/verify", ['decision' => 'verified'])->assertOk();
        $this->assertTrue($this->service->isCurrent($r->fresh()));
        $this->assertSame('pending', $r->fresh()->status, 'Verification is not approval.');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_super_admin_without_explicit_verification_authority_is_denied(): void
    {
        $r = $this->request();
        $this->verifier->revokePermissionTo(RequisitionVerificationService::PERMISSION);
        $this->verifier->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($this->verifier, 'sanctum')->postJson("/api/finance/petty-cash/requisitions/{$r->id}/verify", ['decision' => 'verified'])->assertForbidden();
    }

    public function test_creator_cannot_self_verify_even_with_verification_permission(): void
    {
        $this->creator->givePermissionTo(RequisitionVerificationService::PERMISSION);
        $this->expectException(ValidationException::class);
        $this->service->validateVerifier($this->creator->id, $this->creator->id);
    }

    public function test_inactive_verifier_cannot_be_selected(): void
    {
        $this->verifier->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        $this->service->validateVerifier($this->verifier->id, $this->creator->id);
    }

    public function test_return_requires_reason_and_resubmission_preserves_history(): void
    {
        $r = $this->request();
        $this->actingAs($this->verifier, 'sanctum')->postJson("/api/finance/petty-cash/requisitions/{$r->id}/verify", ['decision' => 'returned_for_correction'])->assertUnprocessable();
        $this->service->review($r->id, $this->verifier, 'returned_for_correction', 'Correct receiver allocation.');
        $this->service->submit($r->fresh(), $this->creator->id);
        $this->service->review($r->id, $this->verifier, 'verified', 'Corrected.');
        $this->assertTrue($this->service->isCurrent($r->fresh()));
        $this->assertSame(1, GovernanceAuditLog::where('model_id', $r->id)->where('gate_type', 'requisition_returned_for_correction')->count());
    }

    public function test_material_parent_and_line_edits_invalidate_current_certification(): void
    {
        $r = $this->request();
        $r = $this->service->review($r->id, $this->verifier, 'verified', null);
        // Exercise every declared parent field in the certification envelope.
        // Foreign-key candidates remain unsaved; the following checks exercise
        // persisted edits and their permanent revocation of certification.
        foreach (PettyCashRequisition::VERIFICATION_FIELDS as $field) {
            $candidate = clone $r;
            $changed = match (true) {
                in_array($field, ['custom_fields', 'type_snapshot'], true) => ['changed' => true],
                str_ends_with($field, '_id') => 999999,
                $field === 'total_amount' => '50000.00',
                default => 'CHANGED',
            };
            $candidate->forceFill([$field => $changed]);
            $this->assertFalse($this->service->isCurrent($candidate), $field);
        }
        foreach (['purpose' => 'Changed purpose', 'project_name' => 'Other project', 'payee_name' => 'Other receiver', 'total_amount' => '50000.00', 'venue' => 'Other venue', 'custom_fields' => ['changed' => true]] as $field => $value) {
            $old = $r->$field;
            $r->update([$field => $value]);
            $this->assertFalse($this->service->isCurrent($r->fresh()), $field);
            $r->update([$field => $old]);
            $this->assertFalse($this->service->isCurrent($r->fresh()), 'Restoring an edited value cannot resurrect certification.');
            $this->service->submit($r->fresh(), $this->creator->id);
            $r = $this->service->review($r->id, $this->verifier, 'verified', null);
        }
        foreach (['amount' => '50000.00', 'description' => 'New purpose', 'payee_name' => 'New receiver', 'details' => ['changed' => true]] as $field => $value) {
            $item = $r->items()->first();
            $old = $item->$field;
            $item->update([$field => $value]);
            $this->assertFalse($this->service->isCurrent($r->fresh()), $field);
            $item->update([$field => $old]);
            $this->service->submit($r->fresh(), $this->creator->id);
            $r = $this->service->review($r->id, $this->verifier, 'verified', null);
        }
    }

    public function test_edit_submission_revokes_certification_and_audits_previous_verifier(): void
    {
        $r = $this->request();
        $r = $this->service->review($r->id, $this->verifier, 'verified', null);
        $r->update(['purpose' => 'Corrected purpose']);
        $this->service->submit($r, $this->creator->id);
        $this->assertSame('pending_verification', $r->fresh()->verification_status);
        $this->assertNull($r->fresh()->verified_by);
        $this->assertDatabaseHas('governance_audit_logs', ['model_id' => $r->id, 'gate_type' => 'requisition_verification_invalidated']);
    }

    public function test_unverified_request_cannot_be_approved_or_paid(): void
    {
        $r = $this->request();
        $finance = User::factory()->create(['is_active' => true]);
        foreach ([Permissions::FINANCE_PETTY_CASH_UPDATE, Permissions::FINANCE_PETTY_CASH_CREATE] as $permission) {
            $finance->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($finance, 'sanctum')->postJson("/api/finance/petty-cash/requisitions/{$r->id}/approve")->assertUnprocessable();
        $r->update(['status' => 'approved', 'approved_at' => now()]);
        $this->postJson("/api/finance/petty-cash/requisitions/{$r->id}/disburse")->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_the_next_step_is_verify_for_the_verifier_then_approve_for_an_approver(): void
    {
        $r = $this->request();
        $approver = User::factory()->create(['is_active' => true]);
        $approver->givePermissionTo(Permission::findOrCreate(Permissions::FINANCE_PETTY_CASH_UPDATE, 'web'));
        $next = fn (User $as) => $this->actingAs($as, 'sanctum')->app->make(\App\Modules\Finance\PettyCash\Services\RequisitionControlProjection::class)
            ->forRequisition(PettyCashRequisition::findOrFail($r->id))['next_step'];

        $this->assertSame(['verify', true, $this->verifier->name], [$next($this->verifier)['key'], $next($this->verifier)['can_act'], $next($this->verifier)['who']]);
        // Nobody is offered Approve before verification, whatever they hold.
        $this->assertSame(['verify', false], [$next($approver)['key'], $next($approver)['can_act']]);
        $this->assertSame(['verify', false], [$next($this->creator)['key'], $next($this->creator)['can_act']]);

        $this->actingAs($this->verifier, 'sanctum')->postJson("/api/finance/petty-cash/requisitions/{$r->id}/verify", ['decision' => 'verified'])->assertOk();
        $this->assertSame(['approve', true], [$next($approver)['key'], $next($approver)['can_act']]);
        $this->assertSame(['approve', false], [$next($this->verifier)['key'], $next($this->verifier)['can_act']]);
    }

    public function test_duplicate_verification_cannot_create_another_review(): void
    {
        $r = $this->request();
        $this->actingAs($this->verifier, 'sanctum')->postJson("/api/finance/petty-cash/requisitions/{$r->id}/verify", ['decision' => 'verified'])->assertOk();
        $this->postJson("/api/finance/petty-cash/requisitions/{$r->id}/verify", ['decision' => 'verified'])->assertStatus(409);
        $this->assertSame(1, GovernanceAuditLog::where('model_id', $r->id)->where('gate_type', 'requisition_verified')->count());
    }

    public function test_parent_partial_payment_projection_does_not_invent_receipt_or_accountability(): void
    {
        $r = $this->request();
        $r->update(['status' => 'approved', 'approved_at' => now()]);
        foreach (['20000.00', '5000.00'] as $amount) {
            Payment::create(['requisition_id' => $r->id, 'payee_name' => 'Timothy', 'amount' => $amount,
                'description' => 'Advance', 'account' => 'Operations', 'date_disbursed' => now()->toDateString(),
                'status' => 'active', 'created_by' => $this->creator->id]);
        }
        $projection = app(RequisitionControlProjection::class)->forRequisition($r->fresh());
        $this->assertSame('25000.00', $projection['disbursed']);
        $this->assertSame('5000.00', $projection['outstanding']);
        $this->assertSame('partially_disbursed', $projection['disbursement_status']);
        $this->assertSame('confirmation_pending', $projection['confirmation_status']);
        $this->assertSame('pending', $projection['accountability_status']);
        $this->assertNull($projection['receivers'][0]['paid']);
        $this->assertSame([$r->id, $r->id], $projection['payments']->pluck('parent_requisition_id')->all());

        $r->update(['status' => 'received', 'received_at' => now()]);
        $confirmed = app(RequisitionControlProjection::class)->forRequisition($r->fresh());
        $this->assertSame('confirmed_parent_receipt', $confirmed['confirmation_status']);
        $this->assertSame('pending', $confirmed['accountability_status']);
        $r->disbursements()->first()->update(['status' => 'voided']);
        $voided = app(RequisitionControlProjection::class)->forRequisition($r->fresh());
        $this->assertSame('5000.00', $voided['disbursed']);
        $this->assertSame('25000.00', $voided['outstanding']);
        $this->assertCount(2, $voided['payments'], 'Voids stay visible for audit.');
    }

    public function test_receiver_grouping_preserves_multiple_lines_and_multiple_people(): void
    {
        $r = $this->request();
        $employees = [];
        foreach (['Steve', 'Timothy'] as $name) {
            $employees[] = \App\Modules\HR\Models\Employee::create([
                'employee_id' => 'R75R-'.$name, 'first_name' => $name, 'last_name' => 'Test',
                'department_id' => $r->department_id, 'position' => 'Tester', 'hire_date' => '2025-01-01',
                'status' => 'active', 'salary' => 10000,
            ]);
        }
        $r->items()->delete();
        foreach ([[$employees[0], '15000.00', 'Site facilitation'], [$employees[0], '5000.00', 'Casual support'], [$employees[1], '10000.00', 'Transport']] as [$employee, $amount, $purpose]) {
            $r->items()->create(['payee_id' => $employee->id, 'amount' => $amount, 'description' => $purpose]);
        }
        $projection = app(RequisitionControlProjection::class)->forRequisition($r->fresh());
        $this->assertCount(2, $projection['receivers']);
        $this->assertSame('20000.00', $projection['receivers'][0]['requested']);
        $this->assertCount(2, $projection['receivers'][0]['lines']);
        $this->assertSame('10000.00', $projection['receivers'][1]['requested']);
    }
}
