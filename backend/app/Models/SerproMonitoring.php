<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\SerproMonitoringFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A projeção sincronizada de **um cliente para uma obrigação**.
 *
 * `state` e `cause` são strings e não enums: o vocabulário da situação é do
 * painel de monitoramento e o da causa é do provedor, e nenhum dos dois é
 * fechado — uma causa nova do SERPRO tem de persistir sem deploy. O que o
 * modelo garante é o vínculo e a proveniência (`source_at`, o carimbo que o
 * serviço respondeu); o que a leitura garante é o resto.
 *
 * `account_id` e `client_id` ficam fora do `#[Fillable]` pelo mesmo motivo
 * das autorizações: o vínculo é a identidade da linha, e o writer a escreve
 * por `forceFill` com a conta explícita — nunca pelo `CurrentTenant`, que
 * no worker carrega o valor do job anterior.
 */
#[Fillable([
    'obligation',
    'state',
    'cause',
    'due_on',
    'fields',
    'periods',
    'messages',
    'source_at',
])]
class SerproMonitoring extends Model
{
    /** @use HasFactory<SerproMonitoringFactory> */
    use BelongsToAccount, HasFactory;

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'fields' => 'array',
            'periods' => 'array',
            'messages' => 'array',
            'source_at' => 'datetime',
        ];
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
