<?php

namespace App\Models\Erp\Commercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpRecords;
use App\Models\Account;
use Database\Factories\Erp\Commercial\OfficeErpInvoiceCounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     * Reserve the next account/year document number. Callers must hold Account then contract
     * locks before invoking this, and reserve before locking or inserting invoices.
     */
    public static function reserveNumber(OfficeErpExecutionContext $ctx, int $year): string
    {
        $ctx->assertVerified();
        if ($year < 1 || $year > 9999) {
            throw ValidationException::withMessages(['year' => 'The invoice counter year is outside the supported range.']);
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return DB::transaction(function () use ($ctx, $year): string {
                    $query = OfficeErpRecords::query($ctx, self::class)->where('year', $year);
                    $counter = (clone $query)->lockForUpdate()->first();
                    if ($counter === null) {
                        $counter = OfficeErpRecords::persist($ctx, new self([
                            'account_id' => $ctx->accountId,
                            'year' => $year,
                            'next_number' => 1,
                        ]));
                        $counter = (clone $query)->lockForUpdate()->firstOrFail();
                    }

                    $nextNumber = (int) $counter->next_number;
                    $counter->next_number = $nextNumber + 1;
                    OfficeErpRecords::persist($ctx, $counter);

                    return sprintf('ERP-%d-%06d', $year, $nextNumber);
                });
            } catch (QueryException $exception) {
                if ($attempt > 0 || ! self::isCounterUniquenessConflict($exception)) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Unable to reserve an invoice number.');
    }

    private static function isCounterUniquenessConflict(QueryException $exception): bool
    {
        $state = $exception->errorInfo[0] ?? null;
        $message = strtolower($exception->getMessage());

        return in_array($state, ['23000', '23505'], true)
            && str_contains($message, 'office_erp_invoice_counters');
    }
}
