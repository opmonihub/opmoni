<?php

namespace App\Models;

use App\Support\LiteralSearch;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var array<int, string|null> */
    protected array $roleMemo = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $users) use ($term): void {
            $users
                ->where(fn (Builder $users): Builder => LiteralSearch::whereContains($users, 'name', $term))
                ->orWhere(fn (Builder $users): Builder => LiteralSearch::whereContains($users, 'email', $term));
        });
    }

    public function scopeWithAdminType(Builder $query, ?string $type): Builder
    {
        return match ($type) {
            'super' => $query->where('is_super_admin', true),
            'user' => $query->where('is_super_admin', false),
            default => $query,
        };
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    public function accountRole(Account|int $account): ?string
    {
        $id = (int) ($account instanceof Account ? $account->getKey() : $account);

        if (! array_key_exists($id, $this->roleMemo)) {
            $this->roleMemo[$id] = $this->accountLinks()->where('account_id', $id)->value('role');
        }

        return $this->roleMemo[$id];
    }

    public function accountLinks(): HasMany
    {
        return $this->hasMany(AccountUser::class);
    }

    public function currentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'current_account_id');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_user')->withPivot('account_id');
    }
}
