<?php

namespace App\Jobs;

use App\Services\SerproException;
use App\Services\SerproTermManager;
use App\Tenant\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Emite o termo de autorização de uma conta, uma vez, depois que o e-CNPJ do
 * escritório foi gravado.
 *
 * **Por que a conta viaja no job e não no tenant.** O `queue:work` é um
 * processo longo e o `CurrentTenant` é um singleton mutável que **nunca** é
 * resetado: o valor da execução anterior sobrevive à seguinte. Um job que
 * lesse o singleton assinaria o termo da conta do job anterior, com o
 * certificado dela, e não haveria exceção nem nada no resultado que parecesse
 * errado — o caminho mais silencioso de que existe nesta base. Por isso o
 * `handle()` recarrega o singleton **antes** de qualquer coisa, e o
 * `SerproTermManager` lê tudo por `account_id` explícito sem consultá-lo: as
 * duas camadas fecham o mesmo problema por lados diferentes, e é redundância
 * deliberada.
 *
 * **A falha sobe, e o que ela deixou na linha é o que fica.** O job não
 * registra estado nenhum: quem escreve o estado é o `SerproTermManager`, que
 * é quem sabe a diferença entre uma recusa do provedor — que grava
 * `recusado` — e uma indisponibilidade, que não grava nada. O job repetiria
 * essa decisão sem a informação que a produziu, e duas pessoas escrevendo o
 * mesmo campo é a forma mais curta de a linha dizer uma coisa e a tela outra.
 */
final class IssueSerproTermJob implements ShouldQueue
{
    use Queueable;

    /**
     * Uma tentativa só.
     *
     * A assinatura é o trabalho caro deste job, e repetir uma emissão que o
     * gate recusou — que é o estado esperado enquanto o teste de contrato não
     * existe — gastaria uma assinatura por conta por dia, para um documento
     * que ninguém pediu. A única falha que repetir resolveria é a
     * indisponibilidade do provedor, e ela é passageira: o que o operador
     * precisa é do aviso, e não de uma segunda tentativa às cegas.
     */
    public int $tries = 1;

    /**
     * Abaixo do `retry_after` de 90 segundos do redis e do `--timeout=120` do
     * worker, com a mesma folga que o job de captura fiscal usa: um job que
     * encosta no `retry_after` é exatamente o que o redis reentrega enquanto o
     * original ainda corre.
     */
    public int $timeout = 85;

    public function __construct(public int $accountId) {}

    /**
     * **A falha da emissão não derruba o upload do e-CNPJ, e essa é a razão de
     * este `try`.**
     *
     * Na fila de verdade o despacho sai depois do commit e o `200` da
     * requisição já aconteceu; ali o efeito não existe. Mas
     * `QUEUE_CONNECTION` pode ser `sync` — como é na suíte e como pode ser
     * numa instalação —, e então o job roda **dentro** da transação do
     * upload, e uma exceção atravessada viraria o `200` do certificado em
     * `500`. O escritório teria entregue o e-CNPJ corretamente e receberia um
     * erro, com o certificado gravado e sem nenhum termo: o pior estado
     * possível para ele entender.
     *
     * E o caso não é teórico: **o gate está fechado**, e estar fechado é o
     * estado correto de uma instalação sem teste de contrato. Toda conta que
     * subisse um e-CNPJ hoje receberia `500` por causa de uma prova que o
     * produto ainda não tem.
     *
     * O que a falha deixa, e é o que importa: o estado na linha, gravado pelo
     * `SerproTermManager`, que é quem sabe a diferença entre recusa do
     * provedor — que grava `recusado` com o código dele — e indisponibilidade,
     * que não grava nada. O job não escreve estado: quem escreve é quem tem a
     * informação, e duas pessoas escrevendo o mesmo campo é a forma mais
     * curta de a linha dizer uma coisa e a tela outra.
     *
     * O motivo que vai para o log é o da `SerproException`, e ela não carrega
     * documento, token nem senha na mensagem — é o que o `SerproClient` e o
     * `SerproTermManager` garantem ao recusar, e é por isso que a frase pode
     * ir para o log sem uma revisão nova a cada chamada.
     */
    public function handle(SerproTermManager $manager): void
    {
        resolve(CurrentTenant::class)->accountId = $this->accountId;

        try {
            $manager->issue($this->accountId);
        } catch (SerproException $exception) {
            Log::error('A emissão do termo de autorização não pôde ser concluída.', [
                'account_id' => $this->accountId,
                'falha' => $exception->failure->value,
                'motivo' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * O que sobe quando a falha **não** é do provedor nem do gate.
     *
     * Um `Error` de PHP — memória, tipo — não é uma falha de emissão que o
     * estado da linha possa descrever, e é a única que o worker precisa
     * registrar para o operador ver. O log é o rótulo e a classe da
     * exceção, e nunca o documento, o token ou a senha — e a
     * `SerproException`, que é a falha comum, nem chega aqui.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('A emissão do termo de autorização falhou de forma inesperada.', [
            'account_id' => $this->accountId,
            'falha' => $exception::class,
        ]);
    }
}
