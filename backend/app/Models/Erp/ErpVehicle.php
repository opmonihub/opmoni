<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpVehicleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpVehicle extends Model
{
    /** @use HasFactory<ErpVehicleFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = [
        'erp_company_id', 'erp_carrier_id', 'vehicle_type', 'plate', 'uf', 'color',
        'model', 'brand', 'wheel_type', 'body_type', 'owner_type', 'status',
    ];

    protected static function newFactory(): ErpVehicleFactory
    {
        return ErpVehicleFactory::new();
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(ErpCarrier::class, 'erp_carrier_id');
    }
}
