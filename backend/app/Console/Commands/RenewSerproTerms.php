<?php

namespace App\Console\Commands;

use App\Jobs\RenewSerproTermsJob;
use App\Models\SerproAuthorizationTerm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * A renovação diária do termo de autorização, disparada pela agenda.
 *
 * **Só percorre contas que já têm termo, e é a distinção que importa.** A
 * emissão é do upload do e-CNPJ — o `AccountCertificateVault` a agenda depois
 * do commit —, e o que este comando faz é reenviar o documento que já está
 * assinado. Uma conta sem termo não tem documento para reenviar, e tentar
 * emiti-lo daqui faria o gate ser consultado por um caminho que não é o da
 * emissão, com o operador esperando uma renovação e encontrando uma
 * assinatura.
 *
 * **A travessia é por `account_id` explícito e por `chunkById`.** A
 * `BelongsToAccount` não filtra nada no console, e o `chunkById` mantém a
 * consulta de chave primária — o que importa quando a fila de renovação
 * despacha o job de uma conta que foi apagada entre o `select` e o
 * `dispatch`, que é o mesmo caso que o job trata como nada.
 */
final class RenewSerproTerms extends Command
{
    protected $signature = 'serpro:renew-terms';

    protected $description = 'Despacha a renovação do termo de autorização das contas que já têm termo';

    public function handle(): int
    {
        $despachados = 0;

        SerproAuthorizationTerm::query()
            ->reorder()
            ->select('id', 'account_id')
            ->chunkById(100, function ($termos) use (&$despachados): void {
                foreach ($termos as $termo) {
                    RenewSerproTermsJob::dispatch($termo->account_id);
                    $despachados++;
                }
            });

        $this->info("Renovações despachadas: {$despachados}");

        return self::SUCCESS;
    }

    /**
     * O que uma falha da travessia deixa.
     *
     * Uma exceção aqui aborta o comando inteiro no meio da travessia, e o que
     * fica é metade da carteira renovada para hoje e metade não. O log é o
     * rótulo e a classe da exceção, e nada mais: nenhum documento, nenhum
     * token, nenhuma senha. A agenda de amanhã cobre quem faltou, e essa é a
     * razão de o comando não tentar recuperar no meio da volta.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('A renovação diária dos termos de autorização não pôde ser despachada.', [
            'falha' => $exception::class,
        ]);
    }
}
