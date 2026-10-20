<?php

namespace Database\Factories\Erp\Stock;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\Stock\ErpPurchaseImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ErpPurchaseImport>
 */
class ErpPurchaseImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = ErpCompany::factory()->create();

        return [
            'erp_company_id' => $company->id,
            'actor_user_id' => User::factory(),
            'erp_establishment_id' => ErpEstablishment::factory()->for($company, 'company'),
            'token_digest' => hash('sha256', (string) Str::uuid()),
            'schema_version' => 'purchase-nfe-moc7-v1',
            'source_sha256' => hash('sha256', 'synthetic purchase source'),
            'source_byte_length' => 25,
            'source_access_key' => null,
            'source_snapshot' => [],
            'errors' => [],
            'expires_at' => now()->addHour(),
            'completed_at' => null,
        ];
    }
}
