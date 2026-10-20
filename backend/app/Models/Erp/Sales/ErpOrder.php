<?php

namespace App\Models\Erp\Sales;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpOrder extends Model
{
    protected $fillable = [
        'conversion_source_id', 'draft_revision', 'commercial_snapshot', 'draft_input', 'erp_price_group_id', 'erp_operation_nature_id', 'reason', 'currency', 'subtotal_amount', 'discount_amount', 'tax_amount',
        'erp_company_id',
        'erp_establishment_id',
        'erp_quote_id',
        'erp_contact_id',
        'order_no',
        'status',
        'total_amount',
        'currency',
        'party_snapshot',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'draft_revision' => 'integer', 'commercial_snapshot' => 'array', 'draft_input' => 'array',
            'subtotal_amount' => 'decimal:4', 'discount_amount' => 'decimal:4', 'tax_amount' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'party_snapshot' => 'array',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(ErpEstablishment::class, 'erp_establishment_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(ErpQuote::class, 'erp_quote_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ErpOrderLine::class, 'erp_order_id');
    }
}
