<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Enums\Erp\ErpEstablishmentResource;
use App\Erp\ErpCompanyAdministration;
use App\Erp\ErpEstablishmentAccess;
use App\Http\Controllers\Controller;
use App\Models\Erp\ErpEstablishment;
use App\Services\BrazilianTaxId;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ErpEstablishmentController extends Controller
{
    public function __construct(private ErpCompanyAdministration $administration, private BrazilianTaxId $taxId, private ErpEstablishmentAccess $establishmentAccess) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->administration->listParams($request, true);
        $context = CurrentCompany::fromRequest($request);
        $hasAllEstablishments = $this->establishmentAccess->allowedIds($context) === null;
        $query = $this->establishmentAccess->apply(
            ErpEstablishment::query()->where('erp_company_id', $context->company->id),
            $context,
            ErpEstablishmentResource::Establishment,
        );
        if (($params['status'] ?? 'all') !== 'all') {
            $query->where('status', $params['status']);
        }
        if (($params['search'] ?? '') !== '') {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($params['search']).'%']);
        }
        $page = $query->orderBy('id')->paginate($params['per_page'] ?? 15);

        return response()->json(['data' => $page->getCollection()->map($this->administration->establishmentPayload(...))->all(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'all_establishments' => $hasAllEstablishments]]);
    }

    public function show(Request $request, int $establishment): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $model = $this->administration->establishment($context->company->id, $establishment);
        $this->establishmentAccess->assertResource($context, $model);

        return response()->json(['data' => $this->administration->establishmentPayload($model)]);
    }

    public function store(Request $request): Response
    {
        return $this->administration->mutate($request, false, function (int $companyId) use ($request): JsonResponse {
            $this->establishmentAccess->assertAllScope(CurrentCompany::fromRequest($request));
            $data = $this->establishmentData($request, true);
            $establishment = ErpEstablishment::query()->create(['erp_company_id' => $companyId, ...$data]);

            return response()->json(['data' => $this->administration->establishmentPayload($establishment)], 201);
        });
    }

    public function update(Request $request, int $establishment): Response
    {
        return $this->administration->mutate($request, false, function (int $companyId) use ($request, $establishment): JsonResponse {
            $model = $this->administration->establishment($companyId, $establishment, true);
            $this->establishmentAccess->assertResource(CurrentCompany::fromRequest($request), $model);
            $model->fill($this->establishmentData($request, false))->save();

            return response()->json(['data' => $this->administration->establishmentPayload($model)]);
        });
    }

    /** @return array<string, mixed> */
    private function establishmentData(Request $request, bool $creating): array
    {
        $this->administration->assertOnlyFields($request, ['name', 'cnpj', 'status']);
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'cnpj' => ['sometimes', 'nullable', 'string', 'max:20'],
            'status' => [$creating ? 'required' : 'sometimes', 'in:active,inactive'],
        ]);
        if ($data === []) {
            throw ValidationException::withMessages(['name' => 'Informe os dados do estabelecimento']);
        }
        if (isset($data['cnpj'])) {
            $data['cnpj'] = $this->taxId->normalize($data['cnpj']);
            if (! $this->taxId->isValidCnpj($data['cnpj'])) {
                throw ValidationException::withMessages(['cnpj' => 'CNPJ inválido']);
            }
        }

        return $data;
    }
}
