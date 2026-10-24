<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogWriteService;
use App\Erp\ErpCompanyAdministration;
use App\Erp\ErpProductTaxProfiles;
use App\Models\Erp\ErpProductTaxProfile;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpProductTaxProfileController extends ErpCatalogController
{
    protected function modelClass(): string
    {
        return ErpProductTaxProfile::class;
    }

    protected function searchableColumns(): array
    {
        return ['name'];
    }

    protected function indexRelations(): array
    {
        return ['currentRevision'];
    }

    protected function serialize(Model $model): array
    {
        $revision = $model->currentRevision;

        return ['id' => $model->id, 'name' => $model->name, 'status' => $model->status, 'current_revision' => $model->current_revision, 'revision_id' => $revision?->id, 'fields' => $revision?->fields ?? [], 'applicability' => $revision?->applicability ?? []];
    }

    public function store(Request $request): JsonResponse
    {
        return app(ErpCompanyAdministration::class)->mutate($request, false, fn () => app(ErpCatalogWriteService::class)->run($request, function (CurrentCompany $context) use ($request): JsonResponse {
            $profile = app(ErpProductTaxProfiles::class)->save($request, $context, $request->all());

            return response()->json(['data' => $this->serialize($profile)], 201);
        }));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return app(ErpCompanyAdministration::class)->mutate($request, false, fn () => app(ErpCatalogWriteService::class)->run($request, function (CurrentCompany $context) use ($request, $id): JsonResponse {
            $profile = app(ErpProductTaxProfiles::class)->save($request, $context, $request->all(), $this->findForCompany($request, $id));

            return response()->json(['data' => $this->serialize($profile)]);
        }));
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        return app(ErpCompanyAdministration::class)->mutate($request, false, fn () => app(ErpCatalogWriteService::class)->run($request, function (CurrentCompany $context) use ($request, $id): JsonResponse {
            $profile = app(ErpProductTaxProfiles::class)->archive($request, $context, $id);

            return response()->json(['data' => $this->serialize($profile)]);
        }));
    }
}
