<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpCarrierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpCarrier extends Model
{
    /** @use HasFactory<ErpCarrierFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = [
        'erp_company_id', 'legal_name', 'tax_id', 'ie', 'address', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'address' => 'array',
        ];
    }

    protected static function newFactory(): ErpCarrierFactory
    {
        return ErpCarrierFactory::new();
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(ErpVehicle::class, 'erp_carrier_id');
    }
}
