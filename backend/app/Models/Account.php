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
