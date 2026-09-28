<?php

namespace Tests\Feature;

use App\Enums\SerproFailure;
use App\Services\SerproEnvelope;
use App\Services\SerproException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SerproContractFixtureTest extends TestCase
{
    private const DIR = __DIR__.'/../Fixtures/serpro';

    /**
     * As três respostas gravadas de verdade. `sitfis-relatorio.json` e
     * `application-error.json` não entram aqui: são exemplos documentais, e a
     * diferença entre o que foi observado e o que foi transcrito da
     * documentação precisa continuar visível na suíte.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function payloads(): array
    {
        return [
            ['regime-consultar-anos.json', 'CONSULTARANOSCALENDARIOS102'],
            ['pgdasd-consultar-declaracao.json', 'CONSDECLARACAO13'],
            ['dte-consultar-situacao.json', 'CONSULTASITUACAODTE111'],
        ];
    }

    #[DataProvider('payloads')]
    public function test_a_recorded_success_parses_into_data(string $file, string $idServico): void
    {
        $payload = $this->fixture($file);

        $this->assertSame($idServico, trim((string) $payload['pedidoDados']['idServico']));
        $this->assertSame(200, $payload['status']);

        $result = (new SerproEnvelope)->parse($payload);

        $this->assertSame(200, $result['status']);
        $this->assertNotNull($result['dados']);
        $this->assertNotEmpty($result['mensagens']);
    }

    public function test_a_recorded_throttle_uses_the_gateway_shape(): void
    {
        $payload = $this->fixture('gateway-429.json');

        $this->assertArrayNotHasKey('mensagens', $payload);
        $this->assertSame('900807', $payload['code']);
        $this->assertSame(SerproFailure::Throttled, SerproException::classify(429, '900807'));
    }

    /**
     * Exemplo **documental** do `SITFIS/RELATORIOSITFIS92` — não é uma resposta
     * observada.
     *
     * Proveniência do que este arquivo afirma: a forma do envelope vem da
     * descrição publicada do Integra Contador, e é a mesma que os outros
     * serviços respondem — o envelope da requisição espelhado, mais `status`,
     * `dados` como string, `mensagens` de `{codigo, texto}` e `responseId`, com
     * o `dados` sendo a string escapada que carrega o JSON do serviço, em duas
     * camadas como a requisição manda. `idSistema`, `idServico` e
     * `versaoSistema` são os de `config/integra-contador.php`, que transcreve o
     * catálogo do SERPRO.
     *
     * O que **não** é observado, e é o que impede este arquivo de passar por
     * captura: o ambiente de demonstração não estava disponível — não há
     * `SERPRO_TRIAL_TOKEN` neste ambiente —, então nenhum byte aqui veio do
     * trial nem da produção, e a captura real segue como bloqueio explícito da
     * tarefa 3.3. O `codigo` da mensagem segue a convenção
     * `[Sucesso-<SISTEMA>]` que as duas fixtures gravadas mostram e não é um
     * código observado do SITFIS; o `dados` tem um campo só, `protocolo`, o
     * único nome de campo do SITFIS documentado no projeto, com valor de
     * placeholder.
     *
     * E o trial, mesmo quando respondeu, não prova o que o SITFIS faz de mais:
     * a máquina de espera, o `304` e o `503` continuam sem verificação até a
     * captura real.
     */
    public function test_o_exemplo_documental_do_sitfis_tem_a_forma_publicada(): void
    {
        $payload = $this->fixture('sitfis-relatorio.json');

        $this->assertSame('SITFIS', $payload['pedidoDados']['idSistema']);
        $this->assertSame('RELATORIOSITFIS92', $payload['pedidoDados']['idServico']);
        $this->assertSame('2.0', $payload['pedidoDados']['versaoSistema']);
        $this->assertSame(200, $payload['status']);
        $this->assertIsString($payload['dados']);
        $this->assertSame(36, strlen((string) $payload['responseId']));
        $this->assertSame(['codigo', 'texto'], array_keys($payload['mensagens'][0]));
    }

    /**
     * O `dados` documentado do SITFIS é a string escapada dentro da string
     * escapada. Uma passagem só entregaria a camada de fora, e o consumidor
     * receberia um texto onde esperava o payload do serviço.
     */
    public function test_o_dados_documental_do_sitfis_precisa_de_duas_passagens(): void
    {
        $result = (new SerproEnvelope)->parse($this->fixture('sitfis-relatorio.json'));

        $this->assertSame(200, $result['status']);
        $this->assertSame(['protocolo' => '000000000000000'], $result['dados']);
        $this->assertSame('00000000-0000-0000-0000-000000000000', $result['response_id']);
        $this->assertCount(1, $result['mensagens']);
    }

    /**
     * Exemplo **documental** de erro de aplicação, também não observado: o
     * `403` e o código `AcessoNegado-ICGERENCIADOR-019` são os documentados
     * para o `autorPedidoDados` que não é o contratante, com o termo de
     * autorização assinado pelo procurador exigido, e a documentação admite que uma
     * resposta de erro nulifique o envelope inteiro — o que o arquivo exercita.
     * O `texto` é a paráfrase do significado documentado, não a frase do
     * provedor.
     */
    public function test_o_erro_de_aplicacao_documental_vem_no_envelope_real(): void
    {
        $payload = $this->fixture('application-error.json');

        $this->assertNull($payload['contratante']);
        $this->assertNull($payload['autorPedidoDados']);
        $this->assertNull($payload['contribuinte']);
        $this->assertNull($payload['pedidoDados']);
        $this->assertNull($payload['dados']);
        $this->assertSame(36, strlen((string) $payload['responseId']));

        $result = (new SerproEnvelope)->parse($payload);

        $this->assertSame(403, $result['status']);
        $this->assertNull($result['dados']);
        $this->assertSame('AcessoNegado-ICGERENCIADOR-019', $result['mensagens'][0]['codigo']);

        // Não está nas listas de reautenticação nem de reenvio de termo, e um
        // `403` não se resolve repetindo: é correção de quem chamou.
        $this->assertSame(SerproFailure::DoNotRetry, SerproException::classify(403, 'AcessoNegado-ICGERENCIADOR-019'));
    }

    /**
     * Fixture entra no repositório e sai dali para sempre, e o lugar mais fácil
     * de vazar um segredo é a própria fixture. A regra é verificada, não pedida:
     * documento de pessoa e portador não entram, e o único documento aceito é o
     * placeholder de dígitos repetidos, que não existe.
     */
    public function test_nenhuma_fixture_carrega_documento_nem_segredo(): void
    {
        $arquivos = glob(self::DIR.'/*.json');

        $this->assertNotFalse($arquivos);
        $this->assertNotEmpty($arquivos);

        foreach ($arquivos as $arquivo) {
            $nome = basename($arquivo);
            $bruto = (string) file_get_contents($arquivo);

            $this->assertStringNotContainsString('Bearer ', $bruto, $nome);
            $this->assertStringNotContainsString('eyJ', $bruto, $nome);

            foreach ($this->violacoes(json_decode($bruto, true)) as $violacao) {
                $this->fail("{$nome}: {$violacao}");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $file): array
    {
        $payload = json_decode((string) file_get_contents(self::DIR.'/'.$file), true);

        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * O que uma fixture não pode trazer, com o caminho do campo que trouxe.
     *
     * @return list<string>
     */
    private function violacoes(mixed $payload, string $caminho = ''): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $violacoes = [];

        foreach ($payload as $chave => $valor) {
            $onde = $caminho === '' ? (string) $chave : $caminho.'.'.$chave;

            if (preg_match('/token|secret|senha|password|authorization/i', (string) $chave) === 1) {
                $violacoes[] = "{$onde} é chave de credencial";
            }

            if (is_array($valor)) {
                $violacoes = [...$violacoes, ...$this->violacoes($valor, $onde)];

                continue;
            }

            if (! is_string($valor)
                || preg_match('/^\d{11}$|^\d{14}$/', $valor) !== 1
                || preg_match('/^(\d)\1+$/', $valor) === 1) {
                continue;
            }

            if (preg_match('/numero|cpf|cnpj|contribuinte|contratante|autor/i', (string) $chave) === 1) {
                $violacoes[] = "{$onde} traz um documento que não é placeholder";
            }
        }

        return $violacoes;
    }
}
