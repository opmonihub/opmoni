<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminAccountProvisioner
{
    /**
     * @param  array{
     *     name?: string,
     *     status?: string,
     *     billing_contact?: array<string, mixed>,
     *     owner_name?: string|null,
     *     login_email?: string|null,
     *     access?: string|null,
     *     password?: string|null
     * }  $data
     * @return array{account: Account, first_access: array{url: string, expires_at: string}|null}
     */
    public function update(Account $account, array $data): array
    {
        return DB::transaction(function () use ($account, $data): array {
            $account = Account::query()->lockForUpdate()->findOrFail($account->getKey());

            if (isset($data['name'])) {
                $account->name = $data['name'];
            }

            if (array_key_exists('billing_contact', $data)) {
                $account->forceFill([
                    'settings' => Account::mergePlatformBillingContact($account->settings, $data['billing_contact']),
                ]);
            }

            $account->save();

            $firstAccess = null;
            $members = $account->members()->count();

            if ($members === 0 && ! empty($data['login_email'])) {
                $access = $data['access'] ?? 'password_now';
                $email = strtolower(trim((string) $data['login_email']));
                $ownerName = trim((string) ($data['owner_name'] ?? ''));
                $ownerName = $ownerName !== '' ? $ownerName : $account->name;

                if ($access === 'password_now' && ! empty($data['password'])) {
                    $this->clearOwnerInvite($account);
                    $this->attachOwner($account, $ownerName, $email, (string) $data['password']);
                } elseif ($access === 'first_access') {
                    $firstAccess = $this->storeFirstAccessInvite($account, $ownerName, $email);
                }
            }

            $account->load('subscription.plan');
            $account->loadCount('members');

            return ['account' => $account, 'first_access' => $firstAccess];
        });
    }

    private function clearOwnerInvite(Account $account): void
    {
        $settings = $account->settings;

        if (! is_array($settings) || ! is_array($settings['platform']['owner_invite'] ?? null)) {
            return;
        }

        unset($settings['platform']['owner_invite']);

        if ($settings['platform'] === []) {
            unset($settings['platform']);
        }

        $account->forceFill(['settings' => $settings === [] ? null : $settings])->save();
    }

    /**
     * @param  array{
     *     name: string,
     *     status: string,
     *     settings: array<string, mixed>|null,
     *     owner_name?: string|null,
     *     login_email?: string|null,
     *     access?: string|null,
     *     password?: string|null
     * }  $data
     * @return array{account: Account, first_access: array{url: string, expires_at: string}|null}
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $account = Account::create([
                'name' => $data['name'],
                'status' => $data['status'],
                'settings' => $data['settings'],
            ]);

            $firstAccess = null;
            $access = $data['access'] ?? 'none';
            $email = isset($data['login_email']) ? strtolower(trim($data['login_email'])) : '';
            $ownerName = trim((string) ($data['owner_name'] ?? ''));

            if ($access === 'password_now' && $email !== '') {
                $this->attachOwner($account, $ownerName !== '' ? $ownerName : $account->name, $email, (string) $data['password']);
            } elseif ($access === 'first_access' && $email !== '') {
                $firstAccess = $this->storeFirstAccessInvite($account, $ownerName !== '' ? $ownerName : $account->name, $email);
            }

            $account->load('subscription.plan');
            $account->loadCount('members');

            return ['account' => $account, 'first_access' => $firstAccess];
        });
    }

    public function attachOwner(Account $account, string $name, string $email, string $password): User
    {
        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = $password;
        $user->current_account_id = $account->getKey();
        $user->save();

        $account->members()->attach($user, ['role' => 'admin']);

        return $user;
    }

    /**
     * @return array{url: string, expires_at: string}
     */
    public function storeFirstAccessInvite(Account $account, string $name, string $email): array
    {
        $plainToken = Str::random(48);
        $expiresAt = now()->addDays(7);

        $settings = $account->settings ?? [];
        $platform = is_array($settings['platform'] ?? null) ? $settings['platform'] : [];
        $platform['owner_invite'] = [
            'name' => $name,
            'email' => $email,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
        $settings['platform'] = $platform;
        $account->forceFill(['settings' => $settings])->save();

        $query = http_build_query([
            'token' => $plainToken,
            'email' => $email,
        ]);

        return [
            'url' => rtrim(config('app.url'), '/').'/primeiro-acesso?'.$query,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public static function findPendingOwnerInvite(string $email): ?Account
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return null;
        }

        return Account::query()
            ->where('settings->platform->owner_invite->email', $email)
            ->first();
    }

    /**
     * @return array{name: string, account_name: string}|null
     */
    public static function pendingInviteMeta(Account $account, string $plainToken): ?array
    {
        $invite = data_get($account->settings, 'platform.owner_invite');

        if (! is_array($invite)) {
            return null;
        }

        if (! self::inviteTokenMatches($invite, $plainToken)) {
            return null;
        }

        if (self::inviteExpired($invite)) {
            return null;
        }

        return [
            'name' => (string) ($invite['name'] ?? ''),
            'account_name' => $account->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $invite
     */
    public static function inviteTokenMatches(array $invite, string $plainToken): bool
    {
        $hash = $invite['token_hash'] ?? null;

        return is_string($hash) && hash_equals($hash, hash('sha256', $plainToken));
    }

    /**
     * @param  array<string, mixed>  $invite
     */
    public static function inviteExpired(array $invite): bool
    {
        $expiresAt = $invite['expires_at'] ?? null;

        if (! is_string($expiresAt) || $expiresAt === '') {
            return true;
        }

        return now()->greaterThan($expiresAt);
    }

    public function completeFirstAccess(Account $account, string $plainToken, string $email, string $password): User
    {
        return DB::transaction(function () use ($account, $plainToken, $email, $password): User {
            $account = Account::query()->lockForUpdate()->findOrFail($account->getKey());
            $invite = data_get($account->settings, 'platform.owner_invite');

            if (! is_array($invite) || strtolower((string) ($invite['email'] ?? '')) !== strtolower($email)) {
                abort(422, 'Convite inválido ou expirado.');
            }

            if (! self::inviteTokenMatches($invite, $plainToken) || self::inviteExpired($invite)) {
                abort(422, 'Convite inválido ou expirado.');
            }

            if (User::query()->where('email', $email)->exists()) {
                abort(422, 'Este e-mail já possui acesso.');
            }

            $user = $this->attachOwner(
                $account,
                (string) ($invite['name'] ?? $account->name),
                strtolower($email),
                $password
            );

            $settings = $account->settings ?? [];
            unset($settings['platform']['owner_invite']);
            if (isset($settings['platform']) && $settings['platform'] === []) {
                unset($settings['platform']);
            }
            $account->forceFill(['settings' => $settings === [] ? null : $settings])->save();

            return $user;
        });
    }
}
