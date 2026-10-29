<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Database\Factories\Erp\Commercial\OfficeErpContractFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OfficeErpContract extends Model
{
    /** @use HasFactory<OfficeErpContractFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id', 'client_id', 'sales_plan_id', 'erp_company_id', 'agreement_status', 'current_revision_id',
        'pending_revision_id', 'service_anchor_on', 'billing_timezone', 'next_period_index',
        'planned_start_on',
        'next_period_month_offset', 'cancel_effective_on', 'manual_hold', 'accepted_at', 'effective_at',
        'canceled_at', 'cancellation_reason', 'provisioning_source', 'erp_plan_id', 'authorized_by_user_id', 'authorized_at',
    ];

    protected function casts(): array
    {
        return [
            'service_anchor_on' => 'date',
            'planned_start_on' => 'date',
            'cancel_effective_on' => 'date',
            'next_period_index' => 'integer',
            'next_period_month_offset' => 'integer',
            'manual_hold' => 'boolean',
            'accepted_at' => 'datetime',
            'effective_at' => 'datetime',
            'canceled_at' => 'datetime',
            'authorized_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OfficeErpContractFactory
    {
        return OfficeErpContractFactory::new();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function salesPlan(): BelongsTo
    {
        return $this->belongsTo(OfficeErpSalesPlan::class, 'sales_plan_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(OfficeErpContractRevision::class, 'contract_id');
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(OfficeErpContractRevision::class, 'current_revision_id');
    }

    public function pendingRevision(): BelongsTo
    {
        return $this->belongsTo(OfficeErpContractRevision::class, 'pending_revision_id');
    }

    public function licenceAllocation(): HasOne
    {
        return $this->hasOne(OfficeErpLicenceAllocation::class, 'contract_id')
            ->whereIn('state', ['reserved', 'occupied']);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(ErpSubscription::class, 'office_erp_contract_id');
    }

    public function erpPlan(): BelongsTo
    {
        return $this->belongsTo(ErpPlan::class, 'erp_plan_id');
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }
}
