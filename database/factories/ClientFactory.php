<?php

namespace Database\Factories;

use App\Modules\ClientService\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'full_name' => $this->faker->company(),
            'contact_person' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'alt_contact' => $this->faker->optional()->phoneNumber(),
            'address' => $this->faker->address(),
            'city' => $this->faker->city(),
            'county' => $this->faker->state(),
            'postal_address' => $this->faker->optional()->address(),
            'customer_type' => $this->faker->randomElement(['individual', 'company', 'organization']),
            'lead_source' => $this->faker->word(),
            'preferred_contact' => $this->faker->randomElement(['email', 'phone', 'sms']),
            'industry' => $this->faker->optional()->word(),
            'registration_date' => $this->faker->date(),
            'status' => $this->faker->randomElement(['active', 'inactive']),
        ];
    }
}
