<?php

namespace App\Models\Erp\Sales;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpQuote extends Model
{
    protected $fillable = [
        'erp_company_id',
        'erp_establishment_id',
        'erp_contact_id',
        'quote_no',
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
}
