<?php

namespace App\Support;

use App\Models\Account;
use App\Models\AccountMembership;
use Illuminate\Http\Request;

class CurrentAccount
{
    public function __construct(public readonly Account $account, public readonly AccountMembership $membership) {}

    public static function fromRequest(?Request $request = null): self
    {
        $context = ($request ?? request())->attributes->get(self::class);
        abort_unless($context instanceof self, 403, 'Escritório não selecionado');

        return $context;
    }

    public function assertWrite(): void
    {
        abort_unless(in_array($this->membership->role, ['admin', 'operador'], true), 403, 'Permissão insuficiente');
    }

    public function assertAdmin(): void
    {
        abort_unless($this->membership->role === 'admin', 403, 'Permissão insuficiente');
    }

    public function cacheKey(string $key): string
    {
        return 'account:'.$this->account->id.':'.$key;
    }
}
