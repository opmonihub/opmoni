<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpCompanyMembers;
use App\Erp\Commercial\OfficeErpMemberCredentials;
use App\Erp\Commercial\OfficeErpMemberInvitations;
use App\Erp\ErpOperationJournal;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfficeErpCompanyMemberController extends Controller
{
    public function index(Request $request, int $company, OfficeErpAdmission $admission, OfficeErpCompanyMembers $members): JsonResponse
    {
        return response()->json($members->index($admission->context($request), $company, $request->query()));
    }

    public function options(Request $request, int $company, OfficeErpAdmission $admission, OfficeErpCompanyMembers $members): JsonResponse
    {
        return response()->json($members->options($admission->context($request), $company));
    }

    public function show(Request $request, int $company, int $membership, OfficeErpAdmission $admission, OfficeErpCompanyMembers $members, OfficeErpMemberCredentials $credentials): JsonResponse
    {
        $ctx = $admission->context($request);
        $data = $members->show($ctx, $company, $membership);
        $data['credential_capabilities'] = $credentials->capabilities($ctx, $company, $membership);

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, int $company, int $membership, OfficeErpAdmission $admission, OfficeErpCompanyMembers $members, ErpOperationJournal $journal): JsonResponse
    {
        return response()->json(['data' => $members->update($admission->context($request), $company, $membership, $request->all(), (string) $journal->key($request, true))]);
    }

    public function destroy(Request $request, int $company, int $membership, OfficeErpAdmission $admission, OfficeErpCompanyMembers $members, ErpOperationJournal $journal): JsonResponse
    {
        return response()->json(['data' => $members->remove($admission->context($request), $company, $membership, $request->all(), (string) $journal->key($request, true))]);
    }

    public function invite(Request $request, int $company, OfficeErpAdmission $admission, OfficeErpMemberInvitations $invitations, ErpOperationJournal $journal): JsonResponse
    {
        return response()->json(['data' => $invitations->issue($admission->context($request), $company, $request->all(), (string) $journal->key($request, true))], 201);
    }

    public function reissue(Request $request, int $company, int $invitation, OfficeErpAdmission $admission, OfficeErpMemberInvitations $invitations, ErpOperationJournal $journal): JsonResponse
    {
        abort_if($request->all() !== [], 422, 'unexpected_request_fields');

        return response()->json(['data' => $invitations->reissue($admission->context($request), $company, $invitation, (string) $journal->key($request, true))]);
    }

    public function revoke(Request $request, int $company, int $invitation, OfficeErpAdmission $admission, OfficeErpMemberInvitations $invitations, ErpOperationJournal $journal): JsonResponse
    {
        abort_if($request->all() !== [], 422, 'unexpected_request_fields');

        return response()->json(['data' => $invitations->revoke($admission->context($request), $company, $invitation, (string) $journal->key($request, true))]);
    }
}
