<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\FiscalProcessingResource;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Read\FiscalProcessing;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class FiscalProcessingController extends Controller
{
    public function index(Request $request, FiscalProcessing $processing): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', FiscalDocument::class);

        $validated = $request->validate([
            'client_id' => ['sometimes', 'integer'],
            'state' => ['sometimes', Rule::in(['awaiting_science', 'science_queued', 'awaiting_xml', 'recovery_queued', 'deferred', 'attention'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $processing->page((int) resolve(CurrentTenant::class)->accountId, $validated);

        return FiscalProcessingResource::collection($page['rows'])
            ->additional(['summary' => $page['summary']]);
    }

    public function summary(FiscalProcessing $processing): JsonResponse
    {
        Gate::authorize('viewAny', FiscalDocument::class);

        return response()->json([
            'data' => $processing->summaryCounts((int) resolve(CurrentTenant::class)->accountId),
        ]);
    }
}
