<?php

namespace App\Models\Erp\Accounting;

use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\Concerns\BelongsToErpCompany;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\FiscalDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpAccountingDelivery extends Model
{
    use BelongsToErpCompany;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'erp_company_id',
        'erp_fiscal_document_id',
        'account_id',
        'client_id',
        'delegation_id',
        'mapping_id',
        'mapping_revision',
        'requested_by_user_id',
        'fiscal_document_id',
        'status',
        'reason_code',
        'attempts',
        'delivered_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'attempts' => 'integer',
            'delegation_id' => 'integer',
            'mapping_id' => 'integer',
            'mapping_revision' => 'integer',
        ];
    }

    public function erpFiscalDocument(): BelongsTo
    {
        return $this->belongsTo(ErpFiscalDocument::class, 'erp_fiscal_document_id');
    }

    public function officeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function officeClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function officeFiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }
}
