<?php

namespace App\Services\Fiscal\Manifestacao;

use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Models\FiscalManifestation;
use Carbon\CarbonInterface;

/**
 * Grava e atualiza o estado da manifestação com deduplicação por chave lógica.
 */
final class FiscalManifestationStore
{
    public function registrarPedido(
        int $accountId,
        int $clientId,
        string $chaveAcesso,
        FiscalManifestationEventType $eventType,
        int $eventSeq,
        string $requestedBy,
        ?CarbonInterface $requestedAt = null,
    ): FiscalManifestation {
        $requestedAt ??= now();

        $registro = FiscalManifestation::query()
            ->withoutGlobalScope('account')
            ->firstOrNew([
                'account_id' => $accountId,
                'client_id' => $clientId,
                'chave_acesso' => $chaveAcesso,
                'event_type' => $eventType,
                'event_seq' => $eventSeq,
            ]);

        // `account_id` não é mass-assignável: jobs gravam a conta de forma explícita.
        $registro->account_id = $accountId;

        // Um veredito já gravado é final: o fisco (ou a nossa impossibilidade
        // de pedir) já respondeu por esta chave, e a re-entrega do resumo não
        // o refaz. Resetar para `pending` reenviaria o evento — o fisco
        // responderia `573` e o registro seria rebaixado para o que ele já é.
        // O pedido é registrado de novo (`requested_*` anda), e o resto fica.
        if ($registro->exists && $registro->outcome->isVerdict()) {
            $registro->fill([
                'requested_by' => $requestedBy,
                'requested_at' => $requestedAt,
            ]);
            $registro->save();

            return $registro;
        }

        $registro->fill([
            'requested_by' => $requestedBy,
            'outcome' => FiscalManifestationOutcome::Pending,
            'requested_at' => $requestedAt,
            'sent_at' => null,
            'resulted_at' => null,
        ]);
        $registro->save();

        return $registro;
    }

    public function marcarEnfileirado(
        int $accountId,
        int $clientId,
        string $chaveAcesso,
        FiscalManifestationEventType $eventType,
        int $eventSeq,
    ): void {
        FiscalManifestation::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('client_id', $clientId)
            ->where('chave_acesso', $chaveAcesso)
            ->where('event_type', $eventType)
            ->where('event_seq', $eventSeq)
            ->update(['outcome' => FiscalManifestationOutcome::Queued]);
    }

    /**
     * Marca que a tentativa saiu para a rede — chamado pelo transporte logo
     * após o envio, para que `sent_at` exista mesmo quando a resposta do
     * fisco é recusa e nunca vira veredito. Idempotente: um `sent_at` já
     * gravado não é reescrito, e sobre modelo não persistido só atualiza em
     * memória (o `veredito` ainda não sabe que vai rodar).
     */
    public function marcarEnviado(FiscalManifestation $manifestation): void
    {
        $manifestation->sent_at ??= now();

        if ($manifestation->exists) {
            $manifestation->save();
        }
    }

    /**
     * O veredito do evento no registro — chamado só quando o fisco respondeu
     * (ou quando a resposta é conhecida sem chamada, como o prazo perdido).
     * `result_code`/`result_message` levam o cStat e o motivo já condensado;
     * `sent_at` marca a ida à rede e `resulted_at` o veredito.
     *
     * Recebe o modelo (e não a chave lógica) porque quem chama já tem o
     * registro na mão — e pode ser um modelo não persistido, que o método
     * atualiza em memória sem salvar.
     */
    public function veredito(
        FiscalManifestation $manifestation,
        FiscalManifestationOutcome $outcome,
        ?string $resultCode,
        ?string $resultMessage,
    ): void {
        $manifestation->outcome = $outcome;
        $manifestation->result_code = $resultCode;
        $manifestation->result_message = $resultMessage;
        $manifestation->sent_at ??= $outcome === FiscalManifestationOutcome::DeadlineMissed
            || $outcome === FiscalManifestationOutcome::NotSendable
            ? null
            : now();
        $manifestation->resulted_at = now();

        if ($manifestation->exists) {
            $manifestation->save();
        }
    }
}
