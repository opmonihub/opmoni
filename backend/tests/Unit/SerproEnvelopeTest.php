<?php

namespace Tests\Unit;

use App\Services\SerproEnvelope;
use PHPUnit\Framework\TestCase;

class SerproEnvelopeTest extends TestCase
{
    public function test_it_encodes_dados_as_a_json_string(): void
    {
        $envelope = (new SerproEnvelope)->build(
            '33683111000107',
            2,
            '33683111000875',
            '33683111000875',
            'REGIMEAPURACAO',
            'CONSULTARANOSCALENDARIOS102',
            '1.0',
            ['anoCalendario' => 2023],
        );

        $this->assertIsString($envelope['pedidoDados']['dados']);
        $this->assertSame(['anoCalendario' => 2023], json_decode($envelope['pedidoDados']['dados'], true));
    }

    public function test_an_empty_payload_becomes_an_empty_string(): void
    {
        $envelope = (new SerproEnvelope)->build(
            '33683111000107',
            2,
            '33683111000875',
            '33683111000875',
            'REGIMEAPURACAO',
            'CONSULTARANOSCALENDARIOS102',
            '1.0',
            [],
        );

        $this->assertSame('', $envelope['pedidoDados']['dados']);
    }

    public function test_it_carries_three_distinct_identities(): void
    {
        $envelope = (new SerproEnvelope)->build(
            '33683111000107',
            2,
            '33683111000875',
            '99999999999999',
            'PROCURACOES',
            'OBTERPROCURACAO41',
            '1',
            [],
        );

        $this->assertSame('33683111000107', $envelope['contratante']['numero']);
        $this->assertSame('33683111000875', $envelope['autorPedidoDados']['numero']);
        $this->assertSame('99999999999999', $envelope['contribuinte']['numero']);
        $this->assertSame('1', $envelope['pedidoDados']['versaoSistema']);
    }

    /**
     * A regra 11→1, 14→2 mora aqui e é a fonte única de três lugares: o
     * `autorPedidoDados.tipo`/`contribuinte.tipo` deste envelope, a coluna
     * `contratante_tipo` gravada pela plataforma e o `X-Request-Tag` de
     * `SerproRequestTag`. Nenhuma cópia local dela: documento e tipo discordando
     * é um `403` que não diz qual dos dois está errado.
     */
    public function test_deriva_o_tipo_do_documento_e_nao_o_declara(): void
    {
        $this->assertSame(1, SerproEnvelope::tipo('12345678901'));
        $this->assertSame(2, SerproEnvelope::tipo('33683111000107'));
        $this->assertSame(2, SerproEnvelope::tipo('12ABC345000188'));

        $envelope = (new SerproEnvelope)->build(
            '33683111000107',
            2,
            '12345678901',
            '99999999999999',
            'PROCURACOES',
            'OBTERPROCURACAO41',
            '1',
            [],
        );

        // O `contratante.tipo` vem pronto do manager, que o derivou do
        // certificado; os outros dois saem da regra, e é o que este teste segura.
        $this->assertSame(2, $envelope['contratante']['tipo']);
        $this->assertSame(1, $envelope['autorPedidoDados']['tipo']);
        $this->assertSame(2, $envelope['contribuinte']['tipo']);
    }

    public function test_it_decodes_dados_twice(): void
    {
        $result = (new SerproEnvelope)->parse([
            'status' => 200,
            'responseId' => 'z45e2f31-03e6-417d-8f1a-7153954f2d5b',
            'dados' => '[{"anoCalendario":2023,"regimeApurado":"CAIXA"}]',
            'mensagens' => [
                ['codigo' => '[Sucesso-REGIME]', 'texto' => 'Requisição efetuada com sucesso.'],
            ],
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('z45e2f31-03e6-417d-8f1a-7153954f2d5b', $result['response_id']);
        $this->assertSame([['anoCalendario' => 2023, 'regimeApurado' => 'CAIXA']], $result['dados']);
        $this->assertSame('[Sucesso-REGIME]', $result['mensagens'][0]['codigo']);
    }

    public function test_it_tolerates_a_nulled_envelope(): void
    {
        $result = (new SerproEnvelope)->parse([
            'contratante' => null,
            'pedidoDados' => null,
            'status' => 400,
            'dados' => null,
            'mensagens' => [
                ['codigo' => 'ERRO', 'texto' => 'Dados inválidos.'],
            ],
        ]);

        $this->assertSame(400, $result['status']);
        $this->assertNull($result['dados']);
        $this->assertNull($result['response_id']);
    }

    /**
     * O `dados` da resposta volta como a mesma string escapada que a requisição
     * manda: JSON dentro de JSON. Uma passagem só entrega a camada de fora, e o
     * consumidor receberia um texto onde esperava o payload do serviço.
     */
    public function test_decodifica_dados_serializado_duas_vezes(): void
    {
        $parsed = (new SerproEnvelope)->parse([
            'status' => 200,
            'dados' => json_encode(json_encode(['tipo' => '2'], JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR),
        ]);

        $this->assertSame(['tipo' => '2'], $parsed['dados']);
    }

    /**
     * O provedor devolve `tipo` ora como inteiro, ora como texto, e as duas
     * formas são verdadeiras. O envelope não escolhe: converter um deles
     * mudaria o que o serviço disse.
     */
    public function test_preserva_o_tipo_numerico_e_o_textual(): void
    {
        $this->assertSame(['tipo' => 2], $this->parseDados(['tipo' => 2]));
        $this->assertSame(['tipo' => '2'], $this->parseDados(['tipo' => '2']));
    }

    /**
     * Uma string que só por acaso é JSON continua sendo a string que o
     * provedor mandou — inclusive quando é um número entre aspas, que é JSON
     * válido e perderia a forma sem nada ganhar.
     */
    public function test_preserva_uma_string_que_por_acaso_e_json(): void
    {
        $this->assertSame('REGIME_APURACAO', $this->parseDadosString('"REGIME_APURACAO"'));
        $this->assertSame('12', $this->parseDadosString('"12"'));
    }

    /**
     * O caso que impede o `json_decode` cego: um documento é uma sequência de
     * dígitos que o PHP leria como número, e `'00000000000000'` viraria `0` —
     * um identificador que existe, que casa com outros e que ninguém
     * reconheceu como documento. Fica string.
     */
    public function test_um_documento_nunca_vira_numero(): void
    {
        $this->assertSame('33683111000107', $this->parseDadosString('33683111000107'));
        $this->assertSame('00000000000000', $this->parseDadosString('00000000000000'));
        $this->assertSame('12345678901', $this->parseDadosString('12345678901'));
    }

    /**
     * A mesma troca de tipo, nos outros dois escalares do JSON. `'true'` virava
     * booleano e `'null'` virava nulo — e um `dados` que o serviço mandou como
     * texto chegando como `true` é um payload que o consumidor lê como sinal, não
     * como conteúdo. Nenhum dos três é objeto ou lista, que é o que o laço
     * aceita atravessar.
     */
    public function test_um_escalar_json_nunca_troca_o_tipo_da_string(): void
    {
        $this->assertSame('true', $this->parseDadosString('true'));
        $this->assertSame('false', $this->parseDadosString('false'));
        $this->assertSame('null', $this->parseDadosString('null'));

        // Um JSON de string é a única forma que o guard deixa atravessar como
        // texto, e atravessar é o certo: são duas camadas de aspas, que é o
        // `dados` duplamente serializado, e o que sai é o conteúdo.
        $this->assertSame('true', $this->parseDadosString('"true"'));

        // A diferença do `null` para os outros dois escalares não é de grau:
        // `json_decode` devolve `null` **sem** erro nenhum, então um guard
        // escrito em cima de `is_scalar` — que é falso justamente para `null` —
        // deixa este passar. A afirmação é sobre o erro, que precisa vir limpo.
        $this->assertNull(json_decode('null', true));
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    /**
     * Duas passagens e nem uma a mais. Com três camadas de string, a terceira
     * entregaria o conteúdo e apagaria a prova de que a camada existia, e um
     * `while` "até não dar mais" continua decompondo o que vier depois.
     */
    public function test_nao_decodifica_uma_terceira_passagem(): void
    {
        $dados = json_encode(
            json_encode(json_encode('REGIME_APURACAO'), JSON_THROW_ON_ERROR),
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('"REGIME_APURACAO"', $this->parseDadosString($dados));
    }

    /**
     * Nem toda string é JSON: um `dados` pode ser um texto de estado, um
     * identificador de protocolo ou o conteúdo de um PDF. Sai como entrou.
     */
    public function test_uma_string_que_nao_e_json_fica_como_esta(): void
    {
        $this->assertSame('', $this->parseDadosString(''));
        $this->assertSame('Relatório pendente de emissão', $this->parseDadosString('Relatório pendente de emissão'));
        $this->assertSame('JVBERi0xLjQKJcOkw7z', $this->parseDadosString('JVBERi0xLjQKJcOkw7z'));
    }

    /**
     * Um erro de aplicação pode não trazer envelope nenhum: nem `status`, nem
     * `responseId`, nem `mensagens`. `0` é o status que o provedor não
     * informou — nunca um status HTTP, e nunca um palpite.
     */
    public function test_uma_resposta_sem_envelope_nao_inventa_status(): void
    {
        $parsed = (new SerproEnvelope)->parse(['code' => '900807']);

        $this->assertSame(0, $parsed['status']);
        $this->assertNull($parsed['dados']);
        $this->assertNull($parsed['response_id']);
        $this->assertSame([], $parsed['mensagens']);
    }

    /**
     * O envelope real não é todo igual: `status` chega como texto em parte dos
     * serviços e `responseId` chega vazio, que é a mesma coisa que não chegar.
     * Um `responseId` vazio no registro da chamada seria um identificador que
     * parece real e não serve para nada. E campo que o provedor manda como
     * objeto não é texto: vira lista vazia de campo, e nunca `"Array"`.
     */
    public function test_le_o_envelope_real_sem_assumir_forma(): void
    {
        $texto = (new SerproEnvelope)->parse(['status' => '200', 'responseId' => 'z45e2f31']);
        $vazio = (new SerproEnvelope)->parse(['status' => 200, 'responseId' => '']);
        $objeto = (new SerproEnvelope)->parse([
            'status' => 403,
            'responseId' => ['z45e2f31'],
            'mensagens' => [['codigo' => ['AcessoNegado'], 'texto' => null]],
        ]);

        $this->assertSame(200, $texto['status']);
        $this->assertSame('z45e2f31', $texto['response_id']);
        $this->assertSame(200, $vazio['status']);
        $this->assertNull($vazio['response_id']);
        $this->assertNull($objeto['response_id']);
        $this->assertSame([['codigo' => '', 'texto' => '']], $objeto['mensagens']);
    }

    /**
     * `mensagens` ausente, vazia, nula ou fora de lista é a mesma resposta:
     * sem mensagens. Uma lista de mensagens que ninguém espera para o rastreio
     * da chamada é pior do que a lista vazia.
     */
    public function test_mensagens_ausentes_ou_vazias_viram_lista_vazia(): void
    {
        $vazias = ['status' => 200, 'dados' => '[]', 'mensagens' => []];
        $ausentes = ['status' => 200, 'dados' => '[]'];
        $nulas = ['status' => 200, 'dados' => '[]', 'mensagens' => null];
        $fora = ['status' => 200, 'dados' => '[]', 'mensagens' => 'Requisição efetuada com sucesso.'];

        $this->assertSame([], (new SerproEnvelope)->parse($vazias)['mensagens']);
        $this->assertSame([], (new SerproEnvelope)->parse($ausentes)['mensagens']);
        $this->assertSame([], (new SerproEnvelope)->parse($nulas)['mensagens']);
        $this->assertSame([], (new SerproEnvelope)->parse($fora)['mensagens']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function parseDados(array $payload): mixed
    {
        return $this->parseDadosString(json_encode(
            json_encode($payload, JSON_THROW_ON_ERROR),
            JSON_THROW_ON_ERROR,
        ));
    }

    private function parseDadosString(string $raw): mixed
    {
        return (new SerproEnvelope)->parse(['status' => 200, 'dados' => $raw])['dados'];
    }
}
