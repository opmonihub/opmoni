<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpOwnerInvitations;
use App\Erp\Commercial\OfficeErpQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\OfficeErp\ListCommercialRequest;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfficeErpClientController extends Controller
{
    public function index(Request $request, OfficeErpAdmission $admission, OfficeErpOwnerInvitations $invitations, OfficeErpQueries $queries): JsonResponse
    {
        $filters = ListCommercialRequest::validateFilters('clients', $request->query());
        $ctx = $admission->context($request);
        $invitations->expireDueInvitationsForOfficeRead($ctx);
        $page = $queries->clients($ctx, $filters);

        return response()->json([
            'data' => array_map(static fn (Client $client): array => [
                'id' => (int) $client->id,
                'name' => (string) $client->name,
                'trade_name' => $client->trade_name,
                'tax_id' => (string) $client->tax_id,
            ], $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ])->header('Cache-Control', 'no-store');
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
