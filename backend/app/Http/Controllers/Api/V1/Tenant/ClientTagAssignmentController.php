<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\AssignClientTagsRequest;
use App\Services\ClientSelectionStore;
use App\Services\ClientTagAssigner;
use App\Services\SupportAudit;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;

class ClientTagAssignmentController extends Controller
{
    public function store(
        AssignClientTagsRequest $request,
        ClientSelectionStore $selections,
        ClientTagAssigner $assigner,
    ): JsonResponse {
        $accountId = (int) resolve(CurrentTenant::class)->accountId;
        $userId = (int) $request->user()->getKey();
        $explicit = array_map(intval(...), $request->validated('ids') ?? []);
        $selectionId = $request->validated('selection_id');
        $excluded = array_map(intval(...), $request->validated('excluded_ids') ?? []);
        $ids = $selections->resolve(
            $accountId,
            $userId,
            $explicit,
            is_string($selectionId) ? $selectionId : null,
            $excluded,
        );

        abort_if($ids === null, 404);
        abort_if($ids === [], 422, 'Nenhum cliente elegível para categorizar.');

        $tagIds = array_map(intval(...), $request->validated('tag_ids'));
        $action = $request->validated('action');
        $clients = $assigner->apply($accountId, $ids, $tagIds, $action);

        SupportAudit::logWrite($request, 'clients', 'categorize', null, [
            'action' => $action,
            'clients' => $clients,
            'tags' => count($tagIds),
        ]);

        return response()->json(['data' => ['clients' => $clients]]);
    }
}
