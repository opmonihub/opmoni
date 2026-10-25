<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use Database\Factories\Erp\Commercial\OfficeErpSalesPlanRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OfficeErpSalesPlanRevision extends Model
{
    /** @use HasFactory<OfficeErpSalesPlanRevisionFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id', 'sales_plan_id', 'revision_number', 'name', 'description', 'price', 'currency',
        'interval_months', 'due_in_days', 'module_keys', 'published_at',
    ];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'module_keys' => 'array', 'published_at' => 'datetime'];
    }

    protected static function newFactory(): OfficeErpSalesPlanRevisionFactory
    {
        return OfficeErpSalesPlanRevisionFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Office ERP sales plan revisions are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Office ERP sales plan revisions are immutable.');
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function salesPlan(): BelongsTo
    {
        return $this->belongsTo(OfficeErpSalesPlan::class, 'sales_plan_id');
    }
}
