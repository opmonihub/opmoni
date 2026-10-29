<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Actions\ErpCommercialRegistration;
use App\Http\Controllers\Controller;
use App\Http\Resources\IdentityResource;
use App\Models\Account;
use App\Models\User;
use App\Services\BrazilianTaxId;
use App\Support\AccessSession;
use App\Support\ErpSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ErpRegistrationController extends Controller
{
    public function __construct(
        private ErpCommercialRegistration $registration,
        private BrazilianTaxId $taxId,
    ) {}

    public function registrationStatus(): JsonResponse
    {
        $ready = User::exists() || Account::exists();

        return response()->json([
            'registration_available' => $ready && config('erp.registration_enabled'),
            'platform_ready' => $ready,
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $this->registration->assertPlatformReady();
        $this->registration->assertRegistrationGate();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'company_name' => ['required', 'string', 'max:255'],
            'cnpj' => ['required', 'string', 'max:20'],
            'establishment_name' => ['nullable', 'string', 'max:255'],
        ]);
        $data['email'] = mb_strtolower(trim($data['email']));
        $cnpj = $this->taxId->normalize($data['cnpj']);
        if (! $this->taxId->isValidCnpj($cnpj)) {
            throw ValidationException::withMessages(['cnpj' => 'CNPJ inválido']);
        }
        $data['cnpj'] = $cnpj;

        [$user, $company] = $this->registration->registerNewOwner($data);
        AccessSession::assertOwnership($request);
        AccessSession::authenticate($request, $user, null, 0);
        ErpSession::applyCompanySelection($request, (int) $company->id, [
            'kind' => 'direct',
            'membership' => $company->memberships()->where('user_id', $user->id)->firstOrFail(),
        ]);

        return response()->json((new IdentityResource($user))->resolve($request), 201);
    }
}
