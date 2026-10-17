<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpPriceGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErpPriceGroup extends Model
{
    /** @use HasFactory<ErpPriceGroupFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = [
        'erp_company_id', 'name', 'is_default', 'percentage_adjustment', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'percentage_adjustment' => 'decimal:4',
        ];
    }

    protected static function newFactory(): ErpPriceGroupFactory
    {
        return ErpPriceGroupFactory::new();
    }
}
