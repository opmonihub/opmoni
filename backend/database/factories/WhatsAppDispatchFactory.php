<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Client;
use App\Models\User;
use App\Models\WhatsAppDispatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppDispatch>
 */
class WhatsAppDispatchFactory extends Factory
{
    protected $model = WhatsAppDispatch::class;

    /**
     * O par `account_id`/`client_id` nasce coerente quando só a conta é
     * dita — mesmo motivo de `SerproManualSearchFactory`: o disparo é de um
     * cliente daquela conta.
     *
     * `dispatched_by_user_id` fica `null` por padrão em vez de fabricar um
     * usuário aleatório: o membro que disparou é contexto do teste, e um
     * usuário solto de outra conta seria o dado errado.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'client_id' => fn (array $attributes): mixed => Client::factory()
                ->company()
                ->create(['account_id' => $attributes['account_id']])
                ->getKey(),
            'obligation' => WhatsAppDispatch::OBLIGATION_DECLARACOES_PGDAS,
            'artifact_type' => 'guia',
            'competence' => now()->format('Y-m'),
            'idempotency_key' => fake()->unique()->lexify(str_repeat('?', 64)),
            'status' => WhatsAppDispatch::STATUS_QUEUED,
            'dispatched_by_user_id' => null,
        ];
    }

    /**
     * A chave real do envio lógico, derivada dos campos da linha.
     *
     * Não pode ser `state()`: lá dentro os atributos ainda não foram
     * expandidos — `account_id` é a factory e `client_id` é o Closure — e
     * a chave sairia calculada sobre placeholders. `afterMaking` roda com
     * os ids reais já resolvidos e, em `create()`, antes do insert: a
     * linha nasce com a chave certa, sem segundo `save`.
     */
    public function withLogicalKey(): static
    {
        return $this->afterMaking(function (WhatsAppDispatch $dispatch): void {
            $dispatch->idempotency_key = WhatsAppDispatch::idempotencyKey(
                (int) $dispatch->account_id,
                (int) $dispatch->client_id,
                (string) $dispatch->obligation,
                (string) $dispatch->artifact_type,
                (string) $dispatch->competence,
                $dispatch->client_dispatch_contact_id === null
                    ? null
                    : (int) $dispatch->client_dispatch_contact_id,
                $dispatch->slip_number === null ? null : (string) $dispatch->slip_number,
            );
        });
    }

    /**
     * Disparo feito por suporte em conta alheia: ator explícito e flag
     * marcada; a trilha de auditoria é ligação do fluxo, não da factory.
     */
    public function inSupport(?User $actor = null): static
    {
        return $this->state(fn (): array => [
            'dispatched_by_user_id' => $actor?->getKey() ?? User::factory(),
            'dispatched_in_support' => true,
        ]);
    }

    /**
     * Envio confirmado pelo gateway: `message_id` e status avançado.
     */
    public function sent(?string $messageId = null): static
    {
        return $this->state(fn (): array => [
            'status' => WhatsAppDispatch::STATUS_SENT,
            'message_id' => $messageId ?? 'wamid.'.fake()->lexify('????????'),
        ]);
    }

    /**
     * Falha terminal com motivo seguro para a UI.
     */
    public function failed(string $reason = 'O gateway recusou o envio. Tente novamente.'): static
    {
        return $this->state(fn (): array => [
            'status' => WhatsAppDispatch::STATUS_FAILED,
            'failure_reason' => $reason,
        ]);
    }
}
