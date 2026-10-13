<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SerproConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * O interruptor da integração no escritório: uma chave em
 * `Account.settings`, por conta, e de ninguém mais.
 *
 * O flag é por Account e não pela plataforma porque a credencial é
 * compartilhada — um problema no contratante não pode derrubar o
 * monitoramento de todos os escritórios de uma vez, e desligar um escritório
 * é o contorno mais rápido que existe. Desligar **não** apaga nada: execuções
 * e dados sincronizados continuam legíveis, e a chave só impede trabalho
 * novo.
 *
 * Ligar exige a credencial de plataforma configurada — "habilitado" sobre
 * uma conexão ausente criaria um estado que a tela não consegue distinguir
 * de "habilitado e quebrado". Desligar não exige nada: a recusa de parar é
 * que seria um defeito.
 */
final class SerproAccountEnablement
{
    private const KEY = 'serpro_enabled';

    public function enabled(int $accountId): bool
    {
        return (bool) (Account::query()->findOrFail($accountId)->settings[self::KEY] ?? false);
    }

    /**
     * @throws ValidationException quando ligar sem credencial de plataforma.
     */
    public function set(int $accountId, bool $enabled): void
    {
        if ($enabled && ! (SerproConnection::current()?->isConfigured() ?? false)) {
            throw ValidationException::withMessages([
                'enabled' => 'A credencial da plataforma não está configurada; a integração não pode ser habilitada.',
            ]);
        }

        // O lock é para o merge, não para o boolean: `settings` é um JSON com
        // outras chaves, e duas escritas simultâneas sem lock perderiam uma
        // delas — a que gravou `serpro_enabled` e a que gravou o resto.
        DB::transaction(function () use ($accountId, $enabled): void {
            $account = Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();

            $account->settings = [...($account->settings ?? []), self::KEY => $enabled];
            $account->save();
        });
    }
}
