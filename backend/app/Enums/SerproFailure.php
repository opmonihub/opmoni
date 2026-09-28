<?php

namespace App\Enums;

enum SerproFailure: string
{
    case Success = 'success';
    case Reauthenticate = 'reauthenticate';
    case ResubmitTerm = 'resubmit_term';

    /**
     * Corrigir e tentar de novo não resolve: o conserto é humano.
     *
     * O rótulo vale para "falha nossa de configuração" e para "recusa do que
     * foi enviado", e é por isso que este é o caso **menos informativo** da
     * taxonomia: ele afirma que repetir é inútil e nada mais. Quem recebe
     * precisa de outra fonte para saber *o que* houve, e quase sempre não tem
     * essa fonte — por isso ele raramente chega cru ao consumidor.
     *
     * **Oito produtores em quatro famílias, e o `status` é o único que separa
     * duas delas.**
     *
     * *Família local — `status` zero, nenhum provedor chegou a ver nada.*
     * Nenhuma delas pede o mesmo conserto:
     *
     * 1. *Não existe credencial.* `SerproClient::call()` e
     *    `SerproTokenProvider::authenticate()` relêem a linha e
     *    `SerproConnection::current()` devolve `null`. O conserto é
     *    **cadastrar**, e não corrigir.
     * 2. *Serviço fora do catálogo.* `SerproClient::service()` não achou o
     *    `idServico` em `config('integra-contador.services')`. É defeito de
     *    código nosso — falta a entrada no catálogo —, e a mensagem nomeia o
     *    serviço, que não é segredo.
     * 3. *Certificado.* `SerproConnection::assertIdentity()` para certificado
     *    vencido e para documento divergente,
     *    `SerproConnection::cachedDocument()` para identidade ilegível e
     *    `SerproCertificateMaterializer::withCertificate()` para certificado
     *    ausente. O conserto é **trocar o certificado** ou recadastrar a
     *    credencial.
     *
     * *Família do provedor — `status` real.* Dois caminhos distintos chegam
     * nela, e nenhum deles devolve `status` zero porque a resposta que os
     * produziu carrega o `status` dela: `SerproTokenProvider::refusal()` para a
     * autenticação e `SerproException::classify()` para a chamada de serviço,
     * cada um no seu `4xx` que não seja `429` nem `504`. O conserto é **revisar
     * chave, segredo e certificado**, e é a única família em que houve resposta
     * de alguém.
     *
     * Três famílias locais compartilham a assinatura `DoNotRetry` com `status`
     * zero, e nenhuma delas é separável da outra por esse campo — e é por isso
     * que `SerproConnectivity::elementFor()` pode tratar `0` como certificado:
     * lá os guard de `check()` responderam **antes** por "não existe credencial"
     * e por "certificado ausente", o que não é verdade de nenhum outro
     * consumidor. A sincronização da plan 04 não passa por `check()` e vai
     * receber as três indistinguíveis.
     *
     * **A correção é do consumidor, porque o contexto não viaja na exceção.**
     * Quem chama `SerproClient::call()` sabe se está no meio de uma execução e
     * qual serviço pediu; `SerproException` não sabe. Até existir uma
     * conferência que distinga as famílias locais, tratar `DoNotRetry` como uma
     * coisa só — "não repetir, avisar o operador" — é a leitura **segura**:
     * nenhum dos casos se resolve com nova tentativa, e é a única que não inventa
     * um desfecho que ninguém produziu.
     */
    case DoNotRetry = 'do_not_retry';
    case Throttled = 'throttled';
    case Upstream = 'upstream';
    case Indeterminate = 'indeterminate';

    /**
     * Falha local, e nada foi enviado.
     *
     * Diferente de `Indeterminate`, que responde a uma pergunta sobre o
     * provedor: "a requisição pode ter sido aplicada e ninguém sabe". Aqui a
     * pergunta nem chegou a existir — o que falhou aconteceu antes de qualquer
     * requisição, como uma pasta temporária sem gravação ou um cifrado guardado
     * que não abre com a chave de aplicação atual.
     *
     * A distinção é o que impede a plan 04 de tratar o que é falha nossa como
     * resultado do provedor: `Indeterminate` alimenta
     * `SerproSyncItemState::Indeterminate` — caso que a plan 04 ainda vai criar,
     * em `backend/app/Enums/SerproSyncItemState.php` — com `failed = 0`, e uma
     * máquina sem espaço em disco produzia uma execução em que nenhum item
     * falhou e todos ficaram indeterminados. Quem consumir este caso conta a
     * falha e não tenta de novo em seguida — corrigir a máquina é pré-condição,
     * não estratégia.
     */
    case NotSent = 'not_sent';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Concluído',
            self::Reauthenticate => 'Credencial expirada',
            self::ResubmitTerm => 'Termo de autorização inválido',
            self::DoNotRetry => 'Correção necessária',
            self::Throttled => 'Limite do provedor',
            self::Upstream => 'Indisponibilidade do provedor',
            self::Indeterminate => 'Resultado indeterminado',
            self::NotSent => 'Falha local, nada enviado',
        };
    }

    /** @return list<string> */
    public static function reauthenticateCodes(): array
    {
        return [
            'AcessoNegado-ICGERENCIADOR-003',
            'AcessoNegado-ICGERENCIADOR-004',
            'AcessoNegado-ICGERENCIADOR-005',
            'AcessoNegado-ICGERENCIADOR-013',
            'AcessoNegado-ICGERENCIADOR-025',
            'AcessoNegado-ICGERENCIADOR-026',
            'AcessoNegado-ICGERENCIADOR-037',
            'AcessoNegado-ICGERENCIADOR-038',
            'AcessoNegado-ICGERENCIADOR-041',
        ];
    }

    /** @return list<string> */
    public static function resubmitTermCodes(): array
    {
        return [
            'AcessoNegado-ICGERENCIADOR-020',
            'AcessoNegado-ICGERENCIADOR-042',
        ];
    }
}
