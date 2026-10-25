<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpReceiptAllocation> */
class OfficeErpReceiptAllocationFactory extends Factory
{
    protected $model = OfficeErpReceiptAllocation::class;

    public function definition(): array
    {
        return [
            'receipt_id' => OfficeErpReceipt::factory()->state(fn (): array => [
                'account_id' => CurrentAccount::fromRequest()->account->id,
            ]),
            'invoice_id' => OfficeErpInvoice::factory()->state(fn (): array => [
                'account_id' => CurrentAccount::fromRequest()->account->id,
            ]),
            'account_id' => fn (array $attributes): int => (int) OfficeErpReceipt::query()->findOrFail($attributes['receipt_id'])->account_id,
            'amount' => '100.00',
            'operation_id' => null,
        ];
    }

    public function forInvoice(OfficeErpInvoice $invoice): static
    {
        return $this->state([
            'account_id' => $invoice->account_id,
            'invoice_id' => $invoice->id,
        ]);
    }

    public function forReceipt(OfficeErpReceipt $receipt): static
    {
        return $this->state([
            'account_id' => $receipt->account_id,
            'receipt_id' => $receipt->id,
        ]);
    }
}
