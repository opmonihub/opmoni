<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogWriteService;
use App\Models\Erp\ErpContact;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpContactController extends ErpCatalogController
{
    public function __construct(private ErpCatalogWriteService $writes) {}

    protected function modelClass(): string
    {
        return ErpContact::class;
    }

    protected function searchableColumns(): array
    {
        return ['name', 'trade_name', 'tax_id', 'email'];
    }

    protected function serialize(Model $model): array
    {
        /** @var ErpContact $model */
        return [
            'id' => $model->id,
            'name' => $model->name,
            'trade_name' => $model->trade_name,
            'tax_id' => $model->tax_id,
            'email' => $model->email,
            'phone' => $model->phone,
            'is_customer' => $model->is_customer,
            'is_supplier' => $model->is_supplier,
            'address' => $model->address,
            'notes' => $model->notes,
            'status' => $model->status,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request): JsonResponse {
            $contact = $this->writes->createContact($context->company->id, $request->all());

            return response()->json(['data' => $this->serialize($contact)], 201);
        });
    }

    public function update(Request $request, int $contact): JsonResponse
    {
        return $this->writes->run($request, function () use ($request, $contact): JsonResponse {
            $model = $this->findForCompany($request, $contact);
            $model = $this->writes->updateContact($model, $request->all());

            return response()->json(['data' => $this->serialize($model)]);
        });
    }
}
