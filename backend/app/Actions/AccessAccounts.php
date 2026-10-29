<?php

namespace App\Actions;

use App\Http\Resources\PlatformAccountResource;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\OwnerActivation;
use App\Models\User;
use App\Support\AccessSession;
use App\Support\AccessTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccessAccounts
{
    /** @return array{User, int} The revision belongs to this locked password proof. */
    public function authenticatePassword(string $email, string $password): array
    {
        return DB::transaction(function () use ($email, $password): array {
            $email = mb_strtolower(trim($email));
            $this->advisory('email:'.$email);
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            $dummy = '$argon2id$v=19$m=65536,t=2,p=1$QWxwaGFCZXRhR2FtbWFEZQ$LAoqYUN2TrYY82BVhXA/LAihsqHFvlalWNKBJVqsMXA';
            $hash = $user?->password ?? $dummy;
            $valid = match (password_get_info($hash)['algoName']) {
                'bcrypt' => strlen($password) <= 72 && Hash::driver('bcrypt')->check($password, $hash),
                'argon2id' => Hash::check($password, $hash),
                default => false,
            };
            if (! $user || ! $valid) {
                throw ValidationException::withMessages(['email' => 'Email ou senha inválidos']);
            }
            if (Hash::needsRehash($user->password)) {
                $user->password = Hash::make($password);
                $user->save();
            }

            return [$user, (int) $user->access_auth_revision];
        });
    }

    public function advisory(string $key): void
    {
        AccessTransaction::boundLockWaits();
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
        }
    }

    /** @return array{User, Account} */
    public function bootstrap(array $data): array
    {
        $hash = Hash::make($data['password']);

        return DB::transaction(function () use ($data, $hash): array {
            $this->advisory('access-bootstrap');
            abort_if(User::exists() || Account::exists(), 403, 'Cadastro inicial indisponível');
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $hash, 'is_super_admin' => true]);
            $account = Account::create(['name' => $data['company'], 'team_size' => $data['size'], 'status' => 'active']);
            AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'admin']);
            $this->assertSessionOwnership();

            return [$user, $account];
        });
    }

    /** @return array{Account, array{url:string, expires_at:string}} */
    public function issue(array $data, ?int $accountId = null): array
    {
        return DB::transaction(function () use ($data, $accountId): array {
            $this->advisory('email:'.$data['login_email']);
            $account = $accountId ? Account::lockForUpdate()->findOrFail($accountId) : null;
            if ($account) {
                if ($account->status !== 'active') {
                    abort(response()->json(['code' => 'account_suspended', 'message' => 'Escritório suspenso'], 403));
                }
                abort_if($account->memberships()->exists(), 409, 'Escritório já possui membros');
            }
            $conflict = User::where('email', $data['login_email'])->exists()
                || OwnerActivation::where('email', $data['login_email'])
                    ->whereNull('consumed_at')->whereNull('revoked_at')->where('expires_at', '>', now())
                    ->when($accountId, fn ($query) => $query->where('account_id', '!=', $accountId))->exists();
            if ($conflict) {
                throw ValidationException::withMessages(['login_email' => 'Este email já está em uso.']);
            }
            $account ??= Account::create(['name' => $data['name'], 'status' => 'active']);
            if ($accountId !== null) {
                app(EnsureAccountSubscription::class)->handle($account);
            }
            app(UpdatePlatformAccountContact::class)->handle($account, $data);
            OwnerActivation::where('account_id', $account->id)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $token = bin2hex(random_bytes(32));
            $link = OwnerActivation::create([
                'account_id' => $account->id,
                'email' => $data['login_email'],
                'owner_name' => $data['owner_name'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(7),
            ]);
            $this->assertSessionOwnership();

            return [$account, [
                'url' => config('access.origin').'/primeiro-acesso?'.http_build_query(['token' => $token, 'email' => $link->email]),
                'expires_at' => $link->expires_at->toISOString(),
            ]];
        });
    }

    public function validLink(array $data, bool $lock = false): ?OwnerActivation
    {
        $query = OwnerActivation::where('token_hash', hash('sha256', $data['token']))
            ->where('email', $data['email'])->whereNull('consumed_at')->whereNull('revoked_at')->where('expires_at', '>', now());
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @return array{User, Account} */
    public function activate(array $data): array
    {
        $hash = Hash::make($data['password']);
        try {
            return DB::transaction(function () use ($data, $hash): array {
                $this->advisory('email:'.$data['email']);
                $candidate = $this->validLink($data);
                if (! $candidate) {
                    throw ValidationException::withMessages(['token' => 'Link inválido ou expirado.']);
                }
                $account = Account::lockForUpdate()->find($candidate->account_id);
                $link = $this->validLink($data, true);
                if (! $account || $account->status !== 'active' || ! $link || User::where('email', $data['email'])->exists()) {
                    throw ValidationException::withMessages(['token' => 'Link inválido ou expirado.']);
                }
                $user = User::create(['name' => $link->owner_name, 'email' => $link->email, 'password' => $hash]);
                AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'admin']);
                $link->update(['consumed_at' => now()]);
                $this->assertSessionOwnership();

                return [$user, $account];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Este email já está em uso.']);
        }
    }

    public function projection(Account $account): array
    {
        return (new PlatformAccountResource($account))->resolve();
    }

    private function assertSessionOwnership(): void
    {
        if (request()->hasSession()) {
            AccessSession::assertOwnership(request());
        }
    }
}
