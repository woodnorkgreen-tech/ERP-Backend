<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Projects\Actions\ActivateProjectAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for the 2026-07-21 fix to ActivateProjectAction:
 * previously looped over literally every user account writing
 * App\Models\Notification rows directly — spamming HR/warehouse-only staff
 * with project news, and only ever creating the in-app row, silently
 * skipping mail/push regardless of the recipient's preferences. Now routes
 * through NotificationService::sendProjectActivated() -> the central
 * engine's all:true broadcast, which correctly scopes to users with
 * projects-module visibility (NotificationService::userCanSeeModule()).
 */
class ActivateProjectNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_activating_a_project_notifies_users_with_projects_module_access(): void
    {
        $activator = $this->user('Manager');
        $enquiry = $this->enquiry(['created_by' => $activator->id]);

        app(ActivateProjectAction::class)->execute($enquiry, $activator);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $activator->id,
            'type' => 'project_activated',
        ]);
    }

    public function test_activating_a_project_does_not_spam_users_without_projects_module_access(): void
    {
        $activator = $this->user('Manager');
        $unrelatedUser = $this->user(); // no role — e.g. HR- or warehouse-only staff

        $enquiry = $this->enquiry(['created_by' => $activator->id]);

        app(ActivateProjectAction::class)->execute($enquiry, $activator);

        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $unrelatedUser->id,
            'type' => 'project_activated',
        ]);
    }

    public function test_activating_a_project_creates_the_project_record(): void
    {
        $activator = $this->user();
        $enquiry = $this->enquiry(['created_by' => $activator->id]);

        app(ActivateProjectAction::class)->execute($enquiry, $activator);

        $this->assertDatabaseHas('projects', ['enquiry_id' => $enquiry->id]);
        $this->assertDatabaseHas('project_enquiries', [
            'id' => $enquiry->id,
            'status' => EnquiryConstants::STATUS_PLANNING,
        ]);
    }

    private function user(?string $role = null): User
    {
        $user = User::create([
            'name' => uniqid('user_'),
            'email' => uniqid('user_') . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);

        if ($role) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    private function client(): Client
    {
        return Client::create([
            'full_name' => 'Acme Test Client',
            'contact_person' => 'Jane Test',
            'email' => uniqid('client_') . '@test.local',
            'phone' => '0700000000',
            'address' => '123 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'company',
            'lead_source' => 'test',
            'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(),
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    private function enquiry(array $overrides = []): ProjectEnquiry
    {
        $creator = $overrides['created_by'] ?? $this->user()->id;

        return ProjectEnquiry::create(array_merge([
            'date_received' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(30)->toDateString(),
            'client_id' => $this->client()->id,
            'title' => 'Activation Test Project',
            'description' => 'Test project',
            'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => EnquiryConstants::STATUS_ENQUIRY_LOGGED,
            'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-TEST-' . uniqid(),
            'job_number' => 'WNG-TEST-' . uniqid(),
            'created_by' => $creator,
            'workflow_preset_type' => 'external_project',
        ], $overrides));
    }
}
