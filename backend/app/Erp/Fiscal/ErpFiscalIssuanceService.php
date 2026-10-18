<?php

namespace App\Erp\Fiscal;

use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Erp\Fiscal\Contracts\ErpFiscalProvider;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Models\Erp\Fiscal\ErpFiscalInvalidation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ErpFiscalIssuanceService
{
    public function __construct(
        private ErpFiscalDocumentStateMachine $stateMachine,
        private ErpFiscalPayloadBuilder $payloadBuilder,
        private ErpFiscalProvider $provider,
        private ErpFiscalArtifactStore $artifacts,
        private ErpFiscalProductionGate $productionGate,
        private ErpAccountingHandoffService $handoff,
    ) {}

    public function transmit(ErpFiscalDocument $document, ErpCompany $company, ?int $actorUserId = null): ErpFiscalDocument
    {
        return DB::transaction(function () use ($document, $company, $actorUserId): ErpFiscalDocument {
            /** @var ErpFiscalDocument $locked */
            $locked = ErpFiscalDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->productionGate->assertLiveDispatch($locked, $actorUserId);

            if ($locked->status === ErpFiscalDocumentStateMachine::AUTHORIZED) {
                return $locked;
            }
            if ($locked->status === ErpFiscalDocumentStateMachine::SUBMITTING) {
                throw new InvalidArgumentException('Documento já em transmissão.');
            }
            if (! in_array($locked->status, [ErpFiscalDocumentStateMachine::QUEUED, ErpFiscalDocumentStateMachine::REJECTED, ErpFiscalDocumentStateMachine::INDETERMINATE], true)) {
                throw new InvalidArgumentException('Documento não está pronto para transmissão.');
            }

            $payload = $this->buildPayload($locked, $company);
            $locked->status = $this->stateMachine->transition($locked->status, ErpFiscalDocumentStateMachine::SUBMITTING);
            $locked->save();

            $result = $this->provider->transmit($locked, $payload);

            return $this->applyTransmissionResult($locked, $result, $payload);
        });
    }

    public function consult(ErpFiscalDocument $document, ErpCompany $company): ErpFiscalDocument
    {
        if ($document->status !== ErpFiscalDocumentStateMachine::INDETERMINATE) {
            throw new InvalidArgumentException('Consulta disponível apenas para documentos indeterminados.');
        }

        $result = $this->provider->consult($document);

        return $this->applyTransmissionResult($document, $result, $this->buildPayload($document, $company));
    }

    public function enableContingency(ErpFiscalDocument $document, ErpCompany $company): ErpFiscalDocument
    {
        if ($document->model !== '65') {
            throw new InvalidArgumentException('Contingência suportada apenas para NFC-e.');
        }
        if ($document->status !== ErpFiscalDocumentStateMachine::DRAFT && $document->status !== ErpFiscalDocumentStateMachine::QUEUED) {
            throw new InvalidArgumentException('Documento não elegível para contingência.');
        }

        $payload = $this->buildPayload($document, $company, contingencyActive: true);
        $xml = $this->minimalContingencyXml($document);
        $artifact = $this->artifacts->persistLocalContingencyXml($document, $xml);

        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $metadata['contingency'] = [
            'active' => true,
            'claims_provider_authorization' => false,
            'tp_emis' => $payload['contingency']['tp_emis'] ?? 9,
        ];
        $metadata['attempts'] = $this->appendAttempt($metadata, 'contingency_local', 'contingency_local');

        $document->fill([
            ...$artifact,
            'status' => ErpFiscalDocumentStateMachine::CONTINGENCY_LOCAL,
            'metadata' => $metadata,
        ]);
        $document->save();

        return $document->fresh();
    }

    public function reconcileContingency(ErpFiscalDocument $document, ErpCompany $company, ?int $actorUserId = null): ErpFiscalDocument
    {
        if ($document->status !== ErpFiscalDocumentStateMachine::CONTINGENCY_LOCAL) {
            throw new InvalidArgumentException('Documento não está em contingência local.');
        }

        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $metadata['contingency']['reconciliation_queued_at'] = now()->toIso8601String();
        $document->metadata = $metadata;
        $document->status = $this->stateMachine->transition($document->status, ErpFiscalDocumentStateMachine::QUEUED);
        $document->save();

        return $this->transmit($document->fresh(), $company, $actorUserId);
    }

    public function submitEvent(ErpFiscalEvent $event, ErpFiscalDocument $document, ?int $actorUserId = null): ErpFiscalEvent
    {
        $this->productionGate->assertLiveDispatch($document, $actorUserId);
        $this->assertEventAllowed($document, $event->event_type);

        if ($event->status === 'accepted') {
            return $event;
        }

        $event->status = 'submitting';
        $event->submitted_at = now();
        $event->save();

        $result = $this->provider->submitEvent($document, $event);
        if ($result->outcome === 'event_accepted') {
            $event->status = 'accepted';
            $event->protocol = $result->protocol;
            $event->provider_response = $result->providerResponse;
            $event->completed_at = now();
            $event->save();
            $this->applyAcceptedEvent($document, $event);

            return $event->fresh();
        }

        $event->status = 'rejected';
        $event->provider_response = $result->providerResponse ?? ['message' => $result->message];
        $event->completed_at = now();
        $event->save();

        return $event->fresh();
    }

    public function submitInvalidation(ErpFiscalInvalidation $invalidation, ?int $actorUserId = null): ErpFiscalInvalidation
    {
        $this->productionGate->assertLiveDispatch($invalidation, $actorUserId);
        $errors = $this->payloadBuilder->validateInvalidation([
            'model' => $invalidation->model,
            'series' => $invalidation->series,
            'number_from' => $invalidation->number_from,
            'number_to' => $invalidation->number_to,
            'justification' => $invalidation->justification,
        ]);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        if ($invalidation->status === 'accepted') {
            return $invalidation;
        }

        $invalidation->status = 'submitting';
        $invalidation->submitted_at = now();
        $invalidation->save();

        $result = $this->provider->submitInvalidation($invalidation);
        if ($result->outcome === 'event_accepted') {
            $invalidation->status = 'accepted';
            $invalidation->protocol = $result->protocol;
            $invalidation->completed_at = now();

            return tap($invalidation)->save();
        }

        $invalidation->status = 'rejected';
        $invalidation->completed_at = now();

        return tap($invalidation)->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyTransmissionResult(ErpFiscalDocument $document, ErpFiscalProviderResult $result, array $payload): ErpFiscalDocument
    {
        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $metadata['attempts'] = $this->appendAttempt($metadata, 'transmit', $result->outcome);

        if ($result->outcome === 'authorized') {
            return DB::transaction(function () use ($document, $result, $metadata, $payload): ErpFiscalDocument {
                /** @var ErpFiscalDocument $locked */
                $locked = ErpFiscalDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === ErpFiscalDocumentStateMachine::AUTHORIZED) {
                    return $locked;
                }

                $xml = $result->xml ?? '';
                if ($xml === '') {
                    throw new RuntimeException('Provedor autorizou sem XML.');
                }

                try {
                    $artifact = $this->artifacts->persistAuthorizedXml($locked, $xml);
                } catch (RuntimeException $exception) {
                    $metadata['artifact_error'] = $exception->getMessage();
                    $locked->fill([
                        'status' => ErpFiscalDocumentStateMachine::INDETERMINATE,
                        'metadata' => $metadata,
                        'authorized_at' => now(),
                    ]);
                    $locked->save();

                    return $locked->fresh();
                }

                $metadata['provider_receipt'] = $result->receipt;
                $metadata['provider_protocol'] = $result->protocol;
                $metadata['payload_snapshot'] = $payload;

                $locked->fill([
                    ...$artifact,
                    'status' => ErpFiscalDocumentStateMachine::AUTHORIZED,
                    'metadata' => $metadata,
                    'authorized_at' => now(),
                ]);
                $locked->save();

                $delivery = $this->handoff->ensureDeliveryIntent($locked);
                if ($delivery !== null) {
                    $this->handoff->deliver($delivery);
                }

                return $locked->fresh();
            });
        }

        if ($result->outcome === 'indeterminate') {
            $metadata['provider_receipt'] = $result->receipt;
            $document->fill([
                'status' => ErpFiscalDocumentStateMachine::INDETERMINATE,
                'metadata' => $metadata,
            ]);
            $document->save();

            return $document->fresh();
        }

        $document->fill([
            'status' => ErpFiscalDocumentStateMachine::REJECTED,
            'metadata' => $metadata,
        ]);
        $document->save();

        return $document->fresh();
    }

    private function applyAcceptedEvent(ErpFiscalDocument $document, ErpFiscalEvent $event): void
    {
        if ($event->event_type === 'cancel' && $document->status === ErpFiscalDocumentStateMachine::AUTHORIZED) {
            $document->status = $this->stateMachine->transition($document->status, ErpFiscalDocumentStateMachine::CANCELED);
            $document->save();
        }
    }

    private function assertEventAllowed(ErpFiscalDocument $document, string $eventType): void
    {
        $allowed = match ($document->model) {
            '55', '65' => ['cancel', 'correct', 'return'],
            '57' => ['cancel', 'complement', 'substitution'],
            '58' => ['cancel', 'close'],
            default => [],
        };

        if (! in_array($eventType, $allowed, true)) {
            throw new InvalidArgumentException('Evento não permitido para o modelo informado.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(ErpFiscalDocument $document, ErpCompany $company, bool $contingencyActive = false): array
    {
        $settings = is_array($company->operating_settings) ? $company->operating_settings : [];
        $uf = is_string($settings['uf'] ?? null) ? $settings['uf'] : 'SP';

        $modelKey = match ($document->model) {
            '55' => 'nfe',
            '65' => 'nfce',
            '57' => 'cte',
            '58' => 'mdfe',
            default => 'nfe',
        };

        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $contingencyActive = $contingencyActive || (($metadata['contingency']['active'] ?? false) === true);

        return $this->payloadBuilder->buildDocumentPayload($modelKey, [
            'uf' => $uf,
            'cnpj' => (string) $company->cnpj,
            'series' => (int) $document->series,
            'number' => (int) $document->number,
            'access_key' => $document->access_key,
            'environment' => $document->environment === 'production' ? 1 : 2,
            'contingency_active' => $contingencyActive,
            'contingency_status' => isset($metadata['contingency']['status_retorno'])
                ? json_encode($metadata['contingency']['status_retorno'], JSON_THROW_ON_ERROR)
                : null,
            'total_produtos' => $document->commercial_snapshot['total_amount'] ?? '0',
            'total_nf' => $document->commercial_snapshot['total_amount'] ?? '0',
            'razao_social' => $company->legal_name ?? $company->trade_name ?? 'Emitente',
        ]);
    }

    private function minimalContingencyXml(ErpFiscalDocument $document): string
    {
        $chave = $document->access_key ?? str_repeat('0', 44);

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <NFe xmlns="http://www.portalfiscal.inf.br/nfe">
          <infNFe Id="NFe{$chave}" versao="4.00">
            <ide><tpEmis>9</tpEmis><mod>{$document->model}</mod></ide>
          </infNFe>
        </NFe>
        XML;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<array<string, mixed>>
     */
    private function appendAttempt(array $metadata, string $action, string $outcome): array
    {
        $attempts = is_array($metadata['attempts'] ?? null) ? $metadata['attempts'] : [];
        $attempts[] = [
            'action' => $action,
            'outcome' => $outcome,
            'at' => now()->toIso8601String(),
        ];

        return $attempts;
    }
}
