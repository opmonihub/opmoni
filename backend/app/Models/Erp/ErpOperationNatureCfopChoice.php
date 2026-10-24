<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpOperationNatureCfopChoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpOperationNatureCfopChoice extends Model
{
    /** @use HasFactory<ErpOperationNatureCfopChoiceFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = ['erp_company_id', 'erp_operation_nature_id', 'cfop', 'reference_version', 'status'];

    public function nature(): BelongsTo
    {
        return $this->belongsTo(ErpOperationNature::class, 'erp_operation_nature_id');
    }
}
