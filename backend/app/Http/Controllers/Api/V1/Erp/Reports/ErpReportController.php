<?php

namespace App\Http\Controllers\Api\V1\Erp\Reports;

use App\Erp\ErpEstablishmentAccess;
use App\Erp\ErpOperationJournal;
use App\Erp\ErpReleaseGate;
use App\Erp\Reports\ErpLedgerReportService;
use App\Erp\Reports\ErpReportExportOperation;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Reports\ErpReportExport;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use JsonException;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ErpReportController extends ErpOperationsController
{
    public function __construct(
        private ErpLedgerReportService $reports,
        private ErpReleaseGate $releaseGate,
    ) {}

    public function show(Request $request, string $category): JsonResponse
    {
        $this->releaseGate->assertReleased($this->companyId($request), $request->user()?->id);
        $context = CurrentCompany::fromRequest($request);
        $companyId = $context->company->id;
        $access = app(ErpEstablishmentAccess::class);
        $allowedEstablishmentIds = $access->allowedIds($context);
        $includeCompanywideFinance = $access->allowsCompanywideNullableRecords($context);
        $filters = $request->validate([
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'erp_establishment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'regex:/^[A-Z]{3}$/'],
            'status' => ['sometimes', 'string'],
            'direction' => ['sometimes', 'string'],
        ]);

        try {
            $payload = $this->reports->report($companyId, $category, $filters, $allowedEstablishmentIds, $includeCompanywideFinance, $request);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'code' => 'validation_error',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['data' => $payload]);
    }

    public function storeExport(Request $request, string $category): JsonResponse
    {
        $this->releaseGate->assertReleased($this->companyId($request), $request->user()?->id);
        CurrentCompany::fromRequest($request)->assertCanMutate();
        if (! in_array($category, ErpLedgerReportService::CATEGORIES, true)) {
            abort(422, 'Categoria de relatório inválida');
        }

        $companyId = $this->companyId($request);
        $context = CurrentCompany::fromRequest($request);
        $access = app(ErpEstablishmentAccess::class);
        $allowedEstablishmentIds = $access->allowedIds($context);
        $includeCompanywideFinance = $access->allowsCompanywideNullableRecords($context);
        $filters = $request->validate([
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'erp_establishment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'regex:/^[A-Z]{3}$/'],
            'status' => ['sometimes', 'string'],
            'direction' => ['sometimes', 'string'],
        ]);

        return app(ErpOperationJournal::class)->executeReportExport($request, $category, $filters, function () use ($request, $category, $companyId, $filters, $allowedEstablishmentIds, $includeCompanywideFinance): JsonResponse {
            try {
                $normalized = $this->reports->normalizeFilters($companyId, $filters, $category, $allowedEstablishmentIds, $includeCompanywideFinance);
                $payload = $this->reports->report($companyId, $category, $normalized, $allowedEstablishmentIds, $includeCompanywideFinance, $request);
                if (in_array($category, ['financial', 'overview'], true)) {
                    $normalized['authorized_payment_ids'] = $this->reports->paymentReferences();
                }
            } catch (InvalidArgumentException $exception) {
                return response()->json([
                    'code' => 'validation_error',
                    'message' => $exception->getMessage(),
                ], 422);
            }

            $authorizedEstablishmentIds = $allowedEstablishmentIds;
            if ($normalized['erp_establishment_id'] !== null) {
                $authorizedEstablishmentIds = [$normalized['erp_establishment_id']];
            }
            $path = sprintf(
                'erp/report-exports/%d/%s-%s.json',
                $companyId,
                $category,
                now()->format('YmdHis').'-'.bin2hex(random_bytes(4)),
            );
            $export = ErpReportExport::query()->create([
                'erp_company_id' => $companyId,
                'user_id' => $request->user()->id,
                'report_type' => $category,
                'filters' => $normalized,
                'authorized_establishment_scope' => $normalized['authorized_establishment_scope'],
                'authorized_establishment_ids' => $authorizedEstablishmentIds,
                'authorized_companywide_finance' => $normalized['authorized_companywide_finance'],
                'status' => 'pending',
                'expires_at' => now()->addDay(),
            ]);

            $failureCode = null;
            try {
                $bytes = json_encode($payload, JSON_THROW_ON_ERROR);
                $disk = Storage::disk('local');
                if ($disk->put($path, $bytes, ['visibility' => 'private']) !== true) {
                    $failureCode = 'export_storage_unavailable';
                } elseif ($disk->get($path) !== $bytes) {
                    $failureCode = 'export_artifact_invalid';
                }
            } catch (JsonException) {
                $failureCode = 'export_artifact_invalid';
            } catch (FilesystemException) {
                $failureCode = 'export_storage_unavailable';
            }

            if ($failureCode !== null) {
                $this->failExport($export, $failureCode, $path);

                return $this->exportError($export, $failureCode, 503);
            }

            $export->update([
                'status' => 'completed',
                'storage_path' => $path,
                'content_sha256' => hash('sha256', $bytes),
                'content_bytes' => strlen($bytes),
            ]);

            return response()->json(['data' => $this->serializeExport($export)], 201);
        });
    }

    public function showExport(Request $request, int $export): JsonResponse
    {
        $this->releaseGate->assertReleased($this->companyId($request), $request->user()?->id);
        $model = $this->findForCompany($request, ErpReportExport::class, $export);
        if ($model->user_id !== $request->user()?->id) {
            abort(403, 'Exportação pertence a outro usuário');
        }
        if ($model->expires_at !== null && $model->expires_at->isPast()) {
            throw new HttpException(410, 'Exportação expirada', null, ['code' => 'export_expired']);
        }
        if ($model->status !== 'completed') {
            return $this->exportError($model, $model->failure_code ?? 'export_not_available', 409);
        }
        if ($model->content_sha256 === null || $model->content_bytes === null) {
            return $this->exportError($model, 'export_requires_regeneration', 409);
        }
        $access = app(ErpEstablishmentAccess::class);
        $context = CurrentCompany::fromRequest($request);
        if (! $this->exportScopeAllows($model, $access->allowedIds($context), $access->allowsCompanywideNullableRecords($context))
            || ! app(ErpReportExportOperation::class)->paymentReferencesAllow($request, $model)) {
            return $this->exportError($model, 'export_requires_regeneration', 409);
        }

        $failureCode = null;
        $contents = null;
        try {
            $bytes = $model->storage_path === null ? null : Storage::disk('local')->get($model->storage_path);
            if (! is_string($bytes)
                || strlen($bytes) !== $model->content_bytes
                || ! hash_equals($model->content_sha256, hash('sha256', $bytes))) {
                $failureCode = 'export_artifact_invalid';
            } else {
                $contents = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($contents)) {
                    $failureCode = 'export_artifact_invalid';
                }
            }
        } catch (JsonException) {
            $failureCode = 'export_artifact_invalid';
        } catch (FilesystemException) {
            $failureCode = 'export_storage_unavailable';
        }

        if ($failureCode !== null) {
            $this->failExport($model, $failureCode, $model->storage_path);

            return $this->exportError($model, $failureCode, 503);
        }

        return response()->json([
            'data' => [
                'export' => $this->serializeExport($model),
                'report' => $contents,
            ],
        ]);
    }

    private function failExport(ErpReportExport $export, string $failureCode, ?string $path): void
    {
        $export->update([
            'status' => 'failed',
            'storage_path' => null,
            'failure_code' => $failureCode,
        ]);

        if ($path !== null) {
            try {
                Storage::disk('local')->delete($path);
            } catch (FilesystemException) {
                // Failure metadata remains available even when cleanup storage is unavailable.
            }
        }
    }

    /** @param list<int>|null $currentIds Null means all locations. */
    private function exportScopeAllows(ErpReportExport $export, ?array $currentIds, bool $currentCompanywideFinance): bool
    {
        if ($export->authorized_companywide_finance === null && ! $currentCompanywideFinance) {
            return false;
        }
        if ($export->authorized_companywide_finance === true && ! $currentCompanywideFinance) {
            return false;
        }

        if ($export->authorized_establishment_scope === null || $export->authorized_establishment_scope === 'all') {
            return $currentIds === null;
        }
        $authorizedIds = $export->authorized_establishment_ids ?? [];

        return $currentIds === null || array_diff($authorizedIds, $currentIds) === [];
    }

    private function exportError(ErpReportExport $export, string $code, int $status): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $code === 'export_requires_regeneration'
                ? 'Esta exportação precisa ser gerada novamente.'
                : 'Exportação indisponível. Gere uma nova exportação ou tente novamente.',
            'data' => $this->serializeExport($export),
        ], $status);
    }

    /** @return array<string, mixed> */
    private function serializeExport(ErpReportExport $export): array
    {
        return [
            'id' => $export->id,
            'report_type' => $export->report_type,
            'filters' => array_diff_key($export->filters ?? [], array_flip(['authorized_payment_ids'])),
            'status' => $export->status,
            'expires_at' => $export->expires_at?->toIso8601String(),
            ...($export->failure_code !== null ? ['failure_code' => $export->failure_code] : []),
        ];
    }
}
