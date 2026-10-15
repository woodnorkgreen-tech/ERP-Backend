<?php

namespace Tests\Feature\Outsourcing;

use App\Models\User;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignJob;
use App\Modules\Printing\Models\PrintJob;
use App\Support\Outsourcing\OutsourcingAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GrantsMatrixPermissions;
use Tests\TestCase;

class OutsourcingAssignmentTest extends TestCase
{
    use GrantsMatrixPermissions;
    use RefreshDatabase;

    private function printingStaff(): User
    {
        $this->grantMatrixPermissions('Printing');
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Role::findOrCreate('Printing', 'web'));
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    private function designer(): User
    {
        $this->grantMatrixPermissions('Designer');
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Role::findOrCreate('Designer', 'web'));
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    private function designItem(): DesignItem
    {
        $job = DesignJob::create(['title' => 'Outsourcing check', 'job_number' => 'OUT-'.uniqid()]);

        return DesignItem::create([
            'design_job_id' => $job->id,
            'stream' => 'graphic',
            'title' => 'Entrance banner',
            'status' => 'in_design',
        ]);
    }

    public function test_printing_staff_can_outsource_a_print_job(): void
    {
        $staff = $this->printingStaff();
        $job = PrintJob::create(['title' => 'Banner run', 'status' => 'queued']);

        $this->putJson("/api/printing/jobs/{$job->id}", [
            'outsourced' => true,
            'outsourced_partner_name' => 'Acme Print Works',
            'outsourced_reason' => 'equipment_down',
        ])->assertOk();

        $job->refresh();
        $this->assertSame('Acme Print Works', $job->outsourced_partner_name);
        $this->assertSame('equipment_down', $job->outsourced_reason);
        $this->assertSame($staff->id, $job->outsourced_by);
        $this->assertNotNull($job->outsourced_at);
        $this->assertTrue($job->isOutsourced());
    }

    public function test_outsourcing_a_print_job_requires_partner_and_reason(): void
    {
        $this->printingStaff();
        $job = PrintJob::create(['title' => 'Banner run', 'status' => 'queued']);

        $this->putJson("/api/printing/jobs/{$job->id}", ['outsourced' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['outsourced_partner_name', 'outsourced_reason']);
    }

    public function test_other_reason_requires_a_note(): void
    {
        $this->printingStaff();
        $job = PrintJob::create(['title' => 'Banner run', 'status' => 'queued']);

        $this->putJson("/api/printing/jobs/{$job->id}", [
            'outsourced' => true,
            'outsourced_partner_name' => 'Acme Print Works',
            'outsourced_reason' => 'other',
        ])->assertUnprocessable()->assertJsonValidationErrors(['outsourced_reason_note']);
    }

    public function test_clearing_outsourcing_removes_the_partner(): void
    {
        $this->printingStaff();
        $job = PrintJob::create([
            'title' => 'Banner run',
            'status' => 'queued',
            'outsourced_partner_name' => 'Acme Print Works',
            'outsourced_reason' => 'tight_deadline',
            'outsourced_at' => now(),
        ]);

        $this->putJson("/api/printing/jobs/{$job->id}", ['outsourced' => false])->assertOk();

        $job->refresh();
        $this->assertNull($job->outsourced_partner_name);
        $this->assertNull($job->outsourced_reason);
        $this->assertNull($job->outsourced_at);
    }

    public function test_user_without_printing_update_cannot_outsource_a_print_job(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);
        $job = PrintJob::create(['title' => 'Banner run', 'status' => 'queued']);

        $this->putJson("/api/printing/jobs/{$job->id}", [
            'outsourced' => true,
            'outsourced_partner_name' => 'Acme Print Works',
            'outsourced_reason' => 'workload_capacity',
        ])->assertForbidden();
    }

    public function test_designer_can_outsource_a_design_item(): void
    {
        $designer = $this->designer();
        $item = $this->designItem();

        $this->putJson("/api/design/graphic/items/{$item->id}", [
            'title' => 'Entrance banner',
            'outsourced' => true,
            'outsourced_partner_name' => 'Studio North',
            'outsourced_reason' => 'specialist_skill',
        ])->assertOk();

        $item->refresh();
        $this->assertSame('Studio North', $item->outsourced_partner_name);
        $this->assertNull($item->assigned_to);
        $this->assertSame($designer->id, $item->outsourced_by);
    }

    public function test_user_without_creatives_view_cannot_outsource_a_design_item(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);
        $item = $this->designItem();

        $this->putJson("/api/design/graphic/items/{$item->id}", [
            'title' => 'Entrance banner',
            'outsourced' => true,
            'outsourced_partner_name' => 'Studio North',
            'outsourced_reason' => 'workload_capacity',
        ])->assertForbidden();
    }

    public function test_outsourcing_and_an_internal_assignee_are_rejected_together(): void
    {
        $this->expectException(ValidationException::class);

        OutsourcingAssignment::apply([
            'outsourced' => true,
            'outsourced_partner_name' => 'Acme Print Works',
            'outsourced_reason' => 'workload_capacity',
            'operator_id' => 5,
        ], 'operator_id', 1);
    }

    public function test_internal_assignment_clears_an_outside_partner(): void
    {
        $saved = OutsourcingAssignment::apply(['operator_id' => 5], 'operator_id', 1);

        $this->assertSame(5, $saved['operator_id']);
        foreach (OutsourcingAssignment::FIELDS as $field) {
            $this->assertNull($saved[$field]);
        }
    }

    public function test_outsourced_fields_without_the_flag_are_dropped(): void
    {
        $saved = OutsourcingAssignment::apply([
            'title' => 'Banner run',
            'outsourced_partner_name' => 'Acme Print Works',
            'outsourced_reason' => 'cost_rate',
        ], 'operator_id', 1);

        $this->assertArrayNotHasKey('outsourced_partner_name', $saved);
        $this->assertArrayNotHasKey('outsourced_reason', $saved);
    }
}
