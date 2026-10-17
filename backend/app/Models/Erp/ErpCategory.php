<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpCategory extends Model
{
    /** @use HasFactory<ErpCategoryFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = ['erp_company_id', 'parent_id', 'name', 'status'];

    protected static function newFactory(): ErpCategoryFactory
    {
        return ErpCategoryFactory::new();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
