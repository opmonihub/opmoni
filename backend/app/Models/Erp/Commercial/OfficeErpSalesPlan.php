<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use Database\Factories\Erp\Commercial\OfficeErpSalesPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficeErpSalesPlan extends Model
{
    /** @use HasFactory<OfficeErpSalesPlanFactory> */
    use HasFactory;

    protected $fillable = ['account_id', 'name', 'description', 'status', 'archived_at', 'current_revision_id'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    protected static function newFactory(): OfficeErpSalesPlanFactory
    {
        return OfficeErpSalesPlanFactory::new();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(OfficeErpSalesPlanRevision::class, 'sales_plan_id');
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(OfficeErpSalesPlanRevision::class, 'current_revision_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(OfficeErpContract::class, 'sales_plan_id');
    }
}
