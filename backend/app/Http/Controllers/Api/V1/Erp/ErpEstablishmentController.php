<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\ErpEstablishment;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpEstablishmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = CurrentCompany::fromRequest($request)->company;
        $perPage = min(100, max(1, (int) $request->query('per_page', 15)));
        $page = ErpEstablishment::query()
            ->where('erp_company_id', $company->id)
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map(fn (ErpEstablishment $row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'status' => $row->status,
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
