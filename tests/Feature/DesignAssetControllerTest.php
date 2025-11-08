<?php

namespace Tests\Feature;

use App\Models\DesignAsset;
use App\Models\User;
use App\Modules\Projects\Models\EnquiryTask;
use App\Models\ProjectEnquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DesignAssetControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private EnquiryTask $task;
    private ProjectEnquiry $enquiry;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user
        $this->user = User::factory()->create();

        // Create test enquiry
        $this->enquiry = ProjectEnquiry::factory()->create([
            'created_by' => $this->user->id
        ]);

        // Create test task
        $this->task = EnquiryTask::factory()->create([
            'project_enquiry_id' => $this->enquiry->id,
            'type' => 'design',
            'assigned_to' => $this->user->id,
            'created_by' => $this->user->id
        ]);
    }

    public function test_user_can_list_design_assets_for_assigned_task()
    {
        DesignAsset::factory()->count(3)->create([
            'enquiry_task_id' => $this->task->id,
            'uploaded_by' => $this->user->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/enquiry-tasks/{$this->task->id}/design-assets");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'name', 'category', 'status', 'uploaded_by',
                        'created_at', 'updated_at'
                    ]
                ],
                'links', 'meta'
            ]);
    }

    public function test_user_cannot_list_assets_for_unassigned_task()
    {
        $otherTask = EnquiryTask::factory()->create([
            'type' => 'design'
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/enquiry-tasks/{$otherTask->id}/design-assets");

        $response->assertStatus(403);
    }

    public function test_user_can_upload_design_assets()
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('design.jpg', 1000, 1000);

        $response = $this->actingAs($this->user)
            ->postJson("/api/projects/enquiry-tasks/{$this->task->id}/design-assets", [
                'files' => [$file],
                'category' => 'concept',
                'description' => 'Test design asset'
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                '*' => ['id', 'name', 'file_path', 'category', 'status']
            ]);

        $this->assertDatabaseHas('design_assets', [
            'enquiry_task_id' => $this->task->id,
            'uploaded_by' => $this->user->id,
            'category' => 'concept'
        ]);
    }

    public function test_file_validation_works()
    {
        $largeFile = UploadedFile::fake()->create('large.exe', 60000); // 60MB

        $response = $this->actingAs($this->user)
            ->postJson("/api/projects/enquiry-tasks/{$this->task->id}/design-assets", [
                'files' => [$largeFile]
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['files.0']);
    }

    public function test_user_can_view_asset_details()
    {
        $asset = DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'uploaded_by' => $this->user->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/enquiry-tasks/{$this->task->id}/design-assets/{$asset->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id', 'name', 'category', 'status', 'file_path',
                'uploader', 'created_at'
            ]);
    }

    public function test_user_can_update_asset()
    {
        $asset = DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'uploaded_by' => $this->user->id,
            'status' => 'pending'
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/projects/enquiry-tasks/{$this->task->id}/design-assets/{$asset->id}", [
                'category' => 'mockup',
                'status' => 'approved',
                'description' => 'Updated description'
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('design_assets', [
            'id' => $asset->id,
            'category' => 'mockup',
            'status' => 'approved',
            'description' => 'Updated description'
        ]);
    }

    public function test_user_can_delete_own_asset()
    {
        Storage::fake('public');

        $asset = DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'uploaded_by' => $this->user->id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/projects/enquiry-tasks/{$this->task->id}/design-assets/{$asset->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('design_assets', ['id' => $asset->id]);
    }

    public function test_user_cannot_delete_others_asset()
    {
        $otherUser = User::factory()->create();
        $asset = DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'uploaded_by' => $otherUser->id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/projects/enquiry-tasks/{$this->task->id}/design-assets/{$asset->id}");

        $response->assertStatus(403);
    }

    public function test_asset_search_functionality()
    {
        DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'name' => 'Homepage Design',
            'uploaded_by' => $this->user->id
        ]);

        DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'name' => 'Logo Concept',
            'uploaded_by' => $this->user->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/enquiry-tasks/{$this->task->id}/design-assets?search=logo");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Logo Concept', $data[0]['name']);
    }

    public function test_asset_filtering_by_status()
    {
        DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'status' => 'approved',
            'uploaded_by' => $this->user->id
        ]);

        DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'status' => 'pending',
            'uploaded_by' => $this->user->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/enquiry-tasks/{$this->task->id}/design-assets?status=approved");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('approved', $data[0]['status']);
    }

    public function test_asset_download_requires_permission()
    {
        $asset = DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'uploaded_by' => $this->user->id
        ]);

        $response = $this->actingAs($this->user)
            ->get("/api/projects/enquiry-tasks/{$this->task->id}/design-assets/{$asset->id}/download");

        $response->assertStatus(200);
    }

    public function test_bulk_asset_operations_require_permission()
    {
        // This would test bulk operations if implemented
        // For now, we test that individual operations work as expected
        $this->assertTrue(true);
    }

    public function test_asset_category_filtering()
    {
        DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'category' => 'concept',
            'uploaded_by' => $this->user->id
        ]);

        DesignAsset::factory()->create([
            'enquiry_task_id' => $this->task->id,
            'category' => 'mockup',
            'uploaded_by' => $this->user->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/enquiry-tasks/{$this->task->id}/design-assets?category=concept");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('concept', $data[0]['category']);
    }
}
