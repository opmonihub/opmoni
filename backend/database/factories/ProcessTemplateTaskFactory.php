<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Models\Account;
use App\Models\ProcessTemplate;
use App\Models\ProcessTemplateTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessTemplateTask>
 */
class ProcessTemplateTaskFactory extends Factory
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
            'template_id' => ProcessTemplate::factory(),
            'title' => fake()->words(3, true),
            'department_id' => null,
            'description' => fake()->optional()->sentence(),
            'due_day' => fake()->numberBetween(1, 28),
            'priority' => TaskPriority::Medium->value,
            'order' => 1,
            'default_assignee_member_id' => null,
        ];
    }
}
