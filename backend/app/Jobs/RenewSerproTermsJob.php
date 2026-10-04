<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\SerproAuthorizationTerm;
use App\Services\SerproAccountEnablement;
use App\Services\SerproException;
use App\Services\SerproTermManager;
use App\Tenant\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Renova o termo de autorização de uma conta reenviando o documento que já
 * está assinado — e, para a conta que ainda não tem termo que autorize o
 * gateway, **emite**: é a segunda chance da emissão que o upload do e-CNPJ
 * dispara, coberta pela mesma agenda diária.
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
 * erro.** A agenda dispara para toda conta que tem termo ou que está
 * habilitada, e uma conta apagada no intervalo não é uma falha de renovação:
 * é uma conta que não existe mais. É a mesma razão de
 * `CaptureFiscalDocumentsJob` não tratar cliente desaparecido como exceção.
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
     * A emissão vem antes da renovação, e é a ordem que decide: quem não tem
     * termo que autorize o gateway recebe a emissão — o gate de prova vale
     * dentro do `issue()`, e sem prova a emissão é recusada e logada, sem
     * exceção subir —, e a renovação fica para quem tem termo em pé.
     */
    public function handle(SerproTermManager $manager): void
    {
        resolve(CurrentTenant::class)->accountId = $this->accountId;

        if (Account::query()->whereKey($this->accountId)->doesntExist()) {
            return;
        }

        $termo = SerproAuthorizationTerm::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $this->accountId)
            ->first();

        if ($termo === null) {
            $this->emitir($manager);

            return;
        }

        if ($termo->authorizesGateway()) {
            $manager->refresh($this->accountId);

            return;
        }

        // O termo existe e não autoriza — `recusado`, `pendente`, `vencido`:
        // reenviar os mesmos bytes seria repetir a recusa que o `refresh()`
        // se recusa a repetir. A emissão assina outro documento, e é a saída
        // que a linha recusada tem; quando as condições de emissão não
        // chegam, a renovação segue como antes.
        if ($this->emitir($manager)) {
            return;
        }

        $manager->refresh($this->accountId);
    }

    /**
     * A primeira emissão pela agenda, para a conta habilitada com e-CNPJ
     * vigente — o caminho feliz é o upload do e-CNPJ, e este é o da conta
     * cujo job falhou ou nunca rodou.
     *
     * **As condições vêm antes da chamada.** Sem habilitação ou sem
     * certificado em vigor, o gate recusaria com uma frase que a conta leria
     * todo dia por um estado que é esperado — e o log diário de ruído é a
     * forma mais cara de dizer a verdade. O gate de prova **não** é lido
     * aqui: quem o lê é o `issue()`, e a recusa dele — `DoNotRetry` — é o
     * caso tolerado abaixo, até a prova de contrato existir.
     *
     * Devolve `true` quando a emissão foi tentada, sucesso ou recusa
     * tolerada — o chamador não renova por cima do que acabou de sair.
     */
    private function emitir(SerproTermManager $manager): bool
    {
        if (! resolve(SerproAccountEnablement::class)->enabled($this->accountId)) {
            return false;
        }

        $certificado = AccountCertificate::currentFor($this->accountId);

        if ($certificado === null || $certificado->valid_until->isPast()) {
            return false;
        }

        try {
            $manager->issue($this->accountId);
        } catch (SerproException $exception) {
            // A mesma tolerância do `IssueSerproTermJob`: o estado que a
            // linha recebe — ou a ausência dela — é o que a tela mostra, e
            // o motivo da `SerproException` é curado, sem documento, token
            // ou senha.
            Log::error('A emissão do termo de autorização não pôde ser concluída.', [
                'account_id' => $this->accountId,
                'falha' => $exception->failure->value,
                'motivo' => $exception->getMessage(),
            ]);
        }

        return true;
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
