<?php

namespace App\Http\Controllers\Api\V1\Erp\Fiscal;

use App\Erp\BrazilianStateCode;
use App\Erp\ErpCatalogScope;
use App\Erp\Fiscal\ErpFiscalArtifactStore;
use App\Erp\Fiscal\ErpFiscalDocumentStateMachine;
use App\Erp\Fiscal\ErpFiscalEventRegistry;
use App\Erp\Fiscal\ErpFiscalIssuanceService;
use App\Erp\LegacyPort\LegacyFiscalEngine;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Http\Requests\Erp\Fiscal\StoreErpFiscalDocumentRequest;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class ErpFiscalDocumentController extends ErpOperationsController
{
    public function __construct(
        private ErpCatalogScope $scope,
        private ErpFiscalDocumentStateMachine $stateMachine,
        private LegacyFiscalEngine $fiscalEngine,
        private ErpFiscalIssuanceService $issuance,
        private ErpFiscalArtifactStore $artifacts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ErpFiscalDocument::query()->where('erp_company_id', $this->companyId($request));
        if ($model = $request->query('model')) {
            $query->where('model', $model);
        }

        return $this->paginated($request, $query, fn (Model $row): array => $this->serialize($row));
    }

    public function show(Request $request, int $document): JsonResponse
    {
        $model = $this->findForCompany($request, ErpFiscalDocument::class, $document);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(StoreErpFiscalDocumentRequest $request): JsonResponse
    {
        CurrentCompany::fromRequest($request)->assertCanMutate();
        $data = $request->validated();
        $data['source'] = $request->input('source');
        [$document, $created] = $this->issuance->draft($request, $data);

        return response()->json(['data' => $this->serialize($document)], $created ? 201 : 200);
    }

    public function queue(Request $request, int $document): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, ErpFiscalDocument::class, $document);
        try {
            $result = $this->issuance->queue($model, $context->company, (int) $request->user()->id, $request->all());
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        return response()->json(['data' => $this->serialize($result)]);
    }

    public function transmit(Request $request, int $document): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, ErpFiscalDocument::class, $document);
        if ($model->status !== ErpFiscalDocumentStateMachine::AUTHORIZED) {
            app(ErpCatalogScope::class)->assertActiveEstablishment($context->company->id, $model->erp_establishment_id);
        }

        try {
            $result = $this->issuance->transmit($model, $context->company, $request->user()?->id, $request->all());
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        return response()->json(['data' => $this->serialize($result)]);
    }

    public function consult(Request $request, int $document): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, ErpFiscalDocument::class, $document);
        app(ErpCatalogScope::class)->assertActiveEstablishment($context->company->id, $model->erp_establishment_id);

        try {
            $result = $this->issuance->consult($model, $context->company, $request->user()?->id, $request->all());
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        return response()->json(['data' => $this->serialize($result)]);
    }

    public function contingency(Request $request, int $document): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, ErpFiscalDocument::class, $document);
        if ($model->status !== ErpFiscalDocumentStateMachine::CONTINGENCY_LOCAL) {
            app(ErpCatalogScope::class)->assertActiveEstablishment($context->company->id, $model->erp_establishment_id);
        }

        try {
            $result = $this->issuance->enableContingency($model, $context->company, $request->user()?->id, $request->all());
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        return response()->json(['data' => $this->serialize($result)]);
    }

    public function reconcileContingency(Request $request, int $document): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, ErpFiscalDocument::class, $document);
        app(ErpCatalogScope::class)->assertActiveEstablishment($context->company->id, $model->erp_establishment_id);

        try {
            $result = $this->issuance->reconcileContingency($model, $context->company, $request->user()?->id, $request->all());
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        return response()->json(['data' => $this->serialize($result)]);
    }

    public function downloadXml(Request $request, int $document): Response
    {
        CurrentCompany::fromRequest($request);
        $model = $this->findForCompany($request, ErpFiscalDocument::class, $document);
        abort_unless(
            in_array($model->status, [
                ErpFiscalDocumentStateMachine::AUTHORIZED,
                ErpFiscalDocumentStateMachine::CONTINGENCY_LOCAL,
            ], true),
            404,
        );
        abort_unless($this->artifacts->verifiedFiscalArtifactReady($model), 409);

        $xml = $this->artifacts->readXml($model);
        abort_if($xml === null, 404);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="fiscal-'.$model->id.'.xml"',
        ]);
    }

    public function downloadPdf(Request $request, int $document): Response
    {
        CurrentCompany::fromRequest($request);
        $model = $this->findForCompany($request, ErpFiscalDocument::class, $document);
        abort_unless($model->status === ErpFiscalDocumentStateMachine::AUTHORIZED, 404);

        abort_unless($this->artifacts->verifiedFiscalArtifactReady($model), 409);
        $pdf = $this->artifacts->renderPdfBytes($model);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="fiscal-'.$model->id.'.pdf"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveAccessKey(ErpEstablishment $establishment, array $data): ?string
    {
        $provided = $data['access_key'] ?? null;
        if (is_string($provided) && $provided !== '') {
            return $provided;
        }

        $ufCode = BrazilianStateCode::ibgeFromUf(strtoupper((string) ($data['uf'] ?? $establishment->uf ?? '')));
        if ($ufCode === null) {
            throw new InvalidArgumentException('UF inválida para montagem da chave.');
        }

        $cnpj = (string) $establishment->cnpj;
        if (! preg_match('/^\d{14}$/', $cnpj)) {
            throw new InvalidArgumentException('CNPJ numérico do estabelecimento emissor inválido para montagem da chave.');
        }

        $issuedAt = now();
        $cnf = isset($data['cnf']) && $data['cnf'] !== ''
            ? (string) $data['cnf']
            : (string) random_int(11111111, 99999999);

        return $this->fiscalEngine->montaChave(
            (int) $ufCode,
            (int) $issuedAt->format('y'),
            (int) $issuedAt->format('m'),
            $cnpj,
            (int) $data['model'],
            (int) ($data['series'] ?? 1),
            (int) $data['number'],
            (int) ($data['tp_emis'] ?? 1),
            $cnf,
        );
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpFiscalDocument $model */
        $metadata = is_array($model->metadata) ? $model->metadata : [];
        $contingency = is_array($metadata['contingency'] ?? null) ? $metadata['contingency'] : null;
        $attempt = $model->attempts()->latest('id')->first();
        $actorId = request()->user()?->id;
        $timeline = $model->attempts()->latest('id')->limit(20)->get()->map(static fn ($row): array => [...$row->only(['id', 'action', 'status', 'safe_result', 'model', 'series', 'number']), 'idempotency_key' => (int) $row->actor_user_id === (int) $actorId ? $row->idempotency_key : null])->all();

        return [
            'id' => $model->id,
            'attempt' => $timeline[0] ?? null,
            'attempts' => $timeline,
            'supported_events' => app(ErpFiscalEventRegistry::class)->supportedEvents($model->model, (string) ($metadata['document_kind'] ?? '')),
            'legacy_attention_required' => ! $model->attempts()->where('action', 'document:draft')->exists(),
            'erp_establishment_id' => $model->erp_establishment_id,
            'erp_fiscal_certificate_id' => $model->erp_fiscal_certificate_id,
            'erp_sale_id' => $model->erp_sale_id,
            'model' => $model->model,
            'environment' => $model->environment,
            'series' => $model->series,
            'number' => $model->number,
            'access_key' => $model->access_key,
            'status' => $model->status,
            'idempotency_key' => $model->idempotency_key,
            'certificate_revision' => $model->certificate_revision,
            'artifact_ready' => $model->status === 'authorized' && $this->artifacts->verifiedFiscalArtifactReady($model),
            'claims_provider_authorization' => match ($model->status) {
                ErpFiscalDocumentStateMachine::AUTHORIZED => true,
                ErpFiscalDocumentStateMachine::CONTINGENCY_LOCAL => false,
                default => (bool) ($contingency['claims_provider_authorization'] ?? false),
            },
            'contingency' => $contingency,
            'authorized_at' => $model->authorized_at?->toIso8601String(),
        ];
    }
}
