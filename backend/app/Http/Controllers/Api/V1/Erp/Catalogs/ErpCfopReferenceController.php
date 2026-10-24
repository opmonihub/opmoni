<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\CfopReference\CfopReferenceCatalog;
use App\Erp\CfopReference\CfopReferenceUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpCfopReferenceController
{
    public function index(Request $request, CfopReferenceCatalog $catalog): JsonResponse
    {
        try {
            return response()->json($catalog->search($request));
        } catch (CfopReferenceUnavailable) {
            return $this->unavailable();
        }
    }

    public function show(string $code, CfopReferenceCatalog $catalog): JsonResponse
    {
        try {
            $reference = $catalog->find($code);
        } catch (CfopReferenceUnavailable) {
            return $this->unavailable();
        }

        if ($reference === null) {
            return response()->json([
                'code' => 'cfop_reference_not_found',
                'message' => 'Referência de CFOP não encontrada.',
            ], 404);
        }

        return response()->json(['data' => $reference]);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'code' => 'cfop_reference_unavailable',
            'message' => 'A consulta da referência de CFOP está indisponível. Tente novamente.',
        ], 503);
    }
}
