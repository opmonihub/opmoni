<?php

namespace App\Models;

use App\Support\LiteralSearch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'status', 'settings'])]
class Account extends Model
{
    /** @use HasFactory<Account> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Contato comercial/cobrança da plataforma — não é perfil da empresa no app.
     *
     * @return array<string, mixed>|null
     */
    public function platformBillingContact(): ?array
    {
        $contact = data_get($this->settings, 'platform.billing_contact');

        return is_array($contact) && $contact !== [] ? $contact : null;
    }

    /**
     * @return array{name: string, email: string}|null
     */
    public function pendingOwnerInvitePreview(): ?array
    {
        $invite = data_get($this->settings, 'platform.owner_invite');

        if (! is_array($invite)) {
            return null;
        }

        $expiresAt = $invite['expires_at'] ?? null;

        if (! is_string($expiresAt) || $expiresAt === '' || now()->greaterThan($expiresAt)) {
            return null;
        }

        $email = $invite['email'] ?? null;
        $name = $invite['name'] ?? null;

        if (! is_string($email) || $email === '' || ! is_string($name) || $name === '') {
            return null;
        }

        return ['name' => $name, 'email' => $email];
    }

    /**
     * Flags e preferências visíveis ao tenant (ex.: integração); sem dados internos.
     *
     * @return array<string, mixed>|null
     */
    public function settingsVisibleToTenant(): ?array
    {
        if (! is_array($this->settings)) {
            return null;
        }

        $settings = $this->settings;
        unset($settings['platform']);

        return $settings === [] ? null : $settings;
    }

    /**
     * @param  array<string, mixed>  $billingContact
     * @return array<string, mixed>|null
     */
    public static function settingsWithPlatformBillingContact(array $billingContact): ?array
    {
        if ($billingContact === []) {
            return null;
        }

        return ['platform' => ['billing_contact' => $billingContact]];
    }

    /**
     * Atualiza contato comercial preservando owner_invite e demais chaves.
     *
     * @param  array<string, mixed>  $billingContact
     * @return array<string, mixed>|null
     */
    public static function mergePlatformBillingContact(?array $settings, array $billingContact): ?array
    {
        $settings = is_array($settings) ? $settings : [];
        $platform = is_array($settings['platform'] ?? null) ? $settings['platform'] : [];

        if ($billingContact === []) {
            unset($platform['billing_contact']);
        } else {
            $platform['billing_contact'] = $billingContact;
        }

        if ($platform === []) {
            unset($settings['platform']);
        } else {
            $settings['platform'] = $platform;
        }

        return $settings === [] ? null : $settings;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $accounts) use ($term): void {
            LiteralSearch::whereContains($accounts, 'name', $term);

            if (ctype_digit($term)) {
                $accounts->orWhereKey((int) $term);
            }
        });
    }

    public function scopeWithStatus(Builder $query, ?string $status): Builder
    {
        return $status === null ? $query : $query->where('status', $status);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_user')
            ->withPivot(['role', 'inviter_id'])
            ->withTimestamps();
    }

    public function accountUsers(): HasMany
    {
        return $this->hasMany(AccountUser::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function monitorings(): HasMany
    {
        return $this->hasMany(SerproMonitoring::class);
    }

    /**
     * O e-CNPJ do escritório e o histórico do que ele teve.
     *
     * A relação traz todas as linhas, correntes e fora de vigência: quem pergunta
     * pelo certificado de hoje usa `AccountCertificate::currentFor()`, que
     * precisa do `account_id` explícito justamente porque roda fora do escopo
     * do tenant — na fila e no console o escopo global não filtra nada.
     */
    public function accountCertificates(): HasMany
    {
        return $this->hasMany(AccountCertificate::class);
    }

    public function processes(): HasMany
    {
        return $this->hasMany(Process::class);
    }

    public function processTemplates(): HasMany
    {
        return $this->hasMany(ProcessTemplate::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
