<?php

namespace Database\Factories;

use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalManifestation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalManifestation>
 */
class FiscalManifestationFactory extends Factory
{
    protected $model = FiscalManifestation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'client_id' => Client::factory(),
            'chave_acesso' => str_repeat((string) fake()->randomDigit(), 44),
            'event_type' => FiscalManifestationEventType::CienciaEmissao,
            'event_seq' => 1,
            'requested_by' => 'job:SendFiscalManifestationJob',
            'outcome' => FiscalManifestationOutcome::Pending,
            'requested_at' => now(),
            'sent_at' => null,
            'resulted_at' => null,
        ];
    }
}
