<?php

namespace App\Support;

use App\Erp\ErpCompanyPermissions;
use App\Erp\ErpOperationNatureRules;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use Illuminate\Http\Request;

class CurrentCompany
{
    public function __construct(
        public readonly ErpCompany $company,
        public readonly string $accessKind,
        public readonly ?ErpCompanyMembership $membership,
        public readonly ?ErpOfficeDelegation $delegation,
        public readonly ?int $delegatedOfficeId,
        public readonly ?string $delegatedOfficeRole = null,
    ) {}

    public static function fromRequest(?Request $request = null): self
    {
        $context = ($request ?? request())->attributes->get(self::class);
        abort_unless($context instanceof self, 403, 'Empresa não selecionada');

        return $context;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        if ($this->accessKind === 'direct' && $this->membership) {
            return $this->membership->effectivePermissions();
        }
        if ($this->accessKind === 'delegated' && $this->delegation) {
            return ErpCompanyPermissions::delegatedEffective($this->delegation->effectivePermissions(), $this->delegatedOfficeRole);
        }

        return [];
    }

    public function assertPermission(string $permission, bool $write = false): void
    {
        $permission = ErpOperationNatureRules::permission($permission, request());
        $permission = match ($permission) {
            'erp.company.initialization.preview', 'erp.company.initialization.apply' => 'erp.company.settings.update',
            'erp.products.tax-preview' => 'erp.products.update',
            'erp.product-tax-profiles.show' => 'erp.product-tax-profiles.index',
            default => $permission,
        };
        if ($this->accessKind === 'delegated') {
            abort_unless(ErpCompanyPermissions::delegatedAllows($permission, $write, $this->delegation?->effectivePermissions() ?? [], $this->delegatedOfficeRole), 403, 'Permissão insuficiente');

            return;
        }
        if ($write && ! $this->membership?->roleEnum()->canMutate()) {
            abort(403, 'Permissão insuficiente');
        }
        $permissions = $this->permissions();
        $family = preg_replace('/\.[^.]+$/', '', $permission);
        foreach (['*', $permission, $family.'.*', $family.($write ? '.write' : '.read'), $write ? 'erp.write' : 'erp.read'] as $candidate) {
            if (in_array($candidate, $permissions, true)) {
                return;
            }
        }
        abort(403, 'Permissão insuficiente');
    }

    public function assertCanMutate(): void
    {
        $this->assertPermission((string) request()->route()?->getName(), true);
    }
}
