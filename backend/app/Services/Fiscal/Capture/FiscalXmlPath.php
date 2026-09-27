<?php

namespace App\Services\Fiscal\Capture;

/**
 * Caminho relativo do XML no disco `fiscal`, derivado da identidade do
 * documento.
 *
 * O contrato mora aqui, e não numa factory nem no writer, porque a coluna
 * `storage_path` é lida de volta por quem serve o download: a factory de teste
 * e a produção precisam derivar exatamente a mesma string, senão todo teste
 * que abre o arquivo pela coluna passa por um caminho que a produção nunca
 * escreve.
 */
final class FiscalXmlPath
{
    /**
     * `{account_id}/{client_id}/{chave_acesso}-{event_id|documento}.xml`.
     *
     * `event_id` vazio é o caso comum — documento sem evento — e recebe o
     * rótulo `documento` para o arquivo continuar legível. O sufixo é o que
     * separa as várias etapas da distribuição que chegam sob a mesma chave de
     * acesso (resumo, documento completo, evento).
     */
    public static function for(
        int $accountId,
        int $clientId,
        string $chaveAcesso,
        string $eventId = '',
    ): string {
        return sprintf(
            '%d/%d/%s-%s.xml',
            $accountId,
            $clientId,
            $chaveAcesso,
            $eventId !== '' ? $eventId : 'documento',
        );
    }
}
