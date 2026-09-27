<?php

namespace App\Services\Fiscal\Capture;

use App\Enums\FiscalStage;

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
     * `{account_id}/{client_id}/{chave_acesso}-{event_id|etapa}.xml`.
     *
     * `event_id` vazio é o caso comum, e o nome do arquivo passa a ser o da
     * etapa: o resumo (`resumo`) e o documento completo (`documento`) são duas
     * entregas de distribuição do mesmo documento, com a mesma chave de acesso, e
     * cada uma precisa do seu XML em disco. O sufixo é o que separa as várias
     * etapas que chegam sob a mesma chave de acesso.
     *
     * A etapa é o quinto parâmetro, e o padrão é a etapa de documento: quem passa
     * um `event_id` não precisa dela, porque o identificador do evento é mais
     * específico e é o que nomeia o arquivo. Quem passa `event_id` vazio precisa
     * dizer em que etapa está — resumo e documento completo têm a mesma chave e o
     * mesmo `event_id` vazio, e sem a etapa o segundo herdaria o arquivo do
     * primeiro, que é o defeito que a coluna `stage` veio resolver na chave.
     */
    public static function for(
        int $accountId,
        int $clientId,
        string $chaveAcesso,
        string $eventId = '',
        ?FiscalStage $stage = null,
    ): string {
        return sprintf(
            '%d/%d/%s-%s.xml',
            $accountId,
            $clientId,
            $chaveAcesso,
            $eventId !== '' ? $eventId : ($stage?->fileToken() ?? FiscalStage::Document->fileToken()),
        );
    }
}
