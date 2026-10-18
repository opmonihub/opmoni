<?php

namespace App\Models\Erp;

use App\Enums\Erp\ErpCompanyRole;
use App\Models\User;
use Database\Factories\Erp\ErpCompanyMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ErpCompanyMembership extends Model
{
    /** @use HasFactory<ErpCompanyMembershipFactory> */
    use HasFactory;

    protected $fillable = ['erp_company_id', 'user_id', 'role', 'action_permissions', 'access_all_establishments'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action_permissions' => 'array',
            'access_all_establishments' => 'boolean',
        ];
    }

    protected static function newFactory(): ErpCompanyMembershipFactory
    {
        return ErpCompanyMembershipFactory::new();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<ErpEstablishment, $this> */
    public function establishments(): BelongsToMany
    {
        return $this->belongsToMany(
            ErpEstablishment::class,
            'erp_company_membership_establishments',
            'erp_company_membership_id',
            'erp_establishment_id',
        )->withPivot('erp_company_id')->orderBy('erp_establishments.id');
    }

    public function hasAllEstablishmentAccess(): bool
    {
        return $this->role === ErpCompanyRole::Owner->value || $this->access_all_establishments;
    }

    public function roleEnum(): ErpCompanyRole
    {
        return ErpCompanyRole::from($this->role);
    }

    /** @return list<string> */
    public function effectivePermissions(): array
    {
        $custom = $this->action_permissions;
        if (is_array($custom) && $custom !== []) {
            return array_values(array_map(strval(...), $custom));
        }

        return $this->roleEnum()->defaultPermissions();
    }
}
