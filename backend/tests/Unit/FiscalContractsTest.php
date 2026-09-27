<?php

namespace Tests\Unit;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Models\Client;
use App\Services\Fiscal\Contracts\FailedEntry;
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
            stage: FiscalStage::Document,
            chave: str_repeat('1', 44),
            eventId: '',
            emitenteCnpj: null,
            destinatarioCnpj: null,
            valorTotal: null,
            digVal: null,
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

    public function test_pulled_document_carries_the_stage_of_the_distribution(): void
    {
        // A etapa atravessa o contrato porque o writer não pode descobrir-a
        // reparseando o XML: resumo e documento completo são a mesma chave de
        // acesso com o mesmo `event_id` vazio, e é a etapa que os separa na
        // chave composta da identidade.
        $document = new PulledDocument(
            model: FiscalModel::Nfe,
            kind: FiscalKind::Document,
            stage: FiscalStage::Summary,
            chave: str_repeat('1', 44),
            eventId: '',
            emitenteCnpj: null,
            destinatarioCnpj: null,
            valorTotal: null,
            digVal: null,
            nsu: 10,
            schema: 'resNFe_v1.01.xsd',
            emissaoAt: null,
            eventoOcorridoEmAt: null,
            xml: '<a/>',
        );

        $this->assertSame(FiscalStage::Summary, $document->stage);
        $this->assertSame(FiscalKind::Document, $document->kind);
        $this->assertSame('', $document->eventId);
    }

    public function test_pulled_document_carries_the_three_metadata_columns(): void
    {
        // São as três colunas que a camada de parse já extraiu e que a escrita
        // precisa: atravessam o contrato para ninguém reparsear o XML depois.
        $document = new PulledDocument(
            model: FiscalModel::Nfe,
            kind: FiscalKind::Document,
            stage: FiscalStage::Document,
            chave: str_repeat('1', 44),
            eventId: '',
            emitenteCnpj: '11222333000181',
            destinatarioCnpj: '99887766000199',
            valorTotal: '1500.75',
            digVal: null,
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

    public function test_pulled_document_carries_the_digest_of_the_xml(): void
    {
        // O `digVal` atravessa o contrato pelo mesmo motivo dos outros três
        // metadados: o writer compara o digest que chega com o que já gravou, e
        // comparar exigiria que ele reparseasse `$xml` — uma segunda fonte para
        // um valor que a camada de parse já resolveu.
        $document = new PulledDocument(
            model: FiscalModel::Nfe,
            kind: FiscalKind::Document,
            stage: FiscalStage::Document,
            chave: str_repeat('1', 44),
            eventId: '',
            emitenteCnpj: null,
            destinatarioCnpj: null,
            valorTotal: null,
            digVal: 'i2rqNaD6rqmCfhXHyTBf4xe1ImQ=',
            nsu: 10,
            schema: 'resNFe_v1.01.xsd',
            emissaoAt: null,
            eventoOcorridoEmAt: null,
            xml: '<a/>',
        );

        $this->assertSame('i2rqNaD6rqmCfhXHyTBf4xe1ImQ=', $document->digVal);
    }

    public function test_pulled_document_metadata_columns_are_null_when_the_xml_has_none(): void
    {
        // Caso comum em evento e em resumo: o valor some, e some como nulo — que
        // é o que a coluna nullable espera, não string vazia nem zero.
        $document = new PulledDocument(
            model: FiscalModel::Nfe,
            kind: FiscalKind::Event,
            stage: FiscalStage::Event,
            chave: str_repeat('1', 44),
            eventId: '110110-1',
            emitenteCnpj: null,
            destinatarioCnpj: null,
            valorTotal: null,
            digVal: null,
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
            stage: FiscalStage::Event,
            chave: str_repeat('1', 44),
            eventId: '110110-1',
            emitenteCnpj: null,
            destinatarioCnpj: null,
            valorTotal: null,
            digVal: null,
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
            mayAdoptPosition: true,
        );

        $this->assertSame([], $result->documents);
        $this->assertSame(200, $result->lastNsu);
        $this->assertFalse($result->more);
        $this->assertNull($result->blockedUntil);
        $this->assertTrue($result->mayAdoptPosition);
    }

    public function test_pull_result_distinguishes_a_lag_behind_from_an_unknown_max(): void
    {
        // `maxNsu` ausente na resposta não é a mesma coisa que `maxNsu` igual à
        // posição: o primeiro não diz nada sobre o tamanho da fila, o segundo
        // diz que ela está vazia.
        $atrasado = new PullResult(documents: [], lastNsu: 200, maxNsu: 900, more: true, blockedUntil: null, mayAdoptPosition: true);
        $desconhecido = new PullResult(documents: [], lastNsu: 200, maxNsu: null, more: false, blockedUntil: null, mayAdoptPosition: true);

        $this->assertSame(900, $atrasado->maxNsu);
        $this->assertTrue($atrasado->more);
        $this->assertNull($desconhecido->maxNsu);
        $this->assertFalse($desconhecido->more);
    }

    public function test_pull_result_carries_the_block_the_service_imposed(): void
    {
        $blockedUntil = CarbonImmutable::parse('2026-09-01T11:00:00-03:00');

        $result = new PullResult(documents: [], lastNsu: 0, maxNsu: null, more: false, blockedUntil: $blockedUntil, mayAdoptPosition: false);

        $this->assertSame($blockedUntil, $result->blockedUntil);
        $this->assertFalse($result->mayAdoptPosition);
    }

    public function test_pull_result_carries_the_entries_that_did_not_become_documents(): void
    {
        // Um lote com buraco não aborta o que deu para ler, e a posição não é
        // adotada: são as duas metades do mesmo requisito.
        $failures = [new FailedEntry(nsu: 199, schema: 'resNFe_v1.01.xsd', reason: 'DocZipDecoder não decodificou o payload comprimido.')];

        $result = new PullResult(
            documents: [],
            lastNsu: 200,
            maxNsu: 200,
            more: false,
            blockedUntil: null,
            mayAdoptPosition: false,
            failures: $failures,
        );

        $this->assertSame($failures, $result->failures);
        $this->assertSame(199, $result->failures[0]->nsu);
        $this->assertSame('resNFe_v1.01.xsd', $result->failures[0]->schema);
        $this->assertFalse($result->mayAdoptPosition);
    }

    public function test_pull_result_without_failures_is_the_default(): void
    {
        // `failures` é a única coisa com padrão: um lote que não teve recusa
        // nenhuma não precisa dizê-lo, e os construtores existentes continuam
        // válidos sem ela.
        $result = new PullResult(documents: [], lastNsu: 0, maxNsu: null, more: false, blockedUntil: null, mayAdoptPosition: true);

        $this->assertSame([], $result->failures);
    }

    public function test_a_failed_entry_names_its_position_and_its_step(): void
    {
        // Sem a chave, sem o `schema` do serviço e sem o motivo: quem reporta é
        // o conector, e o motivo é uma frase fixa que não repete o payload.
        $entry = new FailedEntry(nsu: 199, schema: 'resNFe_v1.01.xsd', reason: 'FiscalXmlMetadata rejeitou o documento decodificado.');

        $this->assertSame(199, $entry->nsu);
        $this->assertSame('resNFe_v1.01.xsd', $entry->schema);
        $this->assertSame('FiscalXmlMetadata rejeitou o documento decodificado.', $entry->reason);
    }

    public function test_pull_result_documents_are_kept_in_the_order_they_arrived(): void
    {
        $documents = [
            new PulledDocument(
                model: FiscalModel::Nfe,
                kind: FiscalKind::Document,
                stage: FiscalStage::Document,
                chave: str_repeat('1', 44),
                eventId: '',
                emitenteCnpj: '11222333000181',
                destinatarioCnpj: '99887766000199',
                valorTotal: '1500.75',
                digVal: null,
                nsu: 198,
                schema: 'resNFe_v1.01.xsd',
                emissaoAt: null,
                eventoOcorridoEmAt: null,
                xml: '<a/>',
            ),
            new PulledDocument(
                model: FiscalModel::Nfe,
                kind: FiscalKind::Event,
                stage: FiscalStage::Event,
                chave: str_repeat('1', 44),
                eventId: '110110-1',
                emitenteCnpj: null,
                destinatarioCnpj: null,
                valorTotal: null,
                digVal: null,
                nsu: 200,
                schema: 'resEvento_v1.01.xsd',
                emissaoAt: null,
                eventoOcorridoEmAt: null,
                xml: '<b/>',
            ),
        ];

        $result = new PullResult(documents: $documents, lastNsu: 200, maxNsu: 200, more: false, blockedUntil: null, mayAdoptPosition: true);

        $this->assertSame($documents, $result->documents);
        $this->assertSame([198, 200], array_column($result->documents, 'nsu'));
    }

    public function test_the_value_objects_are_immutable(): void
    {
        foreach ([PulledDocument::class, PullResult::class, FailedEntry::class] as $class) {
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
