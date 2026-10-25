<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpOperations;
use App\Erp\Commercial\OfficeErpOwnerInvitations;
use App\Erp\ErpOperationJournal;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfficeErpInvitationController extends Controller
{
    public function store(Request $request, int $client, OfficeErpAdmission $admission, ErpOperationJournal $journal, OfficeErpOwnerInvitations $invitations): JsonResponse
    {
        $data = $request->validate([
            'revision_id' => ['required', 'integer', 'min:1'],
            'owner' => ['required', 'array:full_name,email'],
            'owner.full_name' => ['required', 'string', 'min:2', 'max:255'],
            'owner.email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);
        $ctx = $admission->context($request);
        $key = $journal->key($request, true);
        $outcome = $invitations->issue($ctx, $client, (int) $data['revision_id'], $data['owner'], $key);

        return response()->json(['data' => $outcome])->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, int $invitation, OfficeErpAdmission $admission, ErpOperationJournal $journal, OfficeErpOperations $operations, OfficeErpOwnerInvitations $invitations): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $ctx = $admission->context($request);
        $key = $journal->key($request, true);
        $invitations->revoke($ctx, $invitation, $data['reason'], $key);
        $outcome = $operations->lookup(
            $ctx,
            $key,
            fn (string $action, array $payload): mixed => $invitations->authorizeOperation($ctx, $admission, $action, $payload, $key),
        );

        return response()->json($outcome)->header('Cache-Control', 'no-store');
    }

    public function reissue(Request $request, int $invitation, OfficeErpAdmission $admission, ErpOperationJournal $journal, OfficeErpOwnerInvitations $invitations): JsonResponse
    {
        $ctx = $admission->context($request);
        $outcome = $invitations->reissue($ctx, $invitation, $journal->key($request, true));

        return response()->json(['data' => $outcome])->header('Cache-Control', 'no-store');
    }
}
