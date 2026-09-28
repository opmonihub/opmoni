<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\ClientPersonType;
use App\Enums\ClientStatus;
use App\Enums\DeadlineStatus;
use App\Enums\TaxRegime;
use App\Services\BrazilianTaxId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'person_type',
    'tax_id',
    'name',
    'trade_name',
    'status',
    'tax_regime',
    'registration_status',
    'registration_status_date',
    'opened_at',
    'company_size',
    'legal_nature',
    'primary_activity_code',
    'primary_activity_description',
    'street_type',
    'street',
    'address_number',
    'address_complement',
    'district',
    'postal_code',
    'city',
    'state',
    'email',
    'phone',
    'source_updated_at',
    'looked_up_at',
])]
class Client extends Model
{
    /** @use HasFactory<Client> */
    use BelongsToAccount, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'person_type' => ClientPersonType::class,
            'status' => ClientStatus::class,
            'tax_regime' => TaxRegime::class,
            'registration_status_date' => 'date',
            'opened_at' => 'date',
            'source_updated_at' => 'datetime',
            'looked_up_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function certificateHistory(): HasMany
    {
        return $this->hasMany(ClientCertificate::class)->latest('id');
    }

    public function currentCertificate(): HasOne
    {
        return $this->hasOne(ClientCertificate::class)
            ->whereNull('replaced_at')
            ->whereNull('removed_at')
            ->latestOfMany();
    }

    public function ecacPowerOfAttorney(): HasOne
    {
        return $this->hasOne(ClientEcacPowerOfAttorney::class);
    }

    /**
     * As posições que a captura gravou como lacuna e que ninguém recuperou
     * ainda. A relação é simples e sem filtro: quem decide o que é lacuna devida
     * é a reconciliação, e quem decide de quem é a linha é o cliente da
     * chamada — o escopo de conta é condicional e a conta corrente é um
     * singleton que o worker de fila nunca zera, então quem chama em console
     * tira o escopo na consulta, como em `scopeForClientSource`.
     */
    public function fiscalGaps(): HasMany
    {
        return $this->hasMany(FiscalGap::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'client_tag')
            ->withPivot('account_id')
            ->orderBy('tags.name');
    }

    public function processes(): HasMany
    {
        return $this->hasMany(Process::class);
    }

    /**
     * A busca da lista de clientes, e o `tax_id` só entra como documento quando
     * o termo **tem** a forma de um.
     *
     * O que mudou com o CNPJ alfanumérico: `normalize()` deixou de ser um
     * `preg_replace('/\D+/')` e passou a devolver os dígitos **e** as letras em
     * maiúsculo. `normalize('joão silva')` é `JOSILVA`, e um `tax_id LIKE
     * '%JOSILVA%'` em uma busca por nome é uma cláusula que não casa com nada,
     * não usa índice, e é exatamente a pergunta que um otimizador de consulta
     * trata como a pior forma de "varredura disfarçada".
     *
     * A guarda de **onze** caracteres é o comprimento do CNPJ sem os dois
     * dígitos verificadores, e é o piso abaixo do qual nenhuma busca por
     * documento é útil: um termo de dez caracteres ou menos é nome, e o nome já
     * tem as duas primeiras cláusulas. Um termo de onze ou mais que não for
     * documento ainda entra no `LIKE` — nesse caso o usuário digitou uma
     * sequência longa que só pode ser um documento parcial, e um `LIKE` sem
     * índice sobre a carteira inteira custa o mesmo que já custava quando a
     * busca era só numérica.
     *
     * Os 14 completos (CNPJ) e 11 completos (CPF) continuam caindo aqui, e é o
     * que faz a busca por documento continuar funcionando exatamente como antes.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $query, string $term): Builder {
            $normalizedTaxId = resolve(BrazilianTaxId::class)->normalize($term);
            $podeSerDocumento = strlen($normalizedTaxId) >= 11;

            return $query->where(function (Builder $query) use ($term, $normalizedTaxId, $podeSerDocumento): Builder {
                $query
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('trade_name', 'like', "%{$term}%");

                if ($podeSerDocumento) {
                    $query->orWhere('tax_id', 'like', "%{$normalizedTaxId}%");
                }

                return $query;
            });
        });
    }

    public function scopeWithStatus(Builder $query, string|array|null $status): Builder
    {
        $statuses = $this->stringList($status);

        return $statuses === [] ? $query : $query->whereIn('status', $statuses);
    }

    public function scopeWithTaxRegime(Builder $query, string|array|null $regime): Builder
    {
        $regimes = $this->stringList($regime);

        return $regimes === [] ? $query : $query->whereIn('tax_regime', $regimes);
    }

    public function scopeWithTag(Builder $query, int|string|array|null $tagId): Builder
    {
        $ids = $this->idList($tagId);

        return $ids === [] ? $query : $query->whereHas(
            'tags',
            fn (Builder $tags): Builder => $tags->whereKey($ids),
        );
    }

    public function scopeWithCertificateStatus(Builder $query, string|array|null $status): Builder
    {
        return $this->whereAnyOf($query, $this->stringList($status), function (Builder $query, string $status): void {
            $this->applyDocumentStatus($query, 'currentCertificate', 'valid_until', $status);
        });
    }

    /**
     * Cliente capturável: certificado atual dentro da validade e com senha
     * guardada. É o espelho de consulta do `certificateIsUnusable()` do
     * serviço de captura, com uma diferença que é propósito e não omissão: a
     * senha indecifrável (APP_KEY rotacionado) não é distinguível em SQL, e
     * continua sendo guarda do serviço — o único que pode abrir a coluna.
     *
     * A validade é comparada por instante, não por dia como o
     * `applyDocumentStatus` compara: o serviço refuta com `isPast()`, e um
     * certificado que venceu hoje às 10h já não é capturável às 11h.
     */
    public function scopeCapturable(Builder $query): Builder
    {
        return $query->whereHas('currentCertificate', fn (Builder $certificates): Builder => $certificates
            ->whereNotNull('password_encrypted')
            ->where('valid_until', '>=', now()));
    }

    public function scopeWithPoaStatus(Builder $query, string|array|null $status): Builder
    {
        return $this->whereAnyOf($query, $this->stringList($status), function (Builder $query, string $status): void {
            $this->applyDocumentStatus($query, 'ecacPowerOfAttorney', 'expires_at', $status);
        });
    }

    public function scopeOrderByCurrentCertificate(Builder $query, string $direction): Builder
    {
        return $this->orderByDateSubquery($query, ClientCertificate::query()
            ->select('valid_until')
            ->whereColumn('client_certificates.client_id', 'clients.id')
            ->whereNull('client_certificates.replaced_at')
            ->whereNull('client_certificates.removed_at')
            ->orderByDesc('client_certificates.id')
            ->limit(1), $direction);
    }

    public function scopeOrderByPowerOfAttorney(Builder $query, string $direction): Builder
    {
        return $this->orderByDateSubquery($query, ClientEcacPowerOfAttorney::query()
            ->select('expires_at')
            ->whereColumn('client_ecac_powers_of_attorney.client_id', 'clients.id')
            ->orderByDesc('client_ecac_powers_of_attorney.id')
            ->limit(1), $direction);
    }

    public function scopeWithDeadlineStatus(Builder $query, string|array|null $status): Builder
    {
        return $this->whereAnyOf($query, $this->stringList($status), function (Builder $query, string $status): void {
            $this->applyCombinedDeadlineStatus($query, $status);
        });
    }

    private function applyCombinedDeadlineStatus(Builder $query, string $status): void
    {
        $today = now()->startOfDay();
        $limit = $today->copy()->addDays(30)->endOfDay();
        // Day-precision boundaries mirror DeadlineState (startOfDay today, +30d): whereDate/whereBetween/where(>, endOfDay) match expired/expiring/valid.
        $column = fn (Builder $relation, string $name): Builder => match ($status) {
            DeadlineStatus::Expired->value => $relation->whereDate($name, '<', $today),
            DeadlineStatus::Expiring->value => $relation->whereBetween($name, [$today, $limit]),
            DeadlineStatus::Valid->value => $relation->where($name, '>', $limit),
            default => $relation,
        };

        if ($status === DeadlineStatus::Missing->value) {
            $query->where(fn (Builder $query): Builder => $query
                ->whereDoesntHave('currentCertificate')
                ->orWhereDoesntHave('ecacPowerOfAttorney'));

            return;
        }

        $query->where(fn (Builder $query): Builder => $query
            ->whereHas('currentCertificate', fn (Builder $relation): Builder => $column($relation, 'valid_until'))
            ->orWhereHas('ecacPowerOfAttorney', fn (Builder $relation): Builder => $column($relation, 'expires_at')));
    }

    /**
     * Values inside one column are a union. Callers AND this group with the other columns.
     *
     * @param  list<string>  $values
     * @param  callable(Builder, string): void  $apply
     */
    private function whereAnyOf(Builder $query, array $values, callable $apply): Builder
    {
        if ($values === []) {
            return $query;
        }

        if (count($values) === 1) {
            $apply($query, $values[0]);

            return $query;
        }

        return $query->where(function (Builder $query) use ($values, $apply): void {
            foreach ($values as $index => $value) {
                $query->{$index === 0 ? 'where' : 'orWhere'}(
                    function (Builder $query) use ($apply, $value): void {
                        $apply($query, $value);
                    }
                );
            }
        });
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $items = is_array($value) ? $value : [$value];
        $strings = [];

        foreach ($items as $item) {
            if (! is_string($item) || $item === '' || in_array($item, $strings, true)) {
                continue;
            }

            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @return list<int>
     */
    private function idList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === 0) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];
        $ids = [];

        foreach ($items as $item) {
            if (! is_numeric($item) || (int) $item < 1) {
                continue;
            }

            $id = (int) $item;

            if (! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function scopeWithPortfolioView(Builder $query, ?string $view): Builder
    {
        if ($view === null || $view === '' || ! preg_match('/^(certificate|poa)_(missing|valid|expiring|expired)$/', $view, $matches)) {
            return $query;
        }

        $relation = $matches[1] === 'certificate' ? 'currentCertificate' : 'ecacPowerOfAttorney';
        $column = $matches[1] === 'certificate' ? 'valid_until' : 'expires_at';

        return $this->applyDocumentStatus($query, $relation, $column, $matches[2]);
    }

    private function applyDocumentStatus(Builder $query, string $relation, string $column, string $status): Builder
    {
        if (DeadlineStatus::tryFrom($status) === null) {
            return $query;
        }

        if ($status === DeadlineStatus::Missing->value) {
            return $query->whereDoesntHave($relation);
        }

        $today = now()->startOfDay();
        $limit = $today->copy()->addDays(30)->endOfDay();

        return $query->whereHas($relation, fn (Builder $documents): Builder => match ($status) {
            DeadlineStatus::Expired->value => $documents->whereDate($column, '<', $today),
            DeadlineStatus::Expiring->value => $documents->whereBetween($column, [$today, $limit]),
            DeadlineStatus::Valid->value => $documents->where($column, '>', $limit),
            default => $documents,
        });
    }

    private function orderByDateSubquery(Builder $query, Builder $date, string $direction): Builder
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $sql = $date->toSql();
        $bindings = $date->getBindings();

        return $query
            ->orderByRaw('('.$sql.') is null', $bindings)
            ->orderByRaw('('.$sql.') '.$direction, $bindings)
            ->orderBy('name');
    }
}
