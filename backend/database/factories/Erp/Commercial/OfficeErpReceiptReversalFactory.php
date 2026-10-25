<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptReversal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpReceiptReversal> */
class OfficeErpReceiptReversalFactory extends Factory
{
    protected $model = OfficeErpReceiptReversal::class;

    public function definition(): array
    {
        return [
            'receipt_id' => OfficeErpReceipt::factory(),
            'account_id' => fn (array $attributes): int => (int) OfficeErpReceipt::query()->findOrFail($attributes['receipt_id'])->account_id,
            'reason' => 'Estorno de teste',
            'reversed_by_user_id' => null,
            'operation_id' => null,
            'reversed_at' => now(),
        ];
    }
}
