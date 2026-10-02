<?php

namespace Tests\Feature\Printing;

use App\Models\User;
use App\Modules\Design\Models\DesignDocument;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignJob;
use App\Modules\Design\Services\DesignHandoffService;
use App\Modules\Printing\Services\PrintIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DesignPrintHandoffOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_choices_are_reusable_and_handoff_prefills_printing(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $surface = $this->postJson('/api/design/print-options', [
            'kind' => 'surface', 'label' => 'Powder-coated frame',
        ])->assertCreated()->json('data');
        $bleed = $this->postJson('/api/design/print-options', [
            'kind' => 'bleed', 'bleed_per_side_m' => 0.125,
        ])->assertCreated()->json('data');

        $this->getJson('/api/design/print-options')->assertOk()
            ->assertJsonFragment(['id' => $surface['id'], 'label' => 'Powder-coated frame'])
            ->assertJsonFragment(['id' => $bleed['id'], 'label' => '12.5cm / 0.125m']);

        $job = DesignJob::create(['title' => 'Site banner', 'job_number' => 'PRINT-OPTION-1']);
        $item = DesignItem::create([
            'design_job_id' => $job->id,
            'stream' => 'graphic',
            'title' => 'Entrance banner',
            'status' => 'print_ready',
            'application_surface' => $surface['label'],
            'bleed_per_side_m' => 0.125,
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

        $this->assertSame('Powder-coated frame', $printJob->application_surface);
        $this->assertEquals(0.125, $printJob->bleed_per_side_m);
        $this->getJson("/api/printing/jobs/{$printJob->id}")->assertOk()
            ->assertJsonPath('data.application_surface', 'Powder-coated frame')
            ->assertJsonPath('data.bleed_per_side_m', 0.125);
    }
}
