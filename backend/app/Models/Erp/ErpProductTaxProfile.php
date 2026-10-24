<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpProductTaxProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ErpProductTaxProfile extends Model
{
    /** @use HasFactory<ErpProductTaxProfileFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = ['erp_company_id', 'name', 'status', 'current_revision'];

    protected function casts(): array
    {
        return ['current_revision' => 'integer'];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ErpProductTaxProfileRevision::class);
    }

    public function currentRevision(): HasOne
    {
        return $this->hasOne(ErpProductTaxProfileRevision::class)->ofMany('revision', 'max');
    }
}
