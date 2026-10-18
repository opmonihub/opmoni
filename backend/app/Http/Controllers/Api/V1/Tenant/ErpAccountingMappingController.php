<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Erp\Accounting\ErpAccountingMappingService;
use App\Http\Controllers\Controller;
use App\Models\Erp\Accounting\ErpAccountingMapping;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Support\AccessSession;
use App\Support\CurrentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpAccountingMappingController extends Controller
{
    public function __construct(private ErpAccountingMappingService $mappings) {}

    public function context(Request $request): JsonResponse
    {
        $context = CurrentAccount::fromRequest($request);
        $context->assertAdmin();
        $params = $request->validate([
            'company_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $company = ErpCompany::query()->whereKey($params['company_id'])->where('status', 'active')->firstOrFail();
        $delegation = ErpOfficeDelegation::query()
            ->where('erp_company_id', $company->id)
            ->where('account_id', $context->account->id)
            ->where('status', 'active')
            ->first();
        abort_unless($delegation, 404);

        $page = ErpEstablishment::query()
            ->where('erp_company_id', $company->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->paginate($params['per_page'] ?? 15);

        return response()->json([
            'data' => [
                'delegation_id' => $delegation->id,
                'status' => $delegation->status,
                'establishments' => $page->getCollection()->map(fn (ErpEstablishment $establishment): array => [
                    'id' => $establishment->id,
                    'name' => $establishment->name,
                ])->all(),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $context = CurrentAccount::fromRequest($request);
        $context->assertAdmin();
        $data = $request->validate(['company_id' => ['sometimes', 'integer', 'min:1']]);
        $query = ErpAccountingMapping::query()->where('account_id', $context->account->id);
        if (isset($data['company_id'])) {
            $query->where('erp_company_id', $data['company_id']);
        }

        return response()->json(['data' => $query->orderBy('id')->get()->map($this->serialize(...))]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentAccount::fromRequest($request);
        $context->assertAdmin();
        $data = $request->validate([
            'delegation_id' => ['required', 'integer', 'min:1'],
            'erp_establishment_id' => ['required', 'integer', 'min:1'],
            'client_id' => ['required', 'integer', 'min:1'],
        ]);
        $mapping = $this->mappings->approve($context, $data['delegation_id'], $data['erp_establishment_id'], $data['client_id'], fn () => AccessSession::assertOwnership($request));

        return response()->json(['data' => $this->serialize($mapping)], $mapping->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, int $mapping): JsonResponse
    {
        $result = $this->mappings->revoke(CurrentAccount::fromRequest($request), $mapping, fn () => AccessSession::assertOwnership($request));

        return response()->json(['data' => $this->serialize($result)]);
    }

    private function serialize(ErpAccountingMapping $mapping): array
    {
        return $mapping->only(['id', 'erp_company_id', 'erp_establishment_id', 'delegation_id', 'account_id', 'client_id', 'revision', 'status', 'approved_by_user_id', 'approved_at', 'revoked_at']);
    }
}
