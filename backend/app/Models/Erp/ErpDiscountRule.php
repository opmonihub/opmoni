<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpDiscountRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErpDiscountRule extends Model
{
    /** @use HasFactory<ErpDiscountRuleFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = [
        'erp_company_id', 'name', 'kind', 'value', 'coupon_code', 'conditions', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'conditions' => 'array',
        ];
    }

    protected static function newFactory(): ErpDiscountRuleFactory
    {
        return ErpDiscountRuleFactory::new();
    }
}
