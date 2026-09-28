<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\SerproException;
use App\Services\SerproTermManager;
use App\Tenant\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Renova o termo de autorização de uma conta, reenviando o documento que já
 * está assinado.
 *
 * **A conta viaja no job, e a razão é a mesma da emissão:** o `queue:work` é
 * um processo longo e o `CurrentTenant` é um singleton mutável que nunca é
 * resetado, de modo que o valor da execução anterior sobrevive à seguinte. Um
 * job que confiasse nele renovaria o termo da conta errada, e a falha
 * apareceria como "o token do escritório não vale", que é um defeito do
 * escritório e não do processo. O `handle()` recarrega o singleton **antes**
 * de qualquer coisa, e o `SerproTermManager` lê tudo por `account_id`
 * explícito sem consultá-lo.
 *
 * **A conta que saiu da carteira entre o despacho e a execução é nada, não
 * erro.** A agenda dispara para toda conta que tem termo, e uma conta apagada
 * no intervalo não é uma falha de renovação: é uma conta que não existe mais.
 * É a mesma razão de `CaptureFiscalDocumentsJob` não tratar cliente
 * desaparecido como exceção.
 */
final class RenewSerproTermsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Uma tentativa só, e o motivo é o do lado do `304`.
     *
     * A renovação diária é idempotente por natureza — o provedor responde
     * `304` ao mesmo documento —, de modo que uma repetição não causaria
     * dano. O que a repetização causaria é gastar cota do provedor sem
     * necessidade, e a agenda de amanhã já-renova quem precisa. Uma
     * indisponibilidade do provedor, que é o caso que a repetição
     * resolveria, dura minutos e a agenda é diária: esperar não compensa.
     */
    public int $tries = 1;

    /**
     * Abaixo do `retry_after` de 90 segundos do redis e do `--timeout=120` do
     * worker, com a mesma folga do job de captura fiscal. Um job que encosta
     * no `retry_after` é o que o redis reentrega enquanto o original corre.
     */
    public int $timeout = 85;

    public function __construct(public int $accountId) {}

    /**
     * @throws SerproException
     */
    public function handle(SerproTermManager $manager): void
    {
        resolve(CurrentTenant::class)->accountId = $this->accountId;

        if (Account::query()->whereKey($this->accountId)->doesntExist()) {
            return;
        }

        $manager->refresh($this->accountId);
    }

    /**
     * O que uma renovação que falhou deixa, e o que ela não deixa.
     *
     * O estado já está na linha quando esta chega, e é o estado certo: uma
     * recusa do provedor está como `recusado` com o código dele, um termo
     * vencido está como `vencido`, e uma indisponibilidade deixou o que já
     * valia.
     *
     * **O motivo entra, e só quando ele é uma frase nossa.** A `SerproException`
     * carrega uma mensagem curada em cada ponto que a lança — "o provedor não
     * respondeu", "o documento guardado não abre com a chave atual" —, e é
     * exatamente o caso que o operador precisa ler: uma indisponibilidade do
     * provedor não deixa nenhum estado na linha, e sem a frase o único registro
     * seria o rótulo e a classe, que dizem "algo falhou" e nada mais. Uma
     * exceção que não é `SerproException` pode carregar o texto do OpenSSL ou o
     * de uma biblioteca, e por isso a frase **não** vai para o log: quem não
     * é nossa não entra. É a mesma regra que o `IssueSerproTermJob` aplica no
     * `handle()`, e ela é o que separa "a frase é segura" de "a frase está
     * escrita por nós".
     *
     * **Nenhum documento, token ou senha entra**, em nenhum dos dois casos: a
     * cifra de onde eles sairiam não é alcançável a partir de uma exceção.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('A renovação do termo de autorização não pôde ser concluída.', [
            'account_id' => $this->accountId,
            'falha' => $exception::class,
            'motivo' => $exception instanceof SerproException ? $exception->getMessage() : null,
        ]);
    }
}
