<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Illuminate\Database\Eloquent\Model;

class ErpCompanyProductTaxDefault extends Model
{
    use BelongsToErpCompany;

    protected $fillable = ['erp_company_id', 'erp_product_tax_profile_id', 'revision'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'erp_product_tax_profile_id' => 'integer'];
    }
}
