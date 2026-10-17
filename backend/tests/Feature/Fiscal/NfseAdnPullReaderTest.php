<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalFailure;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Nfse\NfseAdnPullReader;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NfseAdnPullReaderTest extends TestCase
{
    #[DataProvider('respostasInvalidas')]
    public function test_read_nao_converte_falhas_em_fila_vazia(string $body, int $status, FiscalFailure $failure): void
    {
        $this->expectException(FiscalException::class);

        try {
            resolve(NfseAdnPullReader::class)->read(new Response(new PsrResponse($status, [], $body)), 900);
        } catch (FiscalException $exception) {
            $this->assertSame($failure, $exception->failure);
            $this->assertStringNotContainsString('detalhe-sensivel', $exception->getMessage());

            throw $exception;
        }
    }

    #[DataProvider('respostasInvalidas')]
    public function test_read_one_nao_converte_falhas_em_ausencia(string $body, int $status, FiscalFailure $failure): void
    {
        $this->expectException(FiscalException::class);

        try {
            resolve(NfseAdnPullReader::class)->readOne(new Response(new PsrResponse($status, [], $body)));
        } catch (FiscalException $exception) {
            $this->assertSame($failure, $exception->failure);
            $this->assertStringNotContainsString('detalhe-sensivel', $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * @return iterable<string, array{string, int, FiscalFailure}>
     */
    public static function respostasInvalidas(): iterable
    {
        $vazio = ['StatusProcessamento' => 'NENHUM_DOCUMENTO_LOCALIZADO', 'LoteDFe' => [], 'Erros' => []];
        $comDocumento = ['StatusProcessamento' => 'DOCUMENTOS_LOCALIZADOS', 'LoteDFe' => [['NSU' => 901, 'ArquivoXml' => '<invalido/>']]];

        foreach ([401 => FiscalFailure::Unauthorized, 403 => FiscalFailure::Unauthorized, 429 => FiscalFailure::Upstream, 500 => FiscalFailure::Upstream] as $status => $failure) {
            yield "HTTP {$status} JSON generico" => [json_encode(['message' => 'indisponivel'], JSON_THROW_ON_ERROR), $status, $failure];
            yield "HTTP {$status} envelope vazio" => [json_encode($vazio, JSON_THROW_ON_ERROR), $status, $failure];
            yield "HTTP {$status} envelope com documento" => [json_encode($comDocumento, JSON_THROW_ON_ERROR), $status, $failure];
        }

        foreach ([200, 404, 500] as $status) {
            $failure = $status === 500 ? FiscalFailure::Upstream : FiscalFailure::Rejected;

            yield "HTTP {$status} Erros de negocio" => [json_encode(['Erros' => [['Descricao' => 'detalhe-sensivel']]], JSON_THROW_ON_ERROR), $status, $failure];
            yield "HTTP {$status} Erros no envelope vazio" => [json_encode(array_replace($vazio, ['Erros' => [['Descricao' => 'detalhe-sensivel']]]), JSON_THROW_ON_ERROR), $status, $failure];
        }

        yield 'Erros junto de documentos' => [json_encode($comDocumento + ['Erros' => [['Descricao' => 'detalhe-sensivel']]], JSON_THROW_ON_ERROR), 200, FiscalFailure::Rejected];

        foreach ([200, 404] as $status) {
            foreach ([
                'objeto generico' => ['message' => 'indisponivel'],
                'status desconhecido' => array_replace($vazio, ['StatusProcessamento' => 'DESCONHECIDO']),
                'status desconhecido com itens' => array_replace($comDocumento, ['StatusProcessamento' => 'DESCONHECIDO']),
                'status ausente' => ['LoteDFe' => []],
                'lote ausente' => ['StatusProcessamento' => 'NENHUM_DOCUMENTO_LOCALIZADO'],
                'lote nulo' => array_replace($vazio, ['LoteDFe' => null]),
                'lote objeto' => array_replace($vazio, ['LoteDFe' => ['NSU' => 901]]),
                'erros malformados' => array_replace($vazio, ['Erros' => 'detalhe-sensivel']),
                'erros nulos' => array_replace($vazio, ['Erros' => null]),
                'status nulo' => array_replace($vazio, ['StatusProcessamento' => null]),
                'status nao escalar' => array_replace($vazio, ['StatusProcessamento' => ['NENHUM_DOCUMENTO_LOCALIZADO']]),
                'documentos sem itens' => array_replace($comDocumento, ['LoteDFe' => []]),
                'ausencia com itens' => array_replace($vazio, ['LoteDFe' => $comDocumento['LoteDFe']]),
                'item nao objeto' => array_replace($comDocumento, ['LoteDFe' => ['detalhe-sensivel']]),
                'lista direta' => $comDocumento['LoteDFe'],
                'lista vazia' => [],
            ] as $name => $body) {
                yield "HTTP {$status} {$name}" => [json_encode($body, JSON_THROW_ON_ERROR), $status, FiscalFailure::Rejected];
            }

            yield "HTTP {$status} lote objeto vazio" => ['{"StatusProcessamento":"NENHUM_DOCUMENTO_LOCALIZADO","LoteDFe":{},"Erros":[]}', $status, FiscalFailure::Rejected];
            yield "HTTP {$status} erros objeto vazio" => ['{"StatusProcessamento":"NENHUM_DOCUMENTO_LOCALIZADO","LoteDFe":[],"Erros":{}}', $status, FiscalFailure::Rejected];
            yield "HTTP {$status} objeto vazio" => ['{}', $status, FiscalFailure::Rejected];
            yield "HTTP {$status} nao JSON" => ['<html>indisponivel</html>', $status, FiscalFailure::Rejected];
        }

        yield '404 com documentos nao e ausencia' => [json_encode($comDocumento, JSON_THROW_ON_ERROR), 404, FiscalFailure::Rejected];
    }

    #[DataProvider('ausenciasReconhecidas')]
    public function test_ausencia_explicita_preserva_posicao_e_consulta_pontual_nula(string $fixture, int $status): void
    {
        $reader = resolve(NfseAdnPullReader::class);
        $response = $this->fixtureResponse($fixture, $status);
        $result = $reader->read($response, 900);

        $this->assertSame([], $result->documents);
        $this->assertSame([], $result->failures);
        $this->assertSame(900, $result->lastNsu);
        $this->assertFalse($result->mayAdoptPosition);
        $this->assertFalse($result->more);
        $this->assertSame(FiscalFailure::NoDocuments, $result->failure);
        $this->assertNotNull($result->blockedUntil);
        $this->assertTrue($result->blockedUntil->isAfter(now()->addMinutes(50)));
        $this->assertNull($reader->readOne($response));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function ausenciasReconhecidas(): iterable
    {
        yield 'fim de fila local hipotetico' => ['fila-vazia-hipotese.json', 200];
        yield '404 local hipotetico' => ['posicao-nao-localizada-404-hipotese.json', 404];
    }

    public function test_o_lote_real_preserva_documentos_e_posicao_devolvida(): void
    {
        $reader = resolve(NfseAdnPullReader::class);
        $response = $this->fixtureResponse('lote-real.json', 200);
        $itens = $response->json('LoteDFe');
        $result = $reader->read($response, 0);

        $this->assertCount(count($itens), $result->documents);
        $this->assertSame([], $result->failures);
        $this->assertSame(max(array_column($itens, 'NSU')), $result->lastNsu);
        $this->assertTrue($result->mayAdoptPosition);
        $this->assertNull($result->blockedUntil);
        $this->assertNull($result->failure);
        $this->assertSame($itens[0]['NSU'], $reader->readOne($response)?->nsu);
    }

    public function test_entrada_corrompida_segura_posicao_e_nao_vira_ausencia_pontual(): void
    {
        $reader = resolve(NfseAdnPullReader::class);
        $body = json_encode([
            'StatusProcessamento' => 'DOCUMENTOS_LOCALIZADOS',
            'LoteDFe' => [['NSU' => 901, 'ArquivoXml' => '<invalido/>']],
        ], JSON_THROW_ON_ERROR);
        $response = new Response(new PsrResponse(200, [], $body));
        $result = $reader->read($response, 900);

        $this->assertSame([], $result->documents);
        $this->assertCount(1, $result->failures);
        $this->assertSame(901, $result->failures[0]->nsu);
        $this->assertSame(901, $result->lastNsu);
        $this->assertFalse($result->mayAdoptPosition);
        $this->assertNull($result->failure);
        $this->assertNull($result->blockedUntil);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('entrada que não pôde ser lida na posição 901');

        $reader->readOne($response);
    }

    private function fixtureResponse(string $name, int $status): Response
    {
        $body = file_get_contents(base_path("tests/Fixtures/fiscal/nfse-adn/{$name}"));
        $this->assertIsString($body);

        return new Response(new PsrResponse($status, [], $body));
    }
}
