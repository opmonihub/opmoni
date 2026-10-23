<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpContactNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ErpContactNote>
 */
class ErpContactNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'erp_contact_id' => ErpContact::factory(),
            'erp_company_id' => fn (array $attributes): int => (int) ErpContact::query()
                ->whereKey($attributes['erp_contact_id'])
                ->value('erp_company_id'),
            'created_by' => User::factory(),
            'heading' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'is_private' => false,
            'state' => 'active',
        ];
    }
}
