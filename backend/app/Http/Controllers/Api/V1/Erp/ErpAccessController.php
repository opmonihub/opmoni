<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Actions\ErpCommercialRegistration;
use App\Erp\ErpCompanyAuthorization;
use App\Http\Controllers\Controller;
use App\Http\Resources\IdentityResource;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpOfficeDelegation;
use App\Services\BrazilianTaxId;
use App\Support\AccessSession;
use App\Support\AccessTransaction;
use App\Support\ErpSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ErpAccessController extends Controller
{
    public function __construct(
        private ErpCompanyAuthorization $authorization,
        private ErpCommercialRegistration $registration,
        private BrazilianTaxId $taxId,
    ) {}

    public function switchCompany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'min:1'],
        ]);
        $companyId = (int) $data['company_id'];
        $officeId = $request->session()->get('current_account_id');
        $officeId = is_int($officeId) || (is_string($officeId) && ctype_digit($officeId)) ? (int) $officeId : null;

        DB::transaction(function () use ($request, $companyId, $officeId): void {
            AccessTransaction::boundLockWaits();
            $company = ErpCompany::lockForUpdate()->find($companyId);
            abort_unless($company, 403, 'Acesso à empresa negado');
            $access = $this->authorization->resolveAccess($request->user(), $company, $officeId);
            abort_unless($access, 403, 'Acesso à empresa negado');
            AccessSession::assertOwnership($request);
            ErpSession::applyCompanySelection($request, $companyId, $access);
            $request->session()->regenerateToken();
        });

        return response()->json((new IdentityResource($request->user()))->resolve($request));
    }

    public function createCompany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'cnpj' => ['required', 'string', 'max:20'],
            'establishment_name' => ['nullable', 'string', 'max:255'],
        ]);
        $cnpj = $this->taxId->normalize($data['cnpj']);
        if (! $this->taxId->isValidCnpj($cnpj)) {
            throw ValidationException::withMessages(['cnpj' => 'CNPJ inválido']);
        }

        $company = null;
        DB::transaction(function () use ($request, $data, $cnpj, &$company): void {
            AccessTransaction::boundLockWaits();
            AccessSession::assertOwnership($request);
            $this->registration->assertPlatformReady();
            $company = $this->registration->createCompanyForAuthenticated($request->user(), [
                'name' => $data['name'],
                'cnpj' => $cnpj,
                'establishment_name' => $data['establishment_name'] ?? null,
            ]);
            ErpSession::applyCompanySelection($request, (int) $company->id, [
                'kind' => 'direct',
                'membership' => $company->memberships()->where('user_id', $request->user()->id)->firstOrFail(),
            ]);
            $request->session()->regenerateToken();
        });

        return response()->json((new IdentityResource($request->user()))->resolve($request), 201);
    }

    public function acceptDelegation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'min:1'],
            'account_id' => ['required', 'integer', 'min:1'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:64'],
        ]);
        $company = ErpCompany::findOrFail((int) $data['company_id']);
        $delegation = $this->authorization->acceptDelegation(
            $company,
            $request->user(),
            (int) $data['account_id'],
            $data['permissions'] ?? ['erp.read'],
            fn () => AccessSession::assertOwnership($request),
        );

        return response()->json(['data' => [
            'id' => $delegation->id,
            'company_id' => $delegation->erp_company_id,
            'account_id' => $delegation->account_id,
            'permissions' => $delegation->effectivePermissions(),
            'status' => $delegation->status,
        ]], 201);
    }

    public function revokeDelegation(Request $request, ErpOfficeDelegation $delegation): JsonResponse
    {
        $this->authorization->revokeDelegation($delegation, $request->user(), fn () => AccessSession::assertOwnership($request));

        return response()->json(['data' => ['id' => $delegation->id, 'status' => $delegation->fresh()->status]]);
    }
}
