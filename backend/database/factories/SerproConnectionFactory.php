<?php

namespace Database\Factories;

use App\Models\SerproConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<SerproConnection>
 */
class SerproConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'consumer_key' => fake()->unique()->bothify('??##########?????'),
            'consumer_secret_encrypted' => Crypt::encryptString(fake()->unique()->sha256()),
            'certificate_encrypted' => null,
            'certificate_password_encrypted' => null,
            'certificate_subject' => fake()->name().' :'.fake()->numerify('##############'),
            'certificate_serial_number' => fake()->unique()->bothify('??##########'),
            'certificate_valid_from' => now()->subMonths(6),
            'certificate_valid_until' => now()->addMonths(6),
            'contratante_numero' => '12345678000195',
            'contratante_tipo' => 2,
        ];
    }
}
