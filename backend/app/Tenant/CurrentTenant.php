<?php

namespace App\Tenant;

use App\Support\CurrentAccount;

final class CurrentTenant
{
    public ?int $accountId = null;

    public function run(int $accountId, callable $callback): mixed
    {
        abort_unless($accountId > 0, 403, 'Escritório inválido');
        $context = request()->attributes->get(CurrentAccount::class);
        if ($context instanceof CurrentAccount) {
            abort_unless((int) $context->account->id === $accountId, 403, 'Escritório inválido');
        }
        $previous = $this->accountId;
        $this->accountId = $accountId;
        try {
            return $callback();
        } finally {
            $this->accountId = $previous;
        }
    }
}
