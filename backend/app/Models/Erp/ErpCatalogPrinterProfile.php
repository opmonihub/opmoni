<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpCatalogPrinterProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErpCatalogPrinterProfile extends Model
{
    /** @use HasFactory<ErpCatalogPrinterProfileFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = ['erp_company_id', 'name', 'capability_profile', 'status'];

    protected static function newFactory(): ErpCatalogPrinterProfileFactory
    {
        return ErpCatalogPrinterProfileFactory::new();
    }
}
