<?php

namespace App\Models\Erp;

use Database\Factories\Erp\ErpEstablishmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpEstablishment extends Model
{
    /** @use HasFactory<ErpEstablishmentFactory> */
    use HasFactory;

    protected $fillable = ['erp_company_id', 'name', 'cnpj', 'status'];

    protected static function newFactory(): ErpEstablishmentFactory
    {
        return ErpEstablishmentFactory::new();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }
}
