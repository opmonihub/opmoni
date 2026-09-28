<?php

namespace App\Services;

use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproFailure;
use App\Enums\SerproTermProof;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproConnection;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * O termo de autorização do escritório: emiti-lo, renová-lo e dizer se ele
 * ainda sustenta uma chamada ao gateway.
 *
 * **Os três métodos e as três regras que não se misturam.** `issue()` assina,
 * `refresh()` reenvia o que já está assinado, e `validToken()` é a única
 * leitura. A separação não é organização: os três rodam em contextos
 * diferentes — o primeiro num job disparado pelo upload do e-CNPJ, o segundo
 * na agenda diária, o terceiro dentro de uma sincronização —, e a diferença
 * entre eles é o que decide o que pode dar errado.
 *
 * **A renovação não assina, e essa é a propriedade inteira da tabela.** O
 * documento assinado é guardado **verbatim** e a renovação decifra
 * exatamente os bytes guardados, sem passar pelo assinante. Um termo que
 * mudasse de byte entre a gravação e o reenvio seria outro documento, e a
 * renovação seria uma emissão disfarçada — o que o `304` do provedor existe
 * para evitar. Por isso `refresh()` não recebe certificado nenhum e lê o
 * autor de `author_document`: ele é função do termo, e não do estado atual da
 * agenda de certificados do escritório. O teste que apaga o e-CNPJ da base
 * antes de renovar é o que prova isso, e um `SerproTermSigner` chamado de
 * dentro de `refresh()` quebraria.
 *
 * **A emissão é bloqueada até que um teste de contrato prove que o provedor
 * aceita o documento, e o predicado é lido de um lugar só**
 * (`SerproConnection::termProof()`). A recusa diz qual das duas metades
 * faltou, porque as duas têm consertos opostos: uma é gravar a prova, a outra
 * é refazer o teste de contrato porque o documento mudou depois dela. Sem
 * essa distinção, quem recebe "emissão bloqueada" não sabe se precisa de um
 * `serpro:record-term-proof` ou de um ambiente de demonstração.
 *
 * **Nada de material sai daqui.** O documento assinado, o token, os bytes do
 * certificado e a senha não são registrados em log, não são impressos e não
 * são devolvidos por nenhum método público. A leitura da API é uma resource
 * que lista quatro campos, e `validToken()` devolve uma credencial de
 * chamada — que é o que o plano 03 precisa — e nunca o documento.
 *
 * **A tenancy entra por parâmetro, e não pelo singleton.** Os três métodos
 * públicos recebem `int $accountId` e leem por `account_id` explícito, e
 * nenhum deles olha `CurrentTenant`. A razão está no `queue:work`: o
 * singleton é mutável e nunca é resetado, e o valor da execução anterior
 * sobrevive à seguinte, de modo que um job que confiasse nele poderia
 * assinar o termo do escritório errado com o certificado do escritório errado
 * — sem exceção e sem nada no resultado que parecesse errado. Os jobs
 * recarregam o singleton assim mesmo, porque o resto da base o lê, e essa
 * redundância é deliberada: as duas camadas fecham o mesmo problema.
 */
final class SerproTermManager
{
    /**
     * O que a spec pede que a recusa do provedor registre, e a frase que a
     * exceção leva quando o provedor recusa sem dizer qual é o código.
     *
     * O texto do provedor **não** entra: a requisição que ele recusou
     * carregava o documento assinado do escritório, e o texto de recusa
     * descreve esse documento. Entra o **código**, que é o que classifica a
     * falha e o que o suporte pede — e é ele, ou a frase de re-assinar de
     * `ResubmitTerm`, que a **linha** recebe; a constante abaixo é a da
     * exceção, que vai para o log.
     */
    private const RECUSA = 'O provedor recusou o termo de autorização enviado.';

    /**
     * A resposta que não é sucesso, não é recusa **e não é um pedido de termo
     * novo**: limite de tentativas, indisponibilidade, resultado indeterminado
     * ou credencial vencida.
     *
     * A frase diz duas coisas que a pessoa precisa saber: o envio não foi
     * confirmado, e o que a linha já valendo foi preservado. Uma frase que
     * dissesse só a primeira faria o operador procurar defeito no escritório —
     * e é por isso que esta frase **não** serve para o caso em que o provedor
     * respondeu "mande outro termo", que tem a sua própria linha em
     * `tratarRecusa()` e a sua própria frase, porque ali a resposta foi um
     * veredito e não uma ausência de resposta.
     */
    private const SEM_RESPOSTA = 'O provedor não confirmou o envio do termo de autorização; o estado que a linha já tinha foi preservado.';

    /**
     * O provedor aceitou o documento e não mandou token.
     *
     * É o estado `validado` sem `autenticado`: existe porque a spec separa os
     * dois, e um `200` sem token é a forma de o provedor chegar lá. Sem a
     * frase, a linha diria `validado` e ninguém saberia por que ela não
     * sustenta chamada nenhuma.
     */
    private const SEM_TOKEN = 'O provedor aceitou o documento, mas não devolveu token de autorização.';

    /**
     * O provedor devolveu o token em cache e **não** disse até quando ele vale.
     *
     * A distinção de `SEM_TOKEN` é o que a frase faz: o token chegou, e o que
     * falta é a validade. Sem validade `validToken()` recusa servir, e a linha
     * fica `validado` — o estado que não afirma uma autorização que o sistema
     * não está servindo. Dizer aqui que o token faltou seria a leitura errada,
     * e mandaria o operador atrás de uma emissão que já deu certo.
     */
    private const SEM_VALIDADE = 'O provedor devolveu o token em cache sem informar até quando ele vale: o termo fica válido, mas sem token em uso.';

    /**
     * O motivo do estado `vencido`, e a frase que diz de quem é a ação.
     *
     * A spec pede que o motivo "nomeie o termo vencido" e que o escritório
     * seja acionado. Dizer "o termo de autorização deste escritório venceu" é
     * a informação inteira: o documento continua gravado, o que falta é a
     * assinatura nova, e nenhum relatório de falha entra nesta conta.
     */
    private const VENCIDO = 'O termo de autorização deste escritório venceu e ele precisa assinar um novo.';

    public function __construct(
        private SerproTermSigner $signer,
        private SerproClient $client,
    ) {}

    /**
     * Assina o termo do escritório, envia ao provedor e guarda o token.
     *
     * **O assinante é chamado uma vez, e só depois do gate.** A ordem é
     * deliberada e é o que o gate existe para proteger: ler a prova é uma
     * comparação de string, e ela vem antes de gastar uma assinatura, antes
     * de gravar um documento e antes de qualquer ida à rede. Um termo
     * assinado num formato que ninguém testou seria trabalho jogado fora — e
     * pior, um documento assinado nesse formato ficaria gravado como se fosse
     * válido.
     *
     * **A conta e o certificado são relidos pelo `id`**, nunca pelo tenant
     * corrente: o gate, a credencial e o e-CNPJ têm de ser os **deste**
     * escritório, e o `CurrentTenant` de um `queue:work` é o do job anterior.
     *
     * @throws SerproException
     */
    public function issue(int $accountId): SerproAuthorizationTerm
    {
        $conta = Account::query()->find($accountId);

        if ($conta === null) {
            throw new SerproException(
                'A conta do termo de autorização não existe mais.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

        /*
         * **A credencial é lida uma vez, e o gate é lido dela.**
         *
         * Duas leituras seriam duas chances de ver linhas diferentes: se a
         * credencial mudasse no meio, o gate poderia ser decidido por uma linha
         * e o documento ser assinado com o `contratante_numero` de outra — e o
         * termo sairia com uma empresa no `destinatario` que nenhuma prova
         * autorizou. Por isso `assertGate()` recebe a linha em vez de relê-la,
         * e a docblock de `conexaoDaPlataforma()` diz que ela é lida aqui
         * **antes** do gate, que é a ordem que a frase descreve.
         */
        $conexao = $this->conexaoDaPlataforma();

        $this->assertGate($conexao);

        $certificado = $this->certificadoCorrente($accountId);

        /*
         * **O assinante é chamado aqui, uma única vez, e o que ele devolve é
         * o documento.** Não há segunda chamada em outro lugar deste arquivo,
         * e `refresh()` não o chama: essa é a assimetria que faz a renovação
         * ser renovação e não emissão. A assinatura custa uma operação de
         * chave privada, e o resultado seria gravado cifrado e reenviado nos
         * próximos trinta dias.
         *
         * **O número dos trinta dias é `SerproTermSigner::PERIODO_VIGENCIA_DAYS`,
         * e ele é um valor não confirmado.** A linha acima descreve a
         * constante, não um fato do provedor: por que trinta e não o fim do
         * exercício, e qual é o contra-argumento, estão na docblock da
         * constante. Quem lê esta linha sem ler aquela está lendo mais do que o
         * código sabe.
         */
        $assinado = $this->signer->sign($conta, $certificado, (string) $conexao->contratante_numero);

        $termo = $this->guardar($accountId, $certificado->document, $assinado);

        return $this->enviar($termo, $certificado->document, $assinado);
    }

    /**
     * Reenvia o documento já guardado e guarda o token novo, sem assinar de
     * novo.
     *
     * **O vencimento do documento é conferido antes de qualquer coisa**, e a
     * linha passa a `vencido` sem sair daqui. Um termo cuja vigência acabou
     * não manda mais nada ao provedor: ele responderia `304` com um token
     * para um documento que a plataforma já declarou fora, e o estado
     * diria "autenticado" para uma autorização que não existe mais.
     *
     * **O documento decifrado é o que vai no corpo, sem passar pelo
     * assinante.** Um byte diferente aqui é outro documento, e o `304` deixaria
     * de ser a resposta certa.
     *
     * @throws SerproException
     */
    public function refresh(int $accountId): SerproAuthorizationTerm
    {
        $termo = $this->termoDe($accountId);

        if ($this->documentoVencido($termo)) {
            $termo->forceFill([
                'state' => SerproAuthorizationTermState::Vencido,
                'state_reason' => self::VENCIDO,
            ])->save();

            return $termo;
        }

        /*
         * **Um termo recusado não é reenviado.**
         *
         * O provedor leu este documento e concluiu que ele não serve; a única
         * coisa que ele pode ver no reenvio são os mesmos bytes, e a resposta
         * seria a mesma. Reenviar todo dia por conta transformaria um defeito
         * permanente em consumo de cota e um registro de log por dia por
         * escritório, e a linha ia continuar `recusado` durante todo esse
         * tempo sem que nada tivesse tentado fazer diferente.
         *
         * O caminho que resolve é o do **`ResubmitTerm`**: um documento novo,
         * assinado de novo. E nada no sistema reassina hoje — a única alavanca
         * é o escritório reentregar o e-CNPJ, que dispara a emissão. O motivo
         * gravado na recusa diz isso, para que a tela não peça "tente de novo"
         * a quem não pode.
         */
        if ($termo->state === SerproAuthorizationTermState::Recusado) {
            return $termo;
        }

        // O autor é o documento que **assinou** o termo, e não o do
        // certificado que a conta tem hoje. Trocar o e-CNPJ depois de assinar
        // mudaria para quem o termo é endereçado, e um documento jurídico
        // endereçado a uma empresa sob o qual não foi assinado é outro
        // documento.
        return $this->enviar($termo, (string) $termo->author_document, $this->documentoCifrado($termo));
    }

    /**
     * O token de autorização do escritório, ou `null` quando não há token que
     * sustente uma chamada.
     *
     * **As quatro recusas, e cada uma tem um conserto diferente.** Sem termo,
     * é o escritório que precisa entregar o e-CNPJ. Com o documento vencido,
     * é o escritório que precisa assinar de novo. Com o token vencido, é a
     * renovação que resolve, e ela acontece sozinha. Com o estado `recusado`
     * ou `pendente`, é o provedor ou a plataforma que precisa agir. O plano
     * 04 depende desta distinção: tratar as quatro como "sem autorização"
     * apresentaria ao escritório um defeito que é nosso.
     *
     * **Nunca lança, e é por isso que é um `?string`.** Um
     * `DecryptException` subindo daqui viraria `500` no meio de uma
     * sincronização, longe de quem tem o que fazer; e um token que não abre é
     * o mesmo caso de "não há token que sirva", que é o que o chamador
     * precisa saber.
     *
     * **O que ele devolve é uma credencial de chamada, e é o plano 03 que a
     * pede.** O documento assinado não sai por aqui, e não há caminho público
     * no model que o devolva.
     */
    public function validToken(int $accountId): ?string
    {
        $termo = SerproAuthorizationTerm::currentFor($accountId);

        if ($termo === null || ! $termo->authorizesGateway()) {
            return null;
        }

        if ($this->documentoVencido($termo)) {
            return null;
        }

        if ($termo->token_expires_at === null || $termo->token_expires_at->isPast()) {
            return null;
        }

        try {
            return $termo->token();
        } catch (SerproException) {
            return null;
        }
    }

    // -------------------------------------------------------------------- o gate

    /**
     * O gate de emissão, lido no único lugar onde o predicado está escrito.
     *
     * **A recusa é uma `SerproException` e não um retorno.** Um retorno —
     * `null`, ou `false` — obrigaria cada chamador a lembrar de checar, e o
     * chamador que esquecesse emitiria um termo sem prova nenhuma sem que
     * nada falhasse. Uma exceção faz o erro aparecer no ponto em que ele
     * acontece.
     *
     * **A falha é `DoNotRetry`, e é a única leitura correta disso aqui.** O
     * `SerproFailure` diz que corrigir e tentar de novo não resolve, e é
     * exato: o gate não abre com nova tentativa, ele abre com um teste de
     * contrato ou com a gravação da prova de um. Repetir a emissão três vezes
     * produziria três `SerproException` e o mesmo gate fechado.
     *
     * @throws SerproException
     */
    private function assertGate(SerproConnection $conexao): void
    {
        $prova = $conexao->termProof();

        if ($prova === SerproTermProof::Provado) {
            return;
        }

        throw new SerproException($prova->label(), SerproFailure::DoNotRetry, 0);
    }

    /**
     * A credencial de plataforma, e ela tem de existir.
     *
     * **Quem a chama tem de lê-la antes do gate, e não depois**, porque sem
     * linha não há gate para ler: uma instalação sem credencial tem de dizer que
     * a credencial falta, e não que a prova falta — a segunda mensagem mandaria
     * o operador atrás de um documento de teste de contrato quando o que ele não
     * tem é a credencial de plataforma. É essa a ordem que `issue()` segue, e é
     * a leitura que a linha única desta chamada permite.
     *
     * @throws SerproException
     */
    private function conexaoDaPlataforma(): SerproConnection
    {
        $conexao = SerproConnection::current();

        if ($conexao === null) {
            throw new SerproException(
                'Conexão com o Integra Contador não configurada.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

        return $conexao;
    }

    // -------------------------------------------------------------------- e-CNPJ

    /**
     * O e-CNPJ corrente do escritório, e ele tem de estar **em vigor**.
     *
     * A vigência é conferida aqui e não só no cofre, mesmo que o cofre já
     * recuse e-CNPJ vencido no upload. São duas defesas: o cofre recusa no
     * instante da entrega, e esta guarda cobre o e-CNPJ que venceu **depois**
     * de entregue — que é o caso comum, e é o que faria o provedor recusar o
     * termo com um código que nada no produto traduz.
     *
     * @throws SerproException
     */
    private function certificadoCorrente(int $accountId): AccountCertificate
    {
        $certificado = AccountCertificate::currentFor($accountId);

        if ($certificado === null) {
            throw new SerproException(
                'O escritório ainda não entregou o e-CNPJ que assina o termo de autorização.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

        if ($certificado->valid_until->isPast()) {
            throw new SerproException(
                'O e-CNPJ do escritório está vencido: envie um certificado vigente.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

        return $certificado;
    }

    // ------------------------------------------------------------- persistência

    /**
     * A linha do termo, e ela tem de existir.
     *
     * Não é um `findOrFail`, porque o `404` do Eloquent viraria `404` HTTP e
     * aqui a ausência de termo é um estado conhecido: a emissão é do upload
     * do e-CNPJ, e o comando diário só renova quem já tem linha.
     *
     * @throws SerproException
     */
    private function termoDe(int $accountId): SerproAuthorizationTerm
    {
        $termo = SerproAuthorizationTerm::currentFor($accountId);

        if ($termo === null) {
            throw new SerproException(
                'Esta conta ainda não tem termo de autorização emitido.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

        return $termo;
    }

    /**
     * Grava o termo recém-assinado, ou substitui o que estava valendo.
     *
     * **A substituição apaga o token e a validade do token antigos.** Um termo
     * novo é outro documento, e o token do anterior é a credencial de uma
     * autorização que este mesmo documento novo revogou. Deixá-lo ali faria
     * `validToken()` devolver um token que o provedor já não reconhece, e a
     * sincronização seguinte falharia com um erro de gateway que nada em
     * `serpro_authorization_terms` explicaria.
     *
     * **O estado é `pendente` entre a gravação e a resposta**, e é o estado
     * que a leitura da API mostra durante a janela. A tela precisa de uma
     * posição entre "não há termo" e "o termo vale": sem ela, o `200` do
     * envio do e-CNPJ mostraria ao escritório que ele está autorizado antes de
     * o provedor ter dito que sim.
     *
     * **A vigência vem do documento, e não de uma segunda conta.** O
     * `vigencia/@data` é o que o provedor vai ler, e recalcular
     * `now()->addDays(30)` aqui seria uma segunda leitura do relógio: no mesmo
     * instante as duas coincidem, e na fronteira da meia-noite de São Paulo
     * divergem do que está gravado no documento que vai ser enviado. Ler do
     * documento é o que garante que a linha e os bytes dizem a mesma coisa.
     *
     * `forceFill` sobre uma linha lida explicitamente, e **não** o
     * `updateOrCreate` que o plano 02 traz escrito. A spec exige que as
     * colunas cifradas não sejam `Fillable` no model, e é essa ausência que
     * protege o documento e o token de um `fill($request->validated())`;
     * `updateOrCreate` é atribuição em massa, e com a lista fechada ele
     * gravaria uma linha com `document_encrypted` nulo — o pior desfeço
     * possível para um termo, e silencioso: a linha existiria, a leitura
     * mostraria um termo guardado, e a renovação não teria o que reenviar. O
     * `forceFill` ignora a lista **por ser este o autor dos valores** — ele os
     * cifrou neste mesmo passo —, que é a mesma razão que faz o cofre do
     * e-CNPJ atravessar a lista dele.
     *
     * O índice único de `account_id` continua sendo a garantia de uma linha, e
     * a leitura é por `account_id` explícito porque o escopo global de
     * `BelongsToAccount` não filtra nada no console nem na fila.
     *
     * @throws SerproException
     */
    private function guardar(int $accountId, string $authorDocument, string $signedXml): SerproAuthorizationTerm
    {
        $attributes = [
            'account_id' => $accountId,
            'author_document' => $authorDocument,
            'document_encrypted' => Crypt::encryptString($signedXml),
            'token_encrypted' => null,
            'document_expires_on' => $this->vigenciaDoDocumento($signedXml),
            'token_expires_at' => null,
            'state' => SerproAuthorizationTermState::Pendente,
            'state_reason' => null,
            'signed_at' => now(),
            // `null` e não o instante: o documento acabou de ser assinado e
            // **ainda não foi enviado**. Um termo novo que substitui um antigo
            // perde aqui a marca do envio anterior, e é o certo — aquele envio
            // era de um documento que não existe mais, e a coluna responde pelo
            // documento que está na linha.
            'last_submitted_at' => null,
        ];

        $termo = SerproAuthorizationTerm::query()->where('account_id', $accountId)->first();

        if ($termo !== null) {
            $termo->forceFill($attributes)->save();

            return $termo;
        }

        return SerproAuthorizationTerm::forceCreate($attributes);
    }

    /**
     * A vigência lida do documento assinado, e a recusa quando ela não está.
     *
     * Um termo sem `vigencia/@data` é um termo que não expira, e o efeito
     * disso é o pior possível em silêncio: o escritório aparece autorizado
     * para sempre, e nenhuma tela, nenhum job e nenhum alerta tem como dizer
     * que a validade não existe. O modelo de referência do provedor escreve o
     * elemento e `SerproTermSigner` o monta, então a ausência seria um defeito
     * nosso — e um defeito nosso não pode virar um estado de negócio calado.
     *
     * @throws SerproException
     */
    private function vigenciaDoDocumento(string $signedXml): Carbon
    {
        $documento = new DOMDocument;
        $documento->preserveWhiteSpace = true;

        $anterior = libxml_use_internal_errors(true);

        try {
            $carregado = $documento->loadXML($signedXml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }

        $data = $carregado === false ? null : $this->vigenciaEm($documento);

        if (! is_string($data) || preg_match('/^\d{8}$/', $data) !== 1) {
            throw new SerproException(
                'O termo assinado não traz a vigência que o provedor precisa ler.',
                SerproFailure::NotSent,
                0,
            );
        }

        try {
            return Carbon::createFromFormat('!Ymd', $data)->startOfDay();
        } catch (InvalidFormatException) {
            throw new SerproException(
                'O termo assinado não traz a vigência que o provedor precisa ler.',
                SerproFailure::NotSent,
                0,
            );
        }
    }

    /**
     * O `vigencia/@data` do documento, e `null` quando ele não está lá.
     *
     * O `XPath` é pedido explicitamente porque o documento **tem** namespace
     * depois de assinado: a `Signature` declara `xmldsig`, e um caminho
     * prefixado pegaria o elemento errado. A raiz do termo, essa sim, não
     * declara namespace nenhum — é a condição de que a canonicalização
     * exclusiva do cálculo do digest coincida com a inclusiva que a
     * `Reference` declara, e ela é garantida por construção do
     * `SerproTermSigner`.
     */
    private function vigenciaEm(DOMDocument $documento): ?string
    {
        $no = (new DOMXPath($documento))->query('/*[local-name()="termoDeAutorizacao"]/dados/vigencia')?->item(0);

        return $no === null ? null : $no->attributes?->getNamedItem('data')?->nodeValue;
    }

    // -------------------------------------------------------------------- o envio

    /**
     * Envia o documento e grava o que o provedor respondeu.
     *
     * **Três respostas, e elas são três estados de negócio diferentes.** Um
     * `200` ou `202` é o provedor aceitando o documento e emitindo um token
     * novo. Um `304` é o provedor dizendo que o documento é o mesmo e o token
     * estava em cache — o caminho que existe para que a renovação não assine.
     * Qualquer outra coisa é **veredito sobre o documento** — que grava
     * `recusado` — ou **evento do provedor**, que não grava nada, e a linha que
     * separa as duas é `tratarRecusa()`.
     *
     * **O `304` não passa por `SerproException`**, e é a razão de
     * `SerproClient::submitTerm()` existir separada de `call()`: `call()`
     * classifica o status pelo provedor e trataria `304` como recusa. O `304`
     * é sucesso sem envelope.
     *
     * @throws SerproException
     */
    private function enviar(SerproAuthorizationTerm $termo, string $autor, string $documento): SerproAuthorizationTerm
    {
        $resposta = $this->client->submitTerm($documento, $autor);

        return $resposta['status'] === 304
            ? $this->guardarTokenDoCache($termo, $resposta)
            : $this->guardarTokenEmitido($termo, $resposta);
    }

    /**
     * O `304`: o token veio no `etag` e a validade no `expires`.
     *
     * **O `etag` é conferido antes de sobrescrever o token, e é essa ordem
     * que importa.** Um `304` cujo `etag` não serve deixa o token que já
     * valia no lugar: ele continua válido até a meia-noite, e trocar um token
     * bom por um `etag` malformado derrubaria a integração do escritório por
     * um cabeçalho. O `304` em si afirma que nada mudou, e a única coisa que
     * ele traz de novo é o token — se esse token não tem a forma de um UUID,
     * o cabeçalho é de um formato que não conhecemos, e recusa-se o
     * cabeçalho em vez do que a linha já tinha.
     *
     * **Sem `expires` utilizável o estado é `validado`, e não `autenticado`.**
     * O provedor disse que o documento é válido e o token estava em cache, e
     * nós gravamos o token; o que não temos é a validade dele, e um token sem
     * validade é um token que `validToken()` recusa servir. Deixar a linha como
     * `autenticado` faria a **tela** — a única coisa que o escritório vê —
     * afirmar que a plataforma fala por ele, no mesmo instante em que o sistema
     * se recusava a falar. `validado` é o estado honesto dos dois casos em que
     * o provedor aceitou o documento e nós não temos token que valha, e é a
     * mesma leitura que o `200` sem token já recebia.
     *
     * **O documento não é tocado, e é a propriedade que este caminho existe
     * para garantir.** Nada aqui escreve em `document_encrypted`, e o
     * `signed_at` continua o da emissão: um `304` que devolvesse a linha com
     * `signed_at` de hoje diria que o escritório assinou hoje, e o que
     * aconteceu foi que reenviamos o que ele assinou há vinte dias.
     *
     * @param  array{status: int, etag: ?string, expires: ?string, codigo: ?string, dados: mixed}  $resposta
     *
     * @throws SerproException
     */
    private function guardarTokenDoCache(SerproAuthorizationTerm $termo, array $resposta): SerproAuthorizationTerm
    {
        $token = $this->tokenDoEtag($resposta['etag']);
        $validade = $this->vencimentoDoHeader($resposta['expires']);

        $termo->forceFill([
            'token_encrypted' => Crypt::encryptString($token),
            'token_expires_at' => $validade,
            'state' => $validade === null
                ? SerproAuthorizationTermState::Validado
                : SerproAuthorizationTermState::Autenticado,
            'state_reason' => $validade === null ? self::SEM_VALIDADE : null,
            'last_submitted_at' => now(),
        ])->save();

        return $termo;
    }

    /**
     * A emissão aceita: o token e a validade vieram em `dados`.
     *
     * **O `dados` é uma string escapada dentro do envelope**, e o
     * `SerproEnvelope::parse()` já a abre em até duas passadas. Sem token
     * num `200` o estado gravado é `validado` e não `autenticado`, e a
     * diferença importa: `validado` é o documento que o provedor aceitou, e
     * `autenticado` é o que já rendeu token. Tratar os dois como o mesmo
     * estado apresentaria ao escritório um termo "autorizado" que nenhuma
     * chamada ao gateway aceitou, e a falha apareceria no meio de uma
     * sincronização, longe da tela que a causou.
     *
     * @param  array{status: int, etag: ?string, expires: ?string, codigo: ?string, dados: mixed}  $resposta
     *
     * @throws SerproException
     */
    private function guardarTokenEmitido(SerproAuthorizationTerm $termo, array $resposta): SerproAuthorizationTerm
    {
        if (! in_array($resposta['status'], [200, 202], true)) {
            $this->tratarRecusa($termo, $resposta);
        }

        $dados = is_array($resposta['dados']) ? $resposta['dados'] : [];
        $token = $this->textoDe($dados, 'autenticar_procurador_token');
        $validade = $this->textoDe($dados, 'data_hora_expiracao');

        $termo->forceFill([
            'token_encrypted' => $token === '' ? null : Crypt::encryptString($token),
            'token_expires_at' => $this->vencimentoDoProvedor($validade),
            'state' => $token === ''
                ? SerproAuthorizationTermState::Validado
                : SerproAuthorizationTermState::Autenticado,
            'state_reason' => $token === '' ? self::SEM_TOKEN : null,
            'last_submitted_at' => now(),
        ])->save();

        return $termo;
    }

    /**
     * O que a resposta que não é sucesso é, gravado onde for preciso.
     *
     * **O provedor respondeu, e a linha registra o veredito — exceto quando ele
     * não respondeu.** A distinção que importa é entre *recusa* e *falha de
     * transporte*, e ela é lida em dois grupos:
     *
     * - `DoNotRetry` e `ResubmitTerm` são **veredito sobre o documento**. O
     *   provedor leu o termo e concluiu que este não serve, e repetir não muda
     *   a resposta. As duas gravam `recusado`.
     * - `Reauthenticate`, `Throttled`, `Indeterminate` e `Upstream` são
     *   **evento do provedor**: o documento continua sendo o mesmo, e o token que
     *   ainda vale continua valendo. Marcar `recusado` apresentaria ao
     *   escritório um defeito que é nosso, e o obrigaria a assinar de novo.
     *
     * **`ResubmitTerm` é veredito, e é o caso em que a versão anterior errava
     * de forma cara.** `AcessoNegado-ICGERENCIADOR-020` e `-042` significam
     * "este termo não serve, mande outro"; com elas caindo no ramo de
     * indisponibilidade, a linha continuava `autenticado` servindo um token que
     * o provedor não aceita, a renovação diária repetia a mesma recusa para
     * sempre, e a mensagem dizia que o provedor não confirmou o envio — o
     * oposto do que aconteceu, porque ele respondeu.
     *
     * **A ação que o motivo nomeia é re-assinar, e nada no sistema a faz.**
     * Um termo recusado precisa de um documento novo assinado, e a única
     * alavanca que o produto tem hoje é o escritório reentregar o e-CNPJ, que
     * dispara a emissão. A frase diz isso, e não diz "tente de novo": reenviar
     * os mesmos bytes ao provedor que já os recusou não pode dar outro
     * resultado, e é por isso que `refresh()` não reenvia uma linha `recusado`.
     *
     * **O motivo gravado é código do provedor ou frase nossa, nunca o texto
     * dele.** A requisição recusada carregava o documento assinado do
     * escritório, e o texto que o provedor escreve descreve esse documento.
     *
     * **O documento assinado é preservado.** Ele é a evidência do que foi
     * enviado, e a próxima emissão vai assinar um novo — mas apagar o anterior
     * faria a recusa ser indistinguível de "nunca foi enviado".
     *
     * @param  array{status: int, etag: ?string, expires: ?string, codigo: ?string, dados: mixed}  $resposta
     *
     * @throws SerproException
     */
    private function tratarRecusa(SerproAuthorizationTerm $termo, array $resposta): never
    {
        $codigo = (string) $resposta['codigo'];
        $falha = SerproException::classify($resposta['status'], $codigo);

        if (! in_array($falha, [SerproFailure::DoNotRetry, SerproFailure::ResubmitTerm], true)) {
            throw new SerproException(self::SEM_RESPOSTA, $falha, $resposta['status'], $codigo === '' ? null : $codigo);
        }

        $motivo = $falha === SerproFailure::ResubmitTerm
            ? sprintf('O provedor recusou este termo e pede outro documento assinado de novo (código %s).', $codigo === '' ? 'não informado' : $codigo)
            : ($codigo === '' ? self::RECUSA : $codigo);

        $termo->forceFill([
            'state' => SerproAuthorizationTermState::Recusado,
            'state_reason' => $motivo,
            // `last_submitted_at` **não** é carimbado aqui: a resposta é uma
            // recusa, e carimbá-la faria a linha dizer que o envio foi aceito
            // há um minuto. Quem grava o carimbo é o caminho do token, que é o
            // único em que o provedor aceitou o documento.
        ])->save();

        throw new SerproException(self::RECUSA, $falha, $resposta['status'], $codigo === '' ? null : $codigo);
    }

    // -------------------------------------------------------------------- leitura

    /**
     * A vigência do documento acabou, e "acabou" é uma comparação de **dia
     * com dia**, não de instante com instante.
     *
     * **A vigência é o `vigencia/@data` do termo, que é `AAAAMMDD` — um
     * calendário, e o documento vale por ele inteiro.** Ler a coluna `date`
     * como instante daria a meia-noite do dia da vigência como vencimento, e
     * o termo estaria vencido **durante** o dia em que ainda vale: às 00:01
     * de 9 de abril, um termo com vigência `20260409` seria recusado. É a
     * leitura errada de um dia inteiro de validade, e o efeito é pedir ao
     * escritório que assine de novo todo dia à meia-noite.
     *
     * **O dia de hoje é o do fuso em que o termo foi escrito**, que é
     * `SerproTermSigner::FUSO` — o mesmo que a vigência usou quando saiu da
     * assinatura. Comparar um dia de São Paulo com o "hoje" de um servidor em
     * UTC desloca a fronteira em três horas, e perto da meia-noite o sistema
     * declararia o termo vencido três horas cedo ou três horas tarde.
     *
     * As duas datas são `Y-m-d`, e a comparação é de string: para esse
     * formato a ordem lexicográfica é a ordem do calendário, e isso evita
     * ter que escolher entre dois fusos dentro da mesma expressão.
     */
    private function documentoVencido(SerproAuthorizationTerm $termo): bool
    {
        $vigencia = $termo->document_expires_on?->format('Y-m-d');

        if ($vigencia === null) {
            // Sem vigência gravada, devolver `false` apresentaria o
            // escritório como autorizado para sempre, e nenhum produto tem
            // como dizer isso. A linha sem vigência é inalcançável pelo
            // caminho normal — a coluna é `NOT NULL` e o manager sempre a
            // grava —, e devolvendo `true` o efeito é o seguro: a renovação
            // assume e a emissão recria o termo.
            return true;
        }

        return $vigencia < Carbon::now(SerproTermSigner::FUSO)->format('Y-m-d');
    }

    /**
     * O token do cabeçalho `etag`, conferido antes de ser usado.
     *
     * **Por que o UUID é conferido, e não só lido.** O `etag` é um cabeçalho
     * de resposta de um serviço de terceiros, e é o valor que vira a
     * credencial de chamada do escritório. Aceitar qualquer texto ali seria
     * aceitar que um `etag` malformado — ou uma resposta de gateway que
     * trouxesse o campo com outro sentido — virasse o token guardado. A
     * forma do UUID é a única parte do contrato do `304` que o repositório
     * consegue verificar, e ela é barata.
     *
     * **As duas formas do cabeçalho são aceitas, e nenhuma das duas é um
     * detalhe de teste.** A documentação do provedor mostra o valor entre
     * aspas e com o nome do campo na frente do token — `etag: "autenticar_procurador_token:<uuid>"` —, e o
     * plano 02 traz o UUID cru. Aceitar só a primeira reprovaria a renovação
     * de todos os escritórios em produção; aceitar só a segunda reprovaria a
     * linha do plano.
     *
     * **O que volta é o valor do provedor, e não uma versão normalizada dele.**
     * A comparação do UUID é a validação; o token gravado é o que o cabeçalho
     * trazia, byte a byte. Um `strtolower()` aqui seria reescrever os bytes de
     * uma credencial que é devolvida ao provedor como cabeçalho `auth` em
     * toda chamada, e o ganho seria zero — o exemplo que ele publica é
     * minúsculo, o que torna a normalização invisível no teste e um
     * `strtoupper()` do provedor, amanhã, um `401` que ninguém atribuiria à
     * forma do token.
     *
     * @throws SerproException
     */
    private function tokenDoEtag(?string $etag): string
    {
        $valor = trim(trim((string) $etag), '"');
        $prefixo = 'autenticar_procurador_token:';

        if (str_starts_with($valor, $prefixo)) {
            $valor = substr($valor, strlen($prefixo));
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $valor) !== 1) {
            throw new SerproException(
                'A resposta de não-modificação do provedor não traz um token de autorização reconhecível.',
                SerproFailure::Upstream,
                502,
            );
        }

        return $valor;
    }

    /**
     * A validade do `304`, e o `expires` é uma data HTTP da RFC 7231.
     *
     * **O que a documentação diz e o que o exemplo mostra divergem, e o
     * código segue o exemplo.** O texto do provedor afirma que o token vale
     * "até a meia-noite do dia seguinte, horário de Brasília", e o `expires`
     * do exemplo é `Sat, 15 Oct 2022 00:00:01 GMT`, que em Brasília são 21:00
     * do dia 14 — não a meia-noite. Ler a data como ela está é o que não
     * inventa reconciliação entre duas afirmações que se contradizem;
     * ajustá-la para a meia-noite de Brasília seria escolher um lado sem
     * prova. O que só um teste de contrato contra o provedor resolve é isto,
     * e é uma das três coisas que `tasks.md` 4.6a ainda tem por pagar.
     *
     * Sem `expires` utilizável, a validade **não é inventada**: fica `null`, e
     * um token sem validade não é servido por `validToken()` — e a linha passa a
     * `validado`, que é o estado que não afirma uma autorização que o sistema
     * não está servindo. O escritório continua precisando da renovação, que é o
     * conserto seguro.
     */
    private function vencimentoDoHeader(?string $expires): ?Carbon
    {
        $bruto = trim((string) $expires);

        if ($bruto === '') {
            return null;
        }

        return $this->instanteOuNulo($bruto, DATE_RFC7231, 'UTC');
    }

    /**
     * A validade que o provedor escreve em `data_hora_expiracao`.
     *
     * O formato é `yyyy-MM-dd'T'HH:mm:ss` e o fuso é o do provedor, o mesmo
     * que as datas do documento levam. Sem ela — ou com um texto que não é
     * essa data — a validade fica `null`, e o efeito é o mesmo do `expires`
     * ausente: o token não é servido, e a renovação assume.
     *
     * **O instante é convertido para UTC antes de ser gravado, e isso não é
     * detalhe de fuso.** Guardar `2026-03-11 00:00:01` numa coluna `timestamp`
     * e relê-lo como UTC devolveria a mesma string, e a meia-noite de São
     * Paulo passaria a valer como meia-noite de Greenwich: o token viveria
     * três horas depois de o provedor o ter invalidado, e a sincronização
     * falharia com um `401` que nada no produto traduz.
     */
    private function vencimentoDoProvedor(?string $validade): ?Carbon
    {
        $bruto = trim((string) $validade);

        if ($bruto === '') {
            return null;
        }

        return $this->instanteOuNulo($bruto, '!Y-m-d\TH:i:s', SerproTermSigner::FUSO);
    }

    /**
     * Uma data que o provedor mandou, ou `null` — e o `null` é a resposta certa
     * para tudo que não é uma data.
     *
     * **O `try`/`catch` sozinho não basta, e a razão é medida.**
     * `Carbon::createFromFormat()` só lança quando a entrada não casa com o
     * formato; quando casa *e* os campos não formam uma data real, o PHP
     * **transborda** e devolve um instante: `2026-13-45T99:99:99` vira
     * `2023-02-18 07:40:39`, que é uma data futura, válida e silenciosamente
     * errada. Um token com essa validade seria servido por um ano, e a linha
     * diria que o provedor mandou uma data — o que ele não mandou.
     *
     * `DateTime::getLastErrors()` é o que separa "a data que o provedor
     * mandou" de "a data que o PHP inventou a partir de uma que não existe":
     * o `warning` de transbordo de campo aparece nele mesmo quando a função
     * não lançou. Checá-lo **depois** da conversão, e não antes, é o que cobre
     * os dois casos — o `catch` continua existindo para a entrada que nem casa
     * com o formato, que é a outra metade.
     *
     * O resultado é convertido para UTC aqui, porque a coluna é `timestamp` e
     * a leitura dela é feita como UTC.
     *
     * @param  string  $formato  o formato que o provedor publica para o campo
     */
    private function instanteOuNulo(string $bruto, string $formato, string $fuso): ?Carbon
    {
        try {
            $instante = Carbon::createFromFormat($formato, $bruto, $fuso);
        } catch (InvalidFormatException) {
            return null;
        }

        if ($instante === false) {
            return null;
        }

        $erros = DateTimeImmutable::getLastErrors();

        // `getLastErrors()` devolve `false` quando não houve nem warning nem
        // erro — e o PHP 8.2+ mudou isso de "array vazia" para `false`, de modo
        // que a comparação tem de ser por estrutura, não por contagem.
        if (is_array($erros) && ($erros['warning_count'] > 0 || $erros['error_count'] > 0)) {
            return null;
        }

        return $instante->utc();
    }

    /**
     * O documento assinado, decifrado, e nada mais o devolve.
     *
     * **Este é o único lugar do sistema que decifra o termo.** O model não tem
     * método público que o devolva, de propósito: a spec exige que o XML
     * assinado nunca saia do backend, e a forma de garantir isso é não ter
     * por onde ele saia. Um `Crypt::decryptString` no controller seria uma
     * linha até alguém precisar dele numa tela.
     *
     * **A decifra que falha é uma falha nomeada**, e não um
     * `DecryptException` virando `500` no meio da renovação: `APP_KEY` girada
     * ou coluna truncada deixam o termo ilegível, e o que se precisa dizer ao
     * operador é que ele tem de entregar o e-CNPJ de novo.
     *
     * @throws SerproException
     */
    private function documentoCifrado(SerproAuthorizationTerm $termo): string
    {
        $cifrado = (string) $termo->getRawOriginal('document_encrypted');

        if ($cifrado === '') {
            throw new SerproException(
                'O termo de autorização gravado não guarda documento assinado.',
                SerproFailure::NotSent,
                0,
            );
        }

        try {
            return Crypt::decryptString($cifrado);
        } catch (DecryptException) {
            throw new SerproException(
                'O termo de autorização gravado não pôde ser lido: o conteúdo guardado não abre com a chave de aplicação atual.',
                SerproFailure::NotSent,
                0,
            );
        }
    }

    /**
     * O `dados` do envelope é um objeto ou nada, e a leitura abaixo só quer
     * dois campos de texto: um objeto onde a chave não é o que se espera, ou
     * um valor que não é escalar, não vira string.
     *
     * @param  array<mixed>  $dados
     */
    private function textoDe(array $dados, string $campo): string
    {
        $valor = $dados[$campo] ?? null;

        return is_scalar($valor) ? trim((string) $valor) : '';
    }
}
