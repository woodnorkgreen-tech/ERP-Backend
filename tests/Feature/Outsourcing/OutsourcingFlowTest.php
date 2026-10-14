<?php

namespace Tests\Feature\Outsourcing;

use App\Models\User;
use App\Modules\Design\Models\DesignDocument;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignJob;
use App\Modules\Design\Models\DesignUpdate;
use App\Modules\Design\Services\DesignHandoffService;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Services\PrintIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GrantsMatrixPermissions;
use Tests\TestCase;

class OutsourcingFlowTest extends TestCase
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

    public function test_print_outsourcing_writes_a_job_event_and_returns_the_fields(): void
    {
        $this->printingStaff();
        $job = PrintJob::create(['title' => 'Banner run', 'status' => 'queued']);

        $this->putJson("/api/printing/jobs/{$job->id}", [
            'outsourced' => true,
            'outsourced_partner_name' => 'Acme Print Works',
            'outsourced_reason' => 'equipment_down',
            'outsourced_reason_note' => null,
        ])->assertOk()
            ->assertJsonPath('data.outsourced_partner_name', 'Acme Print Works')
            ->assertJsonPath('data.outsourced_reason_label', 'Printer or equipment down');

        $this->assertDatabaseHas('print_job_events', [
            'print_job_id' => $job->id,
            'event_type' => 'outsourced',
        ]);
    }

    public function test_clearing_print_outsourcing_writes_a_cleared_event(): void
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

        $this->assertDatabaseHas('print_job_events', [
            'print_job_id' => $job->id,
            'event_type' => 'outsourcing_cleared',
        ]);
    }

    public function test_design_outsourcing_writes_a_history_note(): void
    {
        $this->grantMatrixPermissions('Designer');
        $designer = User::factory()->create(['is_active' => true]);
        $designer->assignRole(Role::findOrCreate('Designer', 'web'));
        Sanctum::actingAs($designer->fresh());

        $job = DesignJob::create(['title' => 'Outsourcing history', 'job_number' => 'OUT-HIST-'.uniqid()]);
        $item = DesignItem::create([
            'design_job_id' => $job->id,
            'stream' => 'graphic',
            'title' => 'Entrance banner',
            'status' => 'in_design',
        ]);

        $this->putJson("/api/design/graphic/items/{$item->id}", [
            'title' => 'Entrance banner',
            'outsourced' => true,
            'outsourced_partner_name' => 'Studio North',
            'outsourced_reason' => 'workload_capacity',
        ])->assertOk()
            ->assertJsonPath('data.outsourced_reason_label', 'Workload / capacity');

        $note = DesignUpdate::where('design_item_id', $item->id)->value('note');
        $this->assertSame('Outsourced to Studio North (Workload / capacity)', $note);
    }

    public function test_outsourced_design_item_does_not_outsource_the_print_job(): void
    {
        $this->printingStaff();
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $job = DesignJob::create(['title' => 'Carry-over', 'job_number' => 'CARRY-'.uniqid()]);
        $item = DesignItem::create([
            'design_job_id' => $job->id,
            'stream' => 'graphic',
            'title' => 'Entrance banner',
            'status' => 'print_ready',
            'destination' => 'printing',
            'application_surface' => 'Vinyl',
            'bleed_per_side_m' => 0.125,
            'outsourced_partner_name' => 'Studio North',
            'outsourced_reason' => 'specialist_skill',
        ]);
        DesignDocument::create([
            'design_item_id' => $item->id,
            'document_type' => 'artwork',
            'name' => 'Approved artwork',
            'original_name' => 'Approved artwork',
            'source' => 'link',
            'external_url' => 'https://example.test/artwork.pdf',
            'file_path' => '',
            'mime_type' => 'application/pdf',
            'status' => 'active',
        ]);

        $handoff = app(DesignHandoffService::class)->createPrintingHandoffOnce($item);
        $printJob = app(PrintIntakeService::class)->accept($handoff);

        // A designer who is outsourced does not print. Print outsourcing is set separately.
        $this->assertNull($printJob->outsourced_partner_name);
        $this->assertSame('original', $printJob->order_type);
        $this->assertFalse($printJob->isOutsourced());
    }

    public function test_print_outsourcing_sets_the_outsourced_order_type_and_clearing_restores_it(): void
    {
        $this->printingStaff();
        $job = PrintJob::create(['title' => 'Banner run', 'status' => 'queued', 'order_type' => 'original']);

        $this->putJson("/api/printing/jobs/{$job->id}", [
            'outsourced' => true,
            'outsourced_partner_name' => 'Acme Print Works',
            'outsourced_reason' => 'equipment_down',
        ])->assertOk()->assertJsonPath('data.order_type', 'outsourced');

        $this->putJson("/api/printing/jobs/{$job->id}", ['outsourced' => false])
            ->assertOk()->assertJsonPath('data.order_type', 'original');
    }

    public function test_outsourcing_a_reprint_keeps_the_reprint_order_type(): void
    {
        $this->printingStaff();
        $job = PrintJob::create(['title' => 'Reprint', 'status' => 'queued', 'order_type' => 'reprint']);

        $this->putJson("/api/printing/jobs/{$job->id}", [
            'outsourced' => true,
            'outsourced_partner_name' => 'Acme Print Works',
            'outsourced_reason' => 'workload_capacity',
        ])->assertOk()->assertJsonPath('data.order_type', 'reprint');
    }

    public function test_dashboard_counts_outsourced_print_jobs_by_reason_and_partner(): void
    {
        $this->printingStaff();
        PrintJob::create(['title' => 'A', 'status' => 'queued', 'outsourced_partner_name' => 'Acme Print Works', 'outsourced_reason' => 'equipment_down']);
        PrintJob::create(['title' => 'B', 'status' => 'queued', 'outsourced_partner_name' => 'Acme Print Works', 'outsourced_reason' => 'workload_capacity']);
        PrintJob::create(['title' => 'C', 'status' => 'queued']);

        $this->getJson('/api/printing/dashboard')->assertOk()
            ->assertJsonPath('data.kpis.outsourced_jobs', 2)
            ->assertJsonPath('data.outsourcing.by_partner.0.partner', 'Acme Print Works')
            ->assertJsonPath('data.outsourcing.by_partner.0.count', 2);
    }
}
