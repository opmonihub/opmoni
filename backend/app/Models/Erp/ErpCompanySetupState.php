<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use App\Models\Erp\Sales\ErpInvoiceLayout;
use App\Models\Erp\Sales\ErpInvoiceScheme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpCompanySetupState extends Model
{
    use BelongsToErpCompany;

    protected $fillable = [
        'erp_company_id', 'preset_version', 'revision', 'currency', 'default_unit_id',
        'default_customer_id', 'default_invoice_scheme_id', 'default_invoice_layout_id', 'preset_owned_keys', 'sale_numbering_adopted',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revision' => 'integer', 'preset_owned_keys' => 'array', 'sale_numbering_adopted' => 'boolean'];
    }

    public function defaultUnit(): BelongsTo
    {
        return $this->belongsTo(ErpUnit::class, 'default_unit_id');
    }

    public function defaultCustomer(): BelongsTo
    {
        return $this->belongsTo(ErpContact::class, 'default_customer_id');
    }

    public function defaultInvoiceScheme(): BelongsTo
    {
        return $this->belongsTo(ErpInvoiceScheme::class, 'default_invoice_scheme_id');
    }

    public function defaultInvoiceLayout(): BelongsTo
    {
        return $this->belongsTo(ErpInvoiceLayout::class, 'default_invoice_layout_id');
    }
}
