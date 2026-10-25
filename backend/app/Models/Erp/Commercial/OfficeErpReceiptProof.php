<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use App\Models\User;
use Database\Factories\Erp\Commercial\OfficeErpReceiptProofFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficeErpReceiptProof extends Model
{
    /** @use HasFactory<OfficeErpReceiptProofFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'account_id', 'actor_user_id', 'receipt_id', 'original_name', 'mime_type', 'byte_length', 'sha256',
        'path', 'expires_at', 'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'byte_length' => 'integer',
            'expires_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): OfficeErpReceiptProofFactory
    {
        return OfficeErpReceiptProofFactory::new();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(OfficeErpReceipt::class, 'receipt_id');
    }
}
