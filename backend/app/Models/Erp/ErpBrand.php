<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpBrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErpBrand extends Model
{
    /** @use HasFactory<ErpBrandFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = ['erp_company_id', 'name', 'status'];

    protected static function newFactory(): ErpBrandFactory
    {
        return ErpBrandFactory::new();
    }
}
