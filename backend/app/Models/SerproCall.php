<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\SerproFailure;
use Database\Factories\SerproCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma chamada ao Integra Contador, para reconciliação de custo e suporte.
 *
 * O que aqui mora é o que se lê no relatório do provedor: serviço, versão,
 * caminho, a tag de 32 caracteres, o identificador de resposta, o código e a
 * duração. O payload não tem coluna — `dados` carrega o documento do cliente
 * dentro do envelope, e gravá-lo criaria uma segunda cópia do que o banco já
 * guarda por outro caminho.
 *
 * `status` é um `SerproFailure`: a taxonomia que o `SerproClient` produz é a
 * mesma que a leitura precisa para separar "sucesso" de "limite" de
 * "indeterminado" sem reclassificar a resposta.
 */
#[Fillable([
    'id_sistema',
    'id_servico',
    'version',
    'path',
    'billable',
    'status',
    'provider_code',
    'response_id',
    'request_tag',
    'messages',
    'duration_ms',
])]
class SerproCall extends Model
{
    /** @use HasFactory<SerproCallFactory> */
    use BelongsToAccount, HasFactory;

    protected function casts(): array
    {
        return [
            'billable' => 'boolean',
            'status' => SerproFailure::class,
            'messages' => 'array',
            'duration_ms' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SerproSyncRun::class, 'run_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
