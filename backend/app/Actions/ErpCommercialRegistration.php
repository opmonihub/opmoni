<?php

namespace App\Actions;

use App\Erp\ErpCompanyProvisioning;
use App\Models\Account;
use App\Models\Erp\ErpCompany;
use App\Models\User;
use App\Support\AccessTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ErpCommercialRegistration
{
    public function __construct(private ErpCompanyProvisioning $provisioning) {}

    public function assertPlatformReady(): void
    {
        abort_if(! User::exists() && ! Account::exists(), 403, 'Cadastro ERP indisponível');
    }

    public function assertRegistrationGate(): void
    {
        abort_unless(config('erp.registration_enabled'), 403, 'Cadastro ERP indisponível');
    }

    /** @param  array{name: string, email: string, password: string, company_name: string, cnpj: string, establishment_name?: string|null}  $data
     * @return array{User, ErpCompany}
     */
    public function registerNewOwner(array $data): array
    {
        $email = mb_strtolower(trim($data['email']));
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Este email já possui cadastro. Faça login para continuar.'])
                ->errorBag('default')
                ->status(422);
        }

        $hash = Hash::make($data['password']);

        return DB::transaction(function () use ($data, $email, $hash): array {
            AccessTransaction::boundLockWaits();
            $this->advisory('erp-register:'.$email);
            if (User::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'Este email já possui cadastro. Faça login para continuar.']);
            }
            if (ErpCompany::query()->where('cnpj', $data['cnpj'])->exists()) {
                throw ValidationException::withMessages(['cnpj' => 'CNPJ já cadastrado']);
            }
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $email,
                'password' => $hash,
            ]);
            $company = $this->provisioning->createCompanyForOwner($user, [
                'name' => $data['company_name'],
                'cnpj' => $data['cnpj'],
                'establishment_name' => $data['establishment_name'] ?? null,
            ]);

            return [$user, $company];
        });
    }

    /** @param  array{name: string, cnpj: string, establishment_name?: string|null}  $data */
    public function createCompanyForAuthenticated(User $user, array $data): ErpCompany
    {
        return DB::transaction(function () use ($user, $data): ErpCompany {
            AccessTransaction::boundLockWaits();
            $this->advisory('erp-company:'.$data['cnpj']);
            if (ErpCompany::query()->where('cnpj', $data['cnpj'])->exists()) {
                throw ValidationException::withMessages(['cnpj' => 'CNPJ já cadastrado']);
            }

            return $this->provisioning->createCompanyForOwner($user, $data);
        });
    }

    private function advisory(string $key): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
        }
    }
}
