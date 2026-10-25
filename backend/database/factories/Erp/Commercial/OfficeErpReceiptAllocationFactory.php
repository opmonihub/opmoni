<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpReceiptAllocation> */
class OfficeErpReceiptAllocationFactory extends Factory
{
    protected $model = OfficeErpReceiptAllocation::class;

    public function definition(): array
    {
        return [
            'receipt_id' => OfficeErpReceipt::factory(),
            'invoice_id' => OfficeErpInvoice::factory(),
            'account_id' => fn (array $attributes): int => (int) OfficeErpReceipt::query()->findOrFail($attributes['receipt_id'])->account_id,
            'amount' => '100.00',
            'operation_id' => null,
        ];
    }
}
