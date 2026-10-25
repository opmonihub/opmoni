<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpInvoiceCounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpInvoiceCounter> */
class OfficeErpInvoiceCounterFactory extends Factory
{
    protected $model = OfficeErpInvoiceCounter::class;

    public function definition(): array
    {
        return ['account_id' => Account::factory(), 'year' => (int) now()->format('Y'), 'next_number' => 1];
    }
}
