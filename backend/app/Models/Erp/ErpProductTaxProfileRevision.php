<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ErpProductTaxProfileRevision extends Model
{
    use BelongsToErpCompany;

    public const UPDATED_AT = null;

    protected $fillable = ['erp_company_id', 'erp_product_tax_profile_id', 'revision', 'name', 'status', 'fields', 'applicability', 'actor_user_id', 'access_kind', 'erp_company_membership_id', 'account_id', 'erp_office_delegation_id'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'fields' => 'array', 'applicability' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Product tax revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Product tax revisions are immutable.'));
    }
}
