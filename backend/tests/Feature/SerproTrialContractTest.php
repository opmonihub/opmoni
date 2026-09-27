<?php

namespace Tests\Feature;

use App\Services\SerproEnvelope;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Conversa com o ambiente de demonstração do SERPRO, que publica o próprio
 * bearer e dispensa certificado. Pulado por padrão porque a cota do trial é
 * global e compartilhada: um 429 aqui é ruído, não regressão.
 *
 * O trial é um mock: sobrescreve as identidades com os números do cenário,
 * ignora o token de autorização e não reproduz a máquina de espera do
 * SITFIS. Ele prova a camada de transporte e nada além disso.
 */
#[Group('serpro-trial')]
class SerproTrialContractTest extends TestCase
{
    public function test_the_trial_answers_a_regime_consultation(): void
    {
        $token = (string) config('integra-contador.trial_token');

        if ($token === '') {
            $this->markTestSkipped('SERPRO_TRIAL_TOKEN não configurado.');
        }

        $envelope = (new SerproEnvelope)->build(
            '00000000000000',
            2,
            '00000000000000',
            '00000000000000',
            'REGIMEAPURACAO',
            'CONSULTARANOSCALENDARIOS102',
            '1.0',
            [],
        );

        $response = Http::acceptJson()
            ->withToken(config('integra-contador.trial_token'))
            ->post(config('integra-contador.trial_gateway_url').'/Consultar', $envelope);

        if ($response->status() === 429) {
            $this->markTestSkipped('Cota do trial esgotada.');
        }

        $result = (new SerproEnvelope)->parse($response->json() ?? []);

        $this->assertSame(200, $result['status']);
        $this->assertNotNull($result['dados']);
    }

    public function test_a_throttled_trial_is_skipped_rather_than_failed(): void
    {
        $token = (string) config('integra-contador.trial_token');

        if ($token === '') {
            $this->markTestSkipped('SERPRO_TRIAL_TOKEN não configurado.');
        }

        Http::preventStrayRequests();

        Http::fake([
            (string) config('integra-contador.trial_gateway_url').'/*' => Http::response([
                'code' => '900807',
                'message' => 'Message throttled out',
            ], 429),
        ]);

        $envelope = (new SerproEnvelope)->build(
            '00000000000000',
            2,
            '00000000000000',
            '00000000000000',
            'REGIMEAPURACAO',
            'CONSULTARANOSCALENDARIOS102',
            '1.0',
            [],
        );

        $response = Http::acceptJson()
            ->withToken(config('integra-contador.trial_token'))
            ->post(config('integra-contador.trial_gateway_url').'/Consultar', $envelope);

        if ($response->status() === 429) {
            $this->markTestSkipped('Cota do trial esgotada: '.$response->json('message', ''));
        }

        $this->fail('O provider deveria ter limitado a chamada.');
    }
}
