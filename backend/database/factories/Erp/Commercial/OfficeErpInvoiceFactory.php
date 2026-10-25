<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpInvoice> */
class OfficeErpInvoiceFactory extends Factory
{
    protected $model = OfficeErpInvoice::class;

    public function definition(): array
    {
        $contractFactory = OfficeErpContract::factory()
            ->state(function (): array {
                $accountId = CurrentAccount::fromRequest()->account->id;

                return [
                    'account_id' => $accountId,
                    'client_id' => Client::factory()->company()->state(['account_id' => $accountId]),
                ];
            })
            ->afterCreating(function (OfficeErpContract $contract): void {
                $revision = OfficeErpContractRevision::factory()->create([
                    'account_id' => $contract->account_id,
                    'contract_id' => $contract->id,
                ]);
                $contract->forceFill(['current_revision_id' => $revision->id])->save();
            });

        return [
            'contract_id' => $contractFactory,
            'account_id' => fn (array $attributes): int => (int) OfficeErpContract::query()->findOrFail($attributes['contract_id'])->account_id,
            'contract_revision_id' => fn (array $attributes): int => (int) OfficeErpContract::query()->findOrFail($attributes['contract_id'])->current_revision_id,
            'kind' => 'manual',
            'document_status' => 'draft',
            'number' => null,
            'issued_at' => null,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonthNoOverflow()->toDateString(),
            'due_on' => now()->addDays(10)->toDateString(),
            'currency' => 'BRL',
            'seller_snapshot' => [],
            'bill_to_snapshot' => [],
            'items_snapshot' => [],
            'original_total' => '100.00',
        ];
    }
}
