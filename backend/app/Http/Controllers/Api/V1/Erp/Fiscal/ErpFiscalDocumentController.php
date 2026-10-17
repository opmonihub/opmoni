<?php

namespace App\Http\Controllers\Api\V1\Erp\Fiscal;

use App\Erp\BrazilianStateCode;
use App\Erp\ErpCatalogScope;
use App\Erp\Fiscal\ErpFiscalArtifactStore;
use App\Erp\Fiscal\ErpFiscalDocumentStateMachine;
use App\Erp\Fiscal\ErpFiscalIssuanceService;
use App\Erp\LegacyPort\LegacyFiscalEngine;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\Fiscal\ErpFiscalCertificate;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Sales\ErpSale;
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

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $data = $request->validate([
            'erp_establishment_id' => ['required', 'integer'],
            'erp_fiscal_certificate_id' => ['sometimes', 'nullable', 'integer'],
            'erp_sale_id' => ['sometimes', 'nullable', 'integer'],
            'model' => ['required', 'string', 'max:8'],
            'environment' => ['sometimes', 'string', 'max:16'],
            'series' => ['sometimes', 'integer', 'min:1'],
            'number' => ['required', 'integer', 'min:1'],
            'access_key' => ['sometimes', 'nullable', 'string', 'size:44'],
            'uf' => ['sometimes', 'nullable', 'string', 'size:2'],
            'cnf' => ['sometimes', 'nullable', 'string', 'max:8'],
            'tp_emis' => ['sometimes', 'integer', 'min:1', 'max:9'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'source_document_id' => ['sometimes', 'nullable', 'integer'],
            'document_kind' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['erp_establishment_id']);
        $this->scope->assertBelongsToCompany(
            ErpFiscalCertificate::class,
            $companyId,
            $data['erp_fiscal_certificate_id'] ?? null,
            'erp_fiscal_certificate_id',
        );
        if (isset($data['erp_sale_id'])) {
            $this->scope->assertBelongsToCompany(ErpSale::class, $companyId, $data['erp_sale_id'], 'erp_sale_id');
        }

        $existing = ErpFiscalDocument::query()
            ->where('erp_company_id', $companyId)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();
        if ($existing) {
            return response()->json(['data' => $this->serialize($existing)]);
        }

        try {
            $accessKey = $this->resolveAccessKey($context->company, $data);
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        $metadata = [];
        if (($data['document_kind'] ?? null) === 'return' && isset($data['source_document_id'])) {
            $source = ErpFiscalDocument::query()
                ->where('erp_company_id', $companyId)
                ->whereKey($data['source_document_id'])
                ->firstOrFail();
            $metadata['return'] = ['source_document_id' => $source->id, 'source_access_key' => $source->access_key];
        }

        $document = ErpFiscalDocument::query()->create([
            ...$data,
            'erp_company_id' => $companyId,
            'access_key' => $accessKey,
            'status' => ErpFiscalDocumentStateMachine::DRAFT,
            'environment' => $data['environment'] ?? 'homologation',
            'series' => $data['series'] ?? 1,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        return response()->json(['data' => $this->serialize($document)], 201);
    }

    public function queue(Request $request, int $document): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, ErpFiscalDocument::class, $document);

        if ($model->status === ErpFiscalDocumentStateMachine::QUEUED) {
            return response()->json(['data' => $this->serialize($model)]);
        }

        if ($model->access_key === null || $model->access_key === '') {
            try {
                $model->access_key = $this->resolveAccessKey($context->company, [
                    'model' => $model->model,
                    'series' => $model->series,
                    'number' => $model->number,
                    'uf' => $request->input('uf'),
                ]);
            } catch (InvalidArgumentException $exception) {
                abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
            }
        }

        try {
            $model->status = $this->stateMachine->queueFromDraft($model->status);
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }
        $model->save();

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function transmit(Request $request, int $document): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, ErpFiscalDocument::class, $document);

        try {
            $result = $this->issuance->transmit($model, $context->company, $request->user()?->id);
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

        try {
            $result = $this->issuance->consult($model, $context->company);
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

        try {
            $result = $this->issuance->enableContingency($model, $context->company);
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

        try {
            $result = $this->issuance->reconcileContingency($model, $context->company, $request->user()?->id);
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
        abort_unless($this->artifacts->verifyIntegrity($model), 409);

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

        $pdf = $this->artifacts->renderPdfBytes($model);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="fiscal-'.$model->id.'.pdf"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveAccessKey(ErpCompany $company, array $data): ?string
    {
        $provided = $data['access_key'] ?? null;
        if (is_string($provided) && $provided !== '') {
            return $provided;
        }

        $ufCode = BrazilianStateCode::ibgeFromUf((string) ($data['uf'] ?? $this->companyUf($company) ?? 'SP'));
        if ($ufCode === null) {
            throw new InvalidArgumentException('UF inválida para montagem da chave.');
        }

        $cnpj = preg_replace('/\D+/', '', (string) $company->cnpj) ?? '';
        if (strlen($cnpj) !== 14) {
            throw new InvalidArgumentException('CNPJ da empresa inválido para montagem da chave.');
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

    private function companyUf(ErpCompany $company): ?string
    {
        $settings = is_array($company->operating_settings) ? $company->operating_settings : [];
        $uf = $settings['uf'] ?? ($settings['common_settings']['uf'] ?? null);

        return is_string($uf) && $uf !== '' ? strtoupper($uf) : null;
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpFiscalDocument $model */
        $metadata = is_array($model->metadata) ? $model->metadata : [];
        $contingency = is_array($metadata['contingency'] ?? null) ? $metadata['contingency'] : null;

        return [
            'id' => $model->id,
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
            'artifact_ready' => $model->storage_path !== null
                && $model->artifact_sha256 !== null
                && $model->artifact_byte_length !== null,
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
