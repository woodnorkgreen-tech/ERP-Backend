<?php

namespace Tests\Unit;

use App\Models\DesignAsset;
use App\Models\User;
use App\Modules\Projects\Models\EnquiryTask;
use App\Models\ProjectEnquiry;
use App\Services\DesignAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DesignAssetServiceTest extends TestCase
{
    use RefreshDatabase;

    private DesignAssetService $service;
    private User $user;
    private EnquiryTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DesignAssetService::class);

        $this->user = User::factory()->create();
        $enquiry = ProjectEnquiry::factory()->create(['created_by' => $this->user->id]);
        $this->task = EnquiryTask::factory()->create([
            'project_enquiry_id' => $enquiry->id,
            'type' => 'design',
            'assigned_to' => $this->user->id
        ]);
    }

    public function test_upload_assets_creates_records()
    {
        Storage::fake('public');

        $files = [
            UploadedFile::fake()->image('design1.jpg'),
            UploadedFile::fake()->create('design2.pdf', 1000)
        ];

        $assets = $this->service->uploadAssets($files, $this->task);

        $this->assertCount(2, $assets);
        $this->assertDatabaseCount('design_assets', 2);

        foreach ($assets as $asset) {
            $this->assertEquals($this->task->id, $asset->enquiry_task_id);
            $this->assertEquals($this->user->id, $asset->uploaded_by);
            Storage::disk('public')->assertExists($asset->file_path);
        }
    }

    public function test_update_asset_status()
    {
        $asset = DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'status' => 'pending'
        ]);

        $result = $this->service->updateAssetStatus($asset, 'approved', $this->user->id);

        $this->assertTrue($result);
        $this->assertEquals('approved', $asset->fresh()->status);
        $this->assertEquals($this->user->id, $asset->fresh()->approved_by);
        $this->assertNotNull($asset->fresh()->approved_at);
    }

    public function test_create_asset_version()
    {
        Storage::fake('public');

        $parentAsset = DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'version' => 1
        ]);

        $file = UploadedFile::fake()->image('version2.jpg');
        $versionAsset = $this->service->createAssetVersion($parentAsset, $file);

        $this->assertEquals($parentAsset->id, $versionAsset->parent_asset_id);
        $this->assertEquals(2, $versionAsset->version);
        $this->assertEquals($parentAsset->category, $versionAsset->category);
    }

    public function test_search_assets_with_filters()
    {
        DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'name' => 'Homepage Design',
            'category' => 'concept',
            'status' => 'approved'
        ]);

        DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'name' => 'Logo Concept',
            'category' => 'logo',
            'status' => 'pending'
        ]);

        // Search by name
        $results = $this->service->searchAssets($this->task, ['search' => 'logo']);
        $this->assertCount(1, $results);
        $this->assertEquals('Logo Concept', $results->first()->name);

        // Filter by status
        $results = $this->service->searchAssets($this->task, ['status' => 'approved']);
        $this->assertCount(1, $results);
        $this->assertEquals('approved', $results->first()->status);

        // Filter by category
        $results = $this->service->searchAssets($this->task, ['category' => 'concept']);
        $this->assertCount(1, $results);
        $this->assertEquals('concept', $results->first()->category);
    }

    public function test_get_asset_stats()
    {
        DesignAsset::factory()->count(2)->create([
            'enquiry_task_id' => $this->task->id,
            'status' => 'approved'
        ]);

        DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'status' => 'pending'
        ]);

        DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'status' => 'rejected'
        ]);

        $stats = $this->service->getAssetStats($this->task);

        $this->assertEquals(4, $stats['total']);
        $this->assertEquals(2, $stats['approved']);
        $this->assertEquals(1, $stats['pending']);
        $this->assertEquals(1, $stats['rejected']);
    }

    public function test_bulk_update_status()
    {
        $assets = DesignAsset::factory()->count(3)->create([
            'enquiry_task_id' => $this->task->id,
            'status' => 'pending'
        ]);

        $updated = $this->service->bulkUpdateStatus(
            $assets->pluck('id')->toArray(),
            'approved',
            $this->user->id
        );

        $this->assertEquals(3, $updated);

        $assets->each(function ($asset) {
            $asset->refresh();
            $this->assertEquals('approved', $asset->status);
            $this->assertEquals($this->user->id, $asset->approved_by);
        });
    }

    public function test_validate_file()
    {
        // Valid image file
        $validFile = UploadedFile::fake()->image('test.jpg');
        $result = $this->service->validateFile($validFile);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);

        // Invalid file type
        $invalidFile = UploadedFile::fake()->create('test.exe', 1000);
        $result = $this->service->validateFile($invalidFile);
        $this->assertFalse($result['valid']);
        $this->assertContains('File type not supported', $result['errors']);

        // File too large
        $largeFile = UploadedFile::fake()->create('large.pdf', 60000); // 60MB
        $result = $this->service->validateFile($largeFile);
        $this->assertFalse($result['valid']);
        $this->assertContains('File size exceeds maximum allowed size', $result['errors']);
    }

    public function test_cleanup_orphaned_files()
    {
        Storage::fake('public');

        // Create some files
        Storage::disk('public')->put('design-assets/1/file1.jpg', 'content1');
        Storage::disk('public')->put('design-assets/1/file2.jpg', 'content2');
        Storage::disk('public')->put('design-assets/1/orphaned.jpg', 'orphaned');

        // Create database record for only one file
        DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'file_path' => 'design-assets/1/file1.jpg'
        ]);

        $result = $this->service->cleanupOrphanedFiles();

        $this->assertEquals(3, $result['scanned']);
        $this->assertEquals(2, $result['orphaned']); // file2.jpg and orphaned.jpg
        $this->assertEquals(2, $result['deleted']);

        Storage::disk('public')->assertMissing('design-assets/1/file2.jpg');
        Storage::disk('public')->assertMissing('design-assets/1/orphaned.jpg');
        Storage::disk('public')->assertExists('design-assets/1/file1.jpg');
    }

    public function test_asset_category_detection()
    {
        // Test various filename patterns
        $testCases = [
            ['homepage_concept_final.jpg', 'concept'],
            ['logo_design_v2.ai', 'logo'],
            ['mobile_mockup.sketch', 'mockup'],
            ['ui_wireframes.fig', 'ui-ux'],
            ['presentation_slides.pdf', 'presentation'],
            ['random_file.xyz', 'other']
        ];

        foreach ($testCases as [$filename, $expectedCategory]) {
            $reflection = new \ReflectionClass($this->service);
            $method = $reflection->getMethod('detectCategory');
            $method->setAccessible(true);

            $result = $method->invoke($this->service, $filename);
            $this->assertEquals($expectedCategory, $result, "Failed for filename: $filename");
        }
    }

    public function test_asset_versioning()
    {
        $parentAsset = DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'version' => 1
        ]);

        // Create multiple versions
        for ($i = 2; $i <= 4; $i++) {
            $versionAsset = DesignAsset::factory()->create([
                'enquiry_task_id' => $this->task->id,
                'parent_asset_id' => $parentAsset->id,
                'version' => $i
            ]);

            $this->assertEquals($parentAsset->id, $versionAsset->parent_asset_id);
            $this->assertEquals($i, $versionAsset->version);
        }

        // Check that parent asset has child assets
        $parentAsset->refresh();
        $this->assertCount(3, $parentAsset->childAssets);
    }
}
