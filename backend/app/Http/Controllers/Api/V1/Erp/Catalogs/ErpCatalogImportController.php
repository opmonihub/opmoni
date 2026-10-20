<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogImportOperation;
use App\Erp\ErpCatalogImportService;
use App\Erp\ErpCatalogWriteService;
use App\Erp\ErpOperationJournal;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpCatalogImportController extends Controller
{
    public function __construct(private ErpCatalogImportService $imports, private ErpCatalogWriteService $writes, private ErpOperationJournal $journal) {}

    public function previewProducts(Request $request): JsonResponse
    {
        return $this->writes->run($request, fn (): JsonResponse => response()->json(['data' => $this->imports->preview($request, 'products')]));
    }

    public function previewContacts(Request $request): JsonResponse
    {
        return $this->writes->run($request, fn (): JsonResponse => response()->json(['data' => $this->imports->preview($request, 'contacts')]));
    }

    public function readProducts(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->imports->read($request, 'products')]);
    }

    public function readContacts(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->imports->read($request, 'contacts')]);
    }

    public function confirmProducts(Request $request): JsonResponse
    {
        return $this->confirm($request, ErpCatalogImportOperation::PRODUCTS);
    }

    public function confirmContacts(Request $request): JsonResponse
    {
        return $this->confirm($request, ErpCatalogImportOperation::CONTACTS);
    }

    private function confirm(Request $request, string $operation): JsonResponse
    {
        return $this->writes->run($request, function () use ($request, $operation): JsonResponse {
            $result = $this->journal->executeImport($request, $operation);

            return response()->json($result['body'], $result['status']);
        });
    }
}
