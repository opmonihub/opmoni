<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCatalogImport;
use App\Models\Erp\ErpCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpCatalogImport> */
class ErpCatalogImportFactory extends Factory
{
    protected $model = ErpCatalogImport::class;

    public function definition(): array
    {
        return ['erp_company_id' => ErpCompany::factory(), 'actor_user_id' => User::factory(), 'kind' => 'products', 'schema_version' => 'products-v1', 'token_digest' => hash('sha256', fake()->uuid()), 'content_digest' => hash('sha256', 'fixture'), 'rows' => [], 'errors' => [], 'totals' => ['records' => 0, 'valid' => 0, 'invalid' => 0], 'expires_at' => now()->addHour()];
    }
}
