<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha de Rastreio por envio lógico de documento ao WhatsApp.
 *
 * **A idempotência é a chave, não o relógio.** `idempotency_key` é um hash
 * determinístico de conta + cliente + contato + obrigação + artefato +
 * competência + slip_number, com unique por conta: o segundo Disparo da
 * mesma guia lógica reencontra esta linha e devolve o resultado dela em
 * vez de mandar um segundo `send` ao wzap — em qualquer dia. O que muda
 * a chave é a guia mudar (`slip_number` novo, recálculo) ou o destino
 * mudar; a Ciência de reenvio gera uma chave única por confirmação.
 *
 * `failure_reason` é sempre texto seguro para a UI — nunca corpo de
 * resposta do gateway, apikey ou dado fiscal.
 */
#[Fillable([
    'client_id',
    'client_dispatch_contact_id',
    'obligation',
    'artifact_type',
    'competence',
    'slip_number',
    'idempotency_key',
    'message_id',
    'whatsapp_id',
    'status',
    'failure_reason',
    'dispatched_by_user_id',
    'dispatched_in_support',
    'support_access_log_id',
])]
class WhatsAppDispatch extends Model
{
    /** @use HasFactory<WhatsAppDispatch> */
    use BelongsToAccount, HasFactory;

    /**
     * O plural padrão do Eloquent quebraria em `whats_app_dispatches`; a
     * tabela é a do contrato, `whatsapp_dispatches`.
     */
    protected $table = 'whatsapp_dispatches';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    public const OBLIGATION_DECLARACOES_PGDAS = 'declaracoes/pgdas';

    protected function casts(): array
    {
        return [
            'dispatched_in_support' => 'boolean',
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

    /**
     * O destino escolhido no Enviar guias. Nullable: disparos do fluxo
     * legado (`client.phone`) e os anteriores à coluna não têm contato.
     * Sem FK de delete: a coluna sobrevive como id órfão quando o contato
     * é removido, e a relação devolve `null` — o Rastreio e o job leem
     * "contato removido" em vez de perder a referência.
     */
    public function dispatchContact(): BelongsTo
    {
        return $this->belongsTo(ClientDispatchContact::class, 'client_dispatch_contact_id');
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by_user_id');
    }

    public function supportAccessLog(): BelongsTo
    {
        return $this->belongsTo(SupportAccessLog::class);
    }

    /**
     * A chave determinística do envio lógico: mesma conta + cliente +
     * contato + obrigação + artefato + competência + slip_number produzem
     * a mesma chave — e a unique do banco faz do segundo clique um
     * reencontro, não um segundo envio. `null` no destino é o fluxo
     * legado sem contato (`client.phone`), e `null` no slip é a guia
     * gerada por `declared_at` sem número sincronizado.
     *
     * `suffix` é a saída da Ciência de reenvio: um valor único por
     * confirmação gera uma linha nova da mesma guia, sem afrouxar a
     * regra para os próximos POSTs.
     */
    public static function idempotencyKey(
        int $accountId,
        int $clientId,
        string $obligation,
        string $artifactType,
        string $competence,
        ?int $contactId = null,
        ?string $slipNumber = null,
        ?string $suffix = null,
    ): string {
        return hash('sha256', implode('|', [
            $accountId,
            $clientId,
            $contactId ?? 0,
            $obligation,
            $artifactType,
            $competence,
            $slipNumber ?? '',
            $suffix ?? '',
        ]));
    }
}
