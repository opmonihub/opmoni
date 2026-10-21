<?php

namespace Tests\Feature;

use App\Erp\Fiscal\Cte57DocumentBuilder;
use App\Erp\Fiscal\ErpFiscalCorrelationValidator;
use App\Erp\Fiscal\ErpFiscalEventRegistry;
use App\Erp\Fiscal\ErpFiscalPayloadBuilder;
use App\Erp\Fiscal\ErpFiscalSchemaValidator;
use App\Erp\Fiscal\ErpFiscalXmlSigner;
use App\Erp\Fiscal\Mdfe58DocumentBuilder;
use App\Erp\Fiscal\Nfce65DocumentBuilder;
use App\Erp\Fiscal\Nfe55DocumentBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpFiscalModelBuilderSchemaTest extends TestCase
{
    #[DataProvider('builderProvider')]
    public function test_each_typed_builder_produces_a_signed_xml_document_that_validates_against_its_pinned_request_schema(
        string $builderClass,
        string $model,
        array $source,
        string $rootElement,
    ): void {
        $builder = app($builderClass);
        $builder->validateSource($source);
        $unsigned = $builder->build($source);
        $this->assertStringContainsString('<'.$rootElement, $unsigned);

        [$certificate, $privateKey] = $this->signingIdentity();
        $signer = app(ErpFiscalXmlSigner::class);
        $signed = $signer->sign($unsigned, $privateKey, $certificate);

        $this->assertNotSame($unsigned, $signed);
        $this->assertTrue($signer->verify($signed, $certificate));
        $this->assertSelectedSchemaValid($signed, $model, 'request');
        if (in_array($model, ['57', '58'], true)) {
            app(ErpFiscalSchemaValidator::class)->assertEmbeddedRoadModalValid($signed, $model);
        }
        $signedDocument = new \DOMDocument;
        $this->assertTrue($signedDocument->loadXML($signed));
        $xpath = new \DOMXPath($signedDocument);
        if ($model === '65') {
            $qr = $xpath->query('//*[local-name()="qrCode"]')->item(0)?->textContent ?? '';
            $this->assertStringContainsString('|3|2', $qr);
            $this->assertStringNotContainsString('%7C', $qr);
        }
        if ($model === '57') {
            $this->assertSame(1, $xpath->query('//*[local-name()="ide"]/*[local-name()="toma3"]')->length);
            $this->assertSame(0, $xpath->query('//*[local-name()="infCte"]/*[local-name()="toma3"]')->length);
            $emit = $xpath->query('//*[local-name()="emit"]')->item(0);
            $elementNames = [];
            foreach ($emit?->childNodes ?? [] as $child) {
                if ($child instanceof \DOMElement) {
                    $elementNames[] = $child->localName;
                }
            }
            $this->assertSame(['CNPJ', 'IE', 'xNome', 'enderEmit', 'CRT'], $elementNames);
        }
        app(ErpFiscalCorrelationValidator::class)->assertDocumentMatches($signed, [
            'model' => $model,
            'access_key' => $source['identity']['access_key'],
            'issuer_cnpj' => $source['issuer']['cnpj'],
            'environment' => $source['identity']['environment'],
        ]);

        $processed = $this->processedXml($model, $signed, $source['identity']['access_key']);
        $this->assertSelectedSchemaValid($processed, $model, 'processed');
        if (in_array($model, ['57', '58'], true)) {
            app(ErpFiscalSchemaValidator::class)->assertEmbeddedRoadModalValid($processed, $model);
        }
        app(ErpFiscalCorrelationValidator::class)->assertProcessedMatches($processed, [
            'model' => $model,
            'access_key' => $source['identity']['access_key'],
            'issuer_cnpj' => $source['issuer']['cnpj'],
            'environment' => $source['identity']['environment'],
            'protocol' => '135260000000001',
        ]);
    }

    public function test_every_builder_rejects_missing_typed_fields_instead_of_defaulting_a_sale_payload(): void
    {
        foreach ([Nfe55DocumentBuilder::class, Nfce65DocumentBuilder::class, Cte57DocumentBuilder::class, Mdfe58DocumentBuilder::class] as $builderClass) {
            try {
                app($builderClass)->validateSource([]);
                $this->fail($builderClass.' must reject an incomplete source snapshot.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', trim($exception->getMessage()));
            }
        }

        foreach ([Cte57DocumentBuilder::class, Mdfe58DocumentBuilder::class] as $builderClass) {
            try {
                app($builderClass)->validateSource($this->saleOnlySnapshot());
                $this->fail($builderClass.' must reject an unrelated zero-total sale snapshot.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', trim($exception->getMessage()));
            }
        }
    }

    public function test_nfe_returns_and_cte_substitution_require_and_preserve_their_original_document_reference(): void
    {
        $nfe = self::nfeSnapshot('55');
        $nfe['identity']['purpose'] = 4;
        $nfe['payment'] = [['method' => '90', 'amount' => '0.00']];
        try {
            app(Nfe55DocumentBuilder::class)->validateSource($nfe);
            $this->fail('A return NF-e without an original document reference must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $originalNfe = (new ErpFiscalPayloadBuilder)->buildAccessKey([
            'uf' => 'SP', 'cnpj' => '11222333000181', 'model' => '55', 'series' => 1, 'number' => 122,
            'cnf' => '87654321', 'issued_at' => '2026-10-20T10:00:00-03:00',
        ]);
        $nfe['references'] = [['access_key' => $originalNfe]];
        app(Nfe55DocumentBuilder::class)->validateSource($nfe);
        $xml = app(Nfe55DocumentBuilder::class)->build($nfe);
        $this->assertStringContainsString('<refNFe>'.$originalNfe.'</refNFe>', $xml);

        $cte = self::cteSnapshot();
        $cte['transport_service']['cte_type'] = 3;
        try {
            app(Cte57DocumentBuilder::class)->validateSource($cte);
            $this->fail('A CT-e substitution without its original CT-e reference must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $originalCte = (new ErpFiscalPayloadBuilder)->buildAccessKey([
            'uf' => 'SP', 'cnpj' => '11222333000181', 'model' => '57', 'series' => 1, 'number' => 122,
            'cnf' => '87654321', 'issued_at' => '2026-10-20T10:00:00-03:00',
        ]);
        $cte['transport_service']['original_cte_access_key'] = $originalCte;
        app(Cte57DocumentBuilder::class)->validateSource($cte);
        $xml = app(Cte57DocumentBuilder::class)->build($cte);
        $this->assertStringContainsString('<chCte>'.$originalCte.'</chCte>', $xml);
    }

    public function test_cte_tomador_role_four_is_signed_as_its_typed_ide_choice(): void
    {
        $source = self::cteSnapshot();
        $source['transport_service']['taker'] = [
            'role' => 4, 'cnpj' => '11222333000181', 'legal_name' => 'Tomador independente',
            'address' => ['street' => 'Rua Tres', 'number' => '30', 'district' => 'Centro', 'city_code' => '3550308', 'city' => 'Sao Paulo', 'uf' => 'SP', 'postal_code' => '01003000'],
        ];
        $builder = app(Cte57DocumentBuilder::class);
        $xml = $builder->build($source);
        [$certificate, $privateKey] = $this->signingIdentity();
        $signed = app(ErpFiscalXmlSigner::class)->sign($xml, $privateKey, $certificate);
        $this->assertSelectedSchemaValid($signed, '57', 'request');
        app(ErpFiscalSchemaValidator::class)->assertEmbeddedRoadModalValid($signed, '57');
        $document = new \DOMDocument;
        $this->assertTrue($document->loadXML($signed));
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[local-name()="ide"]/*[local-name()="toma4"]')->length);
        $this->assertSame('4', $xpath->query('//*[local-name()="toma4"]/*[local-name()="toma"]')->item(0)?->textContent);
        $this->assertSame(0, $xpath->query('//*[local-name()="infCte"]/*[local-name()="toma4"]')->length);
    }

    public function test_cte_tomador_role_four_is_typed_inside_ide_and_requires_its_address(): void
    {
        $cte = self::cteSnapshot();
        $cte['transport_service']['taker'] = [
            'role' => 4, 'cnpj' => '11222333000181', 'legal_name' => 'Tomador independente', 'state_registration' => '110042490114',
            'address' => ['street' => 'Rua Tres', 'number' => '30', 'district' => 'Centro', 'city_code' => '3550308', 'city' => 'Sao Paulo', 'uf' => 'SP', 'postal_code' => '01003000'],
        ];
        $xml = app(Cte57DocumentBuilder::class)->build($cte);
        $document = new \DOMDocument;
        $this->assertTrue($document->loadXML($xml));
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[local-name()="ide"]/*[local-name()="toma4"]')->length);
        $this->assertSame('11222333000181', $xpath->query('//*[local-name()="toma4"]/*[local-name()="CNPJ"]')->item(0)?->textContent);
        $cte['transport_service']['taker']['address'] = [];
        $this->expectException(InvalidArgumentException::class);
        app(Cte57DocumentBuilder::class)->validateSource($cte);
    }

    public function test_issuer_identifier_and_invalid_money_are_rejected_without_normalization(): void
    {
        $source = self::nfeSnapshot('55');
        $source['issuer']['cnpj'] = '30.288.513/0001-00';
        try {
            app(Nfe55DocumentBuilder::class)->validateSource($source);
            $this->fail('The typed issuer identifier must already be the authoritative 14 digit CNPJ.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $source = self::nfeSnapshot('55');
        $source['totals']['products'] = 'bad';
        try {
            app(Nfe55DocumentBuilder::class)->validateSource($source);
            $this->fail('Malformed totals must not compare equal through an error sentinel.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    public function test_mdfe_rejects_cargo_unit_outside_the_supported_official_enum(): void
    {
        $source = self::mdfeSnapshot();
        $source['transport_manifest']['cargo']['unit'] = '03';
        $this->expectException(InvalidArgumentException::class);
        app(Mdfe58DocumentBuilder::class)->validateSource($source);
    }

    public function test_road_transport_requires_typed_rntrc_and_manifest_driver_and_vehicle_data(): void
    {
        $cte = self::cteSnapshot();
        unset($cte['transport_service']['rntrc']);
        try {
            app(Cte57DocumentBuilder::class)->validateSource($cte);
            $this->fail('Road CT-e without RNTRC must be rejected before it can be queued.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $manifest = self::mdfeSnapshot();
        unset($manifest['transport_manifest']['driver']['cpf']);
        try {
            app(Mdfe58DocumentBuilder::class)->validateSource($manifest);
            $this->fail('Road MDF-e without a driver CPF must be rejected before it can be queued.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $manifest = self::mdfeSnapshot();
        unset($manifest['transport_manifest']['vehicle']['plate']);
        $this->expectException(InvalidArgumentException::class);
        app(Mdfe58DocumentBuilder::class)->validateSource($manifest);
    }

    public function test_schema_validator_rejects_unpinned_model_and_malformed_or_incomplete_xml(): void
    {
        $validator = app(ErpFiscalSchemaValidator::class);

        foreach ([
            fn () => $validator->assertValid('<notFiscal/>', '55', 'request'),
            fn () => $validator->assertValid('<NFe/>', '55', 'processed'),
            fn () => $validator->assertValid('<NFe/>', '66', 'request'),
            fn () => $validator->assertValid('<NFe/>', '57', 'invalidation'),
        ] as $reject) {
            try {
                $reject();
                $this->fail('Malformed, incomplete, or unpinned fiscal XML must not pass schema validation.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_event_and_invalidation_registry_is_exactly_model_and_document_kind_scoped(): void
    {
        $registry = app(ErpFiscalEventRegistry::class);

        $accepted = [
            ['55', 'posted_sale', 'cancel'],
            ['55', 'posted_sale', 'correction'],
            ['55', 'return', 'cancel'],
            ['65', 'posted_sale', 'cancel'],
            ['65', 'posted_sale', 'substitution_cancel'],
            ['57', 'transport_service', 'cancel'],
            ['57', 'transport_service', 'correction'],
            ['58', 'transport_manifest', 'cancel'],
            ['58', 'transport_manifest', 'close'],
        ];
        foreach ($accepted as [$model, $kind, $event]) {
            $this->assertTrue($registry->supports($model, $kind, $event), $model.'/'.$kind.'/'.$event.' should be registered.');
        }

        $this->assertSame('110111', $registry->eventCode('55', 'posted_sale', 'cancel'));
        $this->assertSame('110110', $registry->eventCode('55', 'posted_sale', 'correction'));
        $this->assertSame('110112', $registry->eventCode('65', 'posted_sale', 'substitution_cancel'));
        $this->assertNull($registry->eventCode('58', 'transport_manifest', 'invalidation'));
        $this->assertTrue($registry->supportsInvalidation('55', 'posted_sale'));
        $this->assertTrue($registry->supportsInvalidation('65', 'posted_sale'));
        $this->assertFalse($registry->supports('55', 'posted_sale', 'invalidation'), 'A number-range invalidation is not a document event.');
        $this->assertFalse($registry->supportsInvalidation('57', 'transport_service'));
        $this->assertFalse($registry->supportsInvalidation('58', 'transport_manifest'));

        $rejected = [
            ['55', 'posted_sale', 'substitution_cancel'],
            ['65', 'posted_sale', 'correction'],
            ['57', 'transport_service', 'invalidation'],
            ['58', 'transport_manifest', 'correction'],
            ['58', 'transport_manifest', 'invalidation'],
            ['57', 'posted_sale', 'cancel'],
        ];
        foreach ($rejected as [$model, $kind, $event]) {
            $this->assertFalse($registry->supports($model, $kind, $event), $model.'/'.$kind.'/'.$event.' must not be inferred.');
        }
    }

    public function test_return_complement_and_transport_substitution_are_signed_new_documents_with_valid_references(): void
    {
        [$certificate, $privateKey] = $this->signingIdentity();
        $signer = app(ErpFiscalXmlSigner::class);
        $originalNfe = (new ErpFiscalPayloadBuilder)->buildAccessKey(['uf' => 'SP', 'cnpj' => '30288513000100', 'model' => '55', 'series' => 1, 'number' => 122, 'cnf' => '12345678', 'issued_at' => '2026-10-21T09:00:00-03:00']);
        foreach ([2, 4] as $purpose) {
            $source = self::nfeSnapshot('55');
            $source['identity']['purpose'] = $purpose;
            if ($purpose === 4) {
                $source['payment'] = [['method' => '90', 'amount' => '0.00']];
            }
            $source['references'] = [['access_key' => $originalNfe]];
            $signed = $signer->sign(app(Nfe55DocumentBuilder::class)->build($source), $privateKey, $certificate);
            $this->assertSelectedSchemaValid($signed, '55', 'request');
            $this->assertTrue($signer->verify($signed, $certificate));
        }
        $originalCte = (new ErpFiscalPayloadBuilder)->buildAccessKey(['uf' => 'SP', 'cnpj' => '30288513000100', 'model' => '57', 'series' => 1, 'number' => 122, 'cnf' => '12345678', 'issued_at' => '2026-10-21T09:00:00-03:00']);
        foreach ([1, 3] as $type) {
            $source = self::cteSnapshot();
            $source['transport_service']['cte_type'] = $type;
            $source['transport_service']['original_cte_access_key'] = $originalCte;
            $signed = $signer->sign(app(Cte57DocumentBuilder::class)->build($source), $privateKey, $certificate);
            $this->assertSelectedSchemaValid($signed, '57', 'request');
            $this->assertTrue($signer->verify($signed, $certificate));
            $this->assertStringContainsString($originalCte, $signed);
            if ($type === 3) {
                app(ErpFiscalSchemaValidator::class)->assertEmbeddedRoadModalValid($signed, '57');
            }
        }
    }

    public function test_nfce_offline_qr_uses_actual_day_numeric_recipient_type_and_same_certificate_signature(): void
    {
        [$certificate, $privateKey] = $this->signingIdentity();
        $source = self::nfeSnapshot('65');
        $source['identity']['issued_at'] = '2026-10-21T10:00:00-03:00';
        $source['identity']['contingency_started_at'] = $source['identity']['issued_at'];
        $source['identity']['contingency_justification'] = 'Falha de comunicacao com autorizador';
        $source['identity']['access_key'] = (new ErpFiscalPayloadBuilder)->buildAccessKey(['uf' => 'SP', 'cnpj' => $source['issuer']['cnpj'], 'model' => '65', 'series' => 1, 'number' => 123, 'cnf' => '12345678', 'tp_emis' => 9, 'issued_at' => $source['identity']['issued_at']]);
        $source['nfce_qr']['mode'] = 'offline';
        $source = app(ErpFiscalEventRegistry::class)->signOfflineQr($source, $privateKey);
        $unsigned = app(Nfce65DocumentBuilder::class)->build($source);
        $signed = app(ErpFiscalXmlSigner::class)->sign($unsigned, $privateKey, $certificate);
        $this->assertSelectedSchemaValid($signed, '65', 'request');
        $document = new \DOMDocument;
        $this->assertTrue($document->loadXML($signed));
        $xpath = new \DOMXPath($document);
        $qr = $xpath->query('//*[local-name()="qrCode"]')->item(0)->textContent;
        $parts = explode('|', substr($qr, strpos($qr, '?p=') + 3));
        $this->assertCount(8, $parts);
        $this->assertSame('21', $parts[3]);
        $this->assertSame('100.00', $parts[4]);
        $this->assertSame('1', $parts[5]);
        $this->assertSame($source['recipient']['cnpj'], $parts[6]);
        $this->assertSame(1, openssl_verify(implode('|', array_slice($parts, 0, 7)), base64_decode($parts[7], true), $certificate, OPENSSL_ALGO_SHA1));
    }

    public function test_all_registered_events_and_number_ranges_are_really_signed_and_schema_valid(): void
    {
        [$certificate, $privateKey] = $this->signingIdentity();
        $registry = app(ErpFiscalEventRegistry::class);
        $signer = app(ErpFiscalXmlSigner::class);
        foreach (['55' => ['cancel', 'correction'], '65' => ['cancel', 'substitution_cancel'], '57' => ['cancel', 'correction'], '58' => ['cancel', 'close']] as $model => $types) {
            $model = (string) $model;
            $documentSource = match ($model) {
                '55', '65' => self::nfeSnapshot($model), '57' => self::cteSnapshot(), '58' => self::mdfeSnapshot()
            };
            foreach ($types as $type) {
                $source = ['model' => $model, 'document_kind' => match ($model) {
                    '57' => 'transport_service', '58' => 'transport_manifest', default => 'posted_sale'
                },
                    'event_type' => $type, 'issuer' => $documentSource['issuer'], 'access_key' => $documentSource['identity']['access_key'],
                    'environment' => 'homologation', 'protocol' => '135260000000001', 'sequence' => 1, 'occurred_at' => '2026-10-21T10:01:00-03:00',
                    'justification' => 'Justificativa de cancelamento informada', 'correction' => 'Ajuste permitido de observacao complementar', 'legal_scope_attested' => true,
                    'corrections' => [['group' => 'compl', 'field' => 'xObs', 'value' => 'Observacao de transporte corrigida']],
                    'replacement_access_key' => (new ErpFiscalPayloadBuilder)->buildAccessKey(['uf' => 'SP', 'cnpj' => '30288513000100', 'model' => '65', 'series' => 1, 'number' => 124, 'cnf' => '12345678', 'issued_at' => '2026-10-21T10:00:00-03:00']), 'closed_on' => '2026-10-21', 'closing_uf' => 'SP', 'closing_city_code' => '3550308'];
                $unsigned = $registry->buildEvent($source);
                $signed = $signer->sign($unsigned, $privateKey, $certificate);
                $this->assertTrue($signer->verify($signed, $certificate), $model.'/'.$type);
                $this->assertSelectedSchemaValid($signed, $model, 'event', $type);
                $processed = $this->processedOperationXml($source, $signed);
                $this->assertSelectedSchemaValid($processed, $model, 'processed_event', $type);
                $registry->assertOperationResultMatches($processed, $signed, $source, '135260000000001');
                $this->assertTrue($signer->verify($processed, $certificate));
                $tampered = str_replace('Justificativa de cancelamento informada', 'Justificativa alterada depois de assinar', $signed);
                if ($tampered !== $signed) {
                    $this->assertFalse($signer->verify($tampered, $certificate));
                }
            }
        }
        foreach (['55', '65'] as $model) {
            $source = ['model' => $model, 'environment' => 'homologation', 'issuer' => self::nfeSnapshot($model)['issuer'], 'year' => 2026, 'series' => 1, 'number_from' => 201, 'number_to' => 205, 'justification' => 'Faixa sem documentos para inutilizacao'];
            $signed = $signer->sign($registry->buildInvalidation($source), $privateKey, $certificate);
            $this->assertTrue($signer->verify($signed, $certificate));
            app(ErpFiscalSchemaValidator::class)->assertValid($signed, $model, 'invalidation');
            $processed = $this->processedOperationXml($source, $signed);
            $this->assertSelectedSchemaValid($processed, $model, 'processed_invalidation');
            $registry->assertOperationResultMatches($processed, $signed, $source, '135260000000001');
            $this->assertTrue($signer->verify($processed, $certificate));
        }
    }

    public function test_signed_schema_valid_xml_with_number_fields_different_from_its_key_is_rejected(): void
    {
        $source = self::nfeSnapshot('55');
        [$certificate, $privateKey] = $this->signingIdentity();
        foreach (['serie' => '2', 'nNF' => '124', 'cDV' => ((int) substr($source['identity']['access_key'], -1) + 1) % 10] as $field => $value) {
            $dom = new \DOMDocument;
            $this->assertTrue($dom->loadXML(app(Nfe55DocumentBuilder::class)->build($source)));
            (new \DOMXPath($dom))->query('//*[local-name()="ide"]/*[local-name()="'.$field.'"]')->item(0)->textContent = (string) $value;
            $signed = app(ErpFiscalXmlSigner::class)->sign($dom->saveXML(), $privateKey, $certificate);
            $this->assertSelectedSchemaValid($signed, '55', 'request');
            $this->assertTrue(app(ErpFiscalXmlSigner::class)->verify($signed, $certificate));
            try {
                app(ErpFiscalCorrelationValidator::class)->assertDocumentMatches($signed, ['model' => '55', 'access_key' => $source['identity']['access_key'], 'issuer_cnpj' => $source['issuer']['cnpj'], 'environment' => 'homologation', 'series' => 1, 'number' => 123]);
                $this->fail('A valid signature cannot conceal different '.$field.' from the immutable key.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_malformed_nested_groups_and_missing_required_typed_fields_are_rejected_without_type_errors(): void
    {
        $cases = [];
        $source = self::nfeSnapshot('55');
        unset($source['items'][0]['ind_total']);
        $cases[] = [Nfe55DocumentBuilder::class, $source];
        $source = self::nfeSnapshot('55');
        $source['issuer']['address'] = 'invalid group';
        $cases[] = [Nfe55DocumentBuilder::class, $source];
        $source = self::cteSnapshot();
        $source['transport_service']['tax'] = 'invalid group';
        $cases[] = [Cte57DocumentBuilder::class, $source];
        $source = self::mdfeSnapshot();
        unset($source['transport_manifest']['total_nfe']);
        $cases[] = [Mdfe58DocumentBuilder::class, $source];
        foreach ($cases as [$builder, $source]) {
            try {
                app($builder)->validateSource($source);
                $this->fail('Incomplete model fields must fail typed validation.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    private function processedOperationXml(array $source, string $signed): string
    {
        $model = $source['model'];
        $isEvent = isset($source['event_type']);
        $namespace = match ($model) {
            '55', '65' => 'http://www.portalfiscal.inf.br/nfe', '57' => 'http://www.portalfiscal.inf.br/cte', '58' => 'http://www.portalfiscal.inf.br/mdfe'
        };
        $version = $isEvent ? match ($model) {
            '55', '65' => '1.00', '57' => '4.00', '58' => '3.00'
        } : '4.00';
        $root = $isEvent ? match ($model) {
            '55', '65' => 'procEventoNFe', '57' => 'procEventoCTe', '58' => 'procEventoMDFe'
        } : 'ProcInutNFe';
        $ret = $isEvent ? match ($model) {
            '55', '65' => 'retEvento', '57' => 'retEventoCTe', '58' => 'retEventoMDFe'
        } : 'retInutNFe';
        $request = new \DOMDocument;
        $this->assertTrue($request->loadXML($signed));
        $node = $request->documentElement->localName === 'envEvento' ? (new \DOMXPath($request))->query('//*[local-name()="evento"]')->item(0) : $request->documentElement;
        if ($isEvent) {
            $key = match ($model) {
                '55', '65' => 'chNFe', '57' => 'chCTe', '58' => 'chMDFe'
            };
            $fields = '<tpAmb>2</tpAmb><verAplic>TESTE</verAplic><cOrgao>35</cOrgao><cStat>135</cStat><xMotivo>Evento registrado e vinculado</xMotivo><'.$key.'>'.$source['access_key'].'</'.$key.'><tpEvento>'.app(ErpFiscalEventRegistry::class)->eventCode($model, $source['document_kind'], $source['event_type']).'</tpEvento><nSeqEvento>'.$source['sequence'].'</nSeqEvento><dhRegEvento>2026-10-21T10:00:01-03:00</dhRegEvento><nProt>135260000000001</nProt>';
        } else {
            $fields = '<tpAmb>2</tpAmb><verAplic>TESTE</verAplic><cStat>102</cStat><xMotivo>Inutilizacao homologada</xMotivo><cUF>35</cUF><ano>26</ano><CNPJ>'.$source['issuer']['cnpj'].'</CNPJ><mod>'.$model.'</mod><serie>'.$source['series'].'</serie><nNFIni>'.$source['number_from'].'</nNFIni><nNFFin>'.$source['number_to'].'</nNFFin><dhRecbto>2026-10-21T10:00:01-03:00</dhRecbto><nProt>135260000000001</nProt>';
        }

        return '<'.$root.' xmlns="'.$namespace.'" versao="'.$version.'">'.$request->saveXML($node).'<'.$ret.' versao="'.$version.'"><'.($isEvent ? 'infEvento' : 'infInut').'>'.$fields.'</'.($isEvent ? 'infEvento' : 'infInut').'></'.$ret.'></'.$root.'>';
    }

    /** @return array<string, array{0: class-string, 1: string, 2: array<string, mixed>, 3: string}> */
    public static function builderProvider(): array
    {
        return [
            'NF-e 55 posted sale' => [Nfe55DocumentBuilder::class, '55', self::nfeSnapshot('55'), 'NFe'],
            'NFC-e 65 posted sale' => [Nfce65DocumentBuilder::class, '65', self::nfeSnapshot('65'), 'NFe'],
            'CT-e 57 typed transport service' => [Cte57DocumentBuilder::class, '57', self::cteSnapshot(), 'CTe'],
            'MDF-e 58 typed manifest' => [Mdfe58DocumentBuilder::class, '58', self::mdfeSnapshot(), 'MDFe'],
        ];
    }

    /** @return array<string, mixed> */
    private static function nfeSnapshot(string $model): array
    {
        $key = (new ErpFiscalPayloadBuilder)->buildAccessKey([
            'uf' => 'SP', 'cnpj' => '30288513000100', 'model' => $model, 'series' => 1, 'number' => 123,
            'cnf' => '12345678', 'issued_at' => '2026-10-21T10:00:00-03:00',
        ]);

        return [
            'identity' => [
                'model' => $model,
                'environment' => 'homologation',
                'uf' => 'SP',
                'issued_at' => '2026-10-21T10:00:00-03:00',
                'series' => 1,
                'number' => 123,
                'access_key' => $key,
                'operation_nature' => 'Venda de mercadoria',
                'operation_type' => 1,
                'destination_type' => 1,
                'purpose' => 1,
                'final_consumer' => 1,
                'presence_indicator' => $model === '65' ? 1 : 0,
                'print_type' => $model === '65' ? 4 : 1,
            ],
            'issuer' => [
                'cnpj' => '30288513000100',
                'legal_name' => 'Emitente de teste LTDA',
                'state_registration' => '110042490114',
                'tax_regime' => 3,
                'address' => ['street' => 'Rua Um', 'number' => '10', 'district' => 'Centro', 'city_code' => '3550308', 'city' => 'Sao Paulo', 'uf' => 'SP', 'postal_code' => '01001000', 'country_code' => '1058', 'country' => 'Brasil'],
            ],
            'recipient' => [
                'cnpj' => '11222333000181',
                'legal_name' => 'Destinatario de teste LTDA',
                'state_registration_indicator' => 9,
                'address' => ['street' => 'Rua Dois', 'number' => '20', 'district' => 'Centro', 'city_code' => '3550308', 'city' => 'Sao Paulo', 'uf' => 'SP', 'postal_code' => '01002000', 'country_code' => '1058', 'country' => 'Brasil'],
            ],
            'items' => [[
                'code' => 'SKU-1', 'description' => 'Produto de teste', 'ncm' => '12345678', 'cfop' => '5102', 'unit' => 'UN', 'ind_total' => 1,
                'quantity' => '1.0000', 'unit_price' => '100.0000000000', 'gross_total' => '100.00', 'commercial_gtin' => 'SEM GTIN',
                'tax' => ['origin' => 0, 'icms_cst' => '00', 'icms_base' => '100.00', 'icms_rate' => '18.00', 'icms_value' => '18.00', 'pis_cst' => '07', 'pis_value' => '0.00', 'cofins_cst' => '07', 'cofins_value' => '0.00'],
            ]],
            'totals' => ['products' => '100.00', 'discount' => '0.00', 'freight' => '0.00', 'insurance' => '0.00', 'other' => '0.00', 'icms_base' => '100.00', 'icms' => '18.00', 'icms_desonerated' => '0.00', 'fcp' => '0.00', 'st_base' => '0.00', 'st' => '0.00', 'fcp_st' => '0.00', 'fcp_st_retained' => '0.00', 'ii' => '0.00', 'ipi' => '0.00', 'ipi_returned' => '0.00', 'pis' => '0.00', 'cofins' => '0.00', 'taxes_total' => '18.00', 'invoice' => '100.00'],
            'payment' => [['indicator' => 0, 'method' => '01', 'amount' => '100.00']],
            'freight' => ['modality' => 9],
            ...($model === '65' ? ['nfce_qr' => ['version' => 3, 'mode' => 'online', 'consultation_url' => 'https://test.invalid/consulta']] : []),
        ];
    }

    /** @return array<string, mixed> */
    private static function cteSnapshot(): array
    {
        $key = (new ErpFiscalPayloadBuilder)->buildAccessKey([
            'uf' => 'SP', 'cnpj' => '30288513000100', 'model' => '57', 'series' => 1, 'number' => 123,
            'cnf' => '12345678', 'issued_at' => '2026-10-21T10:00:00-03:00',
        ]);

        return [
            'identity' => ['model' => '57', 'environment' => 'homologation', 'uf' => 'SP', 'issued_at' => '2026-10-21T10:00:00-03:00', 'series' => 1, 'number' => 123, 'access_key' => $key],
            'issuer' => ['cnpj' => '30288513000100', 'legal_name' => 'Transportadora teste LTDA', 'state_registration' => '110042490114', 'tax_regime' => 3, 'address' => ['street' => 'Rua Um', 'number' => '10', 'district' => 'Centro', 'city_code' => '3550308', 'city' => 'Sao Paulo', 'uf' => 'SP', 'postal_code' => '01001000']],
            'transport_service' => [
                'service_type' => 0, 'cte_type' => 0, 'emission_type' => 1, 'operation_nature' => 'Prestacao de servico de transporte', 'cfop' => '5353',
                'sender' => ['cnpj' => '11222333000181', 'legal_name' => 'Remetente LTDA', 'state_registration' => '110042490114', 'city_code' => '3550308', 'uf' => 'SP', 'address' => ['street' => 'Rua Um', 'number' => '10', 'district' => 'Centro', 'city_code' => '3550308', 'city' => 'Sao Paulo', 'uf' => 'SP', 'postal_code' => '01001000']],
                'receiver' => ['cnpj' => '11222333000181', 'legal_name' => 'Destinatario LTDA', 'state_registration' => '110042490114', 'city_code' => '3304557', 'uf' => 'RJ', 'address' => ['street' => 'Rua Dois', 'number' => '20', 'district' => 'Centro', 'city_code' => '3304557', 'city' => 'Rio de Janeiro', 'uf' => 'RJ', 'postal_code' => '20000000']],
                'taker' => ['role' => 0, 'cnpj' => '11222333000181', 'legal_name' => 'Tomador LTDA', 'state_registration' => '110042490114', 'city_code' => '3550308', 'uf' => 'SP'],
                'route' => ['origin_city_code' => '3550308', 'origin_city' => 'Sao Paulo', 'destination_city_code' => '3304557', 'destination_city' => 'Rio de Janeiro', 'origin_uf' => 'SP', 'destination_uf' => 'RJ', 'pickup' => 0],
                'modal' => '01',
                'rntrc' => '12345678',
                'service_value' => '250.00', 'received_value' => '250.00', 'tax' => ['cst' => '00', 'base' => '250.00', 'rate' => '12.00', 'value' => '30.00'],
                'cargo' => ['description' => 'Mercadorias diversas', 'measure_type' => 'PESO BRUTO', 'quantity' => '1.0000', 'unit' => '01', 'value' => '250.00'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function mdfeSnapshot(): array
    {
        $key = (new ErpFiscalPayloadBuilder)->buildAccessKey([
            'uf' => 'SP', 'cnpj' => '30288513000100', 'model' => '58', 'series' => 1, 'number' => 123,
            'cnf' => '12345678', 'issued_at' => '2026-10-21T10:00:00-03:00',
        ]);

        $referencedNfeKey = (new ErpFiscalPayloadBuilder)->buildAccessKey([
            'uf' => 'SP', 'cnpj' => '11222333000181', 'model' => '55', 'series' => 1, 'number' => 123,
            'cnf' => '12345678', 'issued_at' => '2026-10-21T10:00:00-03:00',
        ]);

        return [
            'identity' => ['model' => '58', 'environment' => 'homologation', 'uf' => 'SP', 'issued_at' => '2026-10-21T10:00:00-03:00', 'series' => 1, 'number' => 123, 'access_key' => $key],
            'issuer' => ['cnpj' => '30288513000100', 'legal_name' => 'Transportadora teste LTDA', 'state_registration' => '110042490114', 'tax_regime' => 3, 'address' => ['street' => 'Rua Um', 'number' => '10', 'district' => 'Centro', 'city_code' => '3550308', 'city' => 'Sao Paulo', 'uf' => 'SP', 'postal_code' => '01001000']],
            'transport_manifest' => [
                'emission_type' => 1, 'modal' => 1, 'issuer_type' => 2, 'route_ufs' => ['SP', 'RJ'], 'total_cte' => 0, 'total_nfe' => 1, 'total_mdfe' => 0,
                'municipalities' => [['code' => '3550308', 'name' => 'Sao Paulo'], ['code' => '3304557', 'name' => 'Rio de Janeiro']],
                'rntrc' => '12345678',
                'vehicle' => ['plate' => 'ABC1D23', 'uf' => 'SP', 'registry' => '12345678', 'renavam' => '12345678901', 'tare' => 1000, 'capacity_kg' => 10000, 'road_type' => '02', 'trailer_type' => '00'],
                'driver' => ['name' => 'Motorista teste', 'cpf' => '12345678909'],
                'documents' => [['model' => '55', 'access_key' => $referencedNfeKey, 'municipality_code' => '3550308']],
                'cargo' => ['total_value' => '250.00', 'quantity' => '1.0000', 'unit' => '01', 'product_description' => 'Mercadorias diversas', 'product_type' => '01', 'ncm' => '12345678'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function saleOnlySnapshot(): array
    {
        return ['identity' => ['model' => '55'], 'issuer' => ['cnpj' => '30288513000100'], 'items' => [], 'totals' => ['invoice' => '0.00']];
    }

    /** @return array{0: string, 1: string} */
    private function signingIdentity(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => '30288513000100'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($certificate);
        $privatePem = '';
        $certificatePem = '';
        $this->assertTrue(openssl_pkey_export($key, $privatePem));
        $this->assertTrue(openssl_x509_export($certificate, $certificatePem));

        return [$certificatePem, $privatePem];
    }

    private function assertSelectedSchemaValid(string $xml, string $model, string $purpose, ?string $eventType = null): void
    {
        try {
            app(ErpFiscalSchemaValidator::class)->assertValid($xml, $model, $purpose, $eventType);
        } catch (InvalidArgumentException $exception) {
            $validator = app(ErpFiscalSchemaValidator::class);
            $schema = base_path('resources/xsd/erp-fiscal/'.$validator->entrypoint($model, $purpose, $eventType));
            $previous = libxml_use_internal_errors(true);
            libxml_clear_errors();
            $document = new \DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            $document->loadXML($xml, LIBXML_NONET);
            $document->schemaValidate($schema, LIBXML_NONET);
            $diagnostics = [];
            foreach (libxml_get_errors() as $error) {
                $message = preg_replace('/\b\d{6,}\b/', '[value]', trim($error->message)) ?? 'schema validation error';
                $diagnostics[] = ['line' => $error->line, 'column' => $error->column, 'message' => substr($message, 0, 240)];
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $this->fail($model.'/'.$purpose.'/'.($eventType ?? '').' '.$exception->getMessage().' Diagnostics: '.json_encode($diagnostics, JSON_THROW_ON_ERROR));
        }
    }

    private function processedXml(string $model, string $signedXml, string $accessKey): string
    {
        $protocolName = match ($model) {
            '55', '65' => 'protNFe', '57' => 'protCTe', '58' => 'protMDFe'
        };
        $keyName = match ($model) {
            '55', '65' => 'chNFe', '57' => 'chCTe', '58' => 'chMDFe'
        };
        $documentRoot = match ($model) {
            '55', '65' => 'nfeProc', '57' => 'cteProc', '58' => 'mdfeProc'
        };
        $namespace = match ($model) {
            '55', '65' => 'http://www.portalfiscal.inf.br/nfe', '57' => 'http://www.portalfiscal.inf.br/cte', '58' => 'http://www.portalfiscal.inf.br/mdfe'
        };
        $version = $model === '58' ? '3.00' : '4.00';
        $protocol = '<'.$protocolName.' versao="'.$version.'"><infProt><tpAmb>2</tpAmb><verAplic>TESTE</verAplic><'.$keyName.'>'.$accessKey.'</'.$keyName.'><dhRecbto>2026-10-21T10:00:01-03:00</dhRecbto><nProt>135260000000001</nProt><digVal>YWJjZA==</digVal><cStat>100</cStat><xMotivo>Autorizado o uso</xMotivo></infProt></'.$protocolName.'>';
        $documentXml = preg_replace('/^<\?xml[^>]*>\s*/', '', $signedXml);

        return '<'.$documentRoot.' xmlns="'.$namespace.'" versao="'.$version.'">'.$documentXml.$protocol.'</'.$documentRoot.'>';
    }
}
