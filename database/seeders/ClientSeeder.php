<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\ClientService\Models\Client;

class ClientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $clients = [
            [
                'full_name' => 'John Smith',
                'contact_person' => 'John Smith',
                'email' => 'john.smith@company.com',
                'phone' => '+254712345678',
                'alt_contact' => null,
                'address' => '123 Business Park',
                'city' => 'Nairobi',
                'county' => 'Nairobi',
                'postal_address' => 'P.O. Box 12345-00100',
                'customer_type' => 'company',
                'lead_source' => 'referral',
                'preferred_contact' => 'email',
                'industry' => 'Technology',
                'registration_date' => now()->subDays(30),
                'status' => 'active',
                'company_name' => 'Tech Solutions Ltd',
                'is_active' => true,
            ],
            [
                'full_name' => 'Sarah Johnson',
                'contact_person' => 'Sarah Johnson',
                'email' => 'sarah.johnson@email.com',
                'phone' => '+254723456789',
                'alt_contact' => null,
                'address' => '456 Residential Area',
                'city' => 'Nairobi',
                'county' => 'Nairobi',
                'postal_address' => 'P.O. Box 23456-00100',
                'customer_type' => 'individual',
                'lead_source' => 'website',
                'preferred_contact' => 'phone',
                'industry' => null,
                'registration_date' => now()->subDays(15),
                'status' => 'active',
                'company_name' => null,
                'is_active' => true,
            ],
            [
                'full_name' => 'Michael Brown',
                'contact_person' => 'Michael Brown',
                'email' => 'michael.brown@corp.com',
                'phone' => '+254734567890',
                'alt_contact' => '+254734567891',
                'address' => '789 Industrial Zone',
                'city' => 'Nairobi',
                'county' => 'Nairobi',
                'postal_address' => 'P.O. Box 34567-00100',
                'customer_type' => 'company',
                'lead_source' => 'direct',
                'preferred_contact' => 'email',
                'industry' => 'Events',
                'registration_date' => now()->subDays(60),
                'status' => 'active',
                'company_name' => 'Global Events Inc',
                'is_active' => true,
            ],
            [
                'full_name' => 'Emma Davis',
                'contact_person' => 'Emma Davis',
                'email' => 'emma.davis@personal.com',
                'phone' => '+254745678901',
                'alt_contact' => null,
                'address' => '321 Uptown Plaza',
                'city' => 'Nairobi',
                'county' => 'Nairobi',
                'postal_address' => 'P.O. Box 45678-00100',
                'customer_type' => 'individual',
                'lead_source' => 'social_media',
                'preferred_contact' => 'phone',
                'industry' => null,
                'registration_date' => now()->subDays(7),
                'status' => 'active',
                'company_name' => null,
                'is_active' => true,
            ],
            [
                'full_name' => 'David Wilson',
                'contact_person' => 'David Wilson',
                'email' => 'david.wilson@startup.com',
                'phone' => '+254756789012',
                'alt_contact' => null,
                'address' => '654 Tech Hub',
                'city' => 'Nairobi',
                'county' => 'Nairobi',
                'postal_address' => 'P.O. Box 56789-00100',
                'customer_type' => 'company',
                'lead_source' => 'networking',
                'preferred_contact' => 'email',
                'industry' => 'Technology',
                'registration_date' => now()->subDays(45),
                'status' => 'active',
                'company_name' => 'Innovate Kenya',
                'is_active' => true,
            ],
        ];

        foreach ($clients as $clientData) {
            \App\Modules\ClientService\Models\Client::create($clientData);
        }

        $this->command->info('Sample clients seeded successfully!');
    }
}
