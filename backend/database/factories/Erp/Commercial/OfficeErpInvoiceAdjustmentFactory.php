<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpInvoiceAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpInvoiceAdjustment> */
class OfficeErpInvoiceAdjustmentFactory extends Factory
{
    protected $model = OfficeErpInvoiceAdjustment::class;

    public function definition(): array
    {
        return [
            'invoice_id' => OfficeErpInvoice::factory(),
            'account_id' => fn (array $attributes): int => (int) OfficeErpInvoice::query()->findOrFail($attributes['invoice_id'])->account_id,
            'direction' => 'debit',
            'amount' => '5.00',
            'reason' => 'Ajuste de teste',
            'correction_of_id' => null,
            'operation_id' => null,
            'posted_by_user_id' => null,
            'posted_at' => now(),
        ];
    }
}
