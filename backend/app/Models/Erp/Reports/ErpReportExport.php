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
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
