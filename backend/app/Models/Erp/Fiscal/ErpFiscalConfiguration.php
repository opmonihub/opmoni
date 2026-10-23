<?php

namespace App\Models\Erp\Fiscal;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpFiscalConfiguration extends Model
{
    protected $fillable = ['erp_company_id', 'erp_establishment_id', 'current_revision_id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(ErpEstablishment::class, 'erp_establishment_id');
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(ErpFiscalConfigurationRevision::class, 'current_revision_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ErpFiscalConfigurationRevision::class, 'erp_fiscal_configuration_id');
    }
}
