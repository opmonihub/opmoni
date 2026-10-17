<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErpContact extends Model
{
    /** @use HasFactory<ErpContactFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = [
        'erp_company_id', 'name', 'trade_name', 'tax_id', 'email', 'phone',
        'is_customer', 'is_supplier', 'address', 'notes', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_customer' => 'boolean',
            'is_supplier' => 'boolean',
            'address' => 'array',
        ];
    }

    protected static function newFactory(): ErpContactFactory
    {
        return ErpContactFactory::new();
    }
}
