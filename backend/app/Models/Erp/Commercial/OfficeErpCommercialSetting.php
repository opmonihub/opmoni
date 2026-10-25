<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use Database\Factories\Erp\Commercial\OfficeErpCommercialSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficeErpCommercialSetting extends Model
{
    /** @use HasFactory<OfficeErpCommercialSettingFactory> */
    use HasFactory;

    protected $fillable = ['account_id', 'delinquency_policy', 'grace_days', 'billing_timezone'];

    protected function casts(): array
    {
        return ['grace_days' => 'integer'];
    }

    protected static function newFactory(): OfficeErpCommercialSettingFactory
    {
        return OfficeErpCommercialSettingFactory::new();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
