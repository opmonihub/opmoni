<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\BulkDeleteClientsRequest;
use App\Jobs\DeleteClientsJob;
use App\Services\ClientBulkDeleter;
use App\Services\ClientSelectionStore;
use App\Services\SupportAudit;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ClientBulkDeletionController extends Controller
{
    public function store(
        BulkDeleteClientsRequest $request,
        ClientSelectionStore $selections,
        ClientBulkDeleter $deleter,
    ): JsonResponse {
        $accountId = (int) resolve(CurrentTenant::class)->accountId;
        $userId = (int) $request->user()->getKey();
        $ids = $this->resolveIds($request, $selections, $accountId, $userId);

        abort_if($ids === [], 422, 'Nenhum cliente elegível para exclusão.');

        $operationId = (string) Str::uuid();
        SupportAudit::logWrite($request, 'clients', 'bulk-delete', null, [
            'operation' => $operationId,
            'count' => count($ids),
        ]);

        if (count($ids) > 100) {
            Cache::put($this->key($operationId), [
                'status' => 'queued',
                'account_id' => $accountId,
                'user_id' => $userId,
                'total' => count($ids),
                'deleted' => 0,
                'skipped' => 0,
                'failed' => 0,
            ], 7200);
            DeleteClientsJob::dispatch($operationId, $accountId, $userId, $ids);

            return response()->json(['data' => [
                'id' => $operationId,
                'status' => 'queued',
                'total' => count($ids),
            ]], 202);
        }

        $result = $deleter->deleteIds($accountId, $ids);
        $body = ['id' => $operationId, 'status' => 'completed', 'total' => count($ids), ...$result];
        Cache::put($this->key($operationId), ['account_id' => $accountId, 'user_id' => $userId, ...$body], 7200);

        return response()->json(['data' => $body]);
    }

    public function show(Request $request, string $bulkDeletion): JsonResponse
    {
        $payload = Cache::get($this->key($bulkDeletion));
        $accountId = (int) resolve(CurrentTenant::class)->accountId;
        $userId = (int) $request->user()->getKey();

        abort_if(
            ! is_array($payload)
            || ($payload['account_id'] ?? null) !== $accountId
            || ($payload['user_id'] ?? null) !== $userId,
            404,
        );

        return response()->json(['data' => $payload]);
    }

    /**
     * @return list<int>
     */
    private function resolveIds(BulkDeleteClientsRequest $request, ClientSelectionStore $selections, int $accountId, int $userId): array
    {
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

        return $ids;
    }

    private function key(string $id): string
    {
        return 'client-bulk-deletion:'.$id;
    }
}
