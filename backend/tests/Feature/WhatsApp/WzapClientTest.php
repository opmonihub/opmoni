<?php

namespace Tests\Feature\WhatsApp;

use App\Services\WhatsApp\WzapClient;
use App\Services\WhatsApp\WzapException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\BusinessTestCase;

class WzapClientTest extends BusinessTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config()->set('whatsapp.wzap.base_url', 'http://wzap.test');
        config()->set('whatsapp.wzap.api_key', 'chave-global');
        config()->set('whatsapp.wzap.webhook_url', 'https://opmoni.test/api/v1/webhooks/wzap');
    }

    private function client(): WzapClient
    {
        return resolve(WzapClient::class);
    }

    public function test_cria_instancia_com_external_ref_e_devolve_a_chave_uma_vez(): void
    {
        Http::fake([
            'wzap.test/instances' => Http::response([
                'data' => [
                    'id' => 'inst-1',
                    'external_ref' => 'acc-1',
                    'status' => 'disconnected',
                    'instance_api_key' => 'chave-instancia',
                ],
            ], 201),
        ]);

        $instancia = $this->client()->createInstance('acc-1', 'Escritório');

        $this->assertSame('inst-1', $instancia['id']);
        $this->assertSame('chave-instancia', $instancia['instance_api_key']);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('chave-global', $request->header('apikey')[0] ?? null);
            $this->assertSame('POST', $request->method());
            $this->assertSame('http://wzap.test/instances', $request->url());
            $this->assertSame('acc-1', $request['external_ref']);
            $this->assertSame('Escritório', $request['name']);
            $this->assertSame('https://opmoni.test/api/v1/webhooks/wzap', $request['webhook']['url']);
            $this->assertTrue($request['webhook']['enabled']);
            // A assinatura pedida: recibos e viradas de conexão — `message`
            // (texto inbound) fica de fora porque nada o deve persistir.
            $this->assertSame(['receipt', 'connection', 'message.status'], $request['webhook']['events']);

            return true;
        });
    }

    public function test_external_ref_duplicado_busca_a_instancia_existente(): void
    {
        Http::fake([
            'wzap.test/instances*' => Http::sequence()
                ->push(['error' => ['code' => 'conflict', 'message' => 'external ref already taken']], 409)
                ->push(['data' => ['items' => [
                    ['id' => 'inst-outra', 'external_ref' => 'acc-2', 'status' => 'connected'],
                    ['id' => 'inst-1', 'external_ref' => 'acc-1', 'status' => 'connected'],
                ], 'next_cursor' => null]])
                ->push(['data' => ['id' => 'inst-1']]),
        ]);

        $instancia = $this->client()->ensureInstance('acc-1', 'Escritório');

        $this->assertSame('inst-1', $instancia['id']);
        // A chave de instância não volta na busca — só na criação.
        $this->assertArrayNotHasKey('instance_api_key', $instancia);
    }

    public function test_connect_inicia_pareamento_e_devolve_qr(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/connect' => Http::response([
                'data' => ['status' => 'pairing', 'qr_code' => '2@abc', 'qr_expires_at' => '2026-01-01T00:00:00Z'],
            ]),
        ]);

        $pareamento = $this->client()->connect('inst-1', 'chave-instancia');

        $this->assertSame('pairing', $pareamento['status']);
        $this->assertSame('2@abc', $pareamento['qr_code']);

        Http::assertSent(fn (Request $request): bool => ($request->header('apikey')[0] ?? null) === 'chave-instancia');
    }

    public function test_connect_em_instancia_ja_conectada_devolve_connected_sem_qr(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/connect' => Http::response([
                'data' => ['status' => 'connected'],
            ]),
        ]);

        $pareamento = $this->client()->connect('inst-1', 'chave-instancia');

        $this->assertSame('connected', $pareamento['status']);
        $this->assertArrayNotHasKey('qr_code', $pareamento);
    }

    public function test_qr_em_instancia_ja_conectada_levanta_conflito_409(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/qr' => Http::response([
                'error' => ['code' => 'conflict', 'message' => 'instance already connected'],
            ], 409),
        ]);

        try {
            $this->client()->qr('inst-1', 'chave-instancia');
            $this->fail('esperava WzapException');
        } catch (WzapException $e) {
            $this->assertSame(409, $e->status());
            $this->assertSame('conflict', $e->errorCode());
        }
    }

    public function test_status_devolve_o_estado_da_instancia(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/status' => Http::response([
                'data' => ['status' => 'connected', 'whatsapp_jid' => '5511@s.whatsapp.net'],
            ]),
        ]);

        $status = $this->client()->status('inst-1', 'chave-instancia');

        $this->assertSame('connected', $status['status']);
    }

    public function test_disconnect_chama_a_rota_com_a_chave_da_instancia(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/disconnect' => Http::response(null, 204),
        ]);

        $this->client()->disconnect('inst-1', 'chave-instancia');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://wzap.test/instances/inst-1/disconnect'
            && ($request->header('apikey')[0] ?? null) === 'chave-instancia');
    }

    public function test_envia_documento_em_multipart_com_idempotency_key(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/messages/media' => Http::response([
                'data' => ['message_id' => 'msg-1', 'status' => 'queued'],
            ], 202),
        ]);

        $resposta = $this->client()->sendMedia(
            instanceId: 'inst-1',
            instanceApiKey: 'chave-instancia',
            contents: 'bytes-do-pdf',
            filename: 'guia.pdf',
            mimeType: 'application/pdf',
            phone: '5511999999999',
            idempotencyKey: 'idem-1',
        );

        $this->assertSame('msg-1', $resposta['message_id']);
        $this->assertSame('queued', $resposta['status']);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('idem-1', $request->header('Idempotency-Key')[0] ?? null);
            $this->assertSame('chave-instancia', $request->header('apikey')[0] ?? null);
            $this->assertStringContainsString('multipart/form-data', $request->header('Content-Type')[0] ?? '');
            $this->assertTrue($request->hasFile('file', 'bytes-do-pdf', 'guia.pdf'));
            // O multipart gravado chega como lista de partes {name, contents}.
            $partes = collect($request->data())->keyBy('name');
            $this->assertSame('5511999999999', $partes['to']['contents']);
            $this->assertSame('document', $partes['type']['contents']);

            return true;
        });
    }

    public function test_erro_do_wzap_vira_excecao_com_status_e_motivo_sanitizado(): void
    {
        Http::fake([
            'wzap.test/instances/inst-1/messages/media' => Http::response([
                'error' => ['code' => 'conflict', 'message' => 'instance not connected'],
            ], 409),
        ]);

        try {
            $this->client()->sendMedia('inst-1', 'chave-instancia', 'pdf', 'guia.pdf', 'application/pdf', '5511', 'idem-2');
            $this->fail('esperava WzapException');
        } catch (WzapException $e) {
            $this->assertSame(409, $e->status());
            $this->assertStringContainsString('instance not connected', $e->getMessage());
            // A chave nunca pode vazar na mensagem.
            $this->assertStringNotContainsString('chave-instancia', $e->getMessage());
        }
    }

    public function test_sem_chave_global_falha_antes_de_sair_para_a_rede(): void
    {
        config()->set('whatsapp.wzap.api_key', null);

        $this->expectException(WzapException::class);

        $this->client()->createInstance('acc-1', 'Escritório');
    }
}
