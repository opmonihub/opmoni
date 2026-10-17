<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpUnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpUnit extends Model
{
    /** @use HasFactory<ErpUnitFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = [
        'erp_company_id', 'short_name', 'actual_name', 'allow_decimal',
        'base_unit_id', 'conversion_factor', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allow_decimal' => 'boolean',
            'conversion_factor' => 'decimal:8',
        ];
    }

    protected static function newFactory(): ErpUnitFactory
    {
        return ErpUnitFactory::new();
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_unit_id');
    }
}
