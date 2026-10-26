<?php

namespace App\Models\Erp;

use App\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ErpSubscriptionTrial extends Model
{
    protected $fillable = [
        'erp_subscription_id', 'erp_company_id', 'account_id', 'cnpj', 'started_at', 'ends_at',
        'converted_at', 'expired_at', 'link_revision',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'converted_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
            'link_revision' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (self $trial): void {
            if ($trial->isDirty(['erp_subscription_id', 'erp_company_id', 'account_id', 'cnpj', 'started_at', 'ends_at', 'link_revision'])) {
                throw new LogicException('ERP subscription trial provenance and grant timestamps are immutable.');
            }

            foreach (['converted_at', 'expired_at'] as $marker) {
                if ($trial->getOriginal($marker) !== null && $trial->isDirty($marker)) {
                    throw new LogicException('ERP subscription trial lifecycle markers cannot be changed once set.');
                }
            }
        });
        static::deleting(static function (): never {
            throw new LogicException('ERP subscription trial history is retained.');
        });
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ErpSubscription::class, 'erp_subscription_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
