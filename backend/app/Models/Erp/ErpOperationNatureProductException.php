<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpOperationNatureProductExceptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpOperationNatureProductException extends Model
{
    /** @use HasFactory<ErpOperationNatureProductExceptionFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = ['erp_company_id', 'erp_operation_nature_id', 'erp_product_id', 'erp_operation_nature_cfop_choice_id', 'status'];

    public function nature(): BelongsTo
    {
        return $this->belongsTo(ErpOperationNature::class, 'erp_operation_nature_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ErpProduct::class, 'erp_product_id');
    }

    public function choice(): BelongsTo
    {
        return $this->belongsTo(ErpOperationNatureCfopChoice::class, 'erp_operation_nature_cfop_choice_id');
    }
}
