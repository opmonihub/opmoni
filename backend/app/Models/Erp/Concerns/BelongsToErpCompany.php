<?php

namespace App\Models\Erp\Concerns;

use App\Models\Erp\ErpCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @phpstan-require-extends Model */
trait BelongsToErpCompany
{
    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }

    /** @param Builder<static> $query */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.erp_company_id', $companyId);
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }
}
