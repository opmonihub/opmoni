<?php

namespace App\Http\Controllers\Api\V1\Erp\Reports;

use App\Erp\ErpReleaseGate;
use App\Erp\Reports\ErpLedgerReportService;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Reports\ErpReportExport;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
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
        $companyId = $this->companyId($request);
        $filters = $request->validate([
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'erp_establishment_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        try {
            $payload = $this->reports->report($companyId, $category, $filters);
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
        $filters = $request->validate([
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'erp_establishment_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        try {
            $normalized = $this->reports->normalizeFilters($companyId, $filters);
            $payload = $this->reports->report($companyId, $category, $normalized);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'code' => 'validation_error',
                'message' => $exception->getMessage(),
            ], 422);
        }

        $path = sprintf(
            'erp/report-exports/%d/%s-%s.json',
            $companyId,
            $category,
            now()->format('YmdHis').'-'.bin2hex(random_bytes(4)),
        );
        Storage::disk('local')->put($path, json_encode($payload, JSON_THROW_ON_ERROR));

        $export = ErpReportExport::query()->create([
            'erp_company_id' => $companyId,
            'user_id' => $request->user()->id,
            'report_type' => $category,
            'filters' => $normalized,
            'status' => 'completed',
            'storage_path' => $path,
            'expires_at' => now()->addDay(),
        ]);

        return response()->json(['data' => $this->serializeExport($export)], 201);
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
        if ($model->status !== 'completed' || $model->storage_path === null) {
            abort(409, 'Exportação ainda não está disponível');
        }
        if (! Storage::disk('local')->exists($model->storage_path)) {
            abort(503, 'Artefato de exportação indisponível');
        }

        $contents = json_decode(Storage::disk('local')->get($model->storage_path), true, 512, JSON_THROW_ON_ERROR);

        return response()->json([
            'data' => [
                'export' => $this->serializeExport($model),
                'report' => $contents,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeExport(ErpReportExport $export): array
    {
        return [
            'id' => $export->id,
            'report_type' => $export->report_type,
            'filters' => $export->filters,
            'status' => $export->status,
            'expires_at' => $export->expires_at?->toIso8601String(),
        ];
    }
}
