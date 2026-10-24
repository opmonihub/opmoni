<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpProduct extends Model
{
    /** @use HasFactory<ErpProductFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = [
        'erp_company_id', 'sku', 'name', 'kind', 'track_stock', 'erp_unit_id',
        'erp_brand_id', 'erp_category_id', 'sale_price', 'warranty_months',
        'warranty_description', 'barcode', 'barcode_type', 'ncm', 'cest', 'origin_code',
        'icms_cst', 'icms_csosn', 'pis_cst', 'cofins_cst', 'ipi_cst',
        'icms_rate', 'pis_rate', 'cofins_rate', 'ipi_rate',
        'simple_national_credit_rate', 'ibs_cbs_cst', 'ibs_cbs_classification_code',
        'erp_product_tax_profile_id', 'tax_field_intents',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'track_stock' => 'boolean',
            'erp_product_tax_profile_id' => 'integer',
            'tax_field_intents' => 'array',
            'sale_price' => 'decimal:4',
            'icms_rate' => 'decimal:4',
            'pis_rate' => 'decimal:4',
            'cofins_rate' => 'decimal:4',
            'ipi_rate' => 'decimal:4',
            'simple_national_credit_rate' => 'decimal:4',
            'warranty_months' => 'integer',
        ];
    }

    protected static function newFactory(): ErpProductFactory
    {
        return ErpProductFactory::new();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ErpUnit::class, 'erp_unit_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(ErpBrand::class, 'erp_brand_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ErpCategory::class, 'erp_category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ErpProductVariant::class);
    }
}
