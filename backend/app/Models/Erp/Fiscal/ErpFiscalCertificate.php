<?php

namespace App\Models\Erp\Fiscal;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpFiscalCertificate extends Model
{
    protected $fillable = [
        'erp_company_id',
        'erp_establishment_id',
        'label',
        'cnpj',
        'encrypted_certificate',
        'encrypted_password',
        'certificate_fingerprint',
        'valid_from',
        'valid_until',
        'revision',
        'status',
        'uploaded_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
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

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ErpFiscalDocument::class, 'erp_fiscal_certificate_id');
    }
}
