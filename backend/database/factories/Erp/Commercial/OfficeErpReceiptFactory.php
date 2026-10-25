<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpReceipt> */
class OfficeErpReceiptFactory extends Factory
{
    protected $model = OfficeErpReceipt::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'amount' => '100.00',
            'received_on' => now()->toDateString(),
            'reference' => null,
            'proof_path' => null,
            'method' => 'manual',
            'recorded_by_user_id' => null,
            'operation_id' => null,
        ];
    }
}
