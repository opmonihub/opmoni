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
