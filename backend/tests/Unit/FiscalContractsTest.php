<?php

namespace Tests\Unit;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Models\Client;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

class FiscalContractsTest extends TestCase
{
    public function test_pulled_document_holds_its_identity(): void
    {
        $document = new PulledDocument(
            model: FiscalModel::Nfe,
            kind: FiscalKind::Document,
            chave: str_repeat('1', 44),
            eventId: '',
            emitenteCnpj: null,
            destinatarioCnpj: null,
            valorTotal: null,
            nsu: 10,
            schema: 'resNFe_v1.01.xsd',
            emissaoAt: null,
            eventoOcorridoEmAt: null,
            xml: '<a/>',
        );

        $this->assertSame(FiscalModel::Nfe, $document->model);
        $this->assertSame(10, $document->nsu);
        $this->assertSame('', $document->eventId);
    }

    public function test_pulled_document_carries_the_three_metadata_columns(): void
    {
        // São as três colunas que a camada de parse já extraiu e que a escrita
        // precisa: atravessam o contrato para ninguém reparsear o XML depois.
        $document = new PulledDocument(
            model: FiscalModel::Nfe,
            kind: FiscalKind::Document,
            chave: str_repeat('1', 44),
            eventId: '',
            emitenteCnpj: '11222333000181',
            destinatarioCnpj: '99887766000199',
            valorTotal: '1500.75',
            nsu: 10,
            schema: 'procNFe_v1.01.xsd',
            emissaoAt: null,
            eventoOcorridoEmAt: null,
            xml: '<a/>',
        );

        $this->assertSame('11222333000181', $document->emitenteCnpj);
        $this->assertSame('99887766000199', $document->destinatarioCnpj);
        $this->assertSame('1500.75', $document->valorTotal);
    }

    public function test_pulled_document_metadata_columns_are_null_when_the_xml_has_none(): void
    {
        // Caso comum em evento e em resumo: o valor some, e some como nulo — que
        // é o que a coluna nullable espera, não string vazia nem zero.
        $document = new PulledDocument(
            model: FiscalModel::Nfe,
            kind: FiscalKind::Event,
            chave: str_repeat('1', 44),
            eventId: '110110-1',
            emitenteCnpj: null,
            destinatarioCnpj: null,
            valorTotal: null,
            nsu: 11,
            schema: 'resEvento_v1.01.xsd',
            emissaoAt: null,
            eventoOcorridoEmAt: null,
            xml: '<a/>',
        );

        $this->assertNull($document->emitenteCnpj);
        $this->assertNull($document->destinatarioCnpj);
        $this->assertNull($document->valorTotal);
    }

    public function test_pulled_document_carries_the_xml_and_both_dates(): void
    {
        // As duas datas são distintas porque a posição não correlaciona com
        // tempo: um evento pode chegar antes da própria nota.
        $emissao = CarbonImmutable::parse('2026-09-01T10:00:00-03:00');
        $evento = CarbonImmutable::parse('2026-09-02T09:30:00-03:00');

        $document = new PulledDocument(
            model: FiscalModel::Nfe,
            kind: FiscalKind::Event,
            chave: str_repeat('1', 44),
            eventId: '110110-1',
            emitenteCnpj: null,
            destinatarioCnpj: null,
            valorTotal: null,
            nsu: 11,
            schema: 'procNFe_v1.01.xsd',
            emissaoAt: $emissao,
            eventoOcorridoEmAt: $evento,
            xml: '<a/>',
        );

        $this->assertSame(FiscalKind::Event, $document->kind);
        $this->assertSame('110110-1', $document->eventId);
        $this->assertSame('procNFe_v1.01.xsd', $document->schema);
        $this->assertSame($emissao, $document->emissaoAt);
        $this->assertSame($evento, $document->eventoOcorridoEmAt);
        $this->assertSame('<a/>', $document->xml);
    }

    public function test_pull_result_reports_its_cursor_and_block(): void
    {
        $result = new PullResult(
            documents: [],
            lastNsu: 200,
            maxNsu: 200,
            more: false,
            blockedUntil: null,
        );

        $this->assertSame([], $result->documents);
        $this->assertSame(200, $result->lastNsu);
        $this->assertFalse($result->more);
        $this->assertNull($result->blockedUntil);
    }

    public function test_pull_result_distinguishes_a_lag_behind_from_an_unknown_max(): void
    {
        // `maxNsu` ausente na resposta não é a mesma coisa que `maxNsu` igual à
        // posição: o primeiro não diz nada sobre o tamanho da fila, o segundo
        // diz que ela está vazia.
        $atrasado = new PullResult(documents: [], lastNsu: 200, maxNsu: 900, more: true, blockedUntil: null);
        $desconhecido = new PullResult(documents: [], lastNsu: 200, maxNsu: null, more: false, blockedUntil: null);

        $this->assertSame(900, $atrasado->maxNsu);
        $this->assertTrue($atrasado->more);
        $this->assertNull($desconhecido->maxNsu);
        $this->assertFalse($desconhecido->more);
    }

    public function test_pull_result_carries_the_block_the_service_imposed(): void
    {
        $blockedUntil = CarbonImmutable::parse('2026-09-01T11:00:00-03:00');

        $result = new PullResult(documents: [], lastNsu: 0, maxNsu: null, more: false, blockedUntil: $blockedUntil);

        $this->assertSame($blockedUntil, $result->blockedUntil);
    }

    public function test_pull_result_documents_are_kept_in_the_order_they_arrived(): void
    {
        $documents = [
            new PulledDocument(
                model: FiscalModel::Nfe,
                kind: FiscalKind::Document,
                chave: str_repeat('1', 44),
                eventId: '',
                emitenteCnpj: '11222333000181',
                destinatarioCnpj: '99887766000199',
                valorTotal: '1500.75',
                nsu: 198,
                schema: 'resNFe_v1.01.xsd',
                emissaoAt: null,
                eventoOcorridoEmAt: null,
                xml: '<a/>',
            ),
            new PulledDocument(
                model: FiscalModel::Nfe,
                kind: FiscalKind::Event,
                chave: str_repeat('1', 44),
                eventId: '110110-1',
                emitenteCnpj: null,
                destinatarioCnpj: null,
                valorTotal: null,
                nsu: 200,
                schema: 'resEvento_v1.01.xsd',
                emissaoAt: null,
                eventoOcorridoEmAt: null,
                xml: '<b/>',
            ),
        ];

        $result = new PullResult(documents: $documents, lastNsu: 200, maxNsu: 200, more: false, blockedUntil: null);

        $this->assertSame($documents, $result->documents);
        $this->assertSame([198, 200], array_column($result->documents, 'nsu'));
    }

    public function test_the_value_objects_are_immutable(): void
    {
        foreach ([PulledDocument::class, PullResult::class] as $class) {
            $reflection = new ReflectionClass($class);

            $this->assertTrue($reflection->isFinal(), $class);
            $this->assertTrue($reflection->isReadOnly(), $class);
        }
    }

    public function test_the_connector_interface_is_pinned_to_three_operations(): void
    {
        // A interface é o que três tarefas depois escrevem em cima dela, então a
        // forma fica fixada aqui: mudar uma assinatura quebraria o conector de
        // NF-e, o de CT-e e a captura ao mesmo tempo.
        $expected = [
            'source(): '.FiscalSource::class,
            'pull('.Client::class.', int, int): '.PullResult::class,
            'fetchByChave('.Client::class.', string): ?'.PulledDocument::class,
        ];

        $methods = array_map(
            static function (ReflectionMethod $method): string {
                $parameters = array_map(
                    static fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
                    $method->getParameters(),
                );

                return $method->getName().'('.implode(', ', $parameters).'): '.(string) $method->getReturnType();
            },
            array_values((new ReflectionClass(FiscalConnector::class))->getMethods()),
        );

        $this->assertSame($expected, $methods);
    }
}
