<?php

namespace App\Services\Fiscal\Manifestacao;

use App\Enums\FiscalFailure;
use App\Enums\FiscalManifestationEventType;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Support\FiscalEnvironment;

/**
 * Confere se o `retEvento` devolvido pelo fisco corresponde ao pedido que saiu
 * — chave, tipo, sequência e ambiente — antes de qualquer veredito.
 *
 * Um lote `128` com um `retEvento` de outra chave ou de outro evento não é
 * aceite da ciência pedida; o `573` só vale como já-manifestado quando a
 * resposta fala da mesma chave lógica.
 */
final class ManifestationEventResponseCorrelator
{
    /**
     * @param  array{chave_acesso: ?string, tp_evento: ?string, event_seq: ?string, tp_amb: ?string}  $resposta
     *
     * @throws FiscalException quando a resposta não correlaciona com o pedido
     */
    public function assertCorrelates(
        string $chavePedida,
        FiscalManifestationEventType $eventType,
        int $eventSeq,
        array $resposta,
    ): void {
        if ($resposta['chave_acesso'] === null
            || $resposta['tp_evento'] === null
            || $resposta['event_seq'] === null
            || $resposta['tp_amb'] === null) {
            throw new FiscalException(
                'O lote foi processado sem veredito correlacionável para o evento.',
                FiscalFailure::Upstream,
            );
        }

        $esperadoAmb = FiscalEnvironment::tpAmb();
        $esperadoSeq = (string) $eventSeq;

        if ($resposta['chave_acesso'] !== null && $resposta['chave_acesso'] !== $chavePedida) {
            throw new FiscalException(
                'A resposta do serviço de eventos não corresponde à chave solicitada.',
                FiscalFailure::Upstream,
            );
        }

        if ($resposta['tp_evento'] !== null && $resposta['tp_evento'] !== $eventType->value) {
            throw new FiscalException(
                'A resposta do serviço de eventos não corresponde ao tipo solicitado.',
                FiscalFailure::Upstream,
            );
        }

        if ($resposta['event_seq'] !== null && ltrim($resposta['event_seq'], '0') !== ltrim($esperadoSeq, '0')) {
            throw new FiscalException(
                'A resposta do serviço de eventos não corresponde à sequência solicitada.',
                FiscalFailure::Upstream,
            );
        }

        if ($resposta['tp_amb'] !== null && $resposta['tp_amb'] !== $esperadoAmb) {
            throw new FiscalException(
                'A resposta do serviço de eventos não corresponde ao ambiente solicitado.',
                FiscalFailure::Upstream,
            );
        }
    }
}
