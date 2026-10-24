<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpOperationNatureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpOperationNature extends Model
{
    /** @use HasFactory<ErpOperationNatureFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = [
        'erp_company_id', 'erp_establishment_id', 'cfop', 'description', 'movement', 'fiscal_metadata', 'status',
        'configuration_revision', 'cfop_reference_version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fiscal_metadata' => 'array',
            'configuration_revision' => 'integer',
        ];
    }

    protected static function newFactory(): ErpOperationNatureFactory
    {
        return ErpOperationNatureFactory::new();
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(ErpEstablishment::class, 'erp_establishment_id');
    }

    public function cfopChoices(): HasMany
    {
        return $this->hasMany(ErpOperationNatureCfopChoice::class);
    }

    public function productExceptions(): HasMany
    {
        return $this->hasMany(ErpOperationNatureProductException::class);
    }
}
