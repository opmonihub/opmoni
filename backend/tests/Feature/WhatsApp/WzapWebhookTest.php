<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Account;
use App\Models\Client;
use App\Models\WhatsAppChannel;
use App\Models\WhatsAppDispatch;
use App\Models\WhatsAppWebhookEvent;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\BusinessTestCase;

/**
 * O webhook de recibos do wzap: rota pública (`POST /api/v1/webhooks/wzap`)
 * autenticada pelo header `apikey` da instância — a mesma chave que o wzap
 * põe em cada entrega e que o canal guarda cifrada. O envelope é o do
 * barramento do wzap (`event_id`, `type`, `payload`) e cada `event_id`
 * processa uma única vez por canal: a entrega é at-least-once e o dedupe
 * é nosso — com uma exceção, o evento de rastreio que chegou antes da
 * linha do disparo existir (o wzap pode emitir o `message.status` antes
 * do job gravar o `message_id` do 202): ele responde 503 e não é
 * deduplicado, porque o gateway retenta entregas não-2xx com backoff.
 *
 * Nenhum teste fala com o gateway: o webhook é o lado que **recebe**.
 */
class WzapWebhookTest extends BusinessTestCase
{
    use RefreshDatabase;

    private const API_KEY = 'chave-instancia-muito-secreta';

    private Account $account;

    private Client $client;

    private WhatsAppChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        resolve(CurrentTenant::class)->accountId = null;

        $this->account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $this->account->getKey();
        $this->client = Client::factory()->company()->create([
            'account_id' => $this->account->getKey(),
            'phone' => '5511987654321',
        ]);
        $this->channel = WhatsAppChannel::factory()->connected(self::API_KEY)->create([
            'account_id' => $this->account->getKey(),
            'wzap_instance_id' => 'inst-webhook',
        ]);
    }

    protected function tearDown(): void
    {
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public function test_recibo_delivered_avanca_rastreio_do_disparo(): void
    {
        $dispatch = $this->disparoEnviado('wzap-msg-1', 'wamid.ABC');

        $this->postWebhook([
            'message_ids' => ['wamid.ABC'],
            'status' => 'delivered',
            'chat_jid' => '5511987654321@s.whatsapp.net',
            'timestamp' => now()->toIso8601String(),
        ])->assertOk();

        $this->assertSame(WhatsAppDispatch::STATUS_DELIVERED, $dispatch->fresh()->status);

        $evento = WhatsAppWebhookEvent::query()->sole();
        $this->assertSame($this->account->getKey(), $evento->account_id);
        $this->assertSame($this->channel->getKey(), $evento->whatsapp_channel_id);
        $this->assertSame($dispatch->getKey(), $evento->whatsapp_dispatch_id);
    }

    public function test_recibo_read_e_played_avancam_para_read(): void
    {
        $lido = $this->disparoEnviado('wzap-msg-2', 'wamid.LIDO');
        $tocado = $this->disparoEnviado('wzap-msg-3', 'wamid.TOCADO');

        $this->postWebhook([
            'message_ids' => ['wamid.LIDO', 'wamid.TOCADO'],
            'status' => 'read',
        ])->assertOk();
        $this->postWebhook([
            'message_ids' => ['wamid.TOCADO'],
            'status' => 'played',
        ])->assertOk();

        $this->assertSame(WhatsAppDispatch::STATUS_READ, $lido->fresh()->status);
        $this->assertSame(WhatsAppDispatch::STATUS_READ, $tocado->fresh()->status);
    }

    public function test_evento_duplicado_responde_200_sem_alterar_o_rastreio(): void
    {
        $dispatch = $this->disparoEnviado('wzap-msg-4', 'wamid.DUP');
        $eventId = fake()->uuid();

        $this->postWebhook([
            'message_ids' => ['wamid.DUP'],
            'status' => 'delivered',
        ], $eventId)->assertOk();

        // A reentrega traz o mesmo event_id com payload mais adiantado:
        // o dedupe vence, o Rastreio não se move de novo.
        $this->postWebhook([
            'message_ids' => ['wamid.DUP'],
            'status' => 'read',
        ], $eventId)->assertOk();

        $this->assertSame(WhatsAppDispatch::STATUS_DELIVERED, $dispatch->fresh()->status);
        $this->assertSame(1, WhatsAppWebhookEvent::query()->count());
    }

    public function test_falha_ao_atualizar_disparo_nao_consome_evento_e_permite_reentrega(): void
    {
        $dispatch = $this->disparoEnviado('atomic-message', 'wamid.ATOMIC');
        $falhar = true;
        WhatsAppDispatch::updating(function (WhatsAppDispatch $model) use (&$falhar, $dispatch): void {
            if ($model->getKey() === $dispatch->getKey() && $falhar) {
                throw new \RuntimeException('Falha de persistência simulada.');
            }
        });
        $eventId = fake()->uuid();
        $payload = ['message_ids' => ['wamid.ATOMIC'], 'status' => 'delivered'];

        $this->postWebhook($payload, $eventId)->assertStatus(503);
        $this->assertSame(0, WhatsAppWebhookEvent::count());
        $this->assertSame(WhatsAppDispatch::STATUS_SENT, $dispatch->fresh()->status);

        $falhar = false;
        $this->postWebhook($payload, $eventId)->assertOk();
        $this->assertSame(WhatsAppDispatch::STATUS_DELIVERED, $dispatch->fresh()->status);
        $this->assertSame($dispatch->getKey(), WhatsAppWebhookEvent::query()->sole()->whatsapp_dispatch_id);
    }

    public function test_falha_ao_vincular_evento_reverte_tambem_o_status_do_disparo(): void
    {
        $dispatch = $this->disparoEnviado('atomic-link', 'wamid.LINK');
        $falhar = true;
        WhatsAppWebhookEvent::updating(function () use (&$falhar): void {
            if ($falhar) {
                throw new \RuntimeException('Falha de vínculo simulada.');
            }
        });
        $eventId = fake()->uuid();
        $payload = ['message_ids' => ['wamid.LINK'], 'status' => 'delivered'];

        $this->postWebhook($payload, $eventId)->assertStatus(503);
        $this->assertSame(WhatsAppDispatch::STATUS_SENT, $dispatch->fresh()->status);
        $this->assertSame(0, WhatsAppWebhookEvent::count());

        $falhar = false;
        $this->postWebhook($payload, $eventId)->assertOk();
        $this->assertSame(WhatsAppDispatch::STATUS_DELIVERED, $dispatch->fresh()->status);
        $this->assertSame(1, WhatsAppWebhookEvent::count());
    }

    public function test_apikey_invalida_responde_401_sem_tocar_em_nada(): void
    {
        $dispatch = $this->disparoEnviado('wzap-msg-5', 'wamid.SEMCHAVE');

        $this->postWebhook([
            'message_ids' => ['wamid.SEMCHAVE'],
            'status' => 'read',
        ], apikey: 'chave-de-outra-instancia')->assertUnauthorized();

        $this->assertSame(WhatsAppDispatch::STATUS_SENT, $dispatch->fresh()->status);
        $this->assertSame(0, WhatsAppWebhookEvent::query()->count());
    }

    public function test_apikey_ausente_responde_401(): void
    {
        $this->postJson('/api/v1/webhooks/wzap', $this->envelope('receipt', [
            'message_ids' => ['wamid.X'],
            'status' => 'delivered',
        ]))->assertUnauthorized();
    }

    public function test_status_sent_do_wzap_grava_whatsapp_id_na_linha(): void
    {
        // O job grava o message_id interno do wzap (UUID da fila deles);
        // o wamid que os recibos carregam só chega neste evento.
        $dispatch = WhatsAppDispatch::factory()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'status' => WhatsAppDispatch::STATUS_QUEUED,
            'message_id' => 'wzap-msg-6',
        ]);

        $this->postWebhook([
            'message_id' => 'wzap-msg-6',
            'status' => 'sent',
            'whatsapp_id' => 'wamid.NOVO',
        ], type: 'message.status')->assertOk();

        $dispatch->refresh();
        $this->assertSame(WhatsAppDispatch::STATUS_SENT, $dispatch->status);
        $this->assertSame('wamid.NOVO', $dispatch->whatsapp_id);
    }

    public function test_status_sent_antes_do_job_gravar_message_id_pede_reentrega(): void
    {
        // A corrida real: o wzap emite `message.status` assim que o envio
        // sai, e o evento pode chegar antes do 202 do sendMedia virar
        // `message_id` na linha — o disparo ainda está `queued` e sem id.
        // Sem match não há dedupe: a resposta é não-2xx e o worker do
        // wzap retenta a entrega com backoff até o job alcançar.
        $dispatch = WhatsAppDispatch::factory()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'status' => WhatsAppDispatch::STATUS_QUEUED,
            'message_id' => null,
            'whatsapp_id' => null,
        ]);

        $this->postWebhook([
            'message_id' => 'wzap-msg-corrida',
            'status' => 'sent',
            'whatsapp_id' => 'wamid.CORRIDA',
        ], type: 'message.status')->assertStatus(503);

        $this->assertSame(WhatsAppDispatch::STATUS_QUEUED, $dispatch->fresh()->status);
        $this->assertSame(0, WhatsAppWebhookEvent::query()->count());
    }

    public function test_reentrega_de_status_sent_apos_o_job_avanca_e_amarra_o_wamid(): void
    {
        // A retentativa do gateway, agora com o `message_id` gravado pelo
        // job: mesmo `event_id`, o disparo avança e o wamid é amarrado.
        $dispatch = WhatsAppDispatch::factory()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'status' => WhatsAppDispatch::STATUS_QUEUED,
            'message_id' => null,
            'whatsapp_id' => null,
        ]);

        $eventId = fake()->uuid();
        $payload = [
            'message_id' => 'wzap-msg-corrida',
            'status' => 'sent',
            'whatsapp_id' => 'wamid.CORRIDA',
        ];

        $this->postWebhook($payload, $eventId, 'message.status')->assertStatus(503);

        // O job correu entre uma tentativa e outra.
        $dispatch->forceFill(['message_id' => 'wzap-msg-corrida'])->save();

        $this->postWebhook($payload, $eventId, 'message.status')->assertOk();

        $dispatch->refresh();
        $this->assertSame(WhatsAppDispatch::STATUS_SENT, $dispatch->status);
        $this->assertSame('wamid.CORRIDA', $dispatch->whatsapp_id);
        $this->assertSame($dispatch->getKey(), WhatsAppWebhookEvent::query()->sole()->whatsapp_dispatch_id);
    }

    public function test_recibo_que_chega_antes_do_status_casa_pelo_message_id(): void
    {
        // Outra ponta da janela: o recibo agrega wamids, mas o
        // `message.status` que traz o wamid ainda não chegou. O recibo
        // casa pelo `message_id` interno e amarra o wamid ele mesmo —
        // o Rastreio não fica preso em `sent`.
        $dispatch = WhatsAppDispatch::factory()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'status' => WhatsAppDispatch::STATUS_SENT,
            'message_id' => 'wamid.PRECOCOZINHO',
            'whatsapp_id' => null,
        ]);

        $this->postWebhook([
            'message_ids' => ['wamid.PRECOCOZINHO'],
            'status' => 'delivered',
        ])->assertOk();

        $dispatch->refresh();
        $this->assertSame(WhatsAppDispatch::STATUS_DELIVERED, $dispatch->status);
        $this->assertSame('wamid.PRECOCOZINHO', $dispatch->whatsapp_id);
    }

    public function test_recibo_sem_disparo_correspondente_pede_reentrega(): void
    {
        $this->postWebhook([
            'message_ids' => ['wamid.NINGUEM'],
            'status' => 'delivered',
        ])->assertStatus(503);

        $this->assertSame(0, WhatsAppWebhookEvent::query()->count());
    }

    public function test_mixed_receipt_retries_pending_ids_without_regressing_known_dispatch(): void
    {
        $known = $this->disparoEnviado('known-message', 'wamid.KNOWN');
        $eventId = fake()->uuid();
        $payload = ['message_ids' => ['wamid.KNOWN', 'wamid.PENDING'], 'status' => 'delivered'];

        $this->postWebhook($payload, $eventId)->assertStatus(503);
        $this->assertSame(WhatsAppDispatch::STATUS_DELIVERED, $known->fresh()->status);
        $this->assertSame(0, WhatsAppWebhookEvent::count());
        $this->postWebhook(['message_ids' => ['wamid.KNOWN'], 'status' => 'read'])->assertOk();
        $pending = $this->disparoEnviado('pending-message', 'wamid.PENDING');

        $this->postWebhook($payload, $eventId)->assertOk();
        $this->assertSame(WhatsAppDispatch::STATUS_READ, $known->fresh()->status);
        $this->assertSame(WhatsAppDispatch::STATUS_DELIVERED, $pending->fresh()->status);
        $this->assertSame(1, WhatsAppWebhookEvent::where('event_id', $eventId)->count());
        $this->postWebhook([...$payload, 'status' => 'read'], $eventId)->assertOk();
        $this->assertSame(WhatsAppDispatch::STATUS_DELIVERED, $pending->fresh()->status);
    }

    public function test_status_failed_marca_falha_com_motivo_sanitizado(): void
    {
        $dispatch = $this->disparoEnviado('wzap-msg-7', 'wamid.FALHOU');

        $this->postWebhook([
            'message_id' => 'wzap-msg-7',
            'status' => 'failed',
            'error' => 'envio recusado para apikey '.self::API_KEY,
        ], type: 'message.status')->assertOk();

        $dispatch->refresh();
        $this->assertSame(WhatsAppDispatch::STATUS_FAILED, $dispatch->status);
        $this->assertNotNull($dispatch->failure_reason);
        $this->assertStringNotContainsString(self::API_KEY, $dispatch->failure_reason);
        $this->assertStringContainsString('[redigido]', $dispatch->failure_reason);
    }

    public function test_falha_nao_rebaixa_recibo_confirmado(): void
    {
        $dispatch = $this->disparoEnviado('wzap-msg-8', 'wamid.ENTREGUE');
        $dispatch->forceFill(['status' => WhatsAppDispatch::STATUS_DELIVERED])->save();

        $this->postWebhook([
            'message_id' => 'wzap-msg-8',
            'status' => 'failed',
            'error' => 'late failure',
        ], type: 'message.status')->assertOk();

        $this->assertSame(WhatsAppDispatch::STATUS_DELIVERED, $dispatch->fresh()->status);
    }

    public function test_distinct_receipt_events_do_not_regress_a_stale_sent_model_after_read(): void
    {
        $dispatch = $this->disparoEnviado('stale-receipt', 'wamid.STALE');
        $interleaved = false;
        WhatsAppDispatch::retrieved(function (WhatsAppDispatch $model) use ($dispatch, &$interleaved): void {
            if ($model->getKey() !== $dispatch->getKey() || $interleaved) {
                return;
            }

            $interleaved = true;
            $this->assertSame(WhatsAppDispatch::STATUS_SENT, $model->status);
            $this->postWebhook([
                'message_ids' => ['wamid.STALE'], 'status' => 'read',
            ], 'stale-read-winner')->assertOk();
        });

        $this->postWebhook([
            'message_ids' => ['wamid.STALE'], 'status' => 'delivered',
        ], 'stale-delivered-contender')->assertOk();

        $this->assertTrue($interleaved);
        $this->assertSame(WhatsAppDispatch::STATUS_READ, $dispatch->fresh()->status);
        $this->assertSame(2, WhatsAppWebhookEvent::where('whatsapp_dispatch_id', $dispatch->getKey())->count());
    }

    public function test_distinct_failed_event_does_not_overwrite_delivery_using_a_stale_sent_model(): void
    {
        $dispatch = $this->disparoEnviado('stale-failure', 'wamid.STALE-FAILURE');
        $interleaved = false;
        WhatsAppDispatch::retrieved(function (WhatsAppDispatch $model) use ($dispatch, &$interleaved): void {
            if ($model->getKey() !== $dispatch->getKey() || $interleaved) {
                return;
            }

            $interleaved = true;
            $this->assertSame(WhatsAppDispatch::STATUS_SENT, $model->status);
            $this->postWebhook([
                'message_ids' => ['wamid.STALE-FAILURE'], 'status' => 'delivered',
            ], 'delivery-before-stale-failure')->assertOk();
        });

        $this->postWebhook([
            'message_id' => 'stale-failure', 'status' => 'failed', 'error' => 'late failure',
        ], 'stale-failure-contender', 'message.status')->assertOk();

        $this->assertTrue($interleaved);
        $this->assertSame(WhatsAppDispatch::STATUS_DELIVERED, $dispatch->fresh()->status);
        $this->assertNull($dispatch->fresh()->failure_reason);
        $this->assertSame(2, WhatsAppWebhookEvent::where('whatsapp_dispatch_id', $dispatch->getKey())->count());
    }

    public function test_failed_remains_terminal_after_distinct_delivery_and_read_events(): void
    {
        $dispatch = $this->disparoEnviado('terminal-failure', 'wamid.TERMINAL');
        $this->postWebhook([
            'message_id' => 'terminal-failure', 'status' => 'failed', 'error' => 'original failure',
        ], 'terminal-failure', 'message.status')->assertOk();

        foreach (['delivered', 'read'] as $status) {
            $this->postWebhook([
                'message_ids' => ['wamid.TERMINAL'], 'status' => $status,
            ], 'after-failure-'.$status)->assertOk();
        }

        $this->assertSame(WhatsAppDispatch::STATUS_FAILED, $dispatch->fresh()->status);
        $this->assertSame('original failure', $dispatch->fresh()->failure_reason);
        $this->assertSame(3, WhatsAppWebhookEvent::where('whatsapp_dispatch_id', $dispatch->getKey())->count());
    }

    public function test_evento_de_conexao_espelha_status_do_canal(): void
    {
        $this->postWebhook(['status' => 'disconnected'], type: 'connection')
            ->assertOk();
        $this->assertSame(WhatsAppChannel::STATUS_DISCONNECTED, $this->channel->fresh()->status);

        $this->postWebhook(['status' => 'connected'], type: 'connection')
            ->assertOk();
        $this->assertSame(WhatsAppChannel::STATUS_CONNECTED, $this->channel->fresh()->status);
    }

    public function test_evento_de_mensagem_nao_persiste_texto_recebido(): void
    {
        $this->postWebhook([
            'message_id' => 'wamid.INBOUND',
            'text' => 'texto que o cliente mandou e não deve ficar aqui',
        ], type: 'message')->assertOk();

        // O evento é deduplicado, mas nada do conteúdo é gravado.
        $this->assertSame(1, WhatsAppWebhookEvent::query()->count());
        $this->assertDatabaseMissing('whatsapp_dispatches', ['status' => WhatsAppDispatch::STATUS_FAILED]);
    }

    public function test_message_status_sem_disparo_correspondente_pede_reentrega(): void
    {
        // `message.status` para um disparo que não é deste canal (ou que
        // ainda não existe) não pode ser deduplicado — a linha do evento
        // condenaria a reentrega que o gateway vai fazer.
        $this->postWebhook([
            'message_id' => 'wzap-msg-de-ninguem',
            'status' => 'sent',
            'whatsapp_id' => 'wamid.DE.NINGUEM',
        ], type: 'message.status')->assertStatus(503);

        $this->assertSame(0, WhatsAppWebhookEvent::query()->count());
    }

    public function test_evento_desconhecido_responde_200(): void
    {
        $this->postWebhook(['qualquer' => 'coisa'], type: 'poll.vote')->assertOk();
        $this->assertSame(1, WhatsAppWebhookEvent::query()->count());
    }

    public function test_status_nao_escalar_no_payload_responde_200(): void
    {
        // Payload malformado do gateway não pode 500 numa rota pública:
        // `status` como array é ignorado e nada se move.
        $dispatch = $this->disparoEnviado('wzap-msg-10', 'wamid.MALFORMADO');

        $this->postWebhook([
            'message_ids' => ['wamid.MALFORMADO'],
            'status' => ['delivered'],
        ])->assertOk();

        $this->postWebhook([
            'status' => ['disconnected'],
        ], type: 'connection')->assertOk();

        $this->assertSame(WhatsAppDispatch::STATUS_SENT, $dispatch->fresh()->status);
        $this->assertSame(WhatsAppChannel::STATUS_CONNECTED, $this->channel->fresh()->status);
    }

    public function test_logs_do_webhook_nao_carregam_apikey_nem_corpo(): void
    {
        $registros = [];
        Log::listen(function (MessageLogged $event) use (&$registros): void {
            $registros[] = $event->level.' '.$event->message.' '.json_encode($event->context);
        });

        $this->disparoEnviado('wzap-msg-9', 'wamid.LOG');

        $this->postWebhook([
            'message_id' => 'wzap-msg-9',
            'status' => 'failed',
            'error' => 'gateway recusou: apikey '.self::API_KEY.' corpo base64JVBERi0xLjQgcGRmLW1hcmtlcg==',
        ], type: 'message.status')->assertOk();

        $this->postWebhook([
            'message_id' => 'wamid.INBOUND2',
            'text' => 'conteúdo privado do cliente 5511987654321',
        ], type: 'message')->assertOk();

        // Um `message.status` sem match (503 de reentrega) também não
        // vaza nada no log — a resposta não é logada.
        $this->postWebhook([
            'message_id' => 'wzap-msg-sem-linha',
            'status' => 'sent',
            'whatsapp_id' => 'wamid.SEM.LINHA',
        ], type: 'message.status')->assertStatus(503);

        $todoLog = implode("\n", $registros);
        $this->assertStringNotContainsString(self::API_KEY, $todoLog);
        $this->assertStringNotContainsString('JVBERi0xLjQgcGRmLW1hcmtlcg==', $todoLog);
        $this->assertStringNotContainsString('conteúdo privado do cliente', $todoLog);
    }

    /**
     * POST no webhook autenticado por apikey. O corpo é o envelope do
     * wzap: `event_id` + `type` + `payload` — o `event` cru que o wzap
     * anexa para outros consumidores nunca é enviado nos testes porque o
     * controller não deve lê-lo.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload, ?string $eventId = null, string $type = 'receipt', ?string $apikey = null): TestResponse
    {
        return $this->postJson(
            '/api/v1/webhooks/wzap',
            $this->envelope($type, $payload, $eventId),
            ['apikey' => $apikey ?? self::API_KEY],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function envelope(string $type, array $payload, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? fake()->uuid(),
            'event_version' => 1,
            'type' => $type,
            'instance_id' => 'inst-webhook',
            'occurred_at' => now()->toIso8601String(),
            'payload' => $payload,
        ];
    }

    /**
     * A linha como o job a deixa depois do `sent`: `message_id` é o UUID
     * interno do wzap e `whatsapp_id` o wamid que os recibos referenciam.
     */
    private function disparoEnviado(string $messageId, string $whatsappId): WhatsAppDispatch
    {
        return WhatsAppDispatch::factory()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'status' => WhatsAppDispatch::STATUS_SENT,
            'message_id' => $messageId,
            'whatsapp_id' => $whatsappId,
        ]);
    }
}
