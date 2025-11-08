<?php

namespace Tests\Unit;

use App\Modules\ClientService\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_has_correct_fillable_attributes()
    {
        $client = new Client();

        $expectedFillable = [
            'full_name',
            'contact_person',
            'email',
            'phone',
            'alt_contact',
            'address',
            'city',
            'county',
            'postal_address',
            'customer_type',
            'lead_source',
            'preferred_contact',
            'industry',
            'registration_date',
            'status',
        ];

        $this->assertEquals($expectedFillable, $client->getFillable());
    }

    /** @test */
    public function it_has_correct_casts()
    {
        $client = new Client();

        $expectedCasts = [
            'id' => 'int',
            'registration_date' => 'date',
            'customer_type' => 'string',
            'preferred_contact' => 'string',
            'status' => 'string',
        ];

        $this->assertEquals($expectedCasts, $client->getCasts());
    }

    /** @test */
    public function client_validation_passes_with_valid_data()
    {
        $validData = [
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

        $validator = Validator::make($validData, [
            'full_name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'required|email|unique:clients,email',
            'phone' => 'required|string|max:20',
            'alt_contact' => 'nullable|string|max:20',
            'address' => 'required|string',
            'city' => 'required|string|max:255',
            'county' => 'required|string|max:255',
            'postal_address' => 'nullable|string|max:255',
            'customer_type' => 'required|in:individual,company,organization',
            'lead_source' => 'required|string|max:255',
            'preferred_contact' => 'required|in:email,phone,sms',
            'industry' => 'nullable|string|max:255',
            'registration_date' => 'required|date',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function client_validation_fails_with_missing_required_fields()
    {
        $invalidData = [
            // Missing required fields
        ];

        $validator = Validator::make($invalidData, [
            'full_name' => 'required|string|max:255',
            'email' => 'required|email|unique:clients,email',
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
            'city' => 'required|string|max:255',
            'county' => 'required|string|max:255',
            'customer_type' => 'required|in:individual,company,organization',
            'lead_source' => 'required|string|max:255',
            'preferred_contact' => 'required|in:email,phone,sms',
            'registration_date' => 'required|date',
        ]);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('full_name', $validator->errors()->toArray());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
        $this->assertArrayHasKey('phone', $validator->errors()->toArray());
        $this->assertArrayHasKey('address', $validator->errors()->toArray());
        $this->assertArrayHasKey('city', $validator->errors()->toArray());
        $this->assertArrayHasKey('county', $validator->errors()->toArray());
        $this->assertArrayHasKey('customer_type', $validator->errors()->toArray());
        $this->assertArrayHasKey('lead_source', $validator->errors()->toArray());
        $this->assertArrayHasKey('preferred_contact', $validator->errors()->toArray());
        $this->assertArrayHasKey('registration_date', $validator->errors()->toArray());
    }

    /** @test */
    public function client_validation_fails_with_invalid_email()
    {
        $invalidData = [
            'full_name' => 'Test Company Ltd',
            'contact_person' => 'John Doe',
            'email' => 'invalid-email',
            'phone' => '+254712345678',
            'address' => '123 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'company',
            'lead_source' => 'website',
            'preferred_contact' => 'email',
            'registration_date' => '2024-01-15',
        ];

        $validator = Validator::make($invalidData, [
            'email' => 'required|email|unique:clients,email',
        ]);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }

    /** @test */
    public function client_validation_fails_with_invalid_customer_type()
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

        $validator = Validator::make($invalidData, [
            'customer_type' => 'required|in:individual,company,organization',
        ]);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('customer_type', $validator->errors()->toArray());
    }

    /** @test */
    public function client_validation_fails_with_invalid_preferred_contact()
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

        $validator = Validator::make($invalidData, [
            'preferred_contact' => 'required|in:email,phone,sms',
        ]);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('preferred_contact', $validator->errors()->toArray());
    }

    /** @test */
    public function client_validation_fails_with_invalid_status()
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

        $validator = Validator::make($invalidData, [
            'status' => 'sometimes|in:active,inactive',
        ]);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('status', $validator->errors()->toArray());
    }

    /** @test */
    public function client_validation_fails_with_duplicate_email()
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
            'email' => 'existing@test.com', // Duplicate email
            'phone' => '+254798765432',
            'address' => '456 Test Street',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'customer_type' => 'company',
            'lead_source' => 'website',
            'preferred_contact' => 'email',
            'registration_date' => '2024-01-15',
        ];

        $validator = Validator::make($duplicateData, [
            'email' => 'required|email|unique:clients,email',
        ]);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }

    /** @test */
    public function client_validation_fails_with_string_too_long()
    {
        $longString = str_repeat('a', 256); // 256 characters, exceeds max:255

        $invalidData = [
            'full_name' => $longString,
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

        $validator = Validator::make($invalidData, [
            'full_name' => 'required|string|max:255',
        ]);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('full_name', $validator->errors()->toArray());
    }

    /** @test */
    public function client_validation_fails_with_invalid_date()
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
            'registration_date' => 'invalid-date',
        ];

        $validator = Validator::make($invalidData, [
            'registration_date' => 'required|date',
        ]);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('registration_date', $validator->errors()->toArray());
    }
}
