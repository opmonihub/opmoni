<?php

namespace App\Erp\Fiscal;

use App\Erp\ErpEstablishmentAccess;
use App\Erp\Sales\ErpCommercialDraftService;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\Fiscal\ErpFiscalAttempt;
use App\Models\Erp\Fiscal\ErpFiscalCertificate;
use App\Models\Erp\Fiscal\ErpFiscalConfiguration;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Models\Erp\Fiscal\ErpFiscalInvalidation;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleReturn;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ErpFiscalIssuanceService
{
    public function __construct(private ErpFiscalAttemptService $attempts, private ErpFiscalProductionGate $productionGate) {}

    /** @param array<string, mixed> $data */
    public function draft(Request $request, array $data): array
    {
        $context = CurrentCompany::fromRequest($request);
        $issuer = app(ErpEstablishmentAccess::class)->assertRelated($context, (int) $data['erp_establishment_id']);
        $replay = $this->replay($context, 'document:draft', $data['idempotency_key'], $data, (int) $request->user()->id);
        if ($replay) {
            return [$replay->document()->firstOrFail(), false];
        }
        if ($issuer->status !== 'active') {
            throw ValidationException::withMessages(['erp_establishment_id' => 'Estabelecimento emissor inativo.']);
        }
        [$revision, $certificate, $settings] = $this->pinnedSetup($issuer, $data['model'], $data['erp_fiscal_certificate_id'] ?? null);
        if (isset($data['environment']) && $data['environment'] !== $revision->environment || isset($data['series']) && (int) $data['series'] !== (int) $settings['series']) {
            throw ValidationException::withMessages(['environment' => 'Ambiente e série devem corresponder à configuração do emissor.']);
        }
        abort_if(ErpFiscalDocument::query()->where('erp_company_id', $issuer->erp_company_id)->where('idempotency_key', $data['idempotency_key'])->exists(), 409, 'Documento legado sem intenção compatível exige conciliação.');
        $source = $data['source'];
        $config = $revision->configuration;
        $source['issuer'] = $this->issuerSnapshot($issuer, $config['issuer']);
        $source['identity'] = array_replace($source['identity'] ?? [], [
            'model' => $data['model'], 'environment' => $revision->environment, 'uf' => $config['issuer']['uf'],
            'series' => (int) $settings['series'], 'issued_at' => now()->toIso8601String(),
        ]);
        $kind = $data['document_kind'] ?? match ($data['model']) {
            '57' => 'transport_service', '58' => 'transport_manifest', default => 'posted_sale'
        };
        $allowedKinds = match ($data['model']) {
            '55' => ['posted_sale', 'return', 'complement'], '65' => ['posted_sale'], '57' => ['transport_service', 'complement', 'substitution'], '58' => ['transport_manifest']
        };
        if (! in_array($kind, $allowedKinds, true)) {
            throw ValidationException::withMessages(['document_kind' => 'Tipo de fonte sem suporte para este modelo.']);
        }
        $source['document_kind'] = $kind;
        if (isset($settings['operation_nature_id'])) {
            $nature = ErpOperationNature::query()->where('erp_company_id', $issuer->erp_company_id)->where('status', 'active')->whereKey($settings['operation_nature_id'])->first();
            if (! $nature || $nature->erp_establishment_id !== null && (int) $nature->erp_establishment_id !== (int) $issuer->id) {
                throw ValidationException::withMessages(['source.identity.operation_nature' => 'Natureza de operação inválida.']);
            }
            $source['identity']['operation_nature'] = $nature->description;
            $source['operation_nature'] = $nature->only(['id', 'description', 'cfop', 'movement', 'fiscal_metadata']);
        }
        if ($data['model'] === '65') {
            $source['nfce_qr'] = $settings['nfce_qr'] ?? [];
            $source['nfce_qr']['version'] = (int) ($source['nfce_qr']['version'] ?? 0);
        }
        if (in_array($data['model'], ['57', '58'], true)) {
            $transportKey = $data['model'] === '57' ? 'transport_service' : 'transport_manifest';
            $source[$transportKey] = array_replace_recursive($settings['transport'] ?? [], $source[$transportKey] ?? []);
            if ($data['model'] === '57') {
                $source[$transportKey]['cte_type'] = match ($kind) {
                    'complement' => 1, 'substitution' => 3, default => 0
                };
            }
        } else {
            $source['identity']['purpose'] = match ($kind) {
                'return' => 4, 'complement' => 2, default => 1
            };
        }
        $commercial = $this->freezeSourceRecord($context, $issuer, $kind, $data, $source);
        if (in_array($kind, ['return', 'complement', 'substitution'], true)) {
            $original = ErpFiscalDocument::query()->where('erp_company_id', $issuer->erp_company_id)->whereKey($data['source_document_id'] ?? 0)->first();
            if (! $original || $original->status !== 'authorized' || ! $original->access_key) {
                throw ValidationException::withMessages(['source_document_id' => 'Documento original autorizado obrigatório.']);
            }
            app(ErpEstablishmentAccess::class)->assertRelatedResource($context, $original, 'source_document_id');
            if ($data['model'] === '55' && ! in_array($original->model, ['55', '65'], true) || $data['model'] === '57' && $original->model !== '57') {
                throw ValidationException::withMessages(['source_document_id' => 'Modelo original incompatível.']);
            }
            if ((int) $original->erp_establishment_id !== (int) $issuer->id || $original->environment !== $revision->environment) {
                throw ValidationException::withMessages(['source_document_id' => 'Documento original deve pertencer ao mesmo emissor e ambiente.']);
            }
            if ($data['model'] === '57' && $kind === 'substitution') {
                $source['external_prerequisites'] = ['service_disagreement' => 'unverified'];
            }
            if ($kind === 'return') {
                $commercial = $this->freezeReturn($context, $original, $data, $source);
            }
            $source['original_document'] = $original->only(['id', 'access_key', 'model', 'environment', 'erp_establishment_id', 'authorized_at']);
            if ($data['model'] === '55') {
                $source['references'] = [['access_key' => $original->access_key]];
            } else {
                $source['transport_service']['original_cte_access_key'] = $original->access_key;
            }
        }
        $allocator = app(ErpFiscalNumberAllocator::class);
        $next = $allocator->nextNumber((int) $issuer->erp_company_id, $data['model'], $revision->environment, (int) $settings['series']);
        $source['identity']['number'] = isset($data['number']) ? (int) $data['number'] : $next;
        $source['identity']['numeric_code'] = (string) random_int(10_000_000, 99_999_999);
        $source['identity']['access_key'] = $this->accessKey($source, $data['model']);
        $this->validateTypedSource($data['model'], $source);
        if ($data['model'] === '57' && $kind === 'substitution') {
            $this->validateCteSubstitution($original, $source);
        }
        $number = isset($data['number'])
            ? $allocator->reserveExplicitNumber((int) $issuer->erp_company_id, $data['model'], $revision->environment, (int) $settings['series'], (int) $data['number'])
            : $allocator->allocate((int) $issuer->erp_company_id, $data['model'], $revision->environment, (int) $settings['series'], (int) $settings['next_number']);
        $source['identity']['number'] = $number;
        $source['identity']['access_key'] = $this->accessKey($source, $data['model']);
        $document = ErpFiscalDocument::query()->create([
            'erp_company_id' => $issuer->erp_company_id, 'erp_establishment_id' => $issuer->id,
            'erp_fiscal_certificate_id' => $certificate->id, 'certificate_revision' => $certificate->revision,
            'erp_sale_id' => $data['erp_sale_id'] ?? null, 'model' => $data['model'], 'environment' => $revision->environment,
            'series' => $settings['series'], 'number' => $number, 'access_key' => $source['identity']['access_key'],
            'status' => 'draft', 'idempotency_key' => $data['idempotency_key'], 'commercial_snapshot' => $commercial,
            'metadata' => ['document_kind' => $kind],
        ]);
        $attempt = $this->persist($context, $document, 'document:draft', $data['idempotency_key'], $data, $source, (int) $request->user()->id, $revision->id, $certificate->current_revision_id);
        $attempt->forceFill(['status' => 'accepted', 'outbox_state' => 'completed', 'completed_at' => now(), 'safe_result' => ['code' => 'draft_saved', 'artifact_ready' => false]])->save();

        return [$document, true];
    }

    public function queue(ErpFiscalDocument $document, ErpCompany $company, int $actorUserId, array $body = []): ErpFiscalDocument
    {
        if ($this->replayDocument($document, 'document:queue', (int) $actorUserId, $body)) {
            return $document->fresh();
        }
        if (! in_array($document->status, ['draft', 'queued', 'rejected'], true)) {
            throw new InvalidArgumentException('Documento não elegível para fila.');
        }
        $this->documentIntent($document, 'document:queue', $actorUserId, $body);
        $document->status = 'queued';
        $document->save();

        return $document->fresh();
    }

    public function transmit(ErpFiscalDocument $document, ErpCompany $company, ?int $actorUserId = null, array $body = []): ErpFiscalDocument
    {
        if ($this->replayDocument($document, 'document:transmit', (int) $actorUserId, $body)) {
            return $document->fresh();
        }
        $this->productionGate->assertLiveDispatch($document, $actorUserId);
        if ($document->status === 'authorized') {
            abort(409, 'Documento já autorizado; consulte a intenção original.');
        }
        if (! in_array($document->status, ['queued', 'indeterminate', 'contingency_local', 'submitting'], true)) {
            throw new InvalidArgumentException('Documento não está pronto para transmissão.');
        }
        $this->documentIntent($document, 'document:transmit', (int) $actorUserId, $body);

        return $document->fresh();
    }

    public function consult(ErpFiscalDocument $document, ErpCompany $company, ?int $actorUserId = null, array $body = []): ErpFiscalDocument
    {
        if ($this->replayDocument($document, 'document:consult', (int) $actorUserId, $body)) {
            return $document->fresh();
        }
        if (! in_array($document->status, ['indeterminate', 'submitting', 'authorized'], true)) {
            throw new InvalidArgumentException('Consulta disponível apenas para documento pendente de conciliação.');
        }
        $this->productionGate->assertLiveDispatch($document, $actorUserId);
        $this->documentIntent($document, 'document:consult', (int) $actorUserId, $body);

        return $document->fresh();
    }

    public function enableContingency(ErpFiscalDocument $document, ErpCompany $company, ?int $actorUserId = null, array $body = []): ErpFiscalDocument
    {
        if ($this->replayDocument($document, 'document:contingency', (int) $actorUserId, $body)) {
            return $document->fresh();
        }
        if ($document->model !== '65' || ! in_array($document->status, ['draft', 'queued'], true)) {
            throw new InvalidArgumentException('Documento não elegível para contingência NFC-e.');
        }
        if ($document->attempts()->whereIn('action', ['document:transmit', 'document:reconcile', 'document:consult'])->whereIn('status', ['pending', 'submitting', 'indeterminate', 'attention_required', 'artifact_pending', 'accepted'])->exists()) {
            throw ValidationException::withMessages(['document' => 'Transmissão externa existente exige conciliação antes de contingência.']);
        }
        $draft = $this->draftAttempt($document);
        $snapshot = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($draft);
        $source = $snapshot['request']['source'] ?? [];
        if (($source['nfce_qr']['mode'] ?? null) !== 'offline' || (string) substr($document->access_key, 34, 1) !== '9') {
            throw ValidationException::withMessages(['source.nfce_qr' => 'Contingência exige dados offline e chave emitida em contingência.']);
        }
        $this->documentIntent($document, 'document:contingency', (int) $actorUserId, $body);

        return $document->fresh();
    }

    public function reconcileContingency(ErpFiscalDocument $document, ErpCompany $company, ?int $actorUserId = null, array $body = []): ErpFiscalDocument
    {
        if ($this->replayDocument($document, 'document:reconcile', (int) $actorUserId, $body)) {
            return $document->fresh();
        }
        if ($document->status !== 'contingency_local') {
            throw new InvalidArgumentException('Documento não está em contingência local.');
        }
        $this->productionGate->assertLiveDispatch($document, $actorUserId);
        $this->documentIntent($document, 'document:reconcile', (int) $actorUserId, $body);

        return $document->fresh();
    }

    public function eventDraft(Request $request, array $data): array
    {
        $context = CurrentCompany::fromRequest($request);
        $document = ErpFiscalDocument::query()->where('erp_company_id', $context->company->id)->whereKey($data['erp_fiscal_document_id'])->first();
        if (! $document) {
            throw ValidationException::withMessages(['erp_fiscal_document_id' => 'Documento fiscal inválido para esta empresa.']);
        }
        app(ErpEstablishmentAccess::class)->assertRelatedResource($context, $document, 'erp_fiscal_document_id');
        $replay = $this->replay($context, 'event:draft', $data['idempotency_key'], $data, (int) $request->user()->id);
        if ($replay) {
            return [$replay->event()->firstOrFail(), false];
        }
        abort_if($this->hasUnresolvedEventType($document, $data['event_type']), 409, 'Evento anterior deste tipo exige evidência externa de conciliação.');
        if ($document->status !== 'authorized') {
            throw ValidationException::withMessages(['erp_fiscal_document_id' => 'Evento exige documento autorizado.']);
        }
        [$revision, $certificate] = $this->pinnedSetup($document->establishment, $document->model, $document->erp_fiscal_certificate_id);
        $draft = $this->draftAttempt($document);
        $frozen = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($draft)['request']['source'];
        $sequence = 1 + (int) $document->events()->where('event_type', $data['event_type'])->count();
        if ($revision->environment !== $document->environment || (int) ($revision->configuration['models'][$document->model]['series'] ?? -1) !== (int) $document->series) {
            throw ValidationException::withMessages(['configuration' => 'Configuração atual não corresponde ao ambiente e série do documento original.']);
        }
        $source = [...$data, 'model' => $document->model, 'environment' => $document->environment, 'document_kind' => $document->metadata['document_kind'] ?? '',
            'issuer' => $frozen['issuer'], 'access_key' => $document->access_key, 'protocol' => $document->metadata['provider_protocol'] ?? null, 'sequence' => $sequence, 'occurred_at' => now()->toIso8601String()];
        if ($data['event_type'] === 'substitution_cancel') {
            $replacement = ErpFiscalDocument::query()->where('erp_company_id', $document->erp_company_id)->where('erp_establishment_id', $document->erp_establishment_id)->whereKey($data['replacement_document_id'] ?? 0)->first();
            if (! $replacement || $replacement->id === $document->id || $replacement->model !== '65' || $replacement->environment !== $document->environment || $replacement->status !== 'authorized') {
                throw ValidationException::withMessages(['replacement_document_id' => 'NFC-e substituta autorizada e do mesmo emissor/ambiente obrigatória.']);
            }
            app(ErpEstablishmentAccess::class)->assertRelatedResource($context, $replacement, 'replacement_document_id');
            $replacementSnapshot = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($this->draftAttempt($replacement))['request']['source'];
            if ($this->canonical($frozen['items']) !== $this->canonical($replacementSnapshot['items']) || $this->canonical($frozen['recipient']) !== $this->canonical($replacementSnapshot['recipient'])
                || $this->canonical($frozen['totals']) !== $this->canonical($replacementSnapshot['totals']) || $document->created_at->diffInHours($replacement->created_at, true) > 168) {
                throw ValidationException::withMessages(['replacement_document_id' => 'Substituta não corresponde à operação original dentro da janela permitida.']);
            }
            $source['replacement_access_key'] = $replacement->access_key;
        }
        try {
            app(ErpFiscalEventRegistry::class)->buildEvent($source);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['event' => $exception->getMessage()]);
        }
        $event = ErpFiscalEvent::query()->create(['erp_fiscal_document_id' => $document->id, 'event_type' => $data['event_type'], 'status' => 'queued', 'idempotency_key' => $data['idempotency_key'], 'justification' => $data['justification'] ?? null, 'requested_by_user_id' => $request->user()->id]);
        $attempt = $this->persist($context, $document, 'event:draft', $data['idempotency_key'], $data, $source, (int) $request->user()->id, $revision->id, $certificate->current_revision_id, $event->id);
        $attempt->forceFill(['status' => 'accepted', 'outbox_state' => 'completed', 'completed_at' => now(), 'safe_result' => ['code' => 'event_saved', 'artifact_ready' => false]])->save();

        return [$event, true];
    }

    public function invalidationDraft(Request $request, array $data): array
    {
        $context = CurrentCompany::fromRequest($request);
        $issuer = app(ErpEstablishmentAccess::class)->assertRelated($context, (int) $data['erp_establishment_id']);
        $replay = $this->replay($context, 'invalidation:draft', $data['idempotency_key'], $data, (int) $request->user()->id);
        if ($replay) {
            return [$replay->invalidation()->firstOrFail(), false];
        }
        [$revision, $certificate, $settings] = $this->pinnedSetup($issuer, $data['model']);
        if (isset($data['environment']) && $data['environment'] !== $revision->environment || (int) $data['series'] !== (int) $settings['series']) {
            throw ValidationException::withMessages(['environment' => 'Faixa deve corresponder à configuração fiscal.']);
        }
        $source = [...$data, 'environment' => $revision->environment, 'issuer' => $this->issuerSnapshot($issuer, $revision->configuration['issuer'])];
        try {
            app(ErpFiscalEventRegistry::class)->buildInvalidation($source);
            app(ErpFiscalNumberAllocator::class)->reserveRange((int) $issuer->erp_company_id, $data['model'], $revision->environment, (int) $data['series'], (int) $data['number_from'], (int) $data['number_to']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['range' => $exception->getMessage()]);
        }
        $invalidation = ErpFiscalInvalidation::query()->create([...$data, 'environment' => $revision->environment, 'erp_company_id' => $issuer->erp_company_id, 'status' => 'queued', 'requested_by_user_id' => $request->user()->id]);
        $attempt = $this->persistInvalidation($context, $invalidation, 'invalidation:draft', $data['idempotency_key'], $data, $source, (int) $request->user()->id, $revision->id, $certificate->current_revision_id);
        $attempt->forceFill(['status' => 'accepted', 'outbox_state' => 'completed', 'completed_at' => now(), 'safe_result' => ['code' => 'range_saved', 'artifact_ready' => false]])->save();

        return [$invalidation, true];
    }

    public function submitEvent(ErpFiscalEvent $event, ErpFiscalDocument $document, ?int $actorUserId = null, array $body = []): ErpFiscalEvent
    {
        $this->productionGate->assertLiveDispatch($document, $actorUserId);
        $context = CurrentCompany::fromRequest();
        $key = (string) ($body['idempotency_key'] ?? $event->idempotency_key);
        $replay = $this->replay($context, 'event:submit', $key, $body, (int) $actorUserId);
        if ($replay) {
            abort_unless((int) $replay->erp_fiscal_event_id === (int) $event->id, 409, 'Chave de idempotência pertence a outro evento.');

            return $event->fresh();
        }
        abort_if(ErpFiscalAttempt::query()->where('erp_fiscal_event_id', $event->id)->where('action', 'event:submit')->exists(), 409, 'Evento já submetido exige evidência externa de conciliação.');
        abort_if($this->hasUnresolvedEventType($document, $event->event_type), 409, 'Evento anterior deste tipo exige evidência externa de conciliação.');
        if ($event->status !== 'queued' || $document->status !== 'authorized') {
            throw new InvalidArgumentException('Evento exige documento autorizado e intenção ainda não enviada.');
        }
        $draft = ErpFiscalAttempt::query()->where('erp_fiscal_event_id', $event->id)->where('action', 'event:draft')->first();
        abort_unless($draft, 409, 'Evento legado sem intenção fixada exige conciliação.');
        $source = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($draft)['request']['source'];
        app(ErpFiscalEventRegistry::class)->buildEvent($source);
        $attempt = $this->persist($context, $document, 'event:submit', $key, $body, $source, (int) $actorUserId, $draft->erp_fiscal_configuration_revision_id, $draft->erp_fiscal_certificate_revision_id, $event->id);
        $this->attempts->dispatchAfterCommit($attempt);

        return $event->fresh();
    }

    public function submitInvalidation(ErpFiscalInvalidation $invalidation, ?int $actorUserId = null, array $body = []): ErpFiscalInvalidation
    {
        $this->productionGate->assertLiveDispatch($invalidation, $actorUserId);
        $context = CurrentCompany::fromRequest();
        $key = (string) ($body['idempotency_key'] ?? $invalidation->idempotency_key);
        $replay = $this->replay($context, 'invalidation:submit', $key, $body, (int) $actorUserId);
        if ($replay) {
            abort_unless((int) $replay->erp_fiscal_invalidation_id === (int) $invalidation->id, 409, 'Chave de idempotência pertence a outra faixa.');

            return $invalidation->fresh();
        }
        abort_if(ErpFiscalAttempt::query()->where('erp_fiscal_invalidation_id', $invalidation->id)->where('action', 'invalidation:submit')->exists(), 409, 'Faixa já submetida exige evidência externa de conciliação.');
        if ($invalidation->status !== 'queued') {
            throw new InvalidArgumentException('Faixa já submetida exige conciliação explícita.');
        }
        $draft = ErpFiscalAttempt::query()->where('erp_fiscal_invalidation_id', $invalidation->id)->where('action', 'invalidation:draft')->first();
        abort_unless($draft, 409, 'Faixa legada sem intenção fixada exige conciliação.');
        $source = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($draft)['request']['source'];
        app(ErpFiscalEventRegistry::class)->buildInvalidation($source);
        $attempt = $this->persistInvalidation($context, $invalidation, 'invalidation:submit', $key, $body, $source, (int) $actorUserId, $draft->erp_fiscal_configuration_revision_id, $draft->erp_fiscal_certificate_revision_id);
        $this->attempts->dispatchAfterCommit($attempt);

        return $invalidation->fresh();
    }

    private function hasUnresolvedEventType(ErpFiscalDocument $document, string $eventType): bool
    {
        return $document->attempts()->where('action', 'event:submit')
            ->whereIn('status', ['pending', 'submitting', 'indeterminate', 'attention_required', 'artifact_pending'])
            ->whereHas('event', static fn ($query) => $query->where('event_type', $eventType))->exists();
    }

    private function persistInvalidation(CurrentCompany $context, ErpFiscalInvalidation $invalidation, string $action, string $key, array $body, array $source, int $actor, int $configRevision, int $certRevision): ErpFiscalAttempt
    {
        return $this->attempts->createIntent(['erp_company_id' => $invalidation->erp_company_id, 'erp_establishment_id' => $invalidation->erp_establishment_id,
            'erp_fiscal_invalidation_id' => $invalidation->id, 'action' => $action, 'model' => $invalidation->model, 'environment' => $invalidation->environment, 'series' => $invalidation->series,
            'actor_user_id' => $actor, 'idempotency_key' => $key, 'erp_fiscal_configuration_revision_id' => $configRevision, 'erp_fiscal_certificate_revision_id' => $certRevision,
            'schema_release' => app(ErpFiscalSchemaValidator::class)->entrypoint($invalidation->model, 'invalidation'), 'request' => ['body' => $body, 'source' => $source, 'authorization' => $this->authorizationSnapshot($context)]]);
    }

    private function replayDocument(ErpFiscalDocument $document, string $action, int $actor, array $body): bool
    {
        $attempt = $this->replay(CurrentCompany::fromRequest(), $action, (string) ($body['idempotency_key'] ?? $document->idempotency_key), $body, $actor);
        if ($attempt !== null) {
            abort_unless((int) $attempt->erp_fiscal_document_id === (int) $document->id, 409, 'Chave de idempotência pertence a outro documento.');
        }

        return $attempt !== null;
    }

    private function documentIntent(ErpFiscalDocument $document, string $action, int $actorUserId, array $body): ErpFiscalAttempt
    {
        $context = CurrentCompany::fromRequest();
        $key = (string) ($body['idempotency_key'] ?? $document->idempotency_key);
        $replay = $this->replay($context, $action, $key, $body, $actorUserId);
        if ($replay) {
            abort_unless((int) $replay->erp_fiscal_document_id === (int) $document->id, 409, 'Chave de idempotência pertence a outro documento.');

            return $replay;
        }
        if (in_array($action, ['document:transmit', 'document:reconcile'], true)) {
            abort_if($document->attempts()->whereIn('action', ['document:transmit', 'document:reconcile'])->whereIn('status', ['pending', 'submitting', 'indeterminate', 'artifact_pending', 'accepted'])->exists(), 409, 'Transmissão já registrada; concilie a mesma intenção antes de novo envio.');
        }
        $draft = $this->draftAttempt($document);
        $snapshot = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($draft);
        $source = $snapshot['request']['source'] ?? [];
        $this->validateTypedSource($document->model, $source);
        $attempt = $this->persist($context, $document, $action, $key, $body, $source, $actorUserId, $draft->erp_fiscal_configuration_revision_id, $draft->erp_fiscal_certificate_revision_id);
        $this->attempts->dispatchAfterCommit($attempt);

        return $attempt;
    }

    private function draftAttempt(ErpFiscalDocument $document): ErpFiscalAttempt
    {
        $attempt = $document->attempts()->where('action', 'document:draft')->first();
        abort_unless($attempt && $attempt->erp_fiscal_certificate_revision_id && $attempt->erp_fiscal_configuration_revision_id, 409, 'Documento legado sem revisão imutável exige conciliação.');

        return $attempt;
    }

    private function persist(CurrentCompany $context, ErpFiscalDocument $document, string $action, string $key, array $body, array $source, int $actor, int $configurationRevision, int $certificateRevision, ?int $eventId = null): ErpFiscalAttempt
    {
        return $this->attempts->createIntent([
            'erp_company_id' => $document->erp_company_id, 'erp_establishment_id' => $document->erp_establishment_id,
            'erp_fiscal_document_id' => $document->id, 'erp_fiscal_event_id' => $eventId, 'action' => $action, 'model' => $document->model,
            'environment' => $document->environment, 'series' => $document->series, 'number' => $document->number,
            'actor_user_id' => $actor, 'idempotency_key' => $key, 'erp_fiscal_configuration_revision_id' => $configurationRevision,
            'erp_fiscal_certificate_revision_id' => $certificateRevision, 'schema_release' => app(ErpFiscalSchemaValidator::class)->entrypoint($document->model, 'request'),
            'request' => ['body' => $body, 'source' => $source, 'issuer' => ['cnpj' => $source['issuer']['cnpj'] ?? substr($document->access_key, 6, 14)], 'authorization' => $this->authorizationSnapshot($context)],
        ]);
    }

    private function replay(CurrentCompany $context, string $action, string $key, array $body, int $actor): ?ErpFiscalAttempt
    {
        $attempt = ErpFiscalAttempt::query()->where('erp_company_id', $context->company->id)->where('action', $action)->where('idempotency_key', $key)->first();
        if (! $attempt) {
            return null;
        }
        app(ErpEstablishmentAccess::class)->assertResource($context, $attempt->establishment()->firstOrFail());
        $snapshot = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($attempt);
        abort_unless((int) $attempt->actor_user_id === $actor && $this->canonical($snapshot['request']['body'] ?? []) === $this->canonical($body), 409, 'Chave de idempotência reutilizada com ator ou solicitação diferente.');

        return $attempt;
    }

    private function pinnedSetup(ErpEstablishment $issuer, string $model, ?int $certificateId = null): array
    {
        $configuration = ErpFiscalConfiguration::query()->where('erp_company_id', $issuer->erp_company_id)->where('erp_establishment_id', $issuer->id)->lockForUpdate()->first();
        $revision = $configuration?->currentRevision;
        $settings = $revision?->configuration['models'][$model] ?? null;
        $certificateQuery = ErpFiscalCertificate::query()->where('erp_company_id', $issuer->erp_company_id)->where('erp_establishment_id', $issuer->id)->where('status', 'active');
        if ($certificateId !== null) {
            $certificateQuery->whereKey($certificateId);
        }
        $certificate = $certificateQuery->orderByDesc('id')->lockForUpdate()->first();
        if (! $revision || ! is_array($settings) || ! $certificate?->currentRevision) {
            throw ValidationException::withMessages(['configuration' => 'Configuração e certificado do modelo emissor são obrigatórios.']);
        }
        $certRevision = $certificate->currentRevision;
        if ($certRevision->cnpj !== $issuer->cnpj || $certRevision->valid_from->isFuture() || ! $certRevision->valid_until->isFuture()) {
            throw ValidationException::withMessages(['certificate' => 'Certificado do emissor inválido ou fora da validade.']);
        }

        return [$revision, $certificate, $settings];
    }

    private function issuerSnapshot(ErpEstablishment $issuer, array $settings): array
    {
        return ['cnpj' => $issuer->cnpj, 'legal_name' => $issuer->name, 'state_registration' => $settings['state_registration'], 'tax_regime' => $settings['tax_regime'],
            'address' => ['street' => $settings['address']['street'], 'number' => $settings['address']['number'], 'district' => $settings['address']['district'],
                'city_code' => $settings['address']['city_code'], 'city' => $settings['address']['city_name'], 'uf' => $settings['uf'], 'postal_code' => $settings['address']['zip_code'], 'country_code' => '1058', 'country' => 'Brasil']];
    }

    private function freezeReturn(CurrentCompany $context, ErpFiscalDocument $original, array $data, array &$source): array
    {
        $return = ErpSaleReturn::query()->where('erp_company_id', $original->erp_company_id)->where('erp_establishment_id', $original->erp_establishment_id)
            ->where('erp_sale_id', $original->erp_sale_id)->where('status', 'posted')->whereKey($data['erp_sale_return_id'] ?? 0)->with('lines')->first();
        if (! $return || ! $original->erp_sale_id || $return->lines->isEmpty()) {
            throw ValidationException::withMessages(['erp_sale_return_id' => 'Devolução comercial lançada da venda original e do emissor obrigatória.']);
        }
        app(ErpEstablishmentAccess::class)->assertRelated($context, (int) $return->erp_establishment_id);
        if (count($source['items'] ?? []) !== $return->lines->count() || bccomp((string) ($source['totals']['invoice'] ?? '-1'), $return->total_amount, 2) !== 0
            || (string) ($source['recipient']['cnpj'] ?? '') !== (string) ($return->commercial_snapshot['party']['tax_id'] ?? '')) {
            throw ValidationException::withMessages(['source' => 'Destinatário, total e itens devem corresponder à devolução comercial lançada.']);
        }
        if (($source['operation_nature']['movement'] ?? null) !== 'in' || ! in_array($source['operation_nature']['cfop'] ?? null, ['1202', '2202'], true) || (int) ($source['identity']['operation_type'] ?? -1) !== 0) {
            throw ValidationException::withMessages(['source' => 'Devolução de venda suportada exige entrada e CFOP 1202 ou 2202 configurado.']);
        }
        $seen = [];
        foreach ($source['items'] as $index => $item) {
            $line = $return->lines->firstWhere('id', (int) ($item['source_return_line_id'] ?? 0));
            if (! $line || in_array($line->id, $seen, true) || ! isset($line->source_snapshot['accepted'])) {
                throw ValidationException::withMessages(['source.items.'.$index => 'Linha devolvida sem proveniência aceita.']);
            }
            $seen[] = $line->id;
            $originalLine = $line->source_snapshot['line'] ?? [];
            $frozen = $originalLine['product_snapshot'] ?? [];
            $product = $frozen['product'] ?? [];
            if ((int) ($originalLine['id'] ?? 0) !== (int) $line->source_line_id || (int) $line->source_document_id !== (int) $original->erp_sale_id
                || (string) ($item['code'] ?? '') !== (string) ($frozen['variant']['sku'] ?? $product['sku'] ?? '')
                || (string) ($item['ncm'] ?? '') !== (string) ($product['ncm'] ?? '') || (string) ($item['unit'] ?? '') !== (string) ($frozen['unit']['short_name'] ?? '')
                || (string) ($item['cfop'] ?? '') !== (string) ($source['operation_nature']['cfop'] ?? '')
                || bccomp((string) ($item['quantity'] ?? '-1'), $line->quantity, 4) !== 0 || bccomp((string) ($item['gross_total'] ?? '-1'), $line->subtotal_amount, 2) !== 0
                || bccomp((string) ($item['unit_price'] ?? '-1'), (string) ($originalLine['unit_price'] ?? '-2'), 4) !== 0) {
                throw ValidationException::withMessages(['source.items.'.$index => 'Classificação, quantidade e valores divergem da devolução lançada.']);
            }
        }
        $snapshot = [...($return->commercial_snapshot ?? []), 'return_id' => $return->id, 'source_sale_id' => $return->erp_sale_id, 'totals' => $return->only(['subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount']), 'lines' => $return->lines->map(fn ($line): array => $line->attributesToArray())->all()];
        $source['commercial_return'] = $snapshot;

        return $snapshot;
    }

    private function freezeSourceRecord(CurrentCompany $context, ErpEstablishment $issuer, string $kind, array $data, array &$source): ?array
    {
        if (! in_array($data['model'], ['55', '65'], true) || $kind !== 'posted_sale') {
            return null;
        }
        $sale = ErpSale::query()->where('erp_company_id', $issuer->erp_company_id)->where('erp_establishment_id', $issuer->id)->whereKey($data['erp_sale_id'] ?? 0)->first();
        if (! $sale || $sale->status !== 'posted') {
            throw ValidationException::withMessages(['erp_sale_id' => 'Venda lançada no estabelecimento emissor obrigatória.']);
        }
        app(ErpEstablishmentAccess::class)->assertRelatedResource($context, $sale, 'erp_sale_id');
        $snapshot = app(ErpCommercialDraftService::class)->postedSnapshot($sale);
        if (bccomp((string) ($snapshot['totals']['total_amount'] ?? '-1'), (string) ($source['totals']['invoice'] ?? '-2'), 2) !== 0 || count($snapshot['lines'] ?? []) !== count($source['items'] ?? [])) {
            throw ValidationException::withMessages(['source' => 'Totais e itens precisam corresponder à venda lançada.']);
        }
        foreach ($snapshot['lines'] as $index => $line) {
            $item = $source['items'][$index];
            $lineSnapshot = $line['product_snapshot'] ?? [];
            $product = $lineSnapshot['product'] ?? [];
            $variant = $lineSnapshot['variant'] ?? [];
            if ((int) ($item['source_line_id'] ?? 0) !== (int) ($line['id'] ?? -1)
                || (string) ($snapshot['party']['tax_id'] ?? '') !== (string) ($source['recipient']['cnpj'] ?? '')
                || (string) ($variant['sku'] ?? $product['sku'] ?? '') !== (string) ($item['code'] ?? '')
                || (string) ($product['ncm'] ?? '') !== (string) ($item['ncm'] ?? '')
                || (string) ($lineSnapshot['unit']['short_name'] ?? '') !== (string) ($item['unit'] ?? '')
                || (string) ($lineSnapshot['operation_nature']['cfop'] ?? '') !== (string) ($item['cfop'] ?? '')
                || (string) ($line['erp_product_variant_id'] ?? '') !== (string) ($item['erp_product_variant_id'] ?? '')) {
                throw ValidationException::withMessages(['source.items.'.$index => 'Identidade, classificação, destinatário e unidade devem corresponder à venda lançada.']);
            }
            foreach (['origin_code' => 'origin', 'icms_cst' => 'icms_cst', 'pis_cst' => 'pis_cst', 'cofins_cst' => 'cofins_cst'] as $frozenField => $typedField) {
                if ((string) ($product[$frozenField] ?? '') !== (string) ($item['tax'][$typedField] ?? '')) {
                    throw ValidationException::withMessages(['source.items.'.$index.'.tax' => 'Classificação tributária difere da fonte lançada.']);
                }
            }

            foreach (['quantity' => 'quantity', 'unit_price' => 'unit_price', 'gross_amount' => 'gross_total'] as $actual => $typed) {
                if (bccomp((string) ($line[$actual] ?? '-1'), (string) ($item[$typed] ?? '-2'), 4) !== 0) {
                    throw ValidationException::withMessages(['source.items.'.$index => 'Quantidade e valores devem corresponder à venda lançada.']);
                }
            }
        }
        $source['commercial_source'] = $snapshot;

        return $snapshot;
    }

    private function validateCteSubstitution(ErpFiscalDocument $original, array $source): void
    {
        $snapshot = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($this->draftAttempt($original));
        $frozen = $snapshot['request']['source'] ?? [];
        $oldService = $frozen['transport_service'] ?? [];
        $newService = $source['transport_service'] ?? [];
        $invalid = ! $original->authorized_at || $original->authorized_at->isFuture() || now()->greaterThan($original->authorized_at->copy()->addDays(60))
            || ! in_array($oldService['cte_type'] ?? null, [0, 3], true);
        foreach (['cnpj', 'state_registration'] as $field) {
            $invalid = $invalid || ($frozen['issuer'][$field] ?? null) !== ($source['issuer'][$field] ?? null);
        }
        foreach (['sender', 'receiver', 'expedidor', 'recebedor'] as $role) {
            $invalid = $invalid || ($oldService[$role]['cnpj'] ?? null) !== ($newService[$role]['cnpj'] ?? null);
        }
        foreach (['origin_uf', 'destination_uf'] as $field) {
            $invalid = $invalid || ($oldService['route'][$field] ?? null) !== ($newService['route'][$field] ?? null);
        }
        $invalid = $invalid || ($oldService['service_type'] ?? null) !== ($newService['service_type'] ?? null)
            || ($oldService['taker']['role'] ?? null) !== ($newService['taker']['role'] ?? null)
            || (int) ($oldService['taker']['role'] ?? -1) === 4 && ($oldService['taker']['cnpj'] ?? null) !== ($newService['taker']['cnpj'] ?? null)
            || ! empty($newService['ind_altera_toma']);
        $oldDocuments = array_column($oldService['documents'] ?? [], 'access_key');
        $newDocuments = array_column($newService['documents'] ?? [], 'access_key');
        sort($oldDocuments);
        sort($newDocuments);
        $invalid = $invalid || $oldDocuments !== $newDocuments;
        foreach (ErpFiscalAttempt::query()->where('erp_company_id', $original->erp_company_id)->where('action', 'document:draft')->where('model', '57')->whereHas('document', fn ($query) => $query->where('status', 'authorized')->whereIn('metadata->document_kind', ['complement', 'substitution']))->get() as $candidate) {
            $other = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($candidate)['request']['source'] ?? [];
            if (($other['original_document']['id'] ?? null) === $original->id && in_array($other['document_kind'] ?? null, ['complement', 'substitution'], true)
                && $candidate->document()->where('status', 'authorized')->exists()) {
                $invalid = true;
            }
        }
        if ($invalid) {
            throw ValidationException::withMessages(['source_document_id' => 'Substituição exige CT-e original compatível, sem complemento/substituição autorizado, mesmas partes/serviço/documentos e prazo nacional de 60 dias da autorização; exceções de UF não suportadas.']);
        }
    }

    private function validateTypedSource(string $model, array $source): void
    {
        try {
            $builder = app(match ($model) {
                '55' => Nfe55DocumentBuilder::class, '65' => Nfce65DocumentBuilder::class, '57' => Cte57DocumentBuilder::class, '58' => Mdfe58DocumentBuilder::class
            });
            $builder->validateSource($source);
            if (($source['nfce_qr']['mode'] ?? null) !== 'offline') {
                $builder->build($source);
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['source' => $exception->getMessage()]);
        }
    }

    private function accessKey(array $source, string $model): string
    {
        return app(ErpFiscalPayloadBuilder::class)->buildAccessKey([
            'uf' => $source['identity']['uf'], 'cnpj' => $source['issuer']['cnpj'], 'model' => $model, 'series' => $source['identity']['series'],
            'number' => $source['identity']['number'], 'issued_at' => $source['identity']['issued_at'],
            'tp_emis' => $source['transport_service']['emission_type'] ?? $source['transport_manifest']['emission_type'] ?? (($source['nfce_qr']['mode'] ?? null) === 'offline' ? 9 : 1),
            'cnf' => $source['identity']['numeric_code'] ?? (string) random_int(10_000_000, 99_999_999),
        ]);
    }

    private function authorizationSnapshot(CurrentCompany $context): array
    {
        return ['access_kind' => $context->accessKind, 'delegation_id' => $context->delegation?->id, 'office_account_id' => $context->delegatedOfficeId];
    }

    private function canonical(array $value): string
    {
        $sort = function (array $items) use (&$sort): array {
            foreach ($items as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }
            if (! array_is_list($items)) {
                ksort($items);
            }

            return $items;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
