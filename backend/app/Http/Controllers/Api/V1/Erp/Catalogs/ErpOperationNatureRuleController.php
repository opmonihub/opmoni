<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpOperationNatureRules;
use App\Models\Erp\ErpOperationNatureCfopChoice;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ErpOperationNatureRuleController extends ErpOperationNatureController
{
    public function choices(Request $request, int $operationNature): JsonResponse
    {
        $nature = app(ErpOperationNatureRules::class)->nature(CurrentCompany::fromRequest($request), $operationNature);
        $params = app(ErpCatalogScope::class)->listParams($request);
        $query = $nature->cfopChoices()->where('erp_company_id', $nature->erp_company_id)->orderBy('id');
        if ($params['status'] !== null) {
            $query->where('status', $params['status']);
        }
        if ($params['search'] !== null) {
            $query->where('cfop', 'like', '%'.addcslashes($params['search'], '%_\\').'%');
        }
        $implicit = ! $nature->cfopChoices()->where('cfop', $nature->cfop)->exists()
            && $params['status'] !== 'archived'
            && ($params['search'] === null || str_contains($nature->cfop, $params['search']));
        $perPage = $params['per_page'];
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        $page = (int) $request->query('page', 1);
        $total = $query->count() + (int) $implicit;
        $offset = ($page - 1) * $perPage;
        $items = [];
        if ($implicit && $offset === 0) {
            $items[] = ['id' => null, 'cfop' => $nature->cfop, 'reference_version' => $nature->cfop_reference_version, 'status' => 'active', 'implicit' => true];
        }
        $physicalOffset = max(0, $offset - (int) $implicit);
        $limit = $perPage - count($items);
        if ($limit > 0) {
            foreach ($query->offset($physicalOffset)->limit($limit)->get() as $choice) {
                $items[] = $this->choiceData($choice);
            }
        }

        return response()->json(['data' => $items, 'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'per_page' => $perPage, 'total' => $total, 'configuration_revision' => $nature->configuration_revision]]);
    }

    public function showChoice(Request $request, int $operationNature, int $choice): JsonResponse
    {
        $rules = app(ErpOperationNatureRules::class);
        $nature = $rules->nature(CurrentCompany::fromRequest($request), $operationNature);

        return response()->json(['data' => $this->choiceData($rules->choice($nature, $choice)), 'configuration_revision' => $nature->configuration_revision]);
    }

    public function storeChoice(Request $request, int $operationNature): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request, $operationNature): JsonResponse {
            $rules = app(ErpOperationNatureRules::class);
            $nature = $rules->nature($context, $operationNature, lock: true);
            $rules->expectedRevision($nature, $request->all());
            $rules->assertActive($nature);
            $data = $request->validate(['cfop' => ['required', 'string', 'regex:/\A[123567][0-9]{3}\z/']]);
            if (! $rules->ensureChoice($request, $context, $nature, $data['cfop'])) {
                throw ValidationException::withMessages(['cfop' => 'Esta opção já está ativa nesta natureza.']);
            }
            $rules->advance($request, $context, $nature);
            $choice = $nature->cfopChoices()->where('cfop', $data['cfop'])->firstOrFail();

            return response()->json(['data' => $this->choiceData($choice), 'configuration_revision' => $nature->configuration_revision], 201);
        });
    }

    public function archiveChoice(Request $request, int $operationNature, int $choice): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request, $operationNature, $choice): JsonResponse {
            $rules = app(ErpOperationNatureRules::class);
            $nature = $rules->nature($context, $operationNature, lock: true);
            $rules->expectedRevision($nature, $request->all());
            $rules->assertActive($nature);
            $row = $rules->choice($nature, $choice);
            if ($row->status !== 'archived') {
                $rules->assertChoiceUnused($nature, $row);
                if ($nature->cfop === $row->cfop) {
                    $data = $request->validate(['cfop' => ['required', 'string', 'regex:/\A[123567][0-9]{3}\z/']]);
                    $replacement = $nature->cfopChoices()->where('erp_company_id', $context->company->id)->where('cfop', $data['cfop'])->where('status', 'active')->whereKeyNot($row->id)->lockForUpdate()->first();
                    if ($replacement === null) {
                        throw ValidationException::withMessages(['cfop' => 'Selecione outra opção ativa como padrão antes de arquivar.']);
                    }
                    $reference = $rules->reference($replacement->cfop, $nature->movement);
                    $nature->fill(['cfop' => $replacement->cfop, 'cfop_reference_version' => $reference['reference_version']]);
                }
                $row->status = 'archived';
                $row->save();
                $this->record($request, $context, $row, 'archived');
                $rules->advance($request, $context, $nature);
            }

            return response()->json(['data' => $this->choiceData($row), 'configuration_revision' => $nature->configuration_revision]);
        });
    }

    public function exceptions(Request $request, int $operationNature): JsonResponse
    {
        $nature = app(ErpOperationNatureRules::class)->nature(CurrentCompany::fromRequest($request), $operationNature);
        $params = app(ErpCatalogScope::class)->listParams($request);
        $filters = $request->validate(['erp_product_id' => ['sometimes', 'integer', 'min:1']]);
        $query = $nature->productExceptions()->where('erp_company_id', $nature->erp_company_id)->orderBy('id');
        if ($params['status'] !== null) {
            $query->where('status', $params['status']);
        }
        if (isset($filters['erp_product_id'])) {
            $query->where('erp_product_id', $filters['erp_product_id']);
        }
        if ($params['search'] !== null) {
            $search = '%'.addcslashes($params['search'], '%_\\').'%';
            $query->whereHas('product', fn (Builder $product) => $product->where('erp_company_id', $nature->erp_company_id)->where(fn (Builder $match) => $match->where('name', 'like', $search)->orWhere('sku', 'like', $search)));
        }
        $page = $query->paginate($params['per_page']);

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'configuration_revision' => $nature->configuration_revision]]);
    }

    public function showException(Request $request, int $operationNature, int $exception): JsonResponse
    {
        $rules = app(ErpOperationNatureRules::class);
        $nature = $rules->nature(CurrentCompany::fromRequest($request), $operationNature);

        return response()->json(['data' => $rules->exception($nature, $exception), 'configuration_revision' => $nature->configuration_revision]);
    }

    public function storeException(Request $request, int $operationNature): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request, $operationNature): JsonResponse {
            $rules = app(ErpOperationNatureRules::class);
            $nature = $rules->nature($context, $operationNature, lock: true);
            $rules->expectedRevision($nature, $request->all());
            $rules->assertActive($nature);
            $data = $request->validate(['erp_product_id' => ['required', 'integer', 'min:1'], 'erp_operation_nature_cfop_choice_id' => ['required', 'integer', 'min:1'], 'erp_establishment_id' => ['prohibited']]);
            $choice = $rules->choice($nature, $data['erp_operation_nature_cfop_choice_id'], true);
            $rules->reference($choice->cfop, $nature->movement);
            $rules->product($context, $data['erp_product_id']);
            if ($nature->productExceptions()->where('erp_product_id', $data['erp_product_id'])->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['erp_product_id' => 'Este produto já tem uma exceção ativa nesta natureza.']);
            }
            $row = $nature->productExceptions()->create([...$data, 'erp_company_id' => $context->company->id, 'status' => 'active']);
            $this->record($request, $context, $row, 'created');
            $rules->advance($request, $context, $nature);

            return response()->json(['data' => $row, 'configuration_revision' => $nature->configuration_revision], 201);
        });
    }

    public function updateException(Request $request, int $operationNature, int $exception): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request, $operationNature, $exception): JsonResponse {
            $rules = app(ErpOperationNatureRules::class);
            $nature = $rules->nature($context, $operationNature, lock: true);
            $rules->expectedRevision($nature, $request->all());
            $rules->assertActive($nature);
            $row = $rules->exception($nature, $exception);
            $data = $request->validate(['erp_operation_nature_cfop_choice_id' => ['required', 'integer', 'min:1'], 'erp_product_id' => ['prohibited'], 'erp_establishment_id' => ['prohibited']]);
            if ($row->status !== 'active') {
                throw ValidationException::withMessages(['status' => 'A exceção está arquivada.']);
            }
            $choice = $rules->choice($nature, $data['erp_operation_nature_cfop_choice_id'], true);
            $rules->reference($choice->cfop, $nature->movement);
            $rules->product($context, $row->erp_product_id);
            $row->fill($data);
            if ($row->isDirty()) {
                $row->save();
                $this->record($request, $context, $row, 'updated');
                $rules->advance($request, $context, $nature);
            }

            return response()->json(['data' => $row, 'configuration_revision' => $nature->configuration_revision]);
        });
    }

    public function archiveException(Request $request, int $operationNature, int $exception): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request, $operationNature, $exception): JsonResponse {
            $rules = app(ErpOperationNatureRules::class);
            $nature = $rules->nature($context, $operationNature, lock: true);
            $rules->expectedRevision($nature, $request->all());
            $row = $rules->exception($nature, $exception);
            if ($row->status !== 'archived') {
                $row->status = 'archived';
                $row->save();
                $this->record($request, $context, $row, 'archived');
                $rules->advance($request, $context, $nature);
            }

            return response()->json(['data' => $row, 'configuration_revision' => $nature->configuration_revision]);
        });
    }

    private function choiceData(ErpOperationNatureCfopChoice $choice): array
    {
        return ['id' => $choice->id, 'erp_operation_nature_id' => $choice->erp_operation_nature_id, 'cfop' => $choice->cfop, 'reference_version' => $choice->reference_version, 'status' => $choice->status, 'implicit' => false];
    }
}
