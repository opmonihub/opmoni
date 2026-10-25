<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use Database\Factories\Erp\Commercial\OfficeErpReceiptAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficeErpReceiptAllocation extends Model
{
    /** @use HasFactory<OfficeErpReceiptAllocationFactory> */
    use HasFactory;

    protected $fillable = ['account_id', 'receipt_id', 'invoice_id', 'amount', 'operation_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function newFactory(): OfficeErpReceiptAllocationFactory
    {
        return OfficeErpReceiptAllocationFactory::new();
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(OfficeErpReceipt::class, 'receipt_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(OfficeErpInvoice::class, 'invoice_id');
    }
}
