<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use Database\Factories\Erp\Commercial\OfficeErpInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficeErpInvoice extends Model
{
    /** @use HasFactory<OfficeErpInvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id', 'contract_id', 'contract_revision_id', 'kind', 'document_status', 'number', 'issued_at',
        'period_start', 'period_end', 'due_on', 'currency', 'seller_snapshot', 'bill_to_snapshot', 'items_snapshot', 'original_total',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'due_on' => 'date:Y-m-d',
            'seller_snapshot' => 'array',
            'bill_to_snapshot' => 'array',
            'items_snapshot' => 'array',
            'original_total' => 'decimal:2',
        ];
    }

    protected static function newFactory(): OfficeErpInvoiceFactory
    {
        return OfficeErpInvoiceFactory::new();
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(OfficeErpContract::class, 'contract_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contractRevision(): BelongsTo
    {
        return $this->belongsTo(OfficeErpContractRevision::class, 'contract_revision_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(OfficeErpInvoiceAdjustment::class, 'invoice_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(OfficeErpReceiptAllocation::class, 'invoice_id');
    }
}
