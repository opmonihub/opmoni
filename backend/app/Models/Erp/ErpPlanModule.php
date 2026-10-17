<?php

namespace App\Models\Erp;

use Database\Factories\Erp\ErpPlanModuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpPlanModule extends Model
{
    /** @use HasFactory<ErpPlanModuleFactory> */
    use HasFactory;

    protected $fillable = ['erp_plan_id', 'module_key', 'quota'];

    protected static function newFactory(): ErpPlanModuleFactory
    {
        return ErpPlanModuleFactory::new();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ErpPlan::class, 'erp_plan_id');
    }
}
