<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use Database\Factories\Erp\Commercial\OfficeErpContractRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OfficeErpContractRevision extends Model
{
    /** @use HasFactory<OfficeErpContractRevisionFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id', 'contract_id', 'revision_number', 'price', 'currency', 'interval_months', 'due_in_days',
        'module_keys', 'service_period_start', 'service_period_end', 'reason', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'module_keys' => 'array',
            'service_period_start' => 'date',
            'service_period_end' => 'date',
        ];
    }

    protected static function newFactory(): OfficeErpContractRevisionFactory
    {
        return OfficeErpContractRevisionFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Office ERP contract revisions are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Office ERP contract revisions are immutable.');
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(OfficeErpContract::class, 'contract_id');
    }
}
