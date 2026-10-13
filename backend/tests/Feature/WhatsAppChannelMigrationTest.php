<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\WhatsAppChannel;
use App\Models\WhatsAppDispatch;
use App\Models\WhatsAppWebhookEvent;
use App\Tenant\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\BusinessTestCase;

/**
 * As três tabelas novas e as restrições que fazem o Disparo ser idempotente
 * e o canal ser 1:1 com a conta — tudo que o banco garante antes de
 * qualquer regra de aplicação.
 */
class WhatsAppChannelMigrationTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_as_tres_tabelas_whatsapp_existem_com_colunas_de_tenancy(): void
    {
        foreach (['whatsapp_channels', 'whatsapp_dispatches', 'whatsapp_webhook_events'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "tabela {$table} ausente");
            $this->assertTrue(Schema::hasColumn($table, 'account_id'), "{$table}.account_id ausente");
        }

        $this->assertTrue(Schema::hasColumns('whatsapp_channels', [
            'wzap_instance_id', 'instance_api_key_encrypted', 'status',
            'pairing_started_at', 'connected_at', 'last_error',
        ]));

        $this->assertTrue(Schema::hasColumns('whatsapp_dispatches', [
            'client_id', 'obligation', 'artifact_type', 'competence',
            'idempotency_key', 'message_id', 'status', 'failure_reason',
            'dispatched_by_user_id', 'dispatched_in_support', 'support_access_log_id',
        ]));

        $this->assertTrue(Schema::hasColumns('whatsapp_webhook_events', [
            'whatsapp_channel_id', 'event_id', 'whatsapp_dispatch_id',
        ]));
    }

    public function test_a_conta_tem_um_so_canal(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();

        $channelA = WhatsAppChannel::factory()->create(['account_id' => $account->getKey()]);

        $this->expectException(QueryException::class);

        // Segundo canal na mesma conta: a unique de `account_id` é a
        // garantia física de "um número por escritório".
        WhatsAppChannel::factory()->create(['account_id' => $account->getKey()]);
    }

    public function test_a_chave_de_idempotencia_e_unica_por_conta(): void
    {
        $accountA = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $accountA->getKey();
        $accountB = Account::factory()->create();
        $key = hash('sha256', 'mesmo-envio-logico');

        WhatsAppDispatch::factory()->create([
            'account_id' => $accountA->getKey(),
            'idempotency_key' => $key,
        ]);

        // A mesma chave em outra conta é outro envio: a unique é por conta,
        // não global — duas contas podem disparar o mesmo documento.
        WhatsAppDispatch::factory()->create([
            'account_id' => $accountB->getKey(),
            'idempotency_key' => $key,
        ]);

        $this->expectException(QueryException::class);

        // O segundo clique na mesma conta e na mesma janela: o banco recusa
        // o segundo registro — o serviço reencontra a linha existente.
        WhatsAppDispatch::factory()->create([
            'account_id' => $accountA->getKey(),
            'idempotency_key' => $key,
        ]);
    }

    public function test_evento_de_webhook_e_unico_por_canal(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $channel = WhatsAppChannel::factory()->create(['account_id' => $account->getKey()]);

        WhatsAppWebhookEvent::factory()->create([
            'account_id' => $account->getKey(),
            'whatsapp_channel_id' => $channel->getKey(),
            'event_id' => 'evt_1',
        ]);

        $this->expectException(QueryException::class);

        WhatsAppWebhookEvent::factory()->create([
            'account_id' => $account->getKey(),
            'whatsapp_channel_id' => $channel->getKey(),
            'event_id' => 'evt_1',
        ]);
    }

    public function test_remover_a_conta_remove_canal_e_disparos(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $channel = WhatsAppChannel::factory()->create(['account_id' => $account->getKey()]);
        $dispatch = WhatsAppDispatch::factory()->create(['account_id' => $account->getKey()]);
        WhatsAppWebhookEvent::factory()->create([
            'account_id' => $account->getKey(),
            'whatsapp_channel_id' => $channel->getKey(),
        ]);

        DB::table('accounts')->where('id', $account->getKey())->delete();

        $this->assertSame(0, DB::table('whatsapp_channels')->count());
        $this->assertSame(0, DB::table('whatsapp_dispatches')->count());
        $this->assertSame(0, DB::table('whatsapp_webhook_events')->count());
    }
}
