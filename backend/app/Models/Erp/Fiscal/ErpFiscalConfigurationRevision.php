<?php

namespace App\Models\Erp\Fiscal;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpFiscalConfigurationRevision extends Model
{
    protected $fillable = [
        'erp_fiscal_configuration_id',
        'revision',
        'environment',
        'configuration',
        'configuration_sha256',
        'created_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }

    public function configurationOwner(): BelongsTo
    {
        return $this->belongsTo(ErpFiscalConfiguration::class, 'erp_fiscal_configuration_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
