<?php

namespace App\Models\Erp\Finance;

use Database\Factories\Erp\Finance\ErpCashMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErpCashMovement extends Model
{
    /** @use HasFactory<ErpCashMovementFactory> */
    use HasFactory;

    protected $fillable = ['erp_company_id', 'erp_establishment_id', 'erp_cash_register_id', 'erp_cash_session_id', 'currency', 'direction', 'kind', 'amount', 'reason', 'erp_payment_id', 'reversal_of_id', 'source_operation_key', 'posted_by_user_id', 'posted_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'posted_at' => 'datetime'];
    }
}
