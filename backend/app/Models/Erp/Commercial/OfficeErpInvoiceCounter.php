<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use Database\Factories\Erp\Commercial\OfficeErpInvoiceCounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class OfficeErpInvoiceCounter extends Model
{
    /** @use HasFactory<OfficeErpInvoiceCounterFactory> */
    use HasFactory;

    protected $fillable = ['account_id', 'year', 'next_number'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'next_number' => 'integer'];
    }

    protected static function newFactory(): OfficeErpInvoiceCounterFactory
    {
        return OfficeErpInvoiceCounterFactory::new();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Reserve the next account/year document number. Callers that already lock a contract
     * must invoke this before locking or inserting invoices to preserve the billing lock order.
     */
    public static function reserveNumber(int $accountId, int $year): string
    {
        return DB::transaction(function () use ($accountId, $year): string {
            $timestamp = now();
            DB::table('office_erp_invoice_counters')->insertOrIgnore([
                'account_id' => $accountId,
                'year' => $year,
                'next_number' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $counter = self::query()
                ->where('account_id', $accountId)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();
            $nextNumber = (int) $counter->next_number;
            $counter->increment('next_number');

            return sprintf('ERP-%d-%06d', $year, $nextNumber);
        });
    }
}
