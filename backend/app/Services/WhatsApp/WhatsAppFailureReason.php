<?php

namespace App\Services\WhatsApp;

/**
 * O texto de falha que sobe para a UI do Rastreio.
 *
 * `failure_reason` vive em `whatsapp_dispatches` e volta na API da folha —
 * é a única explicação que o Membro lê. Texto de gateway pode ecoar a
 * própria apikey na recusa de autenticação, e a resposta de erro do wzap
 * (`{"error":{"code","message"}}`) cita código e mensagem arbitrários: o
 * segredo da instância e a chave global são redigidos antes de gravar, e
 * o resto é truncado no tamanho da coluna.
 */
final class WhatsAppFailureReason
{
    /**
     * Redige a apikey da instância e a chave global do wzap no motivo e
     * trunca no limite da coluna. `null` ou vazio vira o motivo genérico
     * de falha, nunca texto cru do gateway.
     */
    public static function sanitizar(?string $reason, ?string $instanceApiKey = null): string
    {
        $segredos = array_filter([
            $instanceApiKey,
            config('whatsapp.wzap.api_key'),
        ], fn ($valor): bool => is_string($valor) && $valor !== '');

        $limpo = mb_substr(
            str_replace($segredos, '[redigido]', (string) $reason),
            0,
            200,
        );

        return $limpo !== '' ? $limpo : 'O gateway recusou o envio. Tente novamente.';
    }
}
