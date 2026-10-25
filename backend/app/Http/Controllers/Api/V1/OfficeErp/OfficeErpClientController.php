<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpOwnerInvitations;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OfficeErpClientController extends Controller
{
    public function index(Request $request, OfficeErpAdmission $admission, OfficeErpOwnerInvitations $invitations): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([25, 50, 100])],
        ]);
        $ctx = $admission->context($request);

        return response()->json($invitations->clients(
            $ctx,
            $filters['q'] ?? null,
            (int) ($filters['page'] ?? 1),
            (int) ($filters['per_page'] ?? 25),
        ))->header('Cache-Control', 'no-store');
    }

    public function preview(Request $request, int $client, OfficeErpAdmission $admission, OfficeErpOwnerInvitations $invitations): JsonResponse
    {
        $data = $request->validate([
            'revision_id' => ['required', 'integer', 'min:1'],
            'owner' => ['required', 'array:full_name,email'],
            'owner.full_name' => ['required', 'string', 'min:2', 'max:255'],
            'owner.email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);
        $ctx = $admission->context($request);

        return response()->json(['data' => $invitations->preview($ctx, $client, (int) $data['revision_id'], $data['owner'])])
            ->header('Cache-Control', 'no-store');
    }
}
