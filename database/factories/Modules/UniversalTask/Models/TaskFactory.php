<?php

namespace Database\Factories\Modules\UniversalTask\Models;

use App\Modules\UniversalTask\Models\Task;
use App\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Database\Factories\Modules\UniversalTask\DepartmentFactory;

class TaskFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Task::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        // Get valid foreign key references
        $user = User::first() ?? User::factory()->create();
        $department = Department::first();
        
        // If we don't have a department, create a minimal one
        if (!$department) {
            $department = Department::create([
                'name' => 'Test Department',
                'description' => 'A test department for testing',
                'budget' => 100000,
                'location' => 'Test Location'
            ]);
        }
        
        return [
            'title' => $this->faker->sentence,
            'description' => $this->faker->paragraph,
            'task_type' => $this->faker->randomElement(['development', 'design', 'testing', 'deployment']),
            'status' => $this->faker->randomElement(['pending', 'in_progress', 'blocked', 'review', 'completed', 'cancelled']),
            'priority' => $this->faker->randomElement(['low', 'medium', 'high', 'urgent']),
            'department_id' => $department->id,
            'created_by' => $user->id,
            'assigned_user_id' => null,
            'estimated_hours' => $this->faker->randomFloat(2, 1, 100),
            'actual_hours' => $this->faker->randomFloat(2, 0, 100),
            'due_date' => $this->faker->dateTimeBetween('+1 week', '+1 month'),
            'tags' => [],
            'metadata' => [],
        ];
    }
}