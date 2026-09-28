<?php

namespace App\Console\Commands;

use App\Jobs\RenewSerproTermsJob;
use App\Models\SerproAuthorizationTerm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 * **A travessia é por `chunkById` e com o escopo de conta desligado.** O
 * `chunkById` mantém a consulta de chave primária — o que importa quando a
 * fila de renovação despacha o job de uma conta que foi apagada entre o
 * `select` e o `dispatch`, que é o mesmo caso que o job trata como nada.
 *
 * E o escopo desligado não é cautela: **sem ele a travessia para depois da
 * primeira página sob `QUEUE_CONNECTION=sync`.** O escopo global de
 * `BelongsToAccount` é lido do `CurrentTenant` no momento em que cada consulta
 * é montada, e o `chunkById` monta uma consulta por página; com a fila
 * síncrona o primeiro job roda dentro do `dispatch()` e deixa o singleton
 * carregando a conta dele — de modo que a segunda página é filtrada por aquela
 * conta, `id > <último da primeira>` não devolve linha nenhuma, o laço para e o
 * comando anuncia uma carteira que não renovou. O sintoma é uma contagem
 * menor e nenhum erro, que é a forma mais cara de defeito numa agenda.
 * A justificativa é a mesma do `ProcessGenerationService`: a travessia é de
 * todas as contas e não há conta a filtrar.
 */
final class RenewSerproTerms extends Command
{
    protected $signature = 'serpro:renew-terms';

    protected $description = 'Despacha a renovação do termo de autorização das contas que já têm termo';

    /**
     * A travessia inteira, e o que uma falha no meio dela deixa.
     *
     * **Não se recupera no meio da volta, e a agenda de amanhã cobre quem
     * faltou.** Retomar de onde parou exigiria saber o que já foi despachado,
     * e o estado de cada linha é do `SerproTermManager` e da resposta do
     * provedor — a agenda não tem como lê-lo sem duplicar a decisão que o
     * serviço já tomou. A agenda de amanhã é a recuperação, e ela existe
     * porque a renovação é idempotente.
     *
     * **O log é o rótulo e a classe da exceção, e é aqui dentro que ele é
     * escrito.** Este comando **não tem `failed()`** — o framework só invoca
     * esse gancho nos jobs enfileirados, e um comando de agenda não é um —, e
     * a versão anterior declarava um que nunca era chamado, prometendo uma
     * curadoria que não acontecia: o que sobrava era o `ScheduleRunCommand`
     * capturando a exceção e chamando `report($e)`, que é a mensagem inteira e
     * a pilha de chamadas. A exceção em questão é a de um documento que não
     * abre, e a pilha de chamadas dela passa pelo XML decifrado na memória.
     *
     * O comando sai com `FAILURE` em vez de deixar a exceção subir: uma
     * travessia interrompida é uma falha da agenda, e dizer que correu bem é a
     * mesma mentira que a contagem errada da travessia seria.
     */
    public function handle(): int
    {
        $despachados = 0;

        try {
            SerproAuthorizationTerm::query()
                ->withoutGlobalScope('account')
                ->reorder()
                ->select('id', 'account_id')
                ->chunkById(100, function ($termos) use (&$despachados): void {
                    foreach ($termos as $termo) {
                        RenewSerproTermsJob::dispatch($termo->account_id);
                        $despachados++;
                    }
                });
        } catch (Throwable $falha) {
            Log::error('A renovação diária dos termos de autorização não pôde ser despachada por completo.', [
                'falha' => $falha::class,
            ]);

            return self::FAILURE;
        }

        $this->info("Renovações despachadas: {$despachados}");

        return self::SUCCESS;
    }
}
