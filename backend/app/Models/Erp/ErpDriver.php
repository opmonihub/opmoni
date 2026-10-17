<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpDriverFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErpDriver extends Model
{
    /** @use HasFactory<ErpDriverFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = [
        'erp_company_id', 'name', 'tax_id', 'license_number', 'status',
    ];

    protected static function newFactory(): ErpDriverFactory
    {
        return ErpDriverFactory::new();
    }
}
