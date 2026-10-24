<?php

namespace App\Models\Erp\Sales;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpSale extends Model
{
    protected $fillable = [
        'fiscal_model',
        'conversion_source_id', 'draft_revision', 'commercial_snapshot', 'commercial_layout_snapshot', 'draft_input', 'erp_price_group_id', 'erp_operation_nature_id', 'reason', 'currency', 'subtotal_amount', 'discount_amount', 'tax_amount',
        'erp_company_id',
        'erp_establishment_id',
        'erp_order_id',
        'erp_contact_id',
        'erp_invoice_scheme_id',
        'erp_invoice_layout_id',
        'invoice_no',
        'status',
        'fiscal_status',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'currency',
        'source_operation_key',
        'posted_by_user_id',
        'party_snapshot',
        'metadata',
        'posted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'draft_revision' => 'integer', 'commercial_snapshot' => 'array', 'commercial_layout_snapshot' => 'array', 'draft_input' => 'array',
            'subtotal_amount' => 'decimal:4', 'discount_amount' => 'decimal:4', 'tax_amount' => 'decimal:4',
            'subtotal_amount' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'party_snapshot' => 'array',
            'metadata' => 'array',
            'posted_at' => 'datetime',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(ErpOrder::class, 'erp_order_id');
    }

    public function invoiceScheme(): BelongsTo
    {
        return $this->belongsTo(ErpInvoiceScheme::class, 'erp_invoice_scheme_id');
    }

    public function invoiceLayout(): BelongsTo
    {
        return $this->belongsTo(ErpInvoiceLayout::class, 'erp_invoice_layout_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ErpSaleLine::class, 'erp_sale_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ErpSaleReturn::class, 'erp_sale_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }
}
