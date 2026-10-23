<?php

namespace App\Models\Erp\Reports;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpReportExport extends Model
{
    use BelongsToErpCompany;

    protected $fillable = [
        'erp_company_id',
        'user_id',
        'report_type',
        'filters',
        'status',
        'storage_path',
        'content_sha256',
        'content_bytes',
        'failure_code',
        'authorized_establishment_scope',
        'authorized_establishment_ids',
        'authorized_companywide_finance',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'expires_at' => 'datetime',
            'content_bytes' => 'integer',
            'authorized_establishment_ids' => 'array',
            'authorized_companywide_finance' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
