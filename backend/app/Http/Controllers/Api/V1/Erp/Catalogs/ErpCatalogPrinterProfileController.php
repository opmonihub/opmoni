<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogWriteService;
use App\Http\Controllers\Controller;
use App\Models\Erp\ErpCatalogPrinterProfile;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ErpCatalogPrinterProfileController extends Controller
{
    private const CAPABILITY_PROFILES = ['default', 'simple', 'SP2000', 'TEP-200M', 'P822D'];

    public function __construct(private ErpCatalogWriteService $writes) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = CurrentCompany::fromRequest($request)->company->id;
        $profiles = ErpCatalogPrinterProfile::query()
            ->where('erp_company_id', $companyId)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $profiles->map(fn (ErpCatalogPrinterProfile $profile): array => $this->serialize($profile))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request): JsonResponse {
            $profile = ErpCatalogPrinterProfile::query()->create([
                ...$this->validatedData($request, partial: false),
                'erp_company_id' => $context->company->id,
                'status' => 'active',
            ]);
            $this->writes->record($request, $context, $profile, 'created');

            return response()->json(['data' => $this->serialize($profile)], 201);
        });
    }

    public function show(Request $request, int $profile): JsonResponse
    {
        $companyId = CurrentCompany::fromRequest($request)->company->id;

        return response()->json(['data' => $this->serialize($this->findForCompany($companyId, $profile))]);
    }

    public function update(Request $request, int $profile): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request, $profile): JsonResponse {
            $record = $this->findForCompany((int) $context->company->id, $profile);
            if ($record->status !== 'active') {
                throw ValidationException::withMessages(['profile_id' => 'Um perfil arquivado não pode ser alterado.']);
            }

            $record->fill($this->validatedData($request, partial: true));
            $record->save();
            $this->writes->record($request, $context, $record, 'updated');

            return response()->json(['data' => $this->serialize($record->fresh())]);
        });
    }

    public function archive(Request $request, int $profile): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request, $profile): JsonResponse {
            $record = $this->findForCompany((int) $context->company->id, $profile);
            $record->status = 'archived';
            $record->save();
            $this->writes->record($request, $context, $record, 'archived');

            return response()->json(['data' => $this->serialize($record->fresh())]);
        });
    }

    private function findForCompany(int $companyId, int $profileId): ErpCatalogPrinterProfile
    {
        return ErpCatalogPrinterProfile::query()
            ->where('erp_company_id', $companyId)
            ->whereKey($profileId)
            ->firstOrFail();
    }

    /** @return array{name?: string, capability_profile?: string} */
    private function validatedData(Request $request, bool $partial): array
    {
        $allowed = ['name', 'capability_profile'];
        $prohibited = array_diff(array_keys($request->all()), $allowed);
        if ($prohibited !== []) {
            Validator::make($request->all(), array_fill_keys($prohibited, ['prohibited']))->validate();
        }

        return Validator::make($request->all(), [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:100'],
            'capability_profile' => [$partial ? 'sometimes' : 'required', 'string', 'in:'.implode(',', self::CAPABILITY_PROFILES)],
        ])->validate();
    }

    /** @return array<string, mixed> */
    private function serialize(ErpCatalogPrinterProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'erp_company_id' => $profile->erp_company_id,
            'name' => $profile->name,
            'capability_profile' => $profile->capability_profile,
            'status' => $profile->status,
            'created_at' => $profile->created_at,
            'updated_at' => $profile->updated_at,
        ];
    }
}
