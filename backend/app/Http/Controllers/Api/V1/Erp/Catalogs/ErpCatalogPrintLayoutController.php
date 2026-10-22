<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogDecimal;
use App\Erp\ErpCatalogPrintGeometry;
use App\Erp\ErpCatalogWriteService;
use App\Http\Controllers\Controller;
use App\Models\Erp\ErpCatalogPrintLayout;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ErpCatalogPrintLayoutController extends Controller
{
    public function __construct(private ErpCatalogWriteService $writes) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::fromRequest($request)->company->id;
        $layouts = ErpCatalogPrintLayout::query()
            ->where('erp_company_id', $companyId)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $layouts->map(fn (ErpCatalogPrintLayout $layout): array => $this->serialize($layout))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request): JsonResponse {
            $data = $this->validatedData($request, null);
            $layout = ErpCatalogPrintLayout::query()->create([
                ...$data,
                'erp_company_id' => $context->company->id,
                'is_default' => false,
                'status' => 'active',
            ]);
            $this->writes->record($request, $context, $layout, 'created');

            return response()->json(['data' => $this->serialize($layout)], 201);
        });
    }

    public function show(Request $request, int $layout): JsonResponse
    {
        $companyId = CurrentCompany::fromRequest($request)->company->id;
        $record = $this->findForCompany($companyId, $layout);

        return response()->json(['data' => $this->serialize($record)]);
    }

    public function update(Request $request, int $layout): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request, $layout): JsonResponse {
            $record = $this->findForCompany((int) $context->company->id, $layout);
            if ($record->status !== 'active') {
                throw ValidationException::withMessages(['layout_id' => 'Uma configuração arquivada não pode ser alterada.']);
            }

            $record->fill($this->validatedData($request, $record));
            $record->save();
            $this->writes->record($request, $context, $record, 'updated');

            return response()->json(['data' => $this->serialize($record->fresh())]);
        });
    }

    public function makeDefault(Request $request, int $layout): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request, $layout): JsonResponse {
            $record = $this->findForCompany((int) $context->company->id, $layout);
            if ($record->status !== 'active') {
                throw ValidationException::withMessages(['layout_id' => 'Uma configuração arquivada não pode ser padrão.']);
            }

            $previousDefaults = ErpCatalogPrintLayout::query()
                ->where('erp_company_id', $context->company->id)
                ->where('is_default', true)
                ->whereKeyNot($record->id)
                ->get();
            foreach ($previousDefaults as $previous) {
                $previous->is_default = false;
                $previous->save();
                $this->writes->record($request, $context, $previous, 'updated');
            }
            $record->is_default = true;
            $record->save();
            $this->writes->record($request, $context, $record, 'updated');

            return response()->json(['data' => $this->serialize($record->fresh())]);
        });
    }

    public function archive(Request $request, int $layout): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request, $layout): JsonResponse {
            $record = $this->findForCompany((int) $context->company->id, $layout);
            if ($record->status === 'archived') {
                return response()->json(['data' => $this->serialize($record)]);
            }

            $record->is_default = false;
            $record->status = 'archived';
            $record->save();
            $this->writes->record($request, $context, $record, 'archived');

            return response()->json(['data' => $this->serialize($record->fresh())]);
        });
    }

    private function findForCompany(int $companyId, int $layoutId): ErpCatalogPrintLayout
    {
        return ErpCatalogPrintLayout::query()
            ->where('erp_company_id', $companyId)
            ->whereKey($layoutId)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function validatedData(Request $request, ?ErpCatalogPrintLayout $current): array
    {
        $partial = $current !== null;
        $data = Validator::make($request->all(), [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'label_width_mm' => [$partial ? 'sometimes' : 'required', 'string', ...ErpCatalogDecimal::widthRule(4, 4)],
            'label_height_mm' => [$partial ? 'sometimes' : 'required', 'string', ...ErpCatalogDecimal::widthRule(4, 4)],
            'paper_width_mm' => [$partial ? 'sometimes' : 'required', 'string', ...ErpCatalogDecimal::widthRule(4, 4)],
            'paper_height_mm' => [$partial ? 'sometimes' : 'present', 'nullable', 'string', ...ErpCatalogDecimal::widthRule(4, 4)],
            'top_margin_mm' => [$partial ? 'sometimes' : 'required', 'string', ...ErpCatalogDecimal::widthRule(4, 4)],
            'left_margin_mm' => [$partial ? 'sometimes' : 'required', 'string', ...ErpCatalogDecimal::widthRule(4, 4)],
            'row_spacing_mm' => [$partial ? 'sometimes' : 'required', 'string', ...ErpCatalogDecimal::widthRule(4, 4)],
            'column_spacing_mm' => [$partial ? 'sometimes' : 'required', 'string', ...ErpCatalogDecimal::widthRule(4, 4)],
            'columns_per_row' => [$partial ? 'sometimes' : 'required', 'integer', 'min:1', 'max:20'],
            'labels_per_sheet' => [$partial ? 'sometimes' : 'present', 'nullable', 'integer', 'min:1', 'max:250'],
            'is_continuous' => [$partial ? 'sometimes' : 'required', 'boolean'],
        ])->validate();

        $merged = array_merge($current?->only([
            'name', 'description', 'label_width_mm', 'label_height_mm', 'paper_width_mm', 'paper_height_mm',
            'top_margin_mm', 'left_margin_mm', 'row_spacing_mm', 'column_spacing_mm', 'columns_per_row',
            'labels_per_sheet', 'is_continuous',
        ]) ?? [], $data);
        $merged = ErpCatalogPrintGeometry::normalize($merged);

        ErpCatalogPrintGeometry::assertRange('label_width_mm', $merged['label_width_mm'], 20, 200);
        ErpCatalogPrintGeometry::assertRange('label_height_mm', $merged['label_height_mm'], 10, 200);
        ErpCatalogPrintGeometry::assertRange('paper_width_mm', $merged['paper_width_mm'], 20, 500);
        foreach (['top_margin_mm', 'left_margin_mm', 'row_spacing_mm', 'column_spacing_mm'] as $field) {
            ErpCatalogPrintGeometry::assertRange($field, $merged[$field], 0, 100);
        }
        if ($merged['paper_height_mm'] !== null) {
            ErpCatalogPrintGeometry::assertRange('paper_height_mm', $merged['paper_height_mm'], 10, 2000);
        }
        ErpCatalogPrintGeometry::assertLayoutFits($merged);

        return $merged;
    }

    /** @return array<string, mixed> */
    private function serialize(ErpCatalogPrintLayout $layout): array
    {
        return [
            'id' => $layout->id,
            'erp_company_id' => $layout->erp_company_id,
            'name' => $layout->name,
            'description' => $layout->description,
            'label_width_mm' => (string) $layout->label_width_mm,
            'label_height_mm' => (string) $layout->label_height_mm,
            'paper_width_mm' => (string) $layout->paper_width_mm,
            'paper_height_mm' => $layout->paper_height_mm !== null ? (string) $layout->paper_height_mm : null,
            'top_margin_mm' => (string) $layout->top_margin_mm,
            'left_margin_mm' => (string) $layout->left_margin_mm,
            'row_spacing_mm' => (string) $layout->row_spacing_mm,
            'column_spacing_mm' => (string) $layout->column_spacing_mm,
            'columns_per_row' => $layout->columns_per_row,
            'labels_per_sheet' => $layout->labels_per_sheet,
            'is_continuous' => $layout->is_continuous,
            'is_default' => $layout->is_default,
            'status' => $layout->status,
            'created_at' => $layout->created_at,
            'updated_at' => $layout->updated_at,
        ];
    }
}
