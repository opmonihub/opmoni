<?php

namespace App\Models\Erp\Sales;

use App\Models\Erp\ErpCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpInvoiceLayout extends Model
{
    protected $fillable = [
        'erp_company_id',
        'name',
        'layout_settings',
        'is_default',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'layout_settings' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(ErpSale::class, 'erp_invoice_layout_id');
    }
}
