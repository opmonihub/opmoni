<?php

namespace Database\Factories\Erp\Finance;

use App\Models\Erp\Finance\ErpCashMovement;
use App\Models\Erp\Finance\ErpCashSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpCashMovement> */
class ErpCashMovementFactory extends Factory
{
    protected $model = ErpCashMovement::class;

    public function definition(): array
    {
        return ['direction' => 'in', 'kind' => 'deposit', 'amount' => '1.0000', 'reason' => 'Suprimento de teste', 'source_operation_key' => 'fixture-'.fake()->uuid(), 'posted_by_user_id' => User::factory(), 'posted_at' => now()];
    }

    public function forSession(ErpCashSession $session): static
    {
        return $this->state(fn (): array => ['erp_company_id' => $session->erp_company_id, 'erp_establishment_id' => $session->erp_establishment_id, 'erp_cash_register_id' => $session->erp_cash_register_id, 'erp_cash_session_id' => $session->id, 'currency' => $session->currency]);
    }
}
