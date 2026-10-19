<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpCustomerGroup extends Model
{
    use BelongsToErpCompany;

    protected $fillable = [
        'erp_company_id', 'name', 'percentage_adjustment', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'percentage_adjustment' => 'decimal:4',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ErpContact::class, 'erp_customer_group_id');
    }
}
