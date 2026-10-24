<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Erp\Fiscal\Contracts\ErpFiscalProvider;
use App\Erp\Fiscal\Contracts\ErpFiscalSubmittedRequestProvider;
use App\Erp\Fiscal\ErpFiscalArtifactStore;
use App\Erp\Fiscal\ErpFiscalAttemptAuthorization;
use App\Erp\Fiscal\ErpFiscalCertificateRevisionService;
use App\Erp\Fiscal\ErpFiscalCorrelationValidator;
use App\Erp\Fiscal\ErpFiscalEventRegistry;
use App\Erp\Fiscal\ErpFiscalPayloadBuilder;
use App\Erp\Fiscal\ErpFiscalProviderResult;
use App\Erp\Fiscal\ErpFiscalSchemaValidator;
use App\Erp\Fiscal\ErpFiscalXmlSigner;
use App\Erp\Fiscal\Nfce65DocumentBuilder;
use App\Erp\Fiscal\Nfe55DocumentBuilder;
use App\Erp\Fiscal\NfePhpErpFiscalProvider;
use App\Erp\Fiscal\NfePhpPrivateSoapCurl;
use App\Erp\Fiscal\NfePhpRequestGuardSoap;
use App\Erp\Fiscal\NfePhpSoapTransportFactory;
use App\Erp\Fiscal\NfePhpToolsFactory;
use App\Erp\Fiscal\UnavailableErpFiscalProvider;
use App\Jobs\ProcessErpFiscalAttempt;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Fiscal\ErpFiscalAttempt;
use App\Models\Erp\Fiscal\ErpFiscalCertificateRevision;
use App\Models\Erp\Fiscal\ErpFiscalConfiguration;
use App\Models\Erp\Fiscal\ErpFiscalConfigurationRevision;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Models\Erp\Fiscal\ErpFiscalInvalidation;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use NFePHP\Common\Certificate;
use NFePHP\Common\Soap\SoapCurl;
use NFePHP\Common\Soap\SoapInterface;
use NFePHP\NFe\Tools;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ErpNfePhpProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.release_mode' => 'production']);
    }

    public function test_event_id_padding_follows_each_models_schema_width(): void
    {
        foreach ([['57', '110111', '001', 'cancel'], ['58', '110112', '01', 'close'], ['58', '110112', '100', 'close']] as [$model, $eventCode, $expectedSequence, $eventType]) {
            $source = $this->eventDocumentSource($model);
            $source['model'] = $model;
            $source['environment'] = 'homologation';
            $source['sequence'] = (int) $expectedSequence;
            $source['document_kind'] = $model === '57' ? 'transport_service' : 'transport_manifest';
            $source['event_type'] = $eventType;
            $source['access_key'] = $source['identity']['access_key'];
            $source['protocol'] = '135260000000001';
            $source['occurred_at'] = '2026-10-21T10:01:00-03:00';
            $source['justification'] = 'Justificativa de cancelamento informada';
            $source['closed_on'] = '2026-10-21';
            $source['closing_uf'] = 'SP';
            $source['closing_city_code'] = '3550308';
            $source['identity']['environment'] = 'homologation';
            $xml = app(ErpFiscalEventRegistry::class)->buildEvent($source);
            $this->assertMatchesRegularExpression('/Id="ID'.preg_quote($eventCode, '/').preg_quote($source['access_key'], '/').preg_quote($expectedSequence, '/').'"/', $xml);
        }
    }

    public function test_pinned_nfephp_tools_submit_existing_nfce_qr_v3_without_csc_over_fake_soap(): void
    {
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NFC-e 65 posted sale'][2];
        $unsigned = app(Nfce65DocumentBuilder::class)->build($source);
        [$certificate, $privateKey, $certificatePem] = $this->certificateMaterial();
        $signed = app(ErpFiscalXmlSigner::class)->sign($unsigned, $privateKey, $certificatePem);

        $issuer = new ErpEstablishment(['name' => 'Emitente NFC-e teste', 'cnpj' => '30288513000100']);
        $revision = new ErpFiscalConfigurationRevision([
            'environment' => 'homologation',
            'configuration' => ['issuer' => ['uf' => 'SP']],
        ]);
        $attempt = new ErpFiscalAttempt(['action' => 'document:transmit', 'model' => '65']);
        $soap = new NfePhpFixtureSoap;
        $guard = new NfePhpRequestGuardSoap($soap, $attempt, ['identity' => ['access_key' => $source['identity']['access_key'], 'environment' => $source['identity']['environment']]], $signed, app(ErpFiscalXmlSigner::class), $certificatePem);
        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $privateKey, 'fixture-password'));

        $tools = app(NfePhpToolsFactory::class)->make('65', 'homologation', (string) $source['identity']['access_key'], $issuer, $revision, $pfx, 'fixture-password', $guard);
        $this->assertInstanceOf(Tools::class, $tools);

        $tools->sefazEnviaLote([$signed], str_pad('1', 15, '0', STR_PAD_LEFT), 1);

        $this->assertCount(1, $soap->calls);
        $this->assertStringContainsString('<NFe', $soap->calls[0]['request']);
        $this->assertSame($this->canonicalElement($signed, 'NFe'), $guard->submittedRequestXml());
        $this->assertStringContainsString('|3|2', $this->textElement($signed, 'qrCode'));
        $this->assertArrayNotHasKey('CSC', $tools->config == null ? [] : (array) $tools->config);
        $this->assertArrayNotHasKey('CSCid', (array) $tools->config);
    }

    public function test_pinned_nfephp_tools_can_consult_a_fixed_document_key_through_fake_soap(): void
    {
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NFC-e 65 posted sale'][2];
        $unsigned = app(Nfce65DocumentBuilder::class)->build($source);
        [, $privateKey, $certificatePem] = $this->certificateMaterial();
        $signed = app(ErpFiscalXmlSigner::class)->sign($unsigned, $privateKey, $certificatePem);

        $issuer = new ErpEstablishment(['name' => 'Emitente NFC-e teste', 'cnpj' => '30288513000100']);
        $revision = new ErpFiscalConfigurationRevision([
            'environment' => 'homologation',
            'configuration' => ['issuer' => ['uf' => 'SP']],
        ]);
        $attempt = new ErpFiscalAttempt(['action' => 'document:reconcile', 'model' => '65', 'environment' => 'homologation']);
        $soap = new NfePhpFixtureSoap;
        $guard = new NfePhpRequestGuardSoap($soap, $attempt, ['identity' => ['access_key' => $source['identity']['access_key'], 'environment' => $source['identity']['environment']]], $signed, app(ErpFiscalXmlSigner::class), $certificatePem);
        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($this->readCertificate($certificatePem), $pfx, $privateKey, 'fixture-password'));

        $tools = app(NfePhpToolsFactory::class)->make('65', 'homologation', (string) $source['identity']['access_key'], $issuer, $revision, $pfx, 'fixture-password', $guard);
        $response = $tools->sefazConsultaChave((string) $source['identity']['access_key'], 2);

        $this->assertIsString($response);
        $this->assertCount(1, $soap->calls);
        $this->assertStringContainsString('consSitNFe', $soap->calls[0]['request']);
        $this->assertStringContainsString((string) $source['identity']['access_key'], $soap->calls[0]['request']);
    }

    #[DataProvider('artifactPublicationProvider')]
    public function test_job_reconciles_uncertain_transmission_by_key_without_a_second_transmit(bool $failStorage): void
    {
        Storage::fake('local');
        [$source, $signed, $pfx] = $this->signedDocumentFixture('55');
        [$transmit, $document, $certificateRevision] = $this->persistBoundAttempt($source, $pfx, 'task20-test-password', $signed);
        $company = ErpCompany::query()->findOrFail($transmit->erp_company_id);
        $actor = User::query()->findOrFail($transmit->actor_user_id);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $actor->id, 'role' => 'owner']);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'erp_plan_id' => $plan->id, 'status' => 'active']);
        config(['erp.operating_writes_enabled' => true, 'erp.release_enabled' => true]);
        $transmit->update(['status' => 'pending', 'outbox_state' => 'new', 'dispatch_attempts' => 0]);
        $document->update(['status' => 'queued']);
        $soap = new NfePhpFixtureSoap;
        $soap->throwDuringSend = true;
        app()->instance(ErpFiscalProvider::class, $this->providerWithSoap($soap));

        app()->call([new ProcessErpFiscalAttempt($transmit->id), 'handle']);

        $this->assertSame('indeterminate', $transmit->fresh()->status);
        $this->assertSame('indeterminate', $document->fresh()->status);
        $this->assertCount(1, $soap->calls);
        $this->assertStringContainsString('<enviNFe', $soap->calls[0]['request']);

        $soap->throwDuringSend = false;
        $soap->responseXml = '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><nfeConsultaNFResponse><nfeConsultaNFResult><retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>2</tpAmb><cStat>217</cStat><xMotivo>Chave nao encontrada</xMotivo></retConsSitNFe></nfeConsultaNFResult></nfeConsultaNFResponse></soap:Body></soap:Envelope>';
        $reconcile = ErpFiscalAttempt::query()->create([
            'erp_company_id' => $transmit->erp_company_id,
            'erp_establishment_id' => $transmit->erp_establishment_id,
            'erp_fiscal_document_id' => $document->id,
            'erp_fiscal_configuration_revision_id' => $transmit->erp_fiscal_configuration_revision_id,
            'erp_fiscal_certificate_revision_id' => $certificateRevision->id,
            'action' => 'document:reconcile',
            'model' => '55',
            'environment' => 'homologation',
            'series' => $document->series,
            'number' => $document->number,
            'actor_user_id' => $transmit->actor_user_id,
            'idempotency_key' => 'task20-provider-reconcile-55',
            'request_sha256' => $transmit->request_sha256,
            'encrypted_source_snapshot' => $transmit->encrypted_source_snapshot,
            'schema_release' => $transmit->schema_release,
            'status' => 'pending',
            'outbox_state' => 'new',
            'dispatch_attempts' => 0,
            'available_at' => now()->subSecond(),
        ]);

        app()->call([new ProcessErpFiscalAttempt($reconcile->id), 'handle']);

        $this->assertSame('indeterminate', $reconcile->fresh()->status);
        $this->assertSame('indeterminate', $document->fresh()->status);
        $this->assertCount(2, $soap->calls);
        $this->assertStringContainsString('consSitNFe', $soap->calls[1]['request']);
        $this->assertStringContainsString($document->access_key, $soap->calls[1]['request']);
        $this->assertStringNotContainsString('<enviNFe', $soap->calls[1]['request']);

        $this->assertSame(1, count(array_filter($soap->calls, static fn (array $call): bool => str_contains($call['request'], '<enviNFe'))));
        $soap->responseXml = $this->consultationSoapResponse('55', $document->access_key, $this->textElement($signed, 'DigestValue'));
        $authorizedConsult = $reconcile->replicate();
        $authorizedConsult->fill(['status' => 'pending', 'outbox_state' => 'new', 'dispatch_attempts' => 0, 'lease_token' => null, 'leased_at' => null, 'completed_at' => null, 'idempotency_key' => 'task20-provider-authorized-consult-55'])->save();
        $filesystem = Storage::getFacadeRoot();
        if ($failStorage) {
            $disk = \Mockery::mock(FilesystemAdapter::class);
            $disk->shouldReceive('exists')->andReturn(false);
            $disk->shouldReceive('put')->once()->andReturn(false);
            Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        }
        app()->call([new ProcessErpFiscalAttempt($authorizedConsult->id), 'handle']);
        if ($failStorage) {
            Storage::swap($filesystem);
            $this->assertSame('artifact_pending', $authorizedConsult->fresh()->status);
            $this->assertSame('authorized', $document->fresh()->status);
            $this->assertFalse($authorizedConsult->fresh()->safe_result['artifact_ready']);
            $this->assertSame('authorized', $authorizedConsult->fresh()->safe_result['provider_outcome']);
            $this->assertNull($document->fresh()->storage_path);
            $this->assertFalse(app(ErpFiscalArtifactStore::class)->verifiedFiscalArtifactReady($document->fresh()));
            $repair = $authorizedConsult->replicate();
            $repair->fill(['status' => 'pending', 'outbox_state' => 'new', 'dispatch_attempts' => 0, 'lease_token' => null, 'completed_at' => null, 'idempotency_key' => 'task20-provider-artifact-repair'])->save();
            app()->call([new ProcessErpFiscalAttempt($repair->id), 'handle']);
            $authorizedConsult = $repair;
        }
        $this->assertSame('accepted', $authorizedConsult->fresh()->status, json_encode($authorizedConsult->fresh()->safe_result));
        $this->assertSame('authorized', $document->fresh()->status);
        $this->assertTrue($authorizedConsult->fresh()->safe_result['artifact_ready']);
        $this->assertTrue(app(ErpFiscalArtifactStore::class)->verifyIntegrity($document->fresh()));
        $this->assertTrue(app(ErpFiscalArtifactStore::class)->verifiedFiscalArtifactReady($document->fresh()));
        $this->assertCount($failStorage ? 4 : 3, $soap->calls);
        $this->assertStringContainsString($document->access_key, $soap->calls[2]['request']);
        $this->assertSame(1, count(array_filter($soap->calls, static fn (array $call): bool => str_contains($call['request'], '<enviNFe'))));

    }

    public static function artifactPublicationProvider(): array
    {
        return ['ready artifact' => [false], 'storage failure and repair' => [true]];
    }

    #[DataProvider('bindingMismatchProvider')]
    public function test_attempt_binding_rejects_foreign_tampered_replaced_revisions_and_intent_before_soap(string $case): void
    {
        [$source, $signed, $pfx] = $this->signedDocumentFixture('55');
        [$attempt, $document, $certificate] = $this->persistBoundAttempt($source, $pfx, 'task20-test-password', $signed);
        switch ($case) {
            case 'configuration hash':
                $attempt->configurationRevision->update(['configuration' => ['issuer' => ['uf' => 'RJ']]]);
                break;
            case 'certificate hash':
                $certificate->update(['certificate_fingerprint' => str_repeat('0', 64)]);
                break;
            case 'foreign configuration':
                $attempt->configurationRevision->configurationOwner->update(['erp_company_id' => ErpCompany::factory()->create()->id]);
                break;
            case 'foreign certificate':
                $certificate->certificate->update(['erp_company_id' => ErpCompany::factory()->create()->id]);
                break;
            case 'replaced configuration':
                $replacement = $attempt->configurationRevision->replicate();
                $replacement->revision = 2;
                $replacement->save();
                $attempt->erp_fiscal_configuration_revision_id = $replacement->id;
                break;
            case 'replaced certificate':
                $rotated = app(ErpFiscalCertificateRevisionService::class)->rotate($certificate->certificate, $this->alternatePkcs12('task20-test-password'), 'task20-test-password', $attempt->actor_user_id);
                $attempt->erp_fiscal_certificate_revision_id = $rotated->current_revision_id;
                break;
            case 'snapshot hash':
                $attempt->request_sha256 = str_repeat('0', 64);
                break;
            case 'model':
                $attempt->model = '65';
                break;
            case 'environment':
                $attempt->environment = 'production';
                break;
            case 'key':
                $document->access_key = str_repeat('1', 44);
                $document->save();
                break;
            case 'signed commercial intent':
                $altered = $source;
                $altered['items'][0]['description'] = 'Mercadoria substituida';
                $material = [];
                $this->assertTrue(openssl_pkcs12_read($pfx, $material, 'task20-test-password'));
                $signed = app(ErpFiscalXmlSigner::class)->sign(app(Nfe55DocumentBuilder::class)->build($altered), $material['pkey'], $material['cert']);
                break;
        }
        $attempt->save();
        $soap = new NfePhpFixtureSoap;
        try {
            $bound = $this->providerWithSoap($soap)->forAttempt($attempt->fresh(), $signed);
            $result = $bound->transmit($document->fresh(), [
                'signed_xml' => $signed, 'configuration_revision_id' => $attempt->erp_fiscal_configuration_revision_id,
                'certificate_revision_id' => $attempt->erp_fiscal_certificate_revision_id, 'schema_release' => $attempt->schema_release,
            ]);
            $this->assertSame('indeterminate', $result->outcome, $case);
        } catch (RuntimeException|\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertCount(0, $soap->calls, $case);
    }

    public static function bindingMismatchProvider(): array
    {
        return array_combine($cases = ['configuration hash', 'certificate hash', 'foreign configuration', 'foreign certificate', 'replaced configuration', 'replaced certificate', 'snapshot hash', 'model', 'environment', 'key', 'signed commercial intent'], array_map(static fn (string $case): array => [$case], $cases));
    }

    #[DataProvider('consultationResponseMismatchProvider')]
    public function test_consultation_never_authorizes_a_response_for_another_identity(string $case): void
    {
        [$source, $signed, $pfx] = $this->signedDocumentFixture('55');
        [$attempt, $document] = $this->persistBoundAttempt($source, $pfx, 'task20-test-password', $signed, 'document:consult');
        $receipt = in_array($case, ['receipt', 'missing receipt'], true) ? '123456789012345' : null;
        $attempt->update(['provider_receipt' => $receipt]);
        $response = $this->consultationSoapResponse('55', $document->access_key, $this->textElement($signed, 'DigestValue'), $receipt);
        $response = match ($case) {
            'key' => str_replace($document->access_key, str_repeat('1', 44), $response),
            'environment' => str_replace('<tpAmb>2</tpAmb>', '<tpAmb>1</tpAmb>', $response),
            'outer environment' => preg_replace('/<tpAmb>2<\/tpAmb>/', '<tpAmb>1</tpAmb>', $response, 1),
            'model' => str_replace(['retConsSitNFe', 'protNFe'], ['retConsSitCTe', 'protCTe'], $response),
            'receipt' => str_replace($receipt, '999999999999999', $response),
            'missing receipt' => str_replace('<nRec>'.$receipt.'</nRec>', '', $response),
            'receipt response to key request' => str_replace('retConsSitNFe', 'retConsReciNFe', $response),
            'malformed' => '<invalid',
        };
        $soap = new NfePhpFixtureSoap;
        $soap->responseXml = $response;
        $result = $this->providerWithSoap($soap)->forAttempt($attempt->fresh(), $signed)->consult($document);
        $this->assertSame('indeterminate', $result->outcome, $case);
        $this->assertNull($result->xml);
        $this->assertCount(1, $soap->calls);
    }

    public static function consultationResponseMismatchProvider(): array
    {
        return array_combine($cases = ['key', 'environment', 'outer environment', 'model', 'receipt', 'missing receipt', 'receipt response to key request', 'malformed'], array_map(static fn (string $case): array => [$case], $cases));
    }

    public function test_default_provider_is_unavailable_and_only_explicit_opt_in_resolves_nfephp(): void
    {
        $this->assertInstanceOf(UnavailableErpFiscalProvider::class, app(ErpFiscalProvider::class));
        config(['erp.fiscal_provider' => 'nfephp']);
        $this->assertInstanceOf(NfePhpErpFiscalProvider::class, app(ErpFiscalProvider::class));
        config(['erp.fiscal_provider' => 'unavailable']);
        $this->assertInstanceOf(UnavailableErpFiscalProvider::class, app(ErpFiscalProvider::class));
    }

    #[DataProvider('consultationProvider')]
    public function test_real_provider_consults_fixed_identity_and_returns_verified_processed_xml(string $model, ?string $receipt): void
    {
        [$source, $signed, $pfx] = $this->signedDocumentFixture($model);
        [$attempt, $document] = $this->persistBoundAttempt($source, $pfx, 'task20-test-password', $signed, 'document:consult');
        $attempt->update(['provider_receipt' => $receipt]);
        $soap = new NfePhpFixtureSoap;
        $soap->responseXml = $this->consultationSoapResponse($model, $document->access_key, $this->textElement($signed, 'DigestValue'), $receipt);
        $bound = $this->providerWithSoap($soap)->forAttempt($attempt->fresh(), $signed);

        $result = $bound->consult($document);

        $this->assertSame('authorized', $result->outcome);
        $this->assertSame('135260000000001', $result->protocol);
        $this->assertSame($receipt, $result->receipt);
        $this->assertCount(1, $soap->calls);
        $this->assertStringContainsString($receipt ?? $document->access_key, $soap->calls[0]['request']);
        $this->assertStringContainsString($receipt === null ? 'consSit' : 'consReci', $soap->calls[0]['request']);
        app(ErpFiscalSchemaValidator::class)->assertValid($result->xml, $model, 'processed');
        app(ErpFiscalCorrelationValidator::class)->assertProcessedMatches($result->xml, [
            'model' => $model, 'access_key' => $document->access_key,
            'issuer_cnpj' => $source['issuer']['cnpj'], 'environment' => 'homologation', 'protocol' => $result->protocol,
        ]);
        $certificate = [];
        $this->assertTrue(openssl_pkcs12_read($pfx, $certificate, 'task20-test-password'));
        $this->assertTrue(app(ErpFiscalXmlSigner::class)->verify($signed, $certificate['cert']));
        $root = match ($model) {
            '55', '65' => 'NFe', '57' => 'CTe', '58' => 'MDFe'
        };
        $this->assertSame($this->canonicalElement($signed, $root), $this->canonicalElement($result->xml, $root));
        $this->assertTrue(app(ErpFiscalXmlSigner::class)->verify($result->xml, $certificate['cert']));
    }

    public static function consultationProvider(): array
    {
        return [
            'NF-e key' => ['55', null], 'NFC-e key' => ['65', null], 'CT-e key' => ['57', null], 'MDF-e key' => ['58', null],
            'NF-e receipt' => ['55', '123456789012345'], 'NFC-e receipt' => ['65', '123456789012345'], 'MDF-e receipt' => ['58', '123456789012345'],
        ];
    }

    private function consultationSoapResponse(string $model, string $key, string $digest, ?string $receipt = null): string
    {
        $response = $this->authorizedSoapResponse($model, $key, '100', $digest);
        $protocolName = match ($model) {
            '55', '65' => 'protNFe', '57' => 'protCTe', '58' => 'protMDFe'
        };
        $protocol = $this->canonicalElement($response, $protocolName);
        $namespace = match ($model) {
            '55', '65' => 'http://www.portalfiscal.inf.br/nfe', '57' => 'http://www.portalfiscal.inf.br/cte', '58' => 'http://www.portalfiscal.inf.br/mdfe'
        };
        $root = match ($model) {
            '55', '65' => $receipt === null ? 'retConsSitNFe' : 'retConsReciNFe', '57' => 'retConsSitCTe', '58' => $receipt === null ? 'retConsSitMDFe' : 'retConsReciMDFe'
        };
        $extra = in_array($model, ['55', '65'], true) ? '<dhRecbto>2026-10-21T10:00:01-03:00</dhRecbto>'.($receipt === null ? '<chNFe>'.$key.'</chNFe>' : '') : '';
        $payload = '<'.$root.' xmlns="'.$namespace.'" versao="'.($model === '58' ? '3.00' : '4.00').'"><tpAmb>2</tpAmb><verAplic>TESTE</verAplic>'
            .($receipt === null ? '' : '<nRec>'.$receipt.'</nRec>').'<cStat>'.($receipt === null ? '100' : '104').'</cStat><xMotivo>Consulta processada</xMotivo><cUF>35</cUF>'.$extra.$protocol.'</'.$root.'>';

        return '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'.$payload.'</soap:Body></soap:Envelope>';
    }

    public function test_explicit_reconcile_attempt_consults_even_without_an_earlier_transmission(): void
    {
        [$source, $signed, $pfx] = $this->signedDocumentFixture('55');
        [$attempt, $document] = $this->persistBoundAttempt($source, $pfx, 'task20-test-password', $signed, 'document:reconcile');
        ErpCompanyMembership::factory()->create(['erp_company_id' => $attempt->erp_company_id, 'user_id' => $attempt->actor_user_id, 'role' => 'owner']);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        ErpSubscription::factory()->create(['erp_company_id' => $attempt->erp_company_id, 'erp_plan_id' => $plan->id, 'status' => 'active']);
        config(['erp.operating_writes_enabled' => true, 'erp.release_enabled' => true]);
        $attempt->update(['status' => 'pending', 'outbox_state' => 'new', 'dispatch_attempts' => 0]);
        $document->update(['status' => 'queued']);
        $soap = new NfePhpFixtureSoap;
        $soap->responseXml = $this->consultationSoapResponse('55', $document->access_key, $this->textElement($signed, 'DigestValue'));
        app()->instance(ErpFiscalProvider::class, $this->providerWithSoap($soap));

        app()->call([new ProcessErpFiscalAttempt($attempt->id), 'handle']);

        $this->assertSame('accepted', $attempt->fresh()->status);
        $this->assertCount(1, $soap->calls);
        $this->assertStringContainsString('consSitNFe', $soap->calls[0]['request']);
        $this->assertStringNotContainsString('<enviNFe', $soap->calls[0]['request']);
        $this->assertTrue(app(ErpFiscalArtifactStore::class)->verifiedFiscalArtifactReady($document->fresh()));
    }

    public function test_private_soap_transport_cleans_private_certificate_files_after_success_and_exception(): void
    {
        [$certificate, $privateKey] = $this->certificateMaterial();
        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $privateKey, 'fixture-password'));
        $transport = new NfePhpPrivateSoapCurlProbe;
        $transport->loadCertificate(Certificate::readPfx($pfx, 'fixture-password'));
        $this->assertSame('<ok/>', $transport->send('https://sefaz.test.invalid', request: '<request/>'));
        $successDirectory = $transport->observedDirectory;
        $this->assertSame(0700, $transport->observedDirectoryMode);
        $this->assertSame([0600, 0600, 0600], $transport->observedFileModes);
        $this->assertSame([true, true, true], $transport->observedFileContentAvailable);
        $this->assertDirectoryDoesNotExist($successDirectory);

        $transport->throwDuringSend = false;
        $transport->send('https://sefaz.test.invalid', request: '<request/>');
        $secondDirectory = $transport->observedDirectory;
        $this->assertNotSame($successDirectory, $secondDirectory);
        $this->assertSame([0600, 0600, 0600], $transport->observedFileModes);
        $this->assertSame([true, true, true], $transport->observedFileContentAvailable);
        $this->assertDirectoryDoesNotExist($secondDirectory);

        $transport->throwDuringSend = true;
        try {
            $transport->send('https://sefaz.test.invalid', request: '<request/>');
            $this->fail('A failed SOAP operation must propagate its failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fake transport exception', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($transport->observedDirectory);

        $directoriesBefore = glob(sys_get_temp_dir().'/opmoni-fiscal-*') ?: [];
        $transportWithoutCertificateIdentity = new NfePhpPrivateSoapCurl;
        $transportWithoutCertificateIdentity->loadCertificate(Certificate::readPfx($pfx, 'fixture-password'));
        $preparationFailed = false;
        try {
            $transportWithoutCertificateIdentity->send('https://sefaz.test.invalid', request: '<request/>');
        } catch (RuntimeException $exception) {
            $preparationFailed = true;
            $this->assertStringContainsString('OID', $exception->getMessage());
        }
        $this->assertTrue($preparationFailed);
        $this->assertSame($directoriesBefore, glob(sys_get_temp_dir().'/opmoni-fiscal-*') ?: []);
        $partial = new class extends NfePhpPrivateSoapCurl
        {
            public string $observedDirectory = '';

            public function setTemporaryFolder($folderRealPath = null)
            {
                $this->observedDirectory = (string) $folderRealPath;
                $this->tempdir = rtrim((string) $folderRealPath, '/').'/';
                $this->setLocalFolder($this->tempdir);
            }

            public function saveTemporarilyKeyFiles()
            {
                parent::saveTemporarilyKeyFiles();
                throw new RuntimeException('fake failure after private material preparation');
            }
        };
        $partial->loadCertificate(Certificate::readPfx($pfx, 'fixture-password'));
        $partialPreparationFailed = false;
        try {
            $partial->send('https://sefaz.test.invalid', request: '<request/>');
        } catch (RuntimeException $exception) {
            $partialPreparationFailed = true;
            $this->assertSame('fake failure after private material preparation', $exception->getMessage());
        }
        $this->assertTrue($partialPreparationFailed);
        $this->assertDirectoryDoesNotExist($partial->observedDirectory);
        $this->assertSame($directoriesBefore, glob(sys_get_temp_dir().'/opmoni-fiscal-*') ?: []);

    }

    #[DataProvider('nativeEventProvider')]
    public function test_provider_submits_each_registered_event_with_exact_signed_intent(string $model, string $kind, string $type, int $sequence = 1): void
    {
        $documentSource = $this->eventDocumentSource($model);
        [$certificate, $privateKey, $certificatePem] = $this->certificateMaterial();
        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $privateKey, 'task20-test-password'));
        $source = [
            'model' => $model,
            'document_kind' => $kind,
            'event_type' => $type,
            'issuer' => $documentSource['issuer'],
            'access_key' => $documentSource['identity']['access_key'],
            'environment' => 'homologation',
            'protocol' => '135260000000001',
            'sequence' => $sequence,
            'occurred_at' => '2026-10-21T10:01:00-03:00',
            'justification' => 'Justificativa de cancelamento informada',
            'correction' => 'Ajuste permitido de observacao complementar',
            'legal_scope_attested' => true,
            'corrections' => [['group' => 'compl', 'field' => 'xObs', 'value' => 'Observacao de transporte corrigida']],
            'replacement_access_key' => $type === 'substitution_cancel'
                ? (new ErpFiscalPayloadBuilder)->buildAccessKey(['uf' => 'SP', 'cnpj' => $documentSource['issuer']['cnpj'], 'model' => '65', 'series' => 1, 'number' => 124, 'cnf' => '12345678', 'issued_at' => '2026-10-21T10:00:00-03:00'])
                : $documentSource['identity']['access_key'],
            'closed_on' => '2026-10-21',
            'closing_uf' => 'SP',
            'closing_city_code' => '3550308',
            'identity' => $documentSource['identity'],
        ];
        $signed = app(ErpFiscalXmlSigner::class)->sign(app(ErpFiscalEventRegistry::class)->buildEvent($source), $privateKey, $certificatePem);
        app(ErpFiscalSchemaValidator::class)->assertValid($signed, $model, 'event', $type);
        [$attempt, $document] = $this->persistBoundAttempt($source, $pfx, 'task20-test-password', $signed, 'event:submit');
        $event = ErpFiscalEvent::query()->create([
            'erp_fiscal_document_id' => $document->id,
            'event_type' => $type,
            'status' => 'submitting',
            'idempotency_key' => 'task20-event-'.$model.'-'.$type,
            'payload_snapshot' => ['signed_xml' => $signed],
        ]);
        $attempt->erp_fiscal_event_id = $event->id;
        $attempt->save();
        $soap = new NfePhpFixtureSoap;
        $soap->responseXml = $this->acceptedEventSoapResponse($model, $source);
        $provider = $this->providerWithSoap($soap)->forAttempt($attempt->fresh(), $signed);

        $result = $provider->submitEvent($document->fresh(), $event);

        $this->assertSame('event_accepted', $result->outcome, $model.'/'.$type.' SOAP calls='.count($soap->calls).' submitted='.($provider->submittedRequestXml() === null ? 'no' : 'yes'));
        $this->assertSame('135260000000001', $result->protocol);
        $this->assertCount(1, $soap->calls);
        $this->assertSame($this->canonicalElement($signed, match ($model) {
            '55', '65' => 'evento', '57' => 'eventoCTe', '58' => 'eventoMDFe'
        }), $provider->submittedRequestXml());
        app(ErpFiscalSchemaValidator::class)->assertValid((string) $result->xml, $model, 'processed_event', $type);
        $processedEvent = $this->canonicalElement((string) $result->xml, match ($model) {
            '55', '65' => 'evento', '57' => 'eventoCTe', '58' => 'eventoMDFe'
        });
        $this->assertSame(hash('sha256', (string) $provider->submittedRequestXml()), hash('sha256', $processedEvent), 'Processed event must carry the exact signed request.');
        app(ErpFiscalEventRegistry::class)->assertOperationResultMatches((string) $result->xml, (string) $provider->submittedRequestXml(), $source, (string) $result->protocol);
        $unsigned = app(ErpFiscalEventRegistry::class)->buildEvent($source);
        $changed = $source;
        $changed['occurred_at'] = '2026-10-21T10:02:00-03:00';
        $changedSigned = app(ErpFiscalXmlSigner::class)->sign(app(ErpFiscalEventRegistry::class)->buildEvent($changed), $privateKey, $certificatePem);
        foreach ([$unsigned, $changedSigned, '<requests>'.$signed.$signed.'</requests>'] as $invalidRequest) {
            $negativeSoap = new NfePhpFixtureSoap;
            $guard = new NfePhpRequestGuardSoap($negativeSoap, $attempt, $source, $signed, app(ErpFiscalXmlSigner::class), $certificatePem);
            $rejected = false;
            try {
                $guard->send('https://sefaz.test.invalid/event', request: $invalidRequest);
            } catch (RuntimeException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
            $this->assertCount(0, $negativeSoap->calls);
        }
        $attempt->update(['dispatch_attempts' => 2]);
        $retry = $this->providerWithSoap($soap)->forAttempt($attempt->fresh(), $signed)->submitEvent($document, $event);
        $this->assertSame('indeterminate', $retry->outcome);
        $this->assertCount(1, $soap->calls, 'An uncertain event cannot be blindly submitted twice.');

    }

    /** @return array<string, array{string, string, string, int}> */
    public static function nativeEventProvider(): array
    {
        return [
            'NF-e cancel' => ['55', 'posted_sale', 'cancel', 1],
            'NF-e correction' => ['55', 'posted_sale', 'correction', 1],
            'NFC-e cancel' => ['65', 'posted_sale', 'cancel', 1],
            'NFC-e substitution cancel' => ['65', 'posted_sale', 'substitution_cancel', 1],
            'CT-e cancel' => ['57', 'transport_service', 'cancel', 1],
            'CT-e correction' => ['57', 'transport_service', 'correction', 1],
            'MDF-e cancel' => ['58', 'transport_manifest', 'cancel', 1],
            'MDF-e close' => ['58', 'transport_manifest', 'close', 1],
            'MDF-e close sequence 100' => ['58', 'transport_manifest', 'close', 100],
            'MDF-e cancel sequence 999' => ['58', 'transport_manifest', 'cancel', 999],
        ];
    }

    #[DataProvider('invalidationModelProvider')]
    public function test_provider_submits_nfe_invalidation_with_exact_signed_intent(string $model): void
    {
        $documentSource = $this->eventDocumentSource($model);
        [$certificate, $privateKey, $certificatePem] = $this->certificateMaterial();
        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $privateKey, 'task20-test-password'));
        $source = [
            'model' => $model,
            'environment' => 'homologation',
            'issuer' => $documentSource['issuer'],
            'year' => 2026,
            'series' => 1,
            'number_from' => 201,
            'number_to' => 205,
            'justification' => 'Faixa sem documentos para inutilizacao',
            'identity' => $documentSource['identity'],
        ];
        $signed = app(ErpFiscalXmlSigner::class)->sign(app(ErpFiscalEventRegistry::class)->buildInvalidation($source), $privateKey, $certificatePem);
        app(ErpFiscalSchemaValidator::class)->assertValid($signed, $model, 'invalidation');
        [$attempt] = $this->persistBoundAttempt($source, $pfx, 'task20-test-password', $signed, 'invalidation:submit');
        $invalidation = $attempt->invalidation()->firstOrFail();
        $soap = new NfePhpFixtureSoap;
        $soap->responseXml = $this->acceptedInvalidationSoapResponse($source);
        $provider = $this->providerWithSoap($soap)->forAttempt($attempt, $signed);

        $result = $provider->submitInvalidation($invalidation);

        $this->assertSame('event_accepted', $result->outcome, $model);
        $this->assertSame('135260000000001', $result->protocol);
        $this->assertCount(1, $soap->calls);
        $this->assertSame(hash('sha256', $this->canonicalElement($signed, 'inutNFe')), hash('sha256', (string) $provider->submittedRequestXml()));
        app(ErpFiscalSchemaValidator::class)->assertValid((string) $result->xml, $model, 'processed_invalidation');
        app(ErpFiscalEventRegistry::class)->assertOperationResultMatches((string) $result->xml, (string) $provider->submittedRequestXml(), $source, (string) $result->protocol);
        $attempt->update(['dispatch_attempts' => 2]);
        $retry = $this->providerWithSoap($soap)->forAttempt($attempt->fresh(), $signed)->submitInvalidation($invalidation);
        $this->assertSame('indeterminate', $retry->outcome);
        $this->assertCount(1, $soap->calls, 'An uncertain invalidation cannot be blindly submitted twice.');

    }

    /** @return array<string, array{string}> */
    public static function invalidationModelProvider(): array
    {
        return ['NF-e' => ['55'], 'NFC-e' => ['65']];
    }

    public function test_guard_rejects_a_different_validly_signed_document_before_fake_soap(): void
    {
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NFC-e 65 posted sale'][2];
        $unsigned = app(Nfce65DocumentBuilder::class)->build($source);
        [, $privateKey, $certificatePem] = $this->certificateMaterial();
        $signed = app(ErpFiscalXmlSigner::class)->sign($unsigned, $privateKey, $certificatePem);

        $alteredSource = $source;
        $alteredSource['items'][0]['description'] = 'Outra mercadoria';
        $alteredUnsigned = app(Nfce65DocumentBuilder::class)->build($alteredSource);
        $alteredSigned = app(ErpFiscalXmlSigner::class)->sign($alteredUnsigned, $privateKey, $certificatePem);
        $attempt = new ErpFiscalAttempt(['action' => 'document:transmit', 'model' => '65']);
        $soap = new NfePhpFixtureSoap;
        $guard = new NfePhpRequestGuardSoap($soap, $attempt, [], $signed, app(ErpFiscalXmlSigner::class), $certificatePem);
        $request = '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'.$alteredSigned.'</soap:Body></soap:Envelope>';

        try {
            $guard->send('https://sefaz.test.invalid/nfe', 'nfeAutorizacaoLote', '', SOAP_1_2, [], [], $request);
            $this->fail('A separately signed document cannot replace the attempt document.');
        } catch (RuntimeException $exception) {
            $this->assertTrue(
                in_array($exception->getMessage(), [
                    'Solicitação NFePHP diverge da tentativa fiscal fixada.',
                    'Conteúdo assinado NFePHP diverge da tentativa fiscal fixada.',
                ], true)
                || str_starts_with($exception->getMessage(), 'Solicitação SEFAZ exige um único conteúdo fiscal por tentativa ('),
                $exception->getMessage(),
            );
        }

        $this->assertCount(0, $soap->calls);
    }

    public function test_guard_rejects_consultations_with_wrong_scope_or_multiple_payloads(): void
    {
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NFC-e 65 posted sale'][2];
        $unsigned = app(Nfce65DocumentBuilder::class)->build($source);
        [, $privateKey, $certificatePem] = $this->certificateMaterial();
        $signed = app(ErpFiscalXmlSigner::class)->sign($unsigned, $privateKey, $certificatePem);
        $key = $source['identity']['access_key'];
        $valid = '<consSitNFe xmlns="http://www.portalfiscal.inf.br/nfe"><tpAmb>2</tpAmb><xServ>CONSULTAR</xServ><chNFe>'.$key.'</chNFe></consSitNFe>';
        $cases = [
            'namespace' => '<consSitNFe xmlns="urn:wrong"><tpAmb>2</tpAmb><xServ>CONSULTAR</xServ><chNFe>'.$key.'</chNFe></consSitNFe>',
            'model root' => '<consSitCTe xmlns="http://www.portalfiscal.inf.br/cte"><tpAmb>2</tpAmb><xServ>CONSULTAR</xServ><chCTe>'.$key.'</chCTe></consSitCTe>',
            'access key' => str_replace($key, '35251030288513000100650010000000011123456789', $valid),
            'environment' => str_replace('<tpAmb>2</tpAmb>', '<tpAmb>1</tpAmb>', $valid),
            'receipt' => '<consReciNFe xmlns="http://www.portalfiscal.inf.br/nfe"><tpAmb>2</tpAmb><nRec>000000000000001</nRec></consReciNFe>',
            'multiple payloads' => '<consultations>'.$valid.$valid.'</consultations>',
            'consultation plus document payload' => '<requests>'.$valid.$signed.'</requests>',
        ];
        foreach ($cases as $case => $request) {
            $attempt = new ErpFiscalAttempt([
                'action' => 'document:reconcile',
                'model' => '65',
                'environment' => 'homologation',
                'provider_receipt' => $case === 'receipt' ? '123456789012345' : null,
            ]);
            $soap = new NfePhpFixtureSoap;
            $guard = new NfePhpRequestGuardSoap($soap, $attempt, ['identity' => $source['identity']], $signed, app(ErpFiscalXmlSigner::class), $certificatePem);

            try {
                $guard->send('https://sefaz.test.invalid/consulta', 'consultation', '', SOAP_1_2, [], [], $request);
                $this->fail('Out-of-scope consultation must be rejected: '.$case);
            } catch (RuntimeException) {
                $this->assertCount(0, $soap->calls, $case);
            }
        }
    }

    public function test_guard_rejects_an_unsigned_document_before_fake_soap(): void
    {
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NFC-e 65 posted sale'][2];
        $unsigned = app(Nfce65DocumentBuilder::class)->build($source);
        [, , $certificatePem] = $this->certificateMaterial();
        $attempt = new ErpFiscalAttempt(['action' => 'document:transmit', 'model' => '65']);
        $soap = new NfePhpFixtureSoap;
        $guard = new NfePhpRequestGuardSoap($soap, $attempt, [], $unsigned, app(ErpFiscalXmlSigner::class), $certificatePem);

        try {
            $guard->send('https://sefaz.test.invalid/nfe', 'nfeAutorizacaoLote', '', SOAP_1_2, [], [], $unsigned);
            $this->fail('An unsigned document cannot pass the attempt-bound SOAP guard.');
        } catch (RuntimeException) {
            $this->assertCount(0, $soap->calls);
        }
    }

    public function test_batch_identifier_is_a_deterministic_numeric_fifteen_digit_value(): void
    {
        $provider = app(NfePhpErpFiscalProvider::class);
        $attempt = new ErpFiscalAttempt(['idempotency_key' => 'task20-batch-identifier']);
        $attemptProperty = new \ReflectionProperty(NfePhpErpFiscalProvider::class, 'attempt');
        $attemptProperty->setValue($provider, $attempt);
        $batchMethod = new \ReflectionMethod(NfePhpErpFiscalProvider::class, 'batchId');

        $batchId = $batchMethod->invoke($provider);

        $this->assertMatchesRegularExpression('/^\d{15}$/', $batchId);
        $this->assertSame($batchId, $batchMethod->invoke($provider));
    }

    #[DataProvider('cStat150PolicyProvider')]
    public function test_cstat_150_is_authorized_only_for_nfe_models(string $model, string $expectedOutcome): void
    {
        [$source, $signed] = $this->signedDocumentFixture($model);
        $provider = app(NfePhpErpFiscalProvider::class);
        $signedProperty = new \ReflectionProperty(NfePhpErpFiscalProvider::class, 'signedXml');
        $signedProperty->setValue($provider, $signed);
        $document = new ErpFiscalDocument([
            'model' => $model,
            'environment' => 'homologation',
            'access_key' => $source['identity']['access_key'],
        ]);
        $response = $this->authorizedProtocolResponse($model, $source['identity']['access_key'], '150', $this->textElement($signed, 'DigestValue'));
        $outcomeMethod = new \ReflectionMethod(NfePhpErpFiscalProvider::class, 'documentOutcome');

        $result = $outcomeMethod->invoke($provider, $response, $document, false);

        $this->assertSame($expectedOutcome, $result->outcome);
        if ($expectedOutcome === 'authorized') {
            app(ErpFiscalCorrelationValidator::class)->assertProcessedMatches($result->xml, [
                'model' => $model,
                'access_key' => $source['identity']['access_key'],
                'issuer_cnpj' => $source['issuer']['cnpj'],
                'environment' => 'homologation',
                'protocol' => '135260000000001',
            ]);
        }
    }

    public function test_for_attempt_transmits_using_pinned_certificate_and_configuration_revisions(): void
    {
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NFC-e 65 posted sale'][2];
        $unsigned = app(Nfce65DocumentBuilder::class)->build($source);
        [$certificate, $privateKey, $certificatePem] = $this->certificateMaterial();
        $signed = app(ErpFiscalXmlSigner::class)->sign($unsigned, $privateKey, $certificatePem);
        $password = 'task20-test-password';
        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $privateKey, $password));
        [$attempt, $document, $certificateRevision] = $this->persistBoundAttempt($source, $pfx, $password, $signed);

        $soapResponse = '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><nfeAutorizacaoLoteResponse><nfeResultMsg><retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe"><tpAmb>2</tpAmb><cStat>104</cStat><protNFe><infProt><tpAmb>2</tpAmb><chNFe>'.$document->access_key.'</chNFe><cStat>100</cStat><nProt>135260000000001</nProt></infProt></protNFe></retEnviNFe></nfeResultMsg></nfeAutorizacaoLoteResponse></soap:Body></soap:Envelope>';
        $soap = new NfePhpFixtureSoap;
        $soap->responseXml = $soapResponse;
        $provider = $this->providerWithSoap($soap);
        $bound = $provider->forAttempt($attempt, $signed);

        $configurationRevision = $attempt->configurationRevision;
        $configurationOwner = $configurationRevision->configurationOwner;
        $rotatedConfiguration = $configurationOwner->revisions()->create([
            'revision' => 2,
            'environment' => 'homologation',
            'configuration' => ['issuer' => ['uf' => 'RJ']],
            'configuration_sha256' => hash('sha256', json_encode(['issuer' => ['uf' => 'RJ']], JSON_THROW_ON_ERROR)),
            'created_by_user_id' => $attempt->actor_user_id,
        ]);
        $configurationOwner->current_revision_id = $rotatedConfiguration->id;
        $configurationOwner->save();
        app(ErpFiscalCertificateRevisionService::class)->rotate($certificateRevision->certificate, $this->alternatePkcs12($password), $password, $attempt->actor_user_id);
        $result = $bound->transmit($document, [
            'signed_xml' => $signed,
            'configuration_revision_id' => $attempt->erp_fiscal_configuration_revision_id,
            'certificate_revision_id' => $attempt->erp_fiscal_certificate_revision_id,
            'schema_release' => $attempt->schema_release,
        ]);

        $this->assertSame('authorized', $result->outcome);
        $this->assertSame(1, count($soap->calls));
        $this->assertStringContainsString('sp', strtolower($soap->calls[0]['url']));
        $this->assertMatchesRegularExpression('/<idLote>\d{15}<\/idLote>/', $soap->calls[0]['request']);
        $this->assertSame($this->canonicalElement($signed, 'NFe'), $bound->submittedRequestXml());
        app(ErpFiscalCorrelationValidator::class)->assertProcessedMatches($result->xml, [
            'model' => '65',
            'access_key' => $document->access_key,
            'issuer_cnpj' => $source['issuer']['cnpj'],
            'environment' => 'homologation',
            'protocol' => '135260000000001',
        ]);
    }

    #[DataProvider('documentModelProvider')]
    public function test_for_attempt_transmits_each_supported_native_document_model(string $model): void
    {
        [$source, $signed, $pfx] = $this->signedDocumentFixture($model);
        [$attempt, $document] = $this->persistBoundAttempt($source, $pfx, 'task20-test-password', $signed);
        $soap = new NfePhpFixtureSoap;
        $soap->responseXml = $this->authorizedSoapResponse($model, $document->access_key, '100', $this->textElement($signed, 'DigestValue'));
        if ($model === '58') {
            $responseDom = new \DOMDocument;
            $this->assertTrue($responseDom->loadXML($this->canonicalElement($soap->responseXml, 'retMDFe'), LIBXML_NONET));
            $this->assertTrue($responseDom->schemaValidate(base_path('vendor/nfephp-org/sped-mdfe/schemes/PL_MDFe_300a/retMDFe_v3.00.xsd'), LIBXML_NONET));
        }
        $bound = $this->providerWithSoap($soap)->forAttempt($attempt, $signed);
        $result = $bound->transmit($document, [
            'signed_xml' => $signed,
            'configuration_revision_id' => $attempt->erp_fiscal_configuration_revision_id,
            'certificate_revision_id' => $attempt->erp_fiscal_certificate_revision_id,
            'schema_release' => $attempt->schema_release,
        ]);

        $this->assertSame('authorized', $result->outcome, $model.' '.$result->message);
        $this->assertCount(1, $soap->calls, $model);
        $this->assertSame($this->canonicalElement($signed, match ($model) {
            '55', '65' => 'NFe', '57' => 'CTe', '58' => 'MDFe'
        }), $bound->submittedRequestXml(), $model);
        app(ErpFiscalCorrelationValidator::class)->assertProcessedMatches($result->xml, [
            'model' => $model,
            'access_key' => $document->access_key,
            'issuer_cnpj' => $source['issuer']['cnpj'],
            'environment' => 'homologation',
            'protocol' => '135260000000001',
        ]);
        app(ErpFiscalSchemaValidator::class)->assertValid($result->xml, $model, 'processed');
    }

    #[DataProvider('malformedAuthorizationResponseProvider')]
    public function test_uncorrelated_protocols_never_authorize_a_document(string $case): void
    {
        [$source, $signed, $pfx] = $this->signedDocumentFixture('55');
        [$attempt, $document] = $this->persistBoundAttempt($source, $pfx, 'task20-test-password', $signed);
        $response = $this->authorizedSoapResponse('55', $document->access_key, '100', $this->textElement($signed, 'DigestValue'));
        $response = match ($case) {
            'wrong namespace' => str_replace('http://www.portalfiscal.inf.br/nfe', 'urn:wrong', $response),
            'wrong response root' => str_replace('retEnviNFe', 'retEnviCTe', $response),
            'foreign key' => str_replace($document->access_key, str_repeat('1', 44), $response),
            'foreign environment' => str_replace('<infProt><tpAmb>2</tpAmb>', '<infProt><tpAmb>1</tpAmb>', $response),
            'invalid protocol' => str_replace('<nProt>135260000000001</nProt>', '<nProt>135</nProt>', $response),
            'multiple models' => str_replace('</retEnviNFe>', '<protCTe xmlns="http://www.portalfiscal.inf.br/cte"><infProt/></protCTe></retEnviNFe>', $response),
            'duplicate protocol' => str_replace('</protNFe>', '<protNFe versao="4.00"/> </protNFe>', $response),
            'different content digest' => str_replace('<digVal>'.$this->textElement($signed, 'DigestValue').'</digVal>', '<digVal>'.base64_encode(str_repeat('x', 20)).'</digVal>', $response),
            'duplicate response root' => str_replace('</nfeResultMsg>', '<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe"><tpAmb>2</tpAmb></retEnviNFe></nfeResultMsg>', $response),
            'protocol outside response root' => str_replace('<protNFe versao=', '<protNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao=', preg_replace('/(<retEnviNFe[^>]*>)(.*?)(<protNFe.*?<\/protNFe>)(<\/retEnviNFe>)/s', '$1$2$4$3', $response)),
        };
        $soap = new NfePhpFixtureSoap;
        $soap->responseXml = $response;
        $bound = $this->providerWithSoap($soap)->forAttempt($attempt, $signed);

        $result = $bound->transmit($document, [
            'signed_xml' => $signed,
            'configuration_revision_id' => $attempt->erp_fiscal_configuration_revision_id,
            'certificate_revision_id' => $attempt->erp_fiscal_certificate_revision_id,
            'schema_release' => $attempt->schema_release,
        ]);

        $this->assertSame('indeterminate', $result->outcome, $case);
        $this->assertCount(1, $soap->calls, $case);
    }

    /** @return array<string, array{string}> */
    public static function malformedAuthorizationResponseProvider(): array
    {
        return [
            'wrong namespace' => ['wrong namespace'],
            'wrong response root' => ['wrong response root'],
            'foreign key' => ['foreign key'],
            'foreign environment' => ['foreign environment'],
            'invalid protocol' => ['invalid protocol'],
            'multiple models' => ['multiple models'],
            'duplicate protocol' => ['duplicate protocol'],
            'different content digest' => ['different content digest'],
            'duplicate response root' => ['duplicate response root'],
            'protocol outside response root' => ['protocol outside response root'],
        ];
    }

    #[DataProvider('documentStatusCoherenceProvider')]
    public function test_document_root_and_protocol_statuses_must_follow_the_selected_native_operation(string $model, string $operation, string $case): void
    {
        [$source, $signed, $pfx] = $this->signedDocumentFixture($model);
        [$attempt, $document] = $this->persistBoundAttempt($source, $pfx, 'task20-test-password', $signed, $operation === 'transmit' ? 'document:transmit' : 'document:consult');
        $receipt = $operation === 'receipt' ? '123456789012345' : null;
        $attempt->update(['provider_receipt' => $receipt]);
        $response = $operation === 'transmit'
            ? $this->authorizedSoapResponse($model, $document->access_key, '100', $this->textElement($signed, 'DigestValue'))
            : $this->consultationSoapResponse($model, $document->access_key, $this->textElement($signed, 'DigestValue'), $receipt);
        if ($case === 'root refuses protocol authorizes') {
            $response = preg_replace('/<cStat>\d{3}<\/cStat>/', '<cStat>225</cStat>', $response, 1);
        } elseif ($case === 'root authorizes protocol refuses') {
            $response = preg_replace('/<cStat>\d{3}<\/cStat>/', '<cStat>100</cStat>', $response, 1);
            $response = preg_replace('/(<infProt>.*?)<cStat>100<\/cStat>/s', '$1<cStat>225</cStat>', $response);
        }
        $root = match ($model) {
            '55', '65' => $operation === 'transmit' ? 'retEnviNFe' : ($receipt === null ? 'retConsSitNFe' : 'retConsReciNFe'),
            '57' => $operation === 'transmit' ? 'retCTe' : 'retConsSitCTe',
            '58' => $operation === 'transmit' ? 'retMDFe' : ($receipt === null ? 'retConsSitMDFe' : 'retConsReciMDFe'),
        };
        if ($operation !== 'transmit' || in_array($model, ['57', '58'], true)) {
            $package = match ($model) {
                '55', '65' => 'sped-nfe/schemes/PL_009_V4', '57' => 'sped-cte/schemes/PL_CTe_400', '58' => 'sped-mdfe/schemes/PL_MDFe_300a'
            };
            $dom = new \DOMDocument;
            $this->assertTrue($dom->loadXML($this->canonicalElement($response, $root), LIBXML_NONET));
            $this->assertTrue($dom->schemaValidate(base_path('vendor/nfephp-org/'.$package.'/'.$root.'_v'.($model === '58' ? '3.00' : '4.00').'.xsd'), LIBXML_NONET));
        }
        $soap = new NfePhpFixtureSoap;
        $soap->responseXml = $response;
        $provider = $this->providerWithSoap($soap)->forAttempt($attempt->fresh(), $signed);
        $before = $document->fresh()->getAttributes();
        $result = $operation === 'transmit' ? $provider->transmit($document, ['signed_xml' => $signed, 'configuration_revision_id' => $attempt->erp_fiscal_configuration_revision_id, 'certificate_revision_id' => $attempt->erp_fiscal_certificate_revision_id]) : $provider->consult($document);
        $this->assertSame($case === 'valid' ? 'authorized' : 'indeterminate', $result->outcome, $model.'/'.$operation.'/'.$case);
        $this->assertCount(1, $soap->calls);
        $this->assertSame($before, $document->fresh()->getAttributes());
        if ($case !== 'valid') {
            $this->assertNull($result->protocol);
            $this->assertNull($result->xml);
        }
    }

    public static function documentStatusCoherenceProvider(): array
    {
        $cases = [];
        foreach (['55', '65', '57', '58'] as $model) {
            foreach ($model === '57' ? ['transmit', 'key'] : ['transmit', 'key', 'receipt'] as $operation) {
                foreach (['valid', 'root refuses protocol authorizes', 'root authorizes protocol refuses'] as $case) {
                    $cases[$model.'/'.$operation.'/'.$case] = [$model, $operation, $case];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('contradictoryJobResponseProvider')]
    public function test_job_never_publishes_authorization_from_contradictory_root_and_protocol_status(string $model, bool $outerRefuses): void
    {
        [$source, $signed, $pfx] = $this->signedDocumentFixture($model);
        [$attempt, $document] = $this->persistBoundAttempt($source, $pfx, 'task20-test-password', $signed);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $attempt->erp_company_id, 'user_id' => $attempt->actor_user_id, 'role' => 'owner']);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $plan->modules()->firstOrCreate(['module_key' => 'mdfe']);
        ErpSubscription::factory()->create(['erp_company_id' => $attempt->erp_company_id, 'erp_plan_id' => $plan->id, 'status' => 'active']);
        config(['erp.operating_writes_enabled' => true, 'erp.release_enabled' => true]);
        $attempt->update(['status' => 'pending', 'outbox_state' => 'new', 'dispatch_attempts' => 0]);
        $document->update(['status' => 'queued']);
        $response = $this->authorizedSoapResponse($model, $document->access_key, $outerRefuses ? '100' : '225', $this->textElement($signed, 'DigestValue'));
        $response = preg_replace('/<cStat>\d{3}<\/cStat>/', '<cStat>'.($outerRefuses ? '225' : '100').'</cStat>', $response, 1);
        $soap = new NfePhpFixtureSoap;
        $soap->responseXml = $response;
        app()->instance(ErpFiscalProvider::class, $this->providerWithSoap($soap));

        app()->call([new ProcessErpFiscalAttempt($attempt->id), 'handle']);

        $this->assertSame('indeterminate', $attempt->fresh()->status);
        $this->assertSame('indeterminate', $document->fresh()->status);
        $this->assertNull($document->fresh()->storage_path);
        $this->assertNull($document->fresh()->artifact_sha256);
        $this->assertNull($document->fresh()->authorized_at);
        $this->assertFalse(app(ErpFiscalArtifactStore::class)->verifiedFiscalArtifactReady($document->fresh()));
        $this->assertCount(1, $soap->calls);
    }

    public static function contradictoryJobResponseProvider(): array
    {
        return ['NF-e root refuses' => ['55', true], 'NF-e root authorizes' => ['55', false], 'MDF-e root refuses' => ['58', true], 'MDF-e root authorizes' => ['58', false]];
    }

    #[DataProvider('nativeDocumentRefusalProvider')]
    public function test_native_document_refusals_are_correlated_without_authorization_protocol(string $model, string $case): void
    {
        [$source, $signed, $pfx] = $this->signedDocumentFixture($model);
        [$attempt, $document] = $this->persistBoundAttempt($source, $pfx, 'task20-test-password', $signed);
        $status = $case === 'async root' ? '100' : (in_array($model, ['55', '65'], true) ? '610' : '225');
        $response = $this->authorizedSoapResponse($model, $document->access_key, $status, $this->textElement($signed, 'DigestValue'));
        if ($case !== 'async root') {
            $response = str_replace('<nProt>135260000000001</nProt>', '', $response);
        }
        $expected = 'rejected';
        if ($case === 'unknown') {
            $response = str_replace('<cStat>'.$status.'</cStat>', '<cStat>999</cStat>', $response);
            $expected = 'indeterminate';
        } elseif ($case === 'foreign') {
            $response = str_replace($document->access_key, str_repeat('1', 44), $response);
            $expected = 'indeterminate';
        } elseif ($case === 'async root') {
            $response = str_replace('retMDFe', 'retEnviMDFe', $response);
            $expected = 'indeterminate';
        } elseif ($case === 'native sync rejection') {
            $response = preg_replace('/<protMDFe.*?<\/protMDFe>/s', '', $response);
            $response = str_replace('<cStat>100</cStat>', '<cStat>225</cStat>', $response);
            $dom = new \DOMDocument;
            $this->assertTrue($dom->loadXML($this->canonicalElement($response, 'retMDFe'), LIBXML_NONET));
            $this->assertTrue($dom->schemaValidate(base_path('vendor/nfephp-org/sped-mdfe/schemes/PL_MDFe_300a/retMDFe_v3.00.xsd'), LIBXML_NONET));
        }
        $soap = new NfePhpFixtureSoap;
        $soap->responseXml = $response;
        $result = $this->providerWithSoap($soap)->forAttempt($attempt, $signed)->transmit($document, [
            'signed_xml' => $signed, 'configuration_revision_id' => $attempt->erp_fiscal_configuration_revision_id,
            'certificate_revision_id' => $attempt->erp_fiscal_certificate_revision_id,
        ]);
        $this->assertSame($expected, $result->outcome, $model.'/'.$case);
        $this->assertNull($result->protocol);
        $this->assertNull($result->xml);
        $this->assertCount(1, $soap->calls);
    }

    public static function nativeDocumentRefusalProvider(): array
    {
        $cases = [];
        foreach (['55', '65', '57', '58'] as $model) {
            foreach (['refusal', 'unknown', 'foreign'] as $case) {
                $cases[$model.'/'.$case] = [$model, $case];
            }
        }
        $cases['MDF-e asynchronous result refused'] = ['58', 'async root'];
        $cases['MDF-e schema-valid sync refusal'] = ['58', 'native sync rejection'];

        return $cases;
    }

    #[DataProvider('nativeOperationResponseProvider')]
    public function test_job_requires_unambiguous_original_operation_response_and_preserves_authorized_document(string $model, bool $invalidation, string $case): void
    {
        [$attempt, $document, $operation, $source, $signed] = $this->operationAttemptFixture($model, $invalidation);
        $response = $invalidation ? $this->acceptedInvalidationSoapResponse($source) : $this->acceptedEventSoapResponse($model, $source);
        $returnName = $invalidation ? 'retInutNFe' : match ($model) {
            '55', '65' => 'retEvento', '57' => 'retEventoCTe', '58' => 'retEventoMDFe'
        };
        $infoName = $invalidation ? 'infInut' : 'infEvento';
        $namespace = $invalidation || in_array($model, ['55', '65'], true) ? 'http://www.portalfiscal.inf.br/nfe' : ($model === '57' ? 'http://www.portalfiscal.inf.br/cte' : 'http://www.portalfiscal.inf.br/mdfe');
        $returned = $this->canonicalElement($response, $returnName);
        $info = $this->canonicalElement($response, $infoName);
        $expected = $case === 'refusal' ? 'rejected' : 'indeterminate';
        $response = match ($case) {
            'refusal' => str_replace(['<cStat>'.($invalidation ? '102' : '135').'</cStat>', '<nProt>135260000000001</nProt>'], ['<cStat>225</cStat>', ''], $response),
            'unknown' => str_replace('<cStat>'.($invalidation ? '102' : '135').'</cStat>', '<cStat>999</cStat>', $response),
            'duplicate return' => str_replace('</soap:Body>', $returned.'</soap:Body>', $response),
            'conflicting return' => str_replace('</soap:Body>', str_replace('<cStat>'.($invalidation ? '102' : '135').'</cStat>', '<cStat>225</cStat>', $returned).'</soap:Body>', $response),
            'sibling identity' => preg_replace('/<'.$infoName.'>.*?<\/'.$infoName.'>/s', '', str_replace('</soap:Body>', $info.'</soap:Body>', $response), 1),
            'foreign namespace' => str_replace($namespace, 'urn:foreign-fiscal', $response),
            'foreign result sibling' => str_replace('</soap:Body>', '<retEventoCTe xmlns="http://www.portalfiscal.inf.br/cte"><infEvento/></retEventoCTe></soap:Body>', $response),
            'duplicate identity field' => str_replace('<tpAmb>2</tpAmb>', '<tpAmb>2</tpAmb><tpAmb>1</tpAmb>', $response),
            'conflicting wrapper status' => str_replace('<cStat>128</cStat>', '<cStat>225</cStat>', $response),
            'foreign wrapper environment' => preg_replace('/<tpAmb>2<\/tpAmb>/', '<tpAmb>1</tpAmb>', $response, 1),
            'foreign identity' => $invalidation ? str_replace('<CNPJ>'.$source['issuer']['cnpj'].'</CNPJ>', '<CNPJ>11222333000181</CNPJ>', $response) : str_replace($source['access_key'], str_repeat('1', 44), $response),
        };
        $soap = new NfePhpFixtureSoap;
        $soap->responseXml = $response;
        app()->instance(ErpFiscalProvider::class, $this->providerWithSoap($soap));
        $before = $document->fresh()->getAttributes();

        app()->call([new ProcessErpFiscalAttempt($attempt->id), 'handle']);

        $this->assertSame($expected, $attempt->fresh()->status, json_encode($attempt->fresh()->safe_result));
        $this->assertSame($expected, $operation->fresh()->status);
        $this->assertSame($before, $document->fresh()->getAttributes());
        $this->assertNull($operation->fresh()->protocol);
        $this->assertCount(1, $soap->calls);
        $this->assertStringContainsString($invalidation ? 'inutNFe' : ($model === '55' ? 'evento' : 'evento'.($model === '57' ? 'CTe' : 'MDFe')), $this->canonicalElement($signed, $invalidation ? 'inutNFe' : ($model === '55' ? 'evento' : 'evento'.($model === '57' ? 'CTe' : 'MDFe'))));
    }

    public static function nativeOperationResponseProvider(): array
    {
        $cases = [];
        foreach ([['55', false], ['57', false], ['58', false], ['55', true]] as [$model, $invalidation]) {
            foreach (['refusal', 'unknown', 'duplicate return', 'conflicting return', 'sibling identity', 'foreign namespace', 'foreign result sibling', 'duplicate identity field', 'foreign identity'] as $case) {
                $cases[$model.'/'.($invalidation ? 'invalidation' : 'event').'/'.$case] = [$model, $invalidation, $case];
            }
        }

        $cases['NF-e conflicting wrapper status'] = ['55', false, 'conflicting wrapper status'];
        $cases['NF-e foreign wrapper environment'] = ['55', false, 'foreign wrapper environment'];

        return $cases;
    }

    #[DataProvider('nativeWorkerOperationProvider')]
    public function test_native_accepted_operations_pass_worker_signature_and_immutable_request_authority(string $model, bool $invalidation): void
    {
        [$attempt, $document, $operation, $source] = $this->operationAttemptFixture($model, $invalidation);
        $soap = new NfePhpFixtureSoap;
        $soap->responseXml = $invalidation ? $this->acceptedInvalidationSoapResponse($source) : $this->acceptedEventSoapResponse($model, $source);
        app()->instance(ErpFiscalProvider::class, $this->providerWithSoap($soap));

        app()->call([new ProcessErpFiscalAttempt($attempt->id), 'handle']);

        $this->assertSame('accepted', $attempt->fresh()->status, json_encode($attempt->fresh()->safe_result));
        $this->assertSame('accepted', $operation->fresh()->status);
        $this->assertSame('135260000000001', $operation->fresh()->protocol);
        $this->assertCount(1, $soap->calls);
        $this->assertSame($invalidation || $model === '58' ? 'authorized' : 'canceled', $document->fresh()->status);
        if ($model === '58') {
            $this->assertArrayHasKey('closed_at', $document->fresh()->metadata);
        }
    }

    public static function nativeWorkerOperationProvider(): array
    {
        return ['NF-e cancel' => ['55', false], 'CT-e cancel' => ['57', false], 'MDF-e close' => ['58', false], 'NF-e invalidation' => ['55', true]];
    }

    #[DataProvider('submittedInvalidationSignatureProvider')]
    public function test_worker_verifies_submitted_invalidation_signature_and_original_local_authority(string $case): void
    {
        [$attempt, $document, $operation, $source, $signed, $privateKey, $certificatePem] = $this->operationAttemptFixture('55', true);
        $submitted = $signed;
        if ($case === 'corrupted') {
            $submitted = preg_replace('/<SignatureValue>.*?<\/SignatureValue>/s', '<SignatureValue>'.base64_encode(str_repeat('x', 256)).'</SignatureValue>', $signed);
        } elseif ($case === 'foreign') {
            [, $foreignKey, $foreignCertificate] = $this->certificateMaterial();
            $submitted = app(ErpFiscalXmlSigner::class)->sign(app(ErpFiscalEventRegistry::class)->buildInvalidation($source), $foreignKey, $foreignCertificate);
        } elseif ($case === 'changed local intent') {
            $altered = $source;
            $altered['justification'] = 'Outra justificativa assinada com o certificado fixado';
            $submitted = app(ErpFiscalXmlSigner::class)->sign(app(ErpFiscalEventRegistry::class)->buildInvalidation($altered), $privateKey, $certificatePem);
        }
        $processed = '<ProcInutNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'.$this->canonicalElement($submitted, 'inutNFe').$this->canonicalElement($this->acceptedInvalidationSoapResponse($source), 'retInutNFe').'</ProcInutNFe>';
        app(ErpFiscalSchemaValidator::class)->assertValid($processed, '55', 'processed_invalidation');
        app(ErpFiscalEventRegistry::class)->assertOperationResultMatches($processed, $submitted, $source, '135260000000001');
        app()->instance(ErpFiscalProvider::class, new NfePhpSubmittedInvalidationFixtureProvider($submitted, $processed));
        $before = $document->fresh()->getAttributes();

        app()->call([new ProcessErpFiscalAttempt($attempt->id), 'handle']);

        $this->assertSame($case === 'original' ? 'accepted' : 'attention_required', $attempt->fresh()->status);
        $this->assertSame($case === 'original' ? 'accepted' : 'submitting', $operation->fresh()->status);
        $this->assertSame($before, $document->fresh()->getAttributes());
        if ($case !== 'original') {
            $this->assertSame('provider_event_evidence_missing_or_mismatched', $attempt->fresh()->safe_result['code']);
            $this->assertNull($operation->fresh()->protocol);
        }
    }

    public static function submittedInvalidationSignatureProvider(): array
    {
        return ['corrupted' => ['corrupted'], 'foreign' => ['foreign'], 'changed local intent' => ['changed local intent'], 'original' => ['original']];
    }

    /** @return array{ErpFiscalAttempt, ErpFiscalDocument, ErpFiscalEvent|ErpFiscalInvalidation, array<string, mixed>, string, string, string} */
    private function operationAttemptFixture(string $model, bool $invalidation): array
    {
        $documentSource = $this->eventDocumentSource($model);
        [$certificate, $privateKey, $certificatePem] = $this->certificateMaterial();
        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $privateKey, 'task20-test-password'));
        $source = [
            'model' => $model, 'environment' => 'homologation', 'issuer' => $documentSource['issuer'], 'identity' => $documentSource['identity'],
            ...($invalidation ? ['year' => 2026, 'series' => 1, 'number_from' => 201, 'number_to' => 205, 'justification' => 'Faixa sem documentos para inutilizacao'] : [
                'document_kind' => $model === '55' ? 'posted_sale' : ($model === '57' ? 'transport_service' : 'transport_manifest'),
                'event_type' => $model === '58' ? 'close' : 'cancel', 'access_key' => $documentSource['identity']['access_key'],
                'protocol' => '135260000000001', 'sequence' => 1, 'occurred_at' => '2026-10-21T10:01:00-03:00',
                'justification' => 'Justificativa de cancelamento informada', 'closed_on' => '2026-10-21', 'closing_uf' => 'SP', 'closing_city_code' => '3550308',
            ]),
        ];
        $registry = app(ErpFiscalEventRegistry::class);
        $signed = app(ErpFiscalXmlSigner::class)->sign($invalidation ? $registry->buildInvalidation($source) : $registry->buildEvent($source), $privateKey, $certificatePem);
        [$attempt, $document] = $this->persistBoundAttempt($source, $pfx, 'task20-test-password', $signed, $invalidation ? 'invalidation:submit' : 'event:submit');
        if ($invalidation) {
            $operation = $attempt->invalidation()->firstOrFail();
        } else {
            $operation = ErpFiscalEvent::query()->create(['erp_fiscal_document_id' => $document->id, 'event_type' => $source['event_type'], 'status' => 'submitting', 'idempotency_key' => 'task20-review-event']);
            $attempt->erp_fiscal_event_id = $operation->id;
        }
        $document->update(['status' => 'authorized', 'authorized_at' => now(), 'metadata' => ['provider_protocol' => '135260000000001']]);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $attempt->erp_company_id, 'user_id' => $attempt->actor_user_id, 'role' => 'owner']);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        foreach (['cte', 'mdfe'] as $module) {
            $plan->modules()->firstOrCreate(['module_key' => $module]);
        }
        ErpSubscription::factory()->create(['erp_company_id' => $attempt->erp_company_id, 'erp_plan_id' => $plan->id, 'status' => 'active']);
        config(['erp.operating_writes_enabled' => true, 'erp.release_enabled' => true]);
        $attempt->fill(['status' => 'pending', 'outbox_state' => 'new', 'dispatch_attempts' => 0])->save();

        return [$attempt, $document, $operation, $source, $signed, $privateKey, $certificatePem];
    }

    /** @return array<string, array{string}> */
    public static function documentModelProvider(): array
    {
        return ['NF-e' => ['55'], 'NFC-e' => ['65'], 'CT-e' => ['57'], 'MDF-e' => ['58']];
    }

    /** @return array<string, string> */
    public static function cStat150PolicyProvider(): array
    {
        return [
            'NF-e' => ['55', 'authorized'],
            'NFC-e' => ['65', 'authorized'],
            'CT-e' => ['57', 'indeterminate'],
            'MDF-e' => ['58', 'indeterminate'],
        ];
    }

    /** @return array{array<string, mixed>, string, string} */
    private function signedDocumentFixture(string $model): array
    {
        foreach (ErpFiscalModelBuilderSchemaTest::builderProvider() as [$builderClass, $candidateModel, $source]) {
            if ($candidateModel === $model) {
                $unsigned = app($builderClass)->build($source);
                [$certificate, $privateKey, $certificatePem] = $this->certificateMaterial();
                $pfx = '';
                $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $privateKey, 'task20-test-password'));

                return [$source, app(ErpFiscalXmlSigner::class)->sign($unsigned, $privateKey, $certificatePem), $pfx];
            }
        }
        $this->fail('No fiscal fixture exists for the selected model.');
    }

    /** @return array<string, mixed> */
    private function eventDocumentSource(string $model): array
    {
        $methodName = match ($model) {
            '55', '65' => 'nfeSnapshot', '57' => 'cteSnapshot', '58' => 'mdfeSnapshot',
        };
        $method = new \ReflectionMethod(ErpFiscalModelBuilderSchemaTest::class, $methodName);
        $method->setAccessible(true);

        return $method->invoke(null, ...($model === '55' || $model === '65' ? [$model] : []));
    }

    private function authorizedProtocolResponse(string $model, string $key, string $status, string $digest): string
    {
        return $this->authorizedSoapResponse($model, $key, $status, $digest);
    }

    private function acceptedEventSoapResponse(string $model, array $source): string
    {
        [$namespace, $keyName, $root] = match ($model) {
            '55', '65' => ['http://www.portalfiscal.inf.br/nfe', 'chNFe', 'retEnvEvento'],
            '57' => ['http://www.portalfiscal.inf.br/cte', 'chCTe', 'retEventoCTe'],
            '58' => ['http://www.portalfiscal.inf.br/mdfe', 'chMDFe', 'retEventoMDFe'],
        };
        $eventCode = app(ErpFiscalEventRegistry::class)->eventCode($model, $source['document_kind'], $source['event_type']);
        $info = '<infEvento><tpAmb>2</tpAmb><verAplic>TESTE</verAplic><cOrgao>35</cOrgao><cStat>135</cStat><xMotivo>Evento registrado e vinculado</xMotivo><'.$keyName.'>'.$source['access_key'].'</'.$keyName.'><tpEvento>'.$eventCode.'</tpEvento><nSeqEvento>'.$source['sequence'].'</nSeqEvento><dhRegEvento>2026-10-21T10:00:01-03:00</dhRegEvento><nProt>135260000000001</nProt></infEvento>';
        $version = match ($model) {
            '55', '65' => '1.00', '57' => '4.00', '58' => '3.00'
        };
        $payload = $model === '55' || $model === '65' ? '<idLote>1</idLote><tpAmb>2</tpAmb><verAplic>TESTE</verAplic><cOrgao>35</cOrgao><cStat>128</cStat><xMotivo>Lote de eventos processado</xMotivo><retEvento versao="1.00">'.$info.'</retEvento>' : $info;

        return '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><'.$root.' xmlns="'.$namespace.'" versao="'.$version.'">'.$payload.'</'.$root.'></soap:Body></soap:Envelope>';
    }

    private function acceptedInvalidationSoapResponse(array $source): string
    {
        $info = '<infInut><tpAmb>2</tpAmb><verAplic>TESTE</verAplic><cStat>102</cStat><xMotivo>Inutilizacao homologada</xMotivo><cUF>35</cUF><ano>26</ano><CNPJ>'.$source['issuer']['cnpj'].'</CNPJ><mod>'.$source['model'].'</mod><serie>'.$source['series'].'</serie><nNFIni>'.$source['number_from'].'</nNFIni><nNFFin>'.$source['number_to'].'</nNFFin><dhRecbto>2026-10-21T10:00:01-03:00</dhRecbto><nProt>135260000000001</nProt></infInut>';

        return '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><retInutNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'.$info.'</retInutNFe></soap:Body></soap:Envelope>';
    }

    /** @return array{ErpFiscalAttempt, ErpFiscalDocument, ErpFiscalCertificateRevision} */
    private function persistBoundAttempt(array $source, string $pfx, string $password, string $signed, string $action = 'document:transmit'): array
    {
        $model = (string) $source['identity']['model'];
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => $source['issuer']['cnpj']]);
        $issuer = ErpEstablishment::factory()->create([
            'erp_company_id' => $company->id,
            'cnpj' => $source['issuer']['cnpj'],
        ]);
        $configuration = ErpFiscalConfiguration::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $issuer->id,
        ]);
        $configurationRevision = $configuration->revisions()->create([
            'revision' => 1,
            'environment' => 'homologation',
            'configuration' => ['issuer' => ['uf' => 'SP']],
            'configuration_sha256' => hash('sha256', json_encode(['issuer' => ['uf' => 'SP']], JSON_THROW_ON_ERROR)),
            'created_by_user_id' => $user->id,
        ]);
        $configuration->current_revision_id = $configurationRevision->id;
        $configuration->save();
        $certificate = app(ErpFiscalCertificateRevisionService::class)->store($issuer, 'Task 20 fixture', $pfx, $password, $user->id);
        $certificateRevision = $certificate->currentRevision;
        $document = ErpFiscalDocument::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $issuer->id,
            'erp_fiscal_certificate_id' => $certificate->id,
            'model' => $model,
            'environment' => 'homologation',
            'series' => $source['identity']['series'],
            'number' => $source['identity']['number'],
            'access_key' => $source['identity']['access_key'],
            'status' => 'submitting',
            'idempotency_key' => 'task20-provider-document-'.$model,
            'certificate_revision' => $certificateRevision->revision,
            'commercial_snapshot' => $source,
        ]);
        $snapshot = ['request' => ['source' => $source]];
        $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $invalidation = null;
        if ($action === 'invalidation:submit') {
            $invalidation = ErpFiscalInvalidation::query()->create([
                'erp_company_id' => $company->id,
                'erp_establishment_id' => $issuer->id,
                'model' => $model,
                'environment' => 'homologation',
                'series' => $source['series'],
                'number_from' => $source['number_from'],
                'number_to' => $source['number_to'],
                'status' => 'submitting',
                'idempotency_key' => 'task20-provider-invalidation-'.$model,
                'justification' => $source['justification'],
            ]);
        }
        $attempt = ErpFiscalAttempt::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $issuer->id,
            'erp_fiscal_document_id' => $document->id,
            'erp_fiscal_invalidation_id' => $invalidation?->id,
            'erp_fiscal_configuration_revision_id' => $configurationRevision->id,
            'erp_fiscal_certificate_revision_id' => $certificateRevision->id,
            'action' => $action,
            'model' => $model,
            'environment' => 'homologation',
            'series' => $document->series,
            'number' => $document->number,
            'actor_user_id' => $user->id,
            'idempotency_key' => 'task20-provider-attempt-'.$model,
            'request_sha256' => hash('sha256', $snapshotJson),
            'encrypted_source_snapshot' => Crypt::encryptString($snapshotJson),
            'schema_release' => 'task20-test-schema',
            'status' => 'submitting',
            'outbox_state' => 'dispatching',
            'dispatch_attempts' => 1,
        ]);

        if (str_starts_with($action, 'document:')) {
            $draft = $attempt->replicate();
            $draft->fill(['action' => 'document:draft', 'idempotency_key' => 'task20-provider-draft-'.$model, 'status' => 'accepted', 'outbox_state' => 'completed'])->save();
        }

        return [$attempt, $document, $certificateRevision];
    }

    private function alternatePkcs12(string $password): string
    {
        [$certificate, $privateKey] = $this->certificateMaterial();
        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $privateKey, $password));

        return $pfx;
    }

    private function pfxForCnpj(string $cnpj): string
    {
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => $cnpj], $privateKey, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $privateKey, 2, ['digest_alg' => 'sha256']);
        $pfx = '';
        $this->assertNotFalse($privateKey);
        $this->assertNotFalse($certificate);
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $privateKey, 'task20-test-password'));

        return $pfx;
    }

    private function authorizedSoapResponse(string $model, string $key, string $status, string $digest = 'YWJjZA=='): string
    {
        [$namespace, $protocolName, $keyName, $responseName, $resultName] = match ($model) {
            '55', '65' => ['http://www.portalfiscal.inf.br/nfe', 'protNFe', 'chNFe', 'nfeAutorizacaoLoteResponse', 'nfeResultMsg'],
            '57' => ['http://www.portalfiscal.inf.br/cte', 'protCTe', 'chCTe', 'cteRecepcaoSincResponse', 'cteRecepcaoSincResult'],
            '58' => ['http://www.portalfiscal.inf.br/mdfe', 'protMDFe', 'chMDFe', 'mdfeRecepcaoSincResponse', 'mdfeRecepcaoSincResult'],
        };
        $rootName = match ($model) {
            '55', '65' => 'retEnviNFe', '57' => 'retCTe', '58' => 'retMDFe'
        };
        $version = $model === '58' ? '3.00' : '4.00';
        $payload = '<'.$rootName.' xmlns="'.$namespace.'" versao="'.$version.'"><tpAmb>2</tpAmb>'.(in_array($model, ['57', '58'], true) ? '<cUF>35</cUF><verAplic>TESTE</verAplic>' : '').'<cStat>'.(in_array($model, ['57', '58'], true) ? $status : '104').'</cStat><xMotivo>Lote processado</xMotivo><'.$protocolName.' versao="'.$version.'"><infProt><tpAmb>2</tpAmb><verAplic>TESTE</verAplic><'.$keyName.'>'.$key.'</'.$keyName.'><dhRecbto>2026-10-21T10:00:01-03:00</dhRecbto><nProt>135260000000001</nProt><digVal>'.$digest.'</digVal><cStat>'.$status.'</cStat><xMotivo>Autorizado o uso</xMotivo></infProt></'.$protocolName.'></'.$rootName.'>';

        return '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><'.$responseName.'><'.$resultName.'>'.$payload.'</'.$resultName.'></'.$responseName.'></soap:Body></soap:Envelope>';
    }

    private function providerWithSoap(NfePhpFixtureSoap $soap): NfePhpErpFiscalProvider
    {
        return new NfePhpErpFiscalProvider(
            app(NfePhpToolsFactory::class),
            new NfePhpFixtureTransportFactory($soap),
            app(ErpFiscalAttemptAuthorization::class),
            app(ErpFiscalCertificateRevisionService::class),
        );
    }

    /** @return array{\OpenSSLAsymmetricKey, string, string} */
    private function certificateMaterial(): array
    {
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => '30288513000100'], $privateKey, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $privateKey, 2, ['digest_alg' => 'sha256']);
        $privateKeyPem = '';
        $certificatePem = '';
        $this->assertNotFalse($privateKey);
        $this->assertNotFalse($certificate);
        $this->assertTrue(openssl_pkey_export($privateKey, $privateKeyPem));
        $this->assertTrue(openssl_x509_export($certificate, $certificatePem));

        return [$certificate, $privateKeyPem, $certificatePem];
    }

    private function canonicalElement(string $xml, string $name): string
    {
        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml, LIBXML_NONET));
        $node = (new \DOMXPath($dom))->query('//*[local-name()="'.$name.'"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);

        return $node->C14N();
    }

    private function textElement(string $xml, string $name): string
    {
        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml, LIBXML_NONET));
        $node = (new \DOMXPath($dom))->query('//*[local-name()="'.$name.'"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);

        return $node->textContent;
    }

    private function readCertificate(string $certificatePem): \OpenSSLCertificate
    {
        $certificate = openssl_x509_read($certificatePem);
        $this->assertNotFalse($certificate);

        return $certificate;
    }
}

final class NfePhpFixtureSoap extends SoapCurl
{
    /** @var list<array{url: string, request: string}> */
    public array $calls = [];

    public string $responseXml = '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><nfeAutorizacaoLoteResponse><nfeResultMsg><retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe"><tpAmb>2</tpAmb><cStat>103</cStat><xMotivo>Lote recebido</xMotivo><infRec><nRec>123456789012345</nRec><tMed>1</tMed></infRec></retEnviNFe></nfeResultMsg></nfeAutorizacaoLoteResponse></soap:Body></soap:Envelope>';

    public bool $throwDuringSend = false;

    public function send($url, $operation = '', $action = '', $soapver = SOAP_1_2, $parameters = [], $namespaces = [], $request = '', $soapheader = null)
    {
        $this->calls[] = ['url' => (string) $url, 'request' => (string) $request];

        if ($this->throwDuringSend) {
            throw new RuntimeException('fake provider dispatch timeout');
        }

        return $this->responseXml;
    }
}

final class NfePhpPrivateSoapCurlProbe extends NfePhpPrivateSoapCurl
{
    public ?string $observedDirectory = null;

    public ?int $observedDirectoryMode = null;

    /** @var list<int> */
    public array $observedFileModes = [];

    /** @var list<bool> */
    public array $observedFileContentAvailable = [];

    public bool $throwDuringSend = false;

    /** @var list<array{directory: string, modes: list<int>}> */
    public array $observations = [];

    public function setTemporaryFolder($folderRealPath = null)
    {
        $this->tempdir = rtrim((string) $folderRealPath, '/').'/';
        $this->setLocalFolder($this->tempdir);
    }

    protected function sendWithPrivateFiles($url, $operation, $action, $soapver, $parameters, $namespaces, $request, $soapheader): string
    {
        $this->observedDirectory = rtrim($this->tempdir, '/');
        $this->observedDirectoryMode = fileperms($this->observedDirectory) & 0777;
        $this->observedFileModes = [];
        $this->observedFileContentAvailable = [];
        foreach (glob($this->tempdir.'certs/*') ?: [] as $file) {
            $this->observedFileModes[] = fileperms($file) & 0777;
            $this->observedFileContentAvailable[] = is_file($file) && filesize($file) > 0;
        }
        $this->observations[] = ['directory' => $this->observedDirectory, 'modes' => $this->observedFileModes];
        if ($this->throwDuringSend) {
            throw new RuntimeException('fake transport exception');
        }

        return '<ok/>';
    }
}

final class NfePhpFixtureTransportFactory extends NfePhpSoapTransportFactory
{
    public function __construct(private readonly NfePhpFixtureSoap $soap) {}

    public function make(): SoapInterface
    {
        return $this->soap;
    }
}

final class NfePhpSubmittedInvalidationFixtureProvider implements ErpFiscalProvider, ErpFiscalSubmittedRequestProvider
{
    public function __construct(private readonly string $submitted, private readonly string $processed) {}

    public function transmit(ErpFiscalDocument $document, array $payload): ErpFiscalProviderResult
    {
        throw new \LogicException('Only invalidation submission is expected.');
    }

    public function consult(ErpFiscalDocument $document): ErpFiscalProviderResult
    {
        throw new \LogicException('Only invalidation submission is expected.');
    }

    public function submitEvent(ErpFiscalDocument $document, ErpFiscalEvent $event): ErpFiscalProviderResult
    {
        throw new \LogicException('Only invalidation submission is expected.');
    }

    public function submitInvalidation(ErpFiscalInvalidation $invalidation): ErpFiscalProviderResult
    {
        return new ErpFiscalProviderResult('event_accepted', '135260000000001', xml: $this->processed);
    }

    public function submittedRequestXml(): ?string
    {
        return $this->submitted;
    }
}
