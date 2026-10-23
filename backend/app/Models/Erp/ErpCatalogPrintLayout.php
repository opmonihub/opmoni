<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpCatalogPrintLayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErpCatalogPrintLayout extends Model
{
    /** @use HasFactory<ErpCatalogPrintLayoutFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = [
        'erp_company_id', 'name', 'description', 'label_width_mm', 'label_height_mm',
        'paper_width_mm', 'paper_height_mm', 'top_margin_mm', 'left_margin_mm',
        'row_spacing_mm', 'column_spacing_mm', 'columns_per_row', 'labels_per_sheet',
        'is_continuous', 'is_default', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'label_width_mm' => 'decimal:4',
            'label_height_mm' => 'decimal:4',
            'paper_width_mm' => 'decimal:4',
            'paper_height_mm' => 'decimal:4',
            'top_margin_mm' => 'decimal:4',
            'left_margin_mm' => 'decimal:4',
            'row_spacing_mm' => 'decimal:4',
            'column_spacing_mm' => 'decimal:4',
            'columns_per_row' => 'integer',
            'labels_per_sheet' => 'integer',
            'is_continuous' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    protected static function newFactory(): ErpCatalogPrintLayoutFactory
    {
        return ErpCatalogPrintLayoutFactory::new();
    }
}
