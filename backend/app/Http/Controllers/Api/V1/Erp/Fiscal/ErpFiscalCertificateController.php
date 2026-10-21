<?php

namespace App\Http\Controllers\Api\V1\Erp\Fiscal;

use App\Erp\ErpEstablishmentAccess;
use App\Erp\Fiscal\ErpFiscalCertificateRevisionService;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Http\Requests\Erp\Fiscal\RotateErpFiscalCertificateRequest;
use App\Http\Requests\Erp\Fiscal\StoreErpFiscalCertificateRequest;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\Fiscal\ErpFiscalCertificate;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ErpFiscalCertificateController extends ErpOperationsController
{
    public function __construct(
        private ErpFiscalCertificateRevisionService $revisions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ErpFiscalCertificate::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $certificate): JsonResponse
    {
        $model = $this->findForCompany($request, ErpFiscalCertificate::class, $certificate);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(StoreErpFiscalCertificateRequest $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        try {
            $data = $request->validated();
            $issuer = $this->issuerEstablishment($request, (int) $data['erp_establishment_id']);
            $rawCertificate = $this->certificateBytes($request, $data);
            $certificate = $this->revisions->store($issuer, $data['label'], $rawCertificate, $data['password'], $request->user()?->id);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['certificate' => $exception->getMessage()]);
        }

        return response()->json(['data' => $this->serialize($certificate)], 201);
    }

    public function rotate(RotateErpFiscalCertificateRequest $request, int $certificate): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $existing = $this->findForCompany($request, ErpFiscalCertificate::class, $certificate);
        /** @var ErpFiscalCertificate $existing */
        $issuer = $this->issuerEstablishment($request, (int) $existing->erp_establishment_id);
        try {
            $data = $request->validated();
            $rawCertificate = $this->certificateBytes($request, $data);
            $certificateModel = $this->revisions->rotate($existing, $rawCertificate, $data['password'], $request->user()?->id);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['certificate' => $exception->getMessage()]);
        }

        return response()->json(['data' => $this->serialize($certificateModel)]);
    }

    /** @param array<string, mixed> $data */
    private function certificateBytes(Request $request, array $data): string
    {
        if ($request->file('certificate') instanceof UploadedFile && array_key_exists('certificate_content', $data)) {
            throw new InvalidArgumentException('Envie o certificado em arquivo ou em base64, sem preencher os dois campos.');
        }

        if ($request->file('certificate') instanceof UploadedFile) {
            $bytes = file_get_contents($request->file('certificate')->getRealPath());
            if ($bytes === false) {
                throw new InvalidArgumentException('Não foi possível ler o arquivo PKCS#12 enviado.');
            }

            return $bytes;
        }

        $bytes = base64_decode((string) ($data['certificate_content'] ?? ''), true);
        if ($bytes === false) {
            throw new InvalidArgumentException('O certificado em base64 está inválido.');
        }

        return $bytes;
    }

    private function issuerEstablishment(Request $request, int $establishmentId): ErpEstablishment
    {
        $establishment = ErpEstablishment::query()
            ->where('erp_company_id', $this->companyId($request))
            ->where('status', 'active')
            ->whereKey($establishmentId)
            ->firstOrFail();
        app(ErpEstablishmentAccess::class)->assertResource(CurrentCompany::fromRequest($request), $establishment);

        return $establishment;
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpFiscalCertificate $model */
        return [
            'id' => $model->id,
            'label' => $model->label,
            'cnpj' => $model->cnpj,
            'erp_establishment_id' => $model->erp_establishment_id,
            'certificate_fingerprint' => $model->certificate_fingerprint,
            'revision' => $model->revision,
            'revision_id' => $model->current_revision_id,
            'status' => $model->status,
            'valid_from' => $model->valid_from?->toIso8601String(),
            'valid_until' => $model->valid_until?->toIso8601String(),
        ];
    }
}
