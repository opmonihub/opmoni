<?php

namespace App\Models\Erp\Accounting;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Illuminate\Database\Eloquent\Model;

class ErpAccountingMapping extends Model
{
    use BelongsToErpCompany;

    protected $fillable = [
        'erp_company_id', 'erp_establishment_id', 'delegation_id', 'account_id', 'client_id',
        'approved_by_user_id', 'approved_at', 'revision', 'status', 'revoked_at', 'revoked_by_user_id',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'revoked_at' => 'datetime', 'revision' => 'integer'];
    }
}
