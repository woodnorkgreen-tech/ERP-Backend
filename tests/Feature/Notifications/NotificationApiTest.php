<?php

use App\Models\User;
use App\Models\Notification as LegacyNotification;
use App\Modules\Notifications\Models\AppNotification;
use App\Modules\Notifications\Models\UserDeviceToken;
use App\Modules\Notifications\Jobs\SendMailNotificationJob;
use App\Modules\Notifications\Jobs\SendMailToAddressNotificationJob;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Http\Controllers\LeaveRequestController;
use App\Modules\Notifications\Jobs\SendPushNotificationJob;
use App\Modules\Notifications\Mail\AppNotificationMail;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Logistics\Models\TripRequest;
use App\Modules\Logistics\Observers\TripRequestObserver;
use App\Modules\Production\Models\ProductionNcr;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Observers\ProductionNcrObserver;
use App\Modules\Production\Observers\WorkOrderObserver;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Observers\PurchaseOrderObserver;
use App\Modules\ProcurementStores\Observers\RequisitionObserver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    public function createApplication()
    {
        $app = require __DIR__ . '/../../../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::query()->create(['name' => 'Employee', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('Employee');

        Sanctum::actingAs($this->user);
    }

    public function test_user_can_list_count_open_read_and_star_notifications(): void
    {
        $first = AppNotification::query()->create([
            'user_id' => $this->user->id,
            'module' => 'hr',
            'type' => 'leave_request_submitted',
            'title' => 'Leave request submitted',
            'message' => 'A leave request needs review.',
            'data' => ['url' => '/hr/leave?request=1'],
            'urgency' => 'info',
        ]);

        AppNotification::query()->create([
            'user_id' => $this->user->id,
            'module' => 'projects',
            'type' => 'leave_request_submitted',
            'title' => 'Other module',
            'message' => 'Should not appear in HR filter.',
            'urgency' => 'info',
        ]);

        $this->getJson('/api/notifications?module=hr')
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('count', 2);

        $this->getJson("/api/notifications/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        $this->assertDatabaseHas('app_notifications', [
            'id' => $first->id,
            'is_read' => true,
        ]);

        $this->postJson("/api/notifications/{$first->id}/star")
            ->assertOk()
            ->assertJsonPath('data.is_starred', true);

        $this->postJson('/api/notifications/read-all', ['module' => 'projects'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $this->user->id,
            'module' => 'projects',
            'is_read' => false,
        ]);
    }

    public function test_user_can_update_preferences_including_future_whatsapp_channel(): void
    {
        $this->putJson('/api/notifications/preferences', [
            'preferences' => [
                [
                    'type' => 'leave_request_submitted',
                    'channel' => 'database',
                    'enabled' => true,
                ],
                [
                    'type' => 'leave_request_submitted',
                    'channel' => 'whatsapp',
                    'enabled' => false,
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('app_notification_preferences', [
            'user_id' => $this->user->id,
            'type' => 'leave_request_submitted',
            'channel' => 'database',
            'enabled' => true,
        ]);

        $this->assertDatabaseHas('app_notification_preferences', [
            'user_id' => $this->user->id,
            'type' => 'leave_request_submitted',
            'channel' => 'whatsapp',
            'enabled' => false,
        ]);

        $this->getJson('/api/notifications/preferences')
            ->assertOk()
            ->assertJsonPath('implemented_channels.0', 'database');
    }

    public function test_user_can_register_android_device_token(): void
    {
        $this->postJson('/api/user/device-token', [
            'player_id' => 'onesignal-player-id',
            'platform' => 'android',
        ])->assertCreated()
            ->assertJsonPath('data.player_id', 'onesignal-player-id')
            ->assertJsonPath('data.platform', 'android');

        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $this->user->id,
            'player_id' => 'onesignal-player-id',
            'platform' => 'android',
        ]);

        $this->postJson('/api/user/device-token', [
            'player_id' => 'onesignal-player-id',
            'platform' => 'web',
        ])->assertCreated();

        $this->assertSame(1, UserDeviceToken::query()->where('user_id', $this->user->id)->count());
        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $this->user->id,
            'player_id' => 'onesignal-player-id',
            'platform' => 'web',
        ]);
    }

    public function test_service_deduplicates_role_permission_and_explicit_recipients(): void
    {
        Queue::fake();
        Permission::query()->create(['name' => 'notifications.receive', 'guard_name' => 'web']);
        $this->user->givePermissionTo('notifications.receive');

        config()->set('notifications.types.test_multichannel', [
            'module' => 'hr',
            'label' => 'Test Multichannel',
            'default_channels' => ['database', 'mail', 'push'],
            'urgency' => 'info',
        ]);

        NotificationService::send(
            type: 'test_multichannel',
            title: 'One recipient',
            message: 'The same user matched three targeting modes.',
            module: 'hr',
            users: [$this->user],
            role: 'Employee',
            permission: 'notifications.receive',
        );

        $this->assertDatabaseCount('app_notifications', 1);
        Queue::assertPushed(SendMailNotificationJob::class, 1);
        Queue::assertPushed(SendPushNotificationJob::class, 1);
    }

    public function test_explicit_channel_preference_overrides_registry_default(): void
    {
        Queue::fake();

        config()->set('notifications.types.test_preference', [
            'module' => 'hr',
            'label' => 'Test Preference',
            'default_channels' => ['database', 'mail'],
            'urgency' => 'info',
        ]);

        $this->user->appNotificationPreferences()->create([
            'type' => 'test_preference',
            'channel' => 'mail',
            'enabled' => false,
        ]);

        NotificationService::send(
            type: 'test_preference',
            title: 'Preference test',
            message: 'Mail should be disabled.',
            module: 'hr',
            users: [$this->user],
        );

        $this->assertDatabaseCount('app_notifications', 1);
        Queue::assertNotPushed(SendMailNotificationJob::class);
    }

    public function test_unread_count_cache_is_invalidated_by_model_changes(): void
    {
        Cache::flush();

        $this->getJson('/api/notifications/unread-count')->assertJsonPath('count', 0);

        AppNotification::query()->create([
            'user_id' => $this->user->id,
            'module' => 'hr',
            'type' => 'leave_request_submitted',
            'title' => 'Fresh notification',
            'message' => 'Cache should be invalidated.',
        ]);

        $this->getJson('/api/notifications/unread-count')->assertJsonPath('count', 1);
    }

    public function test_mail_job_delivers_to_the_users_email(): void
    {
        Mail::fake();
        $payload = ['title' => 'Mail test', 'message' => 'Delivered', 'urgency' => 'info'];

        (new SendMailNotificationJob($this->user->id, $payload))->handle();

        Mail::assertSent(AppNotificationMail::class, fn (AppNotificationMail $mail) =>
            $mail->hasTo($this->user->email) && $mail->payload['title'] === 'Mail test'
        );
    }

    public function test_mail_job_prefers_the_linked_employee_profile_email(): void
    {
        Mail::fake();
        $department = Department::query()->create(['name' => 'Mail Test']);
        $employee = Employee::query()->create([
            'employee_id' => 'EMP-MAIL-1',
            'first_name' => 'Mail',
            'last_name' => 'Recipient',
            'email' => 'employee-profile@test.local',
            'department_id' => $department->id,
            'position' => 'Tester',
            'hire_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $this->user->update(['employee_id' => $employee->id]);

        (new SendMailNotificationJob($this->user->id, ['title' => 'Mail test']))->handle();

        Mail::assertSent(AppNotificationMail::class, fn (AppNotificationMail $mail) =>
            $mail->hasTo('employee-profile@test.local')
        );
    }

    public function test_email_only_notification_job_supports_an_employee_without_a_user_account(): void
    {
        Mail::fake();

        (new SendMailToAddressNotificationJob('employee-only@test.local', ['title' => 'Leave approved']))->handle();

        Mail::assertSent(AppNotificationMail::class, fn (AppNotificationMail $mail) =>
            $mail->hasTo('employee-only@test.local')
        );
    }

    public function test_push_job_sends_to_all_registered_devices(): void
    {
        Http::fake(['*' => Http::response(['id' => 'push-id'], 200)]);
        config()->set('services.onesignal.app_id', 'test-app');
        config()->set('services.onesignal.rest_api_key', 'test-key');

        UserDeviceToken::query()->create([
            'user_id' => $this->user->id,
            'player_id' => 'android-device',
            'platform' => 'android',
        ]);
        UserDeviceToken::query()->create([
            'user_id' => $this->user->id,
            'player_id' => 'web-device',
            'platform' => 'web',
        ]);

        (new SendPushNotificationJob($this->user->id, [
            'title' => 'Push test',
            'message' => 'Two devices',
        ]))->handle();

        Http::assertSent(fn ($request) =>
            $request['app_id'] === 'test-app'
            && $request['include_player_ids'] === ['android-device', 'web-device']
        );
    }

    public function test_type_registry_only_exposes_accessible_modules(): void
    {
        $this->getJson('/api/notifications/types')
            ->assertOk()
            ->assertJsonMissingPath('data.production_work_order_assigned')
            ->assertJsonPath('data.leave_request_submitted.module', 'hr');

        Role::query()->create(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('Super Admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/notifications/types')
            ->assertOk()
            ->assertJsonPath('data.production_work_order_assigned.module', 'production')
            ->assertJsonPath('data.procurement_requisition_submitted.module', 'procurement-stores');
    }

    public function test_legacy_project_model_writes_to_universal_table(): void
    {
        $notification = LegacyNotification::query()->create([
            'user_id' => $this->user->id,
            'type' => 'quote_approved',
            'title' => 'Quote approved',
            'message' => 'Compatibility producers use the universal table.',
            'data' => ['enquiry_id' => 10],
        ]);

        $this->assertSame('projects', $notification->module);
        $this->assertDatabaseHas('app_notifications', [
            'id' => $notification->id,
            'module' => 'projects',
        ]);
    }

    public function test_module_observers_dispatch_registered_notification_types(): void
    {
        $types = [];
        $service = \Mockery::mock(NotificationService::class);
        $service->shouldReceive('dispatchNotification')
            ->times(7)
            ->andReturnUsing(function (...$arguments) use (&$types) {
                $types[] = $arguments[0];
                return collect();
            });
        $this->app->instance(NotificationService::class, $service);

        $trip = new TripRequest([
            'request_code' => 'TREQ-TEST',
            'priority' => 'urgent',
            'destination' => 'Nairobi',
            'status' => 'requested',
        ]);
        $trip->id = 1;
        (new TripRequestObserver())->created($trip);
        $trip->setRelation('requestedBy', null);
        $trip->setRelation('assignedDriver', null);
        $trip->syncOriginal();
        $trip->status = 'approved';
        $trip->syncChanges();
        (new TripRequestObserver())->updated($trip);

        $workOrder = new WorkOrder([
            'work_order_number' => 'WO-TEST',
            'title' => 'Test order',
            'status' => 'draft',
            'assigned_to' => $this->user->id,
            'created_by' => $this->user->id,
        ]);
        $workOrder->id = 2;
        (new WorkOrderObserver())->created($workOrder);
        $workOrder->syncOriginal();
        $workOrder->status = 'in_progress';
        $workOrder->syncChanges();
        (new WorkOrderObserver())->updated($workOrder);

        $ncr = new ProductionNcr([
            'ncr_number' => 'NCR-TEST',
            'description' => 'A test non-conformance.',
            'severity' => 'high',
            'owner_user_id' => $this->user->id,
        ]);
        $ncr->id = 3;
        (new ProductionNcrObserver())->created($ncr);

        $requisition = new Requisition([
            'requisition_number' => 'PR-TEST',
            'status' => 'draft',
            'urgency' => 'normal',
            'user_id' => $this->user->id,
        ]);
        $requisition->id = 4;
        $requisition->syncOriginal();
        $requisition->status = 'pending_approval';
        $requisition->syncChanges();
        (new RequisitionObserver())->updated($requisition);

        $purchaseOrder = new PurchaseOrder([
            'po_number' => 'PO-TEST',
            'status' => 'draft',
            'user_id' => $this->user->id,
        ]);
        $purchaseOrder->id = 5;
        $purchaseOrder->syncOriginal();
        $purchaseOrder->status = 'approved';
        $purchaseOrder->syncChanges();
        (new PurchaseOrderObserver())->updated($purchaseOrder);

        $this->assertEqualsCanonicalizing([
            'logistics_trip_requested',
            'logistics_trip_status_changed',
            'production_work_order_assigned',
            'production_work_order_status_changed',
            'production_ncr_raised',
            'procurement_requisition_submitted',
            'procurement_purchase_order_status_changed',
        ], $types);
    }

    public function test_leave_and_incident_types_deliver_through_expected_channels(): void
    {
        Queue::fake();

        NotificationService::send(
            type: 'leave_request_submitted',
            title: 'Leave request submitted',
            message: 'A leave request needs review.',
            module: 'hr',
            users: [$this->user],
        );

        NotificationService::send(
            type: 'incident_reported',
            title: 'Incident reported',
            message: 'A high-severity incident needs review.',
            module: 'hr',
            users: [$this->user],
        );

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->user->id,
            'type' => 'leave_request_submitted',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->user->id,
            'type' => 'incident_reported',
        ]);
        Queue::assertPushed(SendMailNotificationJob::class, 1);
    }

    public function test_leave_submission_notifications_are_scoped_to_local_reviewers_and_hr(): void
    {
        Queue::fake();

        Role::query()->firstOrCreate(['name' => 'HR', 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => 'Stores', 'guard_name' => 'web']);

        $people = Department::query()->create(['name' => 'People']);
        $stores = Department::query()->create(['name' => 'Stores']);

        $peopleLead = Employee::query()->create([
            'employee_id' => 'EMP-LEAD',
            'first_name' => 'People',
            'last_name' => 'Lead',
            'email' => 'people-lead@test.local',
            'department_id' => $people->id,
            'position' => 'Lead',
            'hire_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $people->update(['manager_id' => $peopleLead->id]);

        $directManager = Employee::query()->create([
            'employee_id' => 'EMP-DIRECT-MGR',
            'first_name' => 'Direct',
            'last_name' => 'Manager',
            'email' => 'direct-manager@test.local',
            'department_id' => $people->id,
            'position' => 'Line Manager',
            'hire_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $applicant = Employee::query()->create([
            'employee_id' => 'EMP-APP',
            'first_name' => 'Leave',
            'last_name' => 'Applicant',
            'email' => 'applicant@test.local',
            'department_id' => $people->id,
            'position' => 'Officer',
            'hire_date' => now()->toDateString(),
            'status' => 'active',
            'manager_id' => $directManager->id,
        ]);

        $storesLead = Employee::query()->create([
            'employee_id' => 'EMP-STORES',
            'first_name' => 'Stores',
            'last_name' => 'Lead',
            'email' => 'stores-lead@test.local',
            'department_id' => $stores->id,
            'position' => 'Stores Lead',
            'hire_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $stores->update(['manager_id' => $storesLead->id]);

        $hrEmployee = Employee::query()->create([
            'employee_id' => 'EMP-HR',
            'first_name' => 'HR',
            'last_name' => 'Reviewer',
            'email' => 'hr@test.local',
            'department_id' => $people->id,
            'position' => 'HR',
            'hire_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $peopleLeadUser = User::factory()->create(['is_active' => true, 'employee_id' => $peopleLead->id]);
        $peopleLeadUser->assignRole('Manager');
        $directManagerUser = User::factory()->create(['is_active' => true, 'employee_id' => $directManager->id]);
        $directManagerUser->assignRole('Manager');
        $storesLeadUser = User::factory()->create(['is_active' => true, 'employee_id' => $storesLead->id]);
        $storesLeadUser->assignRole(['Manager', 'Stores']);
        $hrUser = User::factory()->create(['is_active' => true, 'employee_id' => $hrEmployee->id]);
        $hrUser->assignRole('HR');
        $applicantUser = User::factory()->create(['is_active' => true, 'employee_id' => $applicant->id]);
        $applicantUser->assignRole('Employee');
        $adminUser = User::factory()->create(['is_active' => true]);
        $adminUser->assignRole('Admin');

        $leaveType = LeaveType::query()->where('code', 'ANNUAL')->firstOrFail();
        $leaveRequest = LeaveRequest::query()->create([
            'employee_id' => $applicant->id,
            'leave_type_id' => $leaveType->id,
            'created_by' => $applicantUser->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'days_requested' => 1,
            'status' => LeaveRequest::STATUS_PENDING,
            'reason' => 'Annual leave',
        ]);

        $method = new ReflectionMethod(LeaveRequestController::class, 'sendManagerNotifications');
        $method->setAccessible(true);
        $method->invoke(null, $leaveRequest);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $peopleLeadUser->id,
            'type' => 'leave_request_submitted',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $directManagerUser->id,
            'type' => 'leave_request_submitted',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $hrUser->id,
            'type' => 'leave_request_submitted',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $adminUser->id,
            'type' => 'leave_request_submitted',
        ]);
        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $storesLeadUser->id,
            'type' => 'leave_request_submitted',
        ]);
        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $applicantUser->id,
            'type' => 'leave_request_submitted',
        ]);
    }

    public function test_branded_mail_template_renders_notification_content(): void
    {
        $html = (new AppNotificationMail([
            'title' => 'Critical incident',
            'message' => 'Immediate review is required.',
            'urgency' => 'critical',
            'data' => ['url' => '/hr/incidents/1'],
        ]))->render();

        $this->assertStringContainsString('Critical incident', $html);
        $this->assertStringContainsString('Immediate review is required.', $html);
        $this->assertStringContainsString('#EF4444', $html);
    }
}
