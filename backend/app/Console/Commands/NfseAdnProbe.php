<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use App\Services\Fiscal\Nfse\NfseAdnConnector;
use App\Services\Fiscal\Nfse\NfseAdnPullReader;
use App\Services\Fiscal\Nfse\NfseAdnTransport;
use App\Services\Fiscal\Support\DocZipDecoder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * ⚠️ Comando de canário, e não caminho quente: um GET por execução, síncrono,
 * sem fila, sem escrita em `fiscal_documents` nem em `fiscal_cursors`.
 *
 * O `fiscal:nfse-probe` existe para o loop de debug do change
 * `add-nfse-adn-capture`: o shape do JSON da ADN contribuintes ainda não foi
 * observado em resposta real, e iterar parse/transporte pela fila seria
 * martelar o serviço nacional sem observabilidade. Ele faz **uma** chamada ao
 * ADN para **um** cliente e imprime só metadados seguros.
 *
 * O que sai na saída: status HTTP, campos de topo do JSON, contagem de itens,
 * NSU e schema por item — e, em caso de falha, a classe da exceção com a
 * mensagem dela, que este módulo mantém livre de material de certificado. O
 * que **não** sai, em nenhum caso: XML bruto, payload de documento, senha,
 * PFX ou token. A chave de acesso aparece no log de erro do transporte e é
 * identificador fiscal público; tudo o mais é resumo.
 *
 * Com `--save-fixture`, grava o JSON bruto **anonimizado** em
 * `tests/Fixtures/fiscal/nfse-adn/` — CNPJ, CPF, nomes e endereços redigidos,
 * payload decodificado e redigido — para alimentar os testes `Http::fake()`
 * e a confirmação do contrato.
 */
final class NfseAdnProbe extends Command
{
    protected $signature = 'fiscal:nfse-probe {--client=} {--nsu=0} {--save-fixture}';

    protected $description = 'Faz uma consulta única de debug à ADN NFS-e para um cliente, imprimindo só metadados seguros';

    /**
     * Elementos do XML do documento cujo conteúdo identifica pessoa — os dois
     * lados da nota e quem a assina ou autoriza. O canário observou também o
     * `CNPJAutor` do `pedRegEvento`, que a lista de hipótese não cobria.
     */
    private const XML_SENSIVEL = ['CNPJ', 'CPF', 'CPFCNPJ', 'CNPJAutor', 'CPFAutor', 'xNome', 'xFant', 'xLgr', 'xCpl', 'xBairro', 'xMun', 'email', 'fone', 'nro', 'CEP'];

    /**
     * Identificadores de veículo que a descrição do serviço traz em texto
     * livre (`xDescServ`): chassi, placa e RENAVAM identificam bem de
     * terceiro, e o dono é identificável por qualquer um dos três.
     */
    private const TEXTO_IDENTIFICADOR = ['CHASSI', 'PLACA', 'RENAVAM'];

    /**
     * Chaves do JSON cujo valor é dado de pessoa — a mesma lista, do lado do
     * corpo HTTP.
     */
    private const JSON_SENSIVEL = ['xNome', 'nome', 'razaoSocial', 'xFant', 'xLgr', 'logradouro', 'endereco', 'complemento', 'xCpl', 'xBairro', 'bairro', 'xMun', 'municipio', 'email', 'fone', 'cep', 'CEP'];

    public function handle(NfseAdnConnector $connector, NfseAdnTransport $transport): int
    {
        // A chave de instalação é consultada até aqui, no comando: o probe é
        // canário, e canário é ato de quem autorizou — a porta não se abre por
        // engano porque alguém rodou o comando errado.
        if (! config('fiscal.nfse_enabled', false)) {
            $this->error('A captura de NFS-e está desligada nesta instalação (fiscal.nfse_enabled). Nada foi consultado.');

            return self::FAILURE;
        }

        $client = Client::query()->capturable()->whereKey((int) $this->option('client'))->first();

        if ($client === null) {
            $this->error('Cliente inexistente ou não capturável (sem certificado A1 vigente com senha). Nada foi consultado.');

            return self::FAILURE;
        }

        $nsu = (int) $this->option('nsu');
        $url = $connector->urlOf($nsu);

        $this->info("Cliente: {$client->getKey()} (conta {$client->account_id})");
        $this->info("GET {$url}");

        try {
            $response = $transport->get($client, $url);
        } catch (FiscalRequestNotSent $exception) {
            $this->error(class_basename($exception).': '.$exception->getMessage());

            return self::FAILURE;
        } catch (FiscalException $exception) {
            $this->error(class_basename($exception).' ['.$exception->failure->value.']: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Status HTTP: '.$response->status());
        $this->line('Tamanho do corpo: '.strlen($response->body()).' bytes');

        $corpo = json_decode($response->body(), true);

        if (! is_array($corpo)) {
            $this->warn('O corpo não é um JSON de negócio — é isto que o transporte devolveu antes do parse.');
        } else {
            $this->imprimeOsCamposDeTopo($corpo);
            $this->imprimeOsItens(resolve(NfseAdnPullReader::class)->itensDe($corpo));
        }

        if ($this->option('save-fixture') && is_array($corpo)) {
            $this->salvaFixture($corpo, $nsu);
        }

        return self::SUCCESS;
    }

    /**
     * Os campos de topo do JSON, com valores escalares truncados — é a primeira
     * coisa que o probe precisa publicar para que o contrato saia da hipótese.
     *
     * @param  array<string, mixed>|list<mixed>  $corpo
     */
    private function imprimeOsCamposDeTopo(array $corpo): void
    {
        $this->line('Campos de topo:');

        foreach ($corpo as $chave => $valor) {
            if (is_array($valor)) {
                $forma = array_is_list($valor) ? 'lista' : 'objeto';

                $this->line("  {$chave}: ({$forma}, ".count($valor).' itens)');

                continue;
            }

            $texto = is_scalar($valor) ? (string) $valor : get_debug_type($valor);

            $this->line('  '.$chave.': '.mb_substr($texto, 0, 80));
        }
    }

    /**
     * A contagem e a posição de cada item — o dado que decide se a posição do
     * cursor é a maior NSU devolvida.
     *
     * @param  list<array{nsu: int, payload: string, schema: string}>  $itens
     */
    private function imprimeOsItens(array $itens): void
    {
        $this->line('Itens do lote: '.count($itens));

        foreach ($itens as $item) {
            $schema = $item['schema'] !== '' ? $item['schema'] : 'sem tipo declarado';

            $this->line("  NSU {$item['nsu']} — {$schema}");
        }
    }

    /**
     * O JSON bruto, anonimizado, no diretório de fixtures. O arquivo é a
     * matéria-prima dos testes `Http::fake()` e da confirmação do contrato —
     * e é por isso que ele carrega o NSU pedido no nome.
     */
    private function salvaFixture(array $corpo, int $nsu): void
    {
        $diretorio = base_path('tests/Fixtures/fiscal/nfse-adn');

        if (! is_dir($diretorio) && ! mkdir($diretorio, 0775, true)) {
            $this->error('Não foi possível criar o diretório de fixtures.');

            return;
        }

        $caminho = $diretorio.'/probe-nsu-'.$nsu.'-'.now()->format('YmdHis').'.json';

        $anonymizado = $this->anonimiza($corpo);

        file_put_contents(
            $caminho,
            json_encode($anonymizado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );

        $this->info('Fixture anonimizada gravada em '.$caminho);
    }

    /**
     * A anonimização, recursiva: chaves de pessoa viram marcador, valor que é
     * CNPJ ou CPF inteiro vira marcador, e payload que decodifica para XML é
     * redigido elemento a elemento. O que não é identificável segue intacto —
     * posição, schema, estrutura —, que é o que a fixture existe para
     * documentar.
     */
    private function anonimiza(mixed $valor): mixed
    {
        if (is_array($valor)) {
            $anonimizado = [];

            foreach ($valor as $chave => $interno) {
                $anonimizado[$chave] = in_array((string) $chave, self::JSON_SENSIVEL, true)
                    ? '«redigido»'
                    : $this->anonimiza($interno);
            }

            return $anonimizado;
        }

        if (! is_string($valor)) {
            return $valor;
        }

        // CNPJ e CPF inteiros como valor de campo. A chave de acesso segue
        // intacta de propósito: é identificador fiscal público, e é ela que
        // amarra a fixture ao documento.
        if (preg_match('/^\d{14}$/', $valor) === 1) {
            return '«CNPJ»';
        }

        if (preg_match('/^\d{11}$/', $valor) === 1) {
            return '«CPF»';
        }

        // O payload do documento: cru ou encapsulado, é XML de terceiro, e
        // nada dele entra anonimizado pela metade. O que decodifica é
        // redigido elemento a elemento; o que não decodifica é omitido — uma
        // fixture com dado pessoal disfarçado de base64 é pior que a ausência
        // dela.
        try {
            $xml = (new DocZipDecoder)->decode($valor);
        } catch (RuntimeException) {
            $xml = str_starts_with(ltrim($valor), '<') ? $valor : null;
        }

        if ($xml !== null) {
            return $this->redigeXml($xml);
        }

        return str_starts_with(ltrim($valor), '<') ? $this->redigeXml($valor) : $valor;
    }

    /**
     * O conteúdo dos elementos de pessoa, esvaziado — a estrutura do XML é o
     * que a fixture documenta, não os dados de quem nela aparece.
     */
    private function redigeXml(string $xml): string
    {
        $elementos = implode('|', self::XML_SENSIVEL);

        $redigido = (string) preg_replace(
            '/<('.$elementos.')>([^<]*)<\/\1>/u',
            '<$1>«redigido»</$1>',
            $xml,
        );

        // O valor do identificador de veículo segue a palavra-chave no mesmo
        // texto — o `xDescServ` observado traz `CHASSI <17>`, `PLACA <...>` e
        // `RENAVAM <11>` colados no resto da frase. O valor casado é só
        // alfanumérico: um `\S+` cru atravessaria `PLACA X</xInfComp>` sem
        // espaço nenhum e corromperia os tags seguintes.
        return (string) preg_replace(
            '/\b('.implode('|', self::TEXTO_IDENTIFICADOR).')\s+([A-Za-z0-9]+)/iu',
            '$1 «redigido»',
            $redigido,
        );
    }
}
