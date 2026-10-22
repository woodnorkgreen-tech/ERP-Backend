<?php

namespace Tests\Feature\Design;

use App\Models\User;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignJob;
use App\Modules\Design\Services\DesignHandoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DesignWorkRevisionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function graphicItem(): DesignItem
    {
        $job = DesignJob::create(['title' => 'Banner design']);

        return DesignItem::create([
            'design_job_id' => $job->id,
            'stream' => 'graphic',
            'destination' => 'printing',
            'title' => 'Banner artwork',
            'status' => 'pending',
            'length_m' => 2,
            'width_m' => 1,
            'quantity' => 1,
            'print_notes' => 'Print material: vinyl',
        ]);
    }

    public function test_stop_records_outcome_without_completing_item_or_handing_to_printing(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));
        $item = $this->graphicItem();

        $this->postJson("/api/design/items/{$item->id}/work/begin")->assertOk()
            ->assertJsonPath('data.work_tracking.active_revision_round', 1);
        $this->postJson("/api/design/items/{$item->id}/work/stop", ['outcome' => 'rejected'])
            ->assertUnprocessable();
        $this->postJson("/api/design/items/{$item->id}/work/stop", [
            'outcome' => 'rejected', 'reason' => 'Client rejected the brief',
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_design')
            ->assertJsonPath('data.work_tracking.state', 'stopped')
            ->assertJsonPath('data.work_tracking.last_stop_outcome', 'rejected')
            ->assertJsonPath('data.work_tracking.sessions.0.stop_reason', 'Client rejected the brief');

        $this->assertSame(0, $item->handoffs()->count());
        $this->assertNotNull($item->workSessions()->firstOrFail()->ended_at);
    }

    public function test_only_completed_approved_latest_revision_link_can_be_sent_to_printing(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));
        $item = $this->graphicItem();

        $first = $this->postJson("/api/design/items/{$item->id}/revisions", [
            'change_summary' => 'First layout', 'artwork_url' => 'https://example.test/v1.pdf',
        ])->assertCreated()->json('data');
        $this->postJson("/api/design/revisions/{$first['id']}/approve", ['approval_evidence' => 'Client email'])
            ->assertUnprocessable();
        $this->postJson("/api/design/revisions/{$first['id']}/complete")->assertOk();
        $this->postJson("/api/design/revisions/{$first['id']}/approve", ['approval_evidence' => 'Client email'])
            ->assertOk();

        $this->postJson("/api/design/items/{$item->id}/work/begin")->assertOk()
            ->assertJsonPath('data.work_tracking.active_revision_round', 2);
        $this->postJson("/api/design/items/{$item->id}/work/pause", ['reason' => 'break'])->assertOk();

        $second = $this->postJson("/api/design/items/{$item->id}/revisions", [
            'change_summary' => 'Revised layout', 'artwork_url' => 'https://example.test/v2.pdf',
        ])->assertCreated()->json('data');
        $this->postJson("/api/design/graphic/items/{$item->id}/mark-print-ready")
            ->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->postJson("/api/design/revisions/{$second['id']}/complete")->assertOk();
        $this->postJson("/api/design/graphic/items/{$item->id}/mark-print-ready")
            ->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->postJson("/api/design/revisions/{$second['id']}/approve", ['approval_evidence' => 'Client approved V2'])
            ->assertOk();

        $this->assertSame(0, $item->handoffs()->count());
        $item->update(['status' => 'print_ready']);
        $handoff = app(DesignHandoffService::class)->createPrintingHandoffOnce($item->fresh());
        $this->assertSame($second['id'], $handoff->payload_snapshot['final_artwork']['design_revision_id']);
        $this->assertSame('https://example.test/v2.pdf', $handoff->payload_snapshot['final_artwork']['url']);
    }
}
