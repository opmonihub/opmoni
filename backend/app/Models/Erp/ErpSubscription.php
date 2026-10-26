<?php

namespace App\Models\Erp;

use App\Enums\Erp\ErpSubscriptionStatus;
use App\Support\LiteralSearch;
use Database\Factories\Erp\ErpSubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ErpSubscription extends Model
{
    /** @use HasFactory<ErpSubscriptionFactory> */
    use HasFactory;

    protected $fillable = ['erp_company_id', 'erp_plan_id', 'status', 'office_erp_contract_id'];

    protected static function newFactory(): ErpSubscriptionFactory
    {
        return ErpSubscriptionFactory::new();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ErpPlan::class, 'erp_plan_id');
    }

    public function officeErpContract(): BelongsTo
    {
        return $this->belongsTo(Commercial\OfficeErpContract::class, 'office_erp_contract_id');
    }

    public function trial(): HasOne
    {
        return $this->hasOne(ErpSubscriptionTrial::class, 'erp_subscription_id');
    }

    public function statusEnum(): ErpSubscriptionStatus
    {
        return ErpSubscriptionStatus::from($this->status);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $subscriptions) use ($term): void {
            $subscriptions
                ->whereHas('company', fn (Builder $companies): Builder => LiteralSearch::whereContains($companies, 'name', $term))
                ->orWhereHas('plan', fn (Builder $plans): Builder => LiteralSearch::whereContains($plans, 'name', $term));

            if (ctype_digit($term)) {
                $subscriptions->orWhereKey((int) $term);
            }
        });
    }

    public function scopeWithStatus(Builder $query, ?string $status): Builder
    {
        return $status === null ? $query : $query->where('status', $status);
    }
}
