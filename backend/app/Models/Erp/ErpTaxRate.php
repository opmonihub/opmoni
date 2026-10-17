<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpTaxRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpTaxRate extends Model
{
    /** @use HasFactory<ErpTaxRateFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = [
        'erp_company_id', 'erp_establishment_id', 'name', 'rate', 'tax_kind', 'metadata', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    protected static function newFactory(): ErpTaxRateFactory
    {
        return ErpTaxRateFactory::new();
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(ErpEstablishment::class, 'erp_establishment_id');
    }
}
