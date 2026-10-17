<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\User;
use App\Models\WhatsAppChannel;
use App\Models\WhatsAppDispatch;
use App\Models\WhatsAppWebhookEvent;
use App\Services\WhatsApp\WhatsAppChannelEffects;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BusinessTestCase;

/**
 * O isolamento entre contas: membro da Account A não lê canal, disparo nem
 * evento de webhook da Account B — pelo escopo global com `CurrentTenant`
 * e pelo `account_id` que a criação herda dele.
 */
class WhatsAppTenancyTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_membro_da_conta_a_nao_le_canal_da_conta_b(): void
    {
        $accountA = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $accountA->getKey();
        $accountB = Account::factory()->create();

        $channelB = WhatsAppChannel::factory()->create(['account_id' => $accountB->getKey()]);

        resolve(CurrentTenant::class)->accountId = $accountA->getKey();

        $this->assertNull(WhatsAppChannel::query()->find($channelB->getKey()));
        $this->assertSame(0, WhatsAppChannel::query()->count());
    }

    public function test_membro_da_conta_a_nao_le_disparo_nem_evento_da_conta_b(): void
    {
        $accountA = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $accountA->getKey();
        $accountB = Account::factory()->create();

        $channelB = WhatsAppChannel::factory()->create(['account_id' => $accountB->getKey()]);
        $dispatchB = WhatsAppDispatch::factory()->create(['account_id' => $accountB->getKey()]);
        WhatsAppWebhookEvent::factory()->create([
            'account_id' => $accountB->getKey(),
            'whatsapp_channel_id' => $channelB->getKey(),
            'whatsapp_dispatch_id' => $dispatchB->getKey(),
        ]);

        resolve(CurrentTenant::class)->accountId = $accountA->getKey();

        $this->assertNull(WhatsAppDispatch::query()->find($dispatchB->getKey()));
        $this->assertSame(0, WhatsAppDispatch::query()->count());
        $this->assertSame(0, WhatsAppWebhookEvent::query()->count());
    }

    public function test_criacao_herda_o_account_id_do_tenant_e_nao_do_request(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $outraConta = Account::factory()->create();

        resolve(CurrentTenant::class)->accountId = $account->getKey();

        // `account_id` não é `Fillable`: a massa de atributos que tenta
        // plantar outra conta é descartada, e o valor herdado é o do tenant.
        $channel = WhatsAppChannel::query()->create([
            'account_id' => $outraConta->getKey(),
            'status' => WhatsAppChannel::STATUS_DISCONNECTED,
        ]);

        $this->assertSame($account->getKey(), $channel->account_id);

        $dispatch = WhatsAppDispatch::query()->create([
            'account_id' => $outraConta->getKey(),
            'client_id' => Client::factory()->create(['account_id' => $account->getKey()])->getKey(),
            'obligation' => WhatsAppDispatch::OBLIGATION_DECLARACOES_PGDAS,
            'artifact_type' => 'guia',
            'competence' => '2026-09',
            'idempotency_key' => 'tentativa',
        ]);

        $this->assertSame($account->getKey(), $dispatch->account_id);
    }

    public function test_unchanged_observation_advances_receipt_marker_without_replacing_the_credential(): void
    {
        $this->freezeTime();
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $channel = WhatsAppChannel::factory()->connected('unchanged-key')->create(['account_id' => $account->getKey()]);
        $resource = WhatsAppChannelEffects::resource($account->getKey());
        $id = DB::table('external_effect_journal')->insertGetId([
            'account_id' => $account->getKey(), 'actor_id' => 1,
            'operation_id' => (string) Str::uuid(),
            'resource_hash' => $resource, 'effect_key' => hash('sha256', 'unchanged-observation'),
            'step' => 'whatsapp.disconnect', 'state' => 'confirmed', 'receipt' => 'applied',
            'owner_token' => (string) Str::uuid(), 'version' => 3,
            'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $channel = $channel->fresh();
        $this->assertFalse($channel->isDirty());
        $credentialMarker = $channel->last_projected_credential_effect_id;

        $channel->save();

        $this->assertSame($id, $channel->fresh()->last_projected_effect_id);
        $this->assertSame($credentialMarker, $channel->fresh()->last_projected_credential_effect_id);
        $this->assertSame('connected', $channel->fresh()->status);
        $this->assertSame('unchanged-key', $channel->fresh()->instanceApiKey());
    }

    public function test_o_lookup_explicito_por_conta_retorna_so_o_canal_dela(): void
    {
        $accountA = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $accountA->getKey();
        $accountB = Account::factory()->create();

        $channelA = WhatsAppChannel::factory()->create(['account_id' => $accountA->getKey()]);
        WhatsAppChannel::factory()->create(['account_id' => $accountB->getKey()]);

        // Sem `CurrentTenant` (console/fila/webhook): `forAccount` responde
        // pela conta dita, não pelo que sobrou no singleton.
        $this->assertSame($channelA->getKey(), WhatsAppChannel::forAccount($accountA->getKey())?->getKey());
        $this->assertNotSame($channelA->getKey(), WhatsAppChannel::forAccount($accountB->getKey())?->getKey());

        // E com o tenant de B ativo, o lookup por A continua recusando —
        // a falha de um tenant é recusar, nunca devolver o canal alheio.
        resolve(CurrentTenant::class)->accountId = $accountB->getKey();
        $this->assertNull(WhatsAppChannel::forAccount($accountA->getKey()));
    }

    public function test_route_binding_do_canal_nao_vaza_entre_contas(): void
    {
        $accountA = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $accountA->getKey();
        $accountB = Account::factory()->create();
        $channelB = WhatsAppChannel::factory()->create(['account_id' => $accountB->getKey()]);

        $memberA = $this->memberOf($accountA, 'admin');

        // O binding implícito roda antes do middleware de tenant: com um
        // `CurrentTenant` limpo e ninguém autenticado o id de B também não
        // resolve — e o caso que importa é o membro autenticado de A, que
        // a asserção abaixo cobre.
        resolve(CurrentTenant::class)->accountId = null;

        $this->actingAs($memberA, 'web');
        $resolved = (new WhatsAppChannel)->resolveRouteBinding($channelB->getKey());
        $this->assertNull($resolved);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function memberOf(Account $account, string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        AccountUser::create([
            'account_id' => $account->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);

        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
