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
     * As fixtures documentais: transcritas da documentação, não observadas.
     *
     * O marker `_provenance` é o que acompanha o artefato — o JSON aberto
     * sozinho, colado numa issue ou lido daqui a seis meses precisa dizer que
     * aquilo não foi observado. Esta lista é o segundo lugar onde isso se diz, e
     * ela cobre um propósito que o marker não cobre: uma fixture nova não entra
     * em `fixturesGravadas()` nem aqui sem que alguém decida, na suíte, de que
     * lado ela está.
     *
     * Serve a dois usos, e ambos precisam dela: provider de
     * `test_a_varredura_aceita_as_fixtures_documentais`, e uma das duas listas
     * que `test_as_listas_de_fixture_cobrem_o_diretorio()` soma.
     *
     * @return list<array{0: string}>
     */
    public static function documentais(): array
    {
        return [
            ['sitfis-relatorio.json'],
            ['application-error.json'],
        ];
    }

    /**
     * As três respostas gravadas de verdade, com o `idServico` de cada uma.
     * Elas não cobrem o diretório todo: `gateway-429.json` é gravada e não tem
     * `idServico`, e está em `fixturesGravadas()`, que é a lista que a varredura
     * e o teste de cobertura usam.
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
     *
     * O marker `_provenance` do arquivo repete o essencial disto, e é ele que
     * acompanha o artefato: docblock não viaja com o JSON.
     */
    public function test_o_exemplo_documental_do_sitfis_tem_a_forma_publicada(): void
    {
        $payload = $this->fixture('sitfis-relatorio.json');

        $this->assertStringContainsString('não observado', $payload['_provenance']);
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
     * provedor. O marker `_provenance` do arquivo repete o essencial disto, e é
     * ele que acompanha o artefato: docblock não viaja com o JSON.
     */
    public function test_o_erro_de_aplicacao_documental_vem_no_envelope_real(): void
    {
        $payload = $this->fixture('application-error.json');

        $this->assertStringContainsString('não observado', $payload['_provenance']);
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
     *
     * O nome diz o que este teste é — uma varredura de formas conhecidas — e
     * não o que ele não é: ele não prova que uma fixture não tem dado pessoal.
     * Passa quem não tem nome de campo de documento nem 11 ou 14 dígitos sob um
     * desses nomes, com ou sem separador. O que fecha o resto é a revisão de
     * quem adiciona a fixture, e o nome propositalmente não promete o que
     * nenhum teste de string pode prometer.
     */
    public function test_nenhuma_fixture_tem_documento_ou_credencial(): void
    {
        $arquivos = glob(self::DIR.'/*.json');

        $this->assertNotFalse($arquivos);
        $this->assertNotEmpty($arquivos);

        foreach ($arquivos as $arquivo) {
            $nome = basename($arquivo);
            $bruto = (string) file_get_contents($arquivo);

            $this->assertFalse($this->portadorNoCorpo($bruto), $nome);

            foreach ($this->violacoes(json_decode($bruto, true)) as $violacao) {
                $this->fail("{$nome}: {$violacao}");
            }
        }
    }

    /**
     * A varredura acima não pode passar por decorativa, e o jeito de provar isso
     * é ela pegar. Cada forma aqui é a que passaria se a regra ficasse só no
     * nome do campo e no texto: número JSON em vez de string, documento com
     * separador, e a credencial sob chave de nome innocuo ou o portador dentro
     * de um `dados` que ninguém lê como segredo.
     *
     * A última forma é a que pega o texto cru do arquivo e as outras pegam o
     * conteúdo, e as duas precisam existir: um `eyJ...` sob a chave `dados` é
     * um JWT, e `violacoes()` — que olha chave e valor — não tem como saber.
     *
     * @return list<array{0: array<string, mixed>}>
     */
    public static function cargasQueNaoPodemEntrar(): array
    {
        return [
            [['contribuinte' => ['numero' => 33683111000107]]],
            [['contribuinte' => ['numero' => '336.831.110-001-07']]],
            [['autorPedidoDados' => ['numero' => '33.683.111/0001-07']]],
            [['contratante' => ['numero' => '33683111000107', 'tipo' => 2]]],
            [['pedidoDados' => ['senhaCertificado' => 'segredo']]],
        ];
    }

    #[DataProvider('cargasQueNaoPodemEntrar')]
    public function test_a_varredura_pega_documento_e_credencial(array $payload): void
    {
        $this->assertNotEmpty(
            $this->violacoes($payload),
            'a varredura passou por um documento ou uma credencial que devia ter apontado',
        );
    }

    /**
     * Os dois mecanismos da varredura, e eles são diferentes de propósito.
     *
     * O corpo do arquivo é lido como texto porque é assim que o portador se
     * esconde: o valor de um `dados` é uma string de duas camadas, e ninguém
     * vai adivinhar que ali dentro há um JWT. Já `violacoes()` olha a chave e o
     * valor, e um `eyJ...` sob a chave `dados` não é nem documento nem credencial
     * — para ele, é um texto qualquer.
     *
     * Por isso as duas afirmações apontam em direções opostas: o portador **é**
     * apontado no texto e **não** é apontado em `violacoes()`. Se um dia as duas
     * passarem a apontar, este teste diz qual das duas mudou de comportamento.
     */
    public function test_o_portador_e_ponto_no_texto_e_nao_em_violacoes(): void
    {
        $bruto = (string) json_encode(['dados' => 'eyJhbGciOiJSUzI1NiJ9.assinatura']);

        $this->assertTrue($this->portadorNoCorpo($bruto));
        $this->assertSame([], $this->violacoes(json_decode($bruto, true)));
    }

    /**
     * As quatro fixtures que vieram de resposta observada do provedor.
     *
     * É esta lista, e não `payloads()`, que a varredura e o teste de cobertura
     * somam: `payloads()` carrega o `idServico` de cada resposta e por isso não
     * tem lugar para `gateway-429.json`, que é do gateway e não de serviço.
     *
     * @return list<array{0: string}>
     */
    public static function fixturesGravadas(): array
    {
        return [
            ['regime-consultar-anos.json'],
            ['pgdasd-consultar-declaracao.json'],
            ['dte-consultar-situacao.json'],
            ['gateway-429.json'],
        ];
    }

    #[DataProvider('fixturesGravadas')]
    public function test_a_varredura_aceita_as_fixtures_gravadas(string $file): void
    {
        $bruto = (string) file_get_contents(self::DIR.'/'.$file);

        $this->assertFalse($this->portadorNoCorpo($bruto), $file);
        $this->assertSame([], $this->violacoes(json_decode($bruto, true)), $file);
    }

    /**
     * O mesmo para as documentais, que também precisam passar: um guard que
     * reprova o exemplo do SITFIS faria alguém afrouxar o guard inteiro para
     * accommodate o próprio exemplo.
     */
    #[DataProvider('documentais')]
    public function test_a_varredura_aceita_as_fixtures_documentais(string $file): void
    {
        $bruto = (string) file_get_contents(self::DIR.'/'.$file);

        $this->assertFalse($this->portadorNoCorpo($bruto), $file);
        $this->assertSame([], $this->violacoes(json_decode($bruto, true)), $file);
    }

    /**
     * As duas listas de fixture e o diretório precisam ser o mesmo conjunto, e
     * é o que impede uma fixture nova de entrar sem alguém decidir, na suíte, de
     * que lado ela está: `fixturesGravadas()` para as que vieram de resposta
     * observada, `documentais()` para as transcritas da documentação. Sem esta
     * afirmação, uma fixture nova apareceria na varredura e em lugar nenhum, e a
     * distinção que a tarefa 3.3 precisa preservar ficaria só no nome do
     * arquivo.
     *
     * A comparação é de conjunto ordenado e por contagem, então ela também
     * quebra quando uma fixture é declarada nas duas listas: a soma teria o
     * nome duas vezes e o diretório uma.
     */
    public function test_as_listas_de_fixture_cobrem_o_diretorio(): void
    {
        $noDiretorio = array_map(
            fn (string $arquivo): string => basename($arquivo),
            (array) glob(self::DIR.'/*.json'),
        );

        $declaradas = [
            ...array_column(self::fixturesGravadas(), 0),
            ...array_column(self::documentais(), 0),
        ];

        sort($noDiretorio);
        sort($declaradas);

        $this->assertSame($declaradas, $noDiretorio);
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
     * O portador no corpo do arquivo, lido como texto.
     *
     * Ele precisa de um mecanismo só dele, e é o `dados` que mostra por quê: o
     * valor é uma string de duas camadas, e um JWT dentro dela não aparece nem
     * como chave nem como valor que `violacoes()` consiga nomear.
     */
    private function portadorNoCorpo(string $bruto): bool
    {
        return str_contains($bruto, 'Bearer ') || str_contains($bruto, 'eyJ');
    }

    /**
     * O que uma fixture não pode trazer, com o caminho do campo que trouxe.
     *
     * Documento é conferido no **conteúdo**, não no tipo do valor: um CNPJ
     * gravado como número JSON é o que o `json_encode` produz a partir de um
     * `int` no PHP, então exigir string deixava passar a forma mais provável de
     * uma fixture gravada real. O texto é o do valor convertido, e o
     * comprimento é o dos dígitos com os separadores removidos, para que
     * `336.831.110-001-07` e `33.683.111/0001-07` contem tanto quanto
     * `33683111000107`.
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

            if (! is_scalar($valor) || is_bool($valor)) {
                continue;
            }

            if (preg_match('/numero|cpf|cnpj|contribuinte|contratante|autor/i', (string) $chave) !== 1) {
                continue;
            }

            $digitos = preg_replace('/\D/', '', (string) $valor) ?? '';

            if (strlen($digitos) !== 11 && strlen($digitos) !== 14) {
                continue;
            }

            // O placeholder aceito é o de dígitos repetidos, que não existe: ele
            // não é um documento, é a ausência de um.
            if (preg_match('/^(\d)\1+$/', $digitos) === 1) {
                continue;
            }

            $violacoes[] = "{$onde} traz um documento que não é placeholder";
        }

        return $violacoes;
    }
}
