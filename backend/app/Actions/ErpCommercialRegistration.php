<?php

namespace App\Actions;

use App\Erp\ErpCompanyProvisioning;
use App\Models\Account;
use App\Models\Erp\ErpCompany;
use App\Models\OwnerActivation;
use App\Models\User;
use App\Support\AccessTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ErpCommercialRegistration
{
    public function __construct(private ErpCompanyProvisioning $provisioning, private AccessAccounts $identities) {}

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

        return $this->withIdentityCollisionValidation(fn (): array => DB::transaction(function () use ($data, $email, $hash): array {
            AccessTransaction::boundLockWaits();
            $this->identities->advisory('email:'.$email);
            $this->advisory('erp-company:'.$data['cnpj']);
            if (User::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'Este email já possui cadastro. Faça login para continuar.']);
            }
            if (OwnerActivation::query()->where('email', $email)
                ->whereNull('consumed_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['email' => 'Este email já está em uso.']);
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
        }));
    }

    /**
     * Create the owner identity for an office-attributed trial without provisioning a second Company.
     * The caller owns the surrounding transaction and creates the Company, Client and trial atomically.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function createTrialOwner(array $data): User
    {
        $email = mb_strtolower(trim($data['email']));
        $hash = Hash::make($data['password']);

        return DB::transaction(function () use ($data, $email, $hash): User {
            AccessTransaction::boundLockWaits();
            $this->identities->advisory('email:'.$email);
            $this->assertTrialOwnerEmailAvailableLocked($email);

            return User::query()->create([
                'name' => trim($data['name']),
                'email' => $email,
                'password' => $hash,
            ]);
        });
    }

    public function assertTrialOwnerEmailAvailable(string $email): void
    {
        $email = mb_strtolower(trim($email));

        DB::transaction(function () use ($email): void {
            AccessTransaction::boundLockWaits();
            $this->identities->advisory('email:'.$email);
            $this->assertTrialOwnerEmailAvailableLocked($email);
        });
    }

    /** @param  array{name: string, cnpj: string, establishment_name?: string|null}  $data */
    public function createCompanyForAuthenticated(User $user, array $data): ErpCompany
    {
        return $this->withIdentityCollisionValidation(fn (): ErpCompany => DB::transaction(function () use ($user, $data): ErpCompany {
            AccessTransaction::boundLockWaits();
            $this->advisory('erp-company:'.$data['cnpj']);
            if (ErpCompany::query()->where('cnpj', $data['cnpj'])->exists()) {
                throw ValidationException::withMessages(['cnpj' => 'CNPJ já cadastrado']);
            }

            return $this->provisioning->createCompanyForOwner($user, $data);
        }));
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $operation
     * @return T
     */
    private function withIdentityCollisionValidation(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (UniqueConstraintViolationException $exception) {
            $diagnostic = (string) ($exception->errorInfo[2] ?? '');
            $field = match (DB::getDriverName()) {
                'pgsql' => match (true) {
                    preg_match('/unique constraint "erp_companies_cnpj_unique"/', $diagnostic) === 1 => 'cnpj',
                    preg_match('/unique constraint "users_(?:normalized_email_unique|email_unique)"/', $diagnostic) === 1 => 'email',
                    default => null,
                },
                'sqlite' => match ($diagnostic) {
                    'UNIQUE constraint failed: erp_companies.cnpj' => 'cnpj',
                    'UNIQUE constraint failed: users.email' => 'email',
                    default => null,
                },
                default => null,
            };
            if ($field === null) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                $field => $field === 'cnpj' ? 'CNPJ já cadastrado' : 'Este email já possui cadastro. Faça login para continuar.',
            ]);
        }
    }

    private function assertTrialOwnerEmailAvailableLocked(string $email): void
    {
        if (User::query()->where('email', $email)->exists()) {
            abort(response()->json([
                'code' => 'login_required',
                'message' => 'Entre com sua conta para continuar o cadastro.',
            ], 409));
        }

        if (OwnerActivation::query()->where('email', $email)
            ->whereNull('consumed_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
            throw ValidationException::withMessages(['email' => 'Este email já está em uso.']);
        }
    }

    private function advisory(string $key): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
        }
    }
}
