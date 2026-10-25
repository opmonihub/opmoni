<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use Database\Factories\Erp\Commercial\OfficeErpReceiptReversalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficeErpReceiptReversal extends Model
{
    /** @use HasFactory<OfficeErpReceiptReversalFactory> */
    use HasFactory;

    protected $fillable = ['account_id', 'receipt_id', 'reason', 'reversed_by_user_id', 'operation_id', 'reversed_at'];

    protected function casts(): array
    {
        return ['reversed_at' => 'datetime'];
    }

    protected static function newFactory(): OfficeErpReceiptReversalFactory
    {
        return OfficeErpReceiptReversalFactory::new();
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(OfficeErpReceipt::class, 'receipt_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
