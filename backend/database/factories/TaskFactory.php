<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Process;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'process_id' => Process::factory(),
            'title' => fake()->words(3, true),
            'department_id' => null,
            'description' => fake()->optional()->sentence(),
            'status' => TaskStatus::Todo->value,
            'due_on' => null,
            'priority' => TaskPriority::Medium->value,
            'assignee_member_id' => null,
            'completed_at' => null,
            'dismissal_reason' => null,
            'order' => 1,
        ];
    }
}
