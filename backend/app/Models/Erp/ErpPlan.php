<?php

namespace App\Models\Erp;

use Database\Factories\Erp\ErpPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpPlan extends Model
{
    /** @use HasFactory<ErpPlanFactory> */
    use HasFactory;

    protected $fillable = ['slug', 'name', 'quotas'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quotas' => 'array',
        ];
    }

    protected static function newFactory(): ErpPlanFactory
    {
        return ErpPlanFactory::new();
    }

    public function modules(): HasMany
    {
        return $this->hasMany(ErpPlanModule::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ErpSubscription::class);
    }
}
