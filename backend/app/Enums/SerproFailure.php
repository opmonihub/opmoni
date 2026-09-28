<?php

namespace App\Enums;

enum SerproFailure: string
{
    case Success = 'success';
    case Reauthenticate = 'reauthenticate';
    case ResubmitTerm = 'resubmit_term';
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
