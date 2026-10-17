<?php

namespace App\Http\Controllers\Api\V1\Erp\Fiscal;

use App\Erp\ErpCatalogScope;
use App\Erp\Fiscal\ErpFiscalCertificateValidator;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Fiscal\ErpFiscalCertificate;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

class ErpFiscalCertificateController extends ErpOperationsController
{
    public function __construct(
        private ErpCatalogScope $scope,
        private ErpFiscalCertificateValidator $validator,
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

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $userId = $request->user()?->id;
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'cnpj' => ['sometimes', 'nullable', 'string', 'size:14'],
            'erp_establishment_id' => ['sometimes', 'nullable', 'integer'],
            'certificate_content' => ['required', 'string'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['erp_establishment_id'] ?? null);

        $rawCertificate = base64_decode($data['certificate_content'], true);
        abort_if($rawCertificate === false, 422, 'Certificado inválido');

        try {
            $parsed = $this->validator->validatePkcs12(
                $rawCertificate,
                $data['password'],
                $data['cnpj'] ?? $context->company->cnpj,
            );
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        $certificate = ErpFiscalCertificate::query()->create([
            'erp_company_id' => $companyId,
            'erp_establishment_id' => $data['erp_establishment_id'] ?? null,
            'label' => $data['label'],
            'cnpj' => $parsed['cnpj'] ?? ($data['cnpj'] ?? null),
            'encrypted_certificate' => Crypt::encryptString($rawCertificate),
            'encrypted_password' => Crypt::encryptString($data['password']),
            'certificate_fingerprint' => hash('sha256', $rawCertificate),
            'revision' => 1,
            'status' => 'active',
            'valid_from' => $parsed['valid_from'],
            'valid_until' => $parsed['valid_until'],
            'uploaded_by_user_id' => $userId,
        ]);

        return response()->json(['data' => $this->serialize($certificate)], 201);
    }

    public function rotate(Request $request, int $certificate): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $userId = $request->user()?->id;
        $existing = $this->findForCompany($request, ErpFiscalCertificate::class, $certificate);
        /** @var ErpFiscalCertificate $existing */
        $data = $request->validate([
            'certificate_content' => ['required', 'string'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $rawCertificate = base64_decode($data['certificate_content'], true);
        abort_if($rawCertificate === false, 422, 'Certificado inválido');

        try {
            $parsed = $this->validator->validatePkcs12(
                $rawCertificate,
                $data['password'],
                $existing->cnpj ?? $context->company->cnpj,
            );
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        $existing->update([
            'encrypted_certificate' => Crypt::encryptString($rawCertificate),
            'encrypted_password' => Crypt::encryptString($data['password']),
            'certificate_fingerprint' => hash('sha256', $rawCertificate),
            'revision' => (int) $existing->revision + 1,
            'valid_from' => $parsed['valid_from'],
            'valid_until' => $parsed['valid_until'],
            'uploaded_by_user_id' => $userId,
            'status' => 'active',
        ]);

        return response()->json(['data' => $this->serialize($existing->fresh())]);
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
            'status' => $model->status,
            'valid_from' => $model->valid_from?->toIso8601String(),
            'valid_until' => $model->valid_until?->toIso8601String(),
        ];
    }
}
