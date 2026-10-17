<?php

namespace App\Support;

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
            return $this->delegation->effectivePermissions();
        }

        return [];
    }

    public function assertCanMutate(): void
    {
        if ($this->accessKind === 'direct' && $this->membership) {
            abort_unless($this->membership->roleEnum()->canMutate(), 403, 'Permissão insuficiente');
        }
        if ($this->accessKind === 'delegated') {
            abort(response()->json(['code' => 'forbidden', 'message' => 'Acesso delegado somente leitura'], 403));
        }
    }
}
