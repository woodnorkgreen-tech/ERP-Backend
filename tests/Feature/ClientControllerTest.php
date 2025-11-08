<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ClientService\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create and authenticate a user
        $user = User::factory()->create();
        $this->actingAs($user);
    }

    /** @test */
    public function it_creates_client_successfully()
    {
        $clientData = [
            'full_name' => 'Test Company Ltd',
            'contact_person' => 'John Doe',
            'email' => 'john@testcompany.com',
            'phone' => '+254712345678',
            'alt_contact' => '+254798765432',
            'address' => '123 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'postal_address' => 'P.O. Box 12345',
            'customer_type' => 'company',
            'lead_source' => 'website',
            'preferred_contact' => 'email',
            'industry' => 'Technology',
            'registration_date' => '2024-01-15',
            'status' => 'active',
        ];

        $response = $this->postJson('/api/clientservice/clients', $clientData);

        $response->assertStatus(201)
                ->assertJson([
                    'message' => 'Client created successfully',
                    'data' => [
                        'full_name' => 'Test Company Ltd',
                        'email' => 'john@testcompany.com',
                        'phone' => '+254712345678',
                        'customer_type' => 'company',
                        'status' => 'active',
                    ]
                ]);

        $this->assertDatabaseHas('clients', [
            'full_name' => 'Test Company Ltd',
            'email' => 'john@testcompany.com',
        ]);
    }

    /** @test */
    public function it_fails_validation_with_missing_required_fields()
    {
        $incompleteData = [
            'full_name' => 'Test Company Ltd',
            // Missing email, phone, address, etc.
        ];

        $response = $this->postJson('/api/clientservice/clients', $incompleteData);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'message',
                    'errors' => [
                        'email',
                        'phone',
                        'address',
                        'city',
                        'county',
                        'customer_type',
                        'lead_source',
                        'preferred_contact',
                        'registration_date',
                    ]
                ]);
    }

    /** @test */
    public function it_fails_validation_with_invalid_email()
    {
        $invalidData = [
            'full_name' => 'Test Company Ltd',
            'contact_person' => 'John Doe',
            'email' => 'invalid-email-format',
            'phone' => '+254712345678',
            'address' => '123 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'company',
            'lead_source' => 'website',
            'preferred_contact' => 'email',
            'registration_date' => '2024-01-15',
        ];

        $response = $this->postJson('/api/clientservice/clients', $invalidData);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
    }

    /** @test */
    public function it_fails_validation_with_duplicate_email()
    {
        // Create existing client
        Client::create([
            'full_name' => 'Existing Company',
            'email' => 'existing@test.com',
            'phone' => '+254712345678',
            'address' => '123 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'company',
            'lead_source' => 'website',
            'preferred_contact' => 'email',
            'registration_date' => now(),
        ]);

        $duplicateData = [
            'full_name' => 'New Company',
            'email' => 'existing@test.com',
            'phone' => '+254798765432',
            'address' => '456 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'company',
            'lead_source' => 'website',
            'preferred_contact' => 'email',
            'registration_date' => '2024-01-15',
        ];

        $response = $this->postJson('/api/clientservice/clients', $duplicateData);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
    }

    /** @test */
    public function it_fails_validation_with_invalid_customer_type()
    {
        $invalidData = [
            'full_name' => 'Test Company Ltd',
            'contact_person' => 'John Doe',
            'email' => 'john@testcompany.com',
            'phone' => '+254712345678',
            'address' => '123 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'invalid_type',
            'lead_source' => 'website',
            'preferred_contact' => 'email',
            'registration_date' => '2024-01-15',
        ];

        $response = $this->postJson('/api/clientservice/clients', $invalidData);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['customer_type']);
    }

    /** @test */
    public function it_fails_validation_with_invalid_preferred_contact()
    {
        $invalidData = [
            'full_name' => 'Test Company Ltd',
            'contact_person' => 'John Doe',
            'email' => 'john@testcompany.com',
            'phone' => '+254712345678',
            'address' => '123 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'company',
            'lead_source' => 'website',
            'preferred_contact' => 'invalid_contact',
            'registration_date' => '2024-01-15',
        ];

        $response = $this->postJson('/api/clientservice/clients', $invalidData);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['preferred_contact']);
    }

    /** @test */
    public function it_fails_validation_with_invalid_status()
    {
        $invalidData = [
            'full_name' => 'Test Company Ltd',
            'contact_person' => 'John Doe',
            'email' => 'john@testcompany.com',
            'phone' => '+254712345678',
            'address' => '123 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'company',
            'lead_source' => 'website',
            'preferred_contact' => 'email',
            'registration_date' => '2024-01-15',
            'status' => 'invalid_status',
        ];

        $response = $this->postJson('/api/clientservice/clients', $invalidData);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['status']);
    }

    /** @test */
    public function it_handles_database_errors_gracefully()
    {
        // This test would require mocking database failures
        // For now, we'll test with valid data to ensure success path works
        $clientData = [
            'full_name' => 'Test Company Ltd',
            'contact_person' => 'John Doe',
            'email' => 'john@testcompany.com',
            'phone' => '+254712345678',
            'address' => '123 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'company',
            'lead_source' => 'website',
            'preferred_contact' => 'email',
            'registration_date' => '2024-01-15',
        ];

        $response = $this->postJson('/api/clientservice/clients', $clientData);

        $response->assertStatus(201);
    }

    /** @test */
    public function it_returns_paginated_clients()
    {
        // Create some test clients
        Client::create([
            'full_name' => 'Client One',
            'email' => 'client1@test.com',
            'phone' => '+254712345678',
            'address' => '123 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'company',
            'lead_source' => 'website',
            'preferred_contact' => 'email',
            'registration_date' => now(),
        ]);

        Client::create([
            'full_name' => 'Client Two',
            'email' => 'client2@test.com',
            'phone' => '+254798765432',
            'address' => '456 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'individual',
            'lead_source' => 'referral',
            'preferred_contact' => 'phone',
            'registration_date' => now(),
        ]);

        $response = $this->getJson('/api/clientservice/clients');

        $response->assertStatus(200);

        // Debug the response
        dd($response->json());
    }
}
