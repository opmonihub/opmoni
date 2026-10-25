<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use Database\Factories\Erp\Commercial\OfficeErpReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OfficeErpReceipt extends Model
{
    /** @use HasFactory<OfficeErpReceiptFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id', 'amount', 'received_on', 'reference', 'proof_path', 'method', 'recorded_by_user_id', 'operation_id',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_on' => 'date:Y-m-d'];
    }

    protected static function newFactory(): OfficeErpReceiptFactory
    {
        return OfficeErpReceiptFactory::new();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(OfficeErpReceiptAllocation::class, 'receipt_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(OfficeErpReceiptReversal::class, 'receipt_id');
    }
}
