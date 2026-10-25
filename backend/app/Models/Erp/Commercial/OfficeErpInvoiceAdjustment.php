<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use Database\Factories\Erp\Commercial\OfficeErpInvoiceAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficeErpInvoiceAdjustment extends Model
{
    /** @use HasFactory<OfficeErpInvoiceAdjustmentFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id', 'invoice_id', 'direction', 'amount', 'reason', 'correction_of_id', 'operation_id', 'posted_by_user_id', 'posted_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'posted_at' => 'datetime'];
    }

    protected static function newFactory(): OfficeErpInvoiceAdjustmentFactory
    {
        return OfficeErpInvoiceAdjustmentFactory::new();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(OfficeErpInvoice::class, 'invoice_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function correctionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'correction_of_id');
    }
}
