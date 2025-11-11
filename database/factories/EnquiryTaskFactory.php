<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Modules\Projects\Models\EnquiryTask;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Projects\Models\EnquiryTask>
 */
class EnquiryTaskFactory extends Factory
{
    protected $model = EnquiryTask::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_enquiry_id' => 1, // Use existing enquiry ID to avoid factory issues
            'department_id' => 1, // Use existing department ID
            'title' => $this->faker->sentence(),
            'task_description' => $this->faker->paragraph(),
            'status' => 'pending',
            'assigned_user_id' => 1, // Use existing user ID
            'priority' => $this->faker->randomElement(['low', 'medium', 'high', 'urgent']),
            'estimated_hours' => $this->faker->numberBetween(1, 100),
            'due_date' => $this->faker->dateTimeBetween('now', '+30 days'),
            'task_order' => $this->faker->numberBetween(1, 10),
            'created_by' => 1, // Use existing user ID
            'type' => $this->faker->randomElement(['site-survey', 'design', 'materials', 'budget', 'quote', 'production', 'logistics', 'stores', 'project_management']),
        ];
    }
}
