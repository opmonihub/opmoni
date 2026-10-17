<?php

namespace App\Models\Erp\Fiscal;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\Sales\ErpSale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpFiscalDocument extends Model
{
    protected $fillable = [
        'erp_company_id',
        'erp_establishment_id',
        'erp_fiscal_certificate_id',
        'erp_sale_id',
        'model',
        'environment',
        'series',
        'number',
        'access_key',
        'status',
        'idempotency_key',
        'certificate_revision',
        'storage_path',
        'artifact_byte_length',
        'artifact_sha256',
        'commercial_snapshot',
        'metadata',
        'authorized_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'commercial_snapshot' => 'array',
            'metadata' => 'array',
            'authorized_at' => 'datetime',
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

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(ErpFiscalCertificate::class, 'erp_fiscal_certificate_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(ErpSale::class, 'erp_sale_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ErpFiscalEvent::class, 'erp_fiscal_document_id');
    }
}
