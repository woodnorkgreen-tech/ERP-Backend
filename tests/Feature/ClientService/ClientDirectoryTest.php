<?php

namespace Tests\Feature\ClientService;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::findOrCreate('Client Service', 'web');
        foreach ([Permissions::CLIENT_CREATE, Permissions::CLIENT_READ, Permissions::CLIENT_UPDATE, Permissions::CLIENT_DELETE] as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        Sanctum::actingAs($user);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Grace Wanjiku',
            'company_name' => null,
            'contact_person' => 'Grace Wanjiku',
            'email' => 'grace@example.com',
            'phone' => '+254712345678',
            'address' => '12 Riverside Drive',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'individual',
            'lead_source' => 'Referral',
            'preferred_contact' => 'email',
            'registration_date' => '2026-01-15',
        ], $overrides);
    }

    public function test_a_company_is_stored_under_its_trading_name_while_the_contact_keeps_theirs(): void
    {
        $this->postJson('/api/clientservice/clients', $this->payload([
            'customer_type' => 'company',
            'company_name' => 'Bright Events Ltd',
            'contact_person' => 'Grace Wanjiku',
            'full_name' => '',
        ]))->assertCreated();

        $client = Client::firstOrFail();
        $this->assertSame('Bright Events Ltd', $client->full_name);
        $this->assertSame('Bright Events Ltd', $client->company_name);
        $this->assertSame('Grace Wanjiku', $client->contact_person);
    }

    public function test_organization_is_no_longer_an_accepted_client_type(): void
    {
        $this->postJson('/api/clientservice/clients', $this->payload([
            'customer_type' => 'organization',
            'company_name' => 'Bright Events Ltd',
            'full_name' => '',
        ]))->assertUnprocessable()->assertJsonValidationErrors('customer_type');
    }

    public function test_an_individual_never_carries_a_company_name(): void
    {
        $this->postJson('/api/clientservice/clients', $this->payload([
            'customer_type' => 'individual',
            'company_name' => 'Left over from a previous selection',
        ]))->assertCreated();

        $this->assertNull(Client::firstOrFail()->company_name);
    }

    public function test_a_company_can_store_multiple_contact_persons_with_one_primary_contact(): void
    {
        $response = $this->postJson('/api/clientservice/clients', $this->payload([
            'customer_type' => 'company',
            'company_name' => 'Bright Events Ltd',
            'full_name' => '',
            'contact_person' => 'Grace Wanjiku',
            'contacts' => [
                ['name' => 'Grace Wanjiku', 'email' => 'grace@example.com', 'phone' => '+254712345678', 'is_primary' => true],
                ['name' => 'Peter Otieno', 'email' => 'peter@example.com', 'phone' => '+254722222222', 'job_title' => 'Procurement Lead', 'is_primary' => false],
            ],
        ]))->assertCreated();

        $client = Client::firstOrFail();
        $this->assertCount(2, $client->contacts);
        $this->assertSame('Grace Wanjiku', $client->contacts->firstWhere('is_primary', true)->name);
        $this->assertSame('email', $client->preferred_contact);
        $this->assertSame('Procurement Lead', $client->contacts->firstWhere('name', 'Peter Otieno')->job_title);
        $response->assertJsonPath('data.contacts.1.name', 'Peter Otieno');
    }

    public function test_each_client_type_requires_only_the_name_that_applies_to_it(): void
    {
        $this->postJson('/api/clientservice/clients', $this->payload([
            'customer_type' => 'company',
            'company_name' => '',
            'full_name' => '',
        ]))->assertUnprocessable()->assertJsonValidationErrors('company_name');

        $this->postJson('/api/clientservice/clients', $this->payload([
            'customer_type' => 'individual',
            'full_name' => '',
        ]))->assertUnprocessable()->assertJsonValidationErrors('full_name');
    }

    public function test_a_client_can_be_saved_without_its_own_email_being_reported_as_taken(): void
    {
        $client = Client::factory()->create([
            'email' => 'repeat@example.com',
            'customer_type' => 'individual',
        ]);

        $this->putJson("/api/clientservice/clients/{$client->id}", $this->payload([
            'email' => 'repeat@example.com',
            'city' => 'Mombasa',
        ]))->assertOk();

        $this->assertSame('Mombasa', $client->fresh()->city);
    }

    public function test_active_state_is_not_writable_through_the_edit_form(): void
    {
        $client = Client::factory()->create([
            'customer_type' => 'individual',
            'status' => 'active',
            'is_active' => true,
        ]);

        // Sending `status` used to change it here while leaving is_active
        // untouched, so the two columns disagreed from then on.
        $this->putJson("/api/clientservice/clients/{$client->id}", $this->payload([
            'email' => $client->email,
            'status' => 'inactive',
        ]))->assertOk();

        $client->refresh();
        $this->assertSame('active', $client->status);
        $this->assertTrue((bool) $client->is_active);
    }

    public function test_toggling_status_moves_both_columns_together(): void
    {
        $client = Client::factory()->create(['status' => 'active', 'is_active' => true]);

        $this->patchJson("/api/clientservice/clients/{$client->id}/toggle-status")->assertOk();

        $client->refresh();
        $this->assertSame('inactive', $client->status);
        $this->assertFalse((bool) $client->is_active);
    }

    public function test_a_client_with_enquiries_cannot_be_deleted(): void
    {
        $client = Client::factory()->create();
        $client->enquiries()->create([
            'title' => 'Roadshow branding',
            'client_id' => $client->id,
            'date_received' => now()->toDateString(),
            'status' => 'enquiry_logged',
            'enquiry_number' => 'ENQ-TEST-0001',
            'contact_person' => 'Grace Wanjiku',
            'created_by' => User::factory()->create()->id,
        ]);

        $this->deleteJson("/api/clientservice/clients/{$client->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This client has 1 enquiry record(s) and cannot be deleted. Set them to inactive instead.');

        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_a_client_with_no_history_can_be_deleted(): void
    {
        $client = Client::factory()->create();

        $this->deleteJson("/api/clientservice/clients/{$client->id}")->assertOk();
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }
}
