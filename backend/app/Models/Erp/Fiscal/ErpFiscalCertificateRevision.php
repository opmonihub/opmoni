<?php

namespace App\Models\Erp\Fiscal;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpFiscalCertificateRevision extends Model
{
    protected $fillable = [
        'erp_fiscal_certificate_id',
        'revision',
        'encrypted_certificate',
        'encrypted_password',
        'certificate_fingerprint',
        'cnpj',
        'subject',
        'valid_from',
        'valid_until',
        'uploaded_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['valid_from' => 'immutable_datetime', 'valid_until' => 'immutable_datetime'];
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(ErpFiscalCertificate::class, 'erp_fiscal_certificate_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
