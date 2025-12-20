<?php

namespace Database\Factories\Modules\UniversalTask;

use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

class DepartmentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Department::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->company,
            'description' => $this->faker->sentence,
            'budget' => $this->faker->randomFloat(2, 10000, 1000000),
            'location' => $this->faker->city,
        ];
    }
}