<?php

namespace App\Models\Erp\Fiscal;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpFiscalAttempt extends Model
{
    protected $fillable = [
        'erp_company_id', 'erp_establishment_id', 'erp_fiscal_document_id', 'erp_fiscal_event_id', 'erp_fiscal_invalidation_id',
        'erp_fiscal_configuration_revision_id', 'erp_fiscal_certificate_revision_id', 'action', 'model', 'environment', 'series',
        'number', 'actor_user_id', 'idempotency_key', 'request_sha256', 'encrypted_source_snapshot', 'schema_release', 'status',
        'outbox_state', 'dispatch_attempts', 'provider_receipt', 'provider_protocol', 'safe_result', 'claimed_at', 'lease_token',
        'lease_expires_at', 'available_at', 'last_error_code', 'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'series' => 'integer', 'number' => 'integer', 'dispatch_attempts' => 'integer', 'safe_result' => 'array',
            'claimed_at' => 'immutable_datetime', 'lease_expires_at' => 'immutable_datetime', 'available_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ErpFiscalDocument::class, 'erp_fiscal_document_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ErpFiscalEvent::class, 'erp_fiscal_event_id');
    }

    public function invalidation(): BelongsTo
    {
        return $this->belongsTo(ErpFiscalInvalidation::class, 'erp_fiscal_invalidation_id');
    }

    public function configurationRevision(): BelongsTo
    {
        return $this->belongsTo(ErpFiscalConfigurationRevision::class, 'erp_fiscal_configuration_revision_id');
    }

    public function certificateRevision(): BelongsTo
    {
        return $this->belongsTo(ErpFiscalCertificateRevision::class, 'erp_fiscal_certificate_revision_id');
    }
}
