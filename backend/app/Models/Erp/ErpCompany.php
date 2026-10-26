<?php

namespace App\Models\Erp;

use Database\Factories\Erp\ErpCompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ErpCompany extends Model
{
    /** @use HasFactory<ErpCompanyFactory> */
    use HasFactory;

    protected $fillable = ['name', 'razao_social', 'cnpj', 'ie', 'status', 'operating_settings'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'operating_settings' => 'array',
        ];
    }

    protected static function newFactory(): ErpCompanyFactory
    {
        return ErpCompanyFactory::new();
    }

    public function establishments(): HasMany
    {
        return $this->hasMany(ErpEstablishment::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ErpCompanyMembership::class);
    }

    public function officeDelegations(): HasMany
    {
        return $this->hasMany(ErpOfficeDelegation::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(ErpSubscription::class, 'erp_company_id');
    }

    public function setupState(): HasOne
    {
        return $this->hasOne(ErpCompanySetupState::class, 'erp_company_id');
    }

    public function trial(): HasOne
    {
        return $this->hasOne(ErpSubscriptionTrial::class, 'erp_company_id');
    }
}
