<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpMemberInvitationContinuation;
use App\Erp\Commercial\OfficeErpMemberInvitations;
use App\Erp\ErpOperationJournal;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class OfficeErpMemberInvitationController extends Controller
{
    public function resolve(Request $request, OfficeErpMemberInvitationContinuation $continuations): JsonResponse
    {
        $input = $request->all();
        $this->clearSensitiveInput($request);
        $this->assertOnlyInputFields($input, ['token']);
        $data = Validator::make($input, ['token' => ['required', 'string', 'size:64']])->validate();

        return response()->json($continuations->exchange($request, $data['token']))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function current(Request $request, OfficeErpMemberInvitationContinuation $continuations): JsonResponse
    {
        $this->assertOnlyFields($request, []);

        return response()->json($continuations->current($request))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(Request $request, OfficeErpMemberInvitations $invitations, ErpOperationJournal $journal): JsonResponse
    {
        $input = $request->all();
        $this->clearSensitiveInput($request);

        return response()->json(['data' => $invitations->accept($request, $input, (string) $journal->key($request, true))])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    /** @param list<string> $allowed */
    private function assertOnlyFields(Request $request, array $allowed): void
    {
        $this->assertOnlyInputFields($request->all(), $allowed);
    }

    /** @param array<string, mixed> $input @param list<string> $allowed */
    private function assertOnlyInputFields(array $input, array $allowed): void
    {
        $unexpected = array_diff(array_keys($input), $allowed);
        abort_if($unexpected !== [], 422, 'unexpected_request_fields');
    }

    private function clearSensitiveInput(Request $request): void
    {
        $request->request->replace([]);
        $request->json()->replace([]);
        $request->query->replace([]);
    }
}
