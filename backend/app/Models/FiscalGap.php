<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\FiscalSource;
use Database\Factories\FiscalGapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma posição do serviço de distribuição que chegou e não virou documento.
 *
 * A lacuna é estado, não evento: a linha fica enquanto ninguém recuperar a
 * posição, e `attempts` com `next_attempt_at` é o que impede que ela vire uma
 * máquina de consulta: uma posição que o fisco já disse que não tem não pode
 * custar uma consulta por execução para sempre, e a tentativa é contada de
 * consulta que **saiu** — adiar por falta de orçamento não conta.
 */
#[Fillable(['client_id', 'source', 'nsu', 'attempts', 'next_attempt_at'])]
class FiscalGap extends Model
{
    /** @use HasFactory<FiscalGapFactory> */
    use BelongsToAccount, HasFactory;

    protected function casts(): array
    {
        return [
            'source' => FiscalSource::class,
            'nsu' => 'integer',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
        ];
    }

    /**
     * As lacunas de um cliente e de uma fonte, com a dona da linha escrita na
     * consulta.
     *
     * O escopo global de conta é condicional e a conta corrente é um singleton
     * que o worker de fila nunca zera: a mesma execução pode chegar aqui com a
     * conta do job anterior. Por isso a conta entra no `where` e o escopo global
     * sai — quem explica de quem é a lacuna é o cliente da chamada, nunca o
     * resíduo do ambiente.
     */
    public function scopeForClientSource(Builder $query, Client $client, FiscalSource $source): Builder
    {
        return $query
            ->withoutGlobalScope('account')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->getKey())
            ->where('source', $source);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
