<?php

namespace Tests\Support;

use App\Erp\Fiscal\ErpFiscalArtifactStore;
use App\Erp\Fiscal\ErpFiscalAttemptAuthorization;
use App\Erp\Fiscal\ErpFiscalAttemptService;
use App\Erp\Fiscal\ErpFiscalCertificateRevisionService;
use App\Erp\Fiscal\ErpFiscalCorrelationValidator;
use App\Erp\Fiscal\ErpFiscalSchemaValidator;
use App\Erp\Fiscal\ErpFiscalXmlSigner;
use App\Erp\Fiscal\Nfe55DocumentBuilder;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Sales\ErpSale;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ErpFiscalModelBuilderSchemaTest;

trait BuildsErpFiscalHarness
{
    private function fiscalHarnessHeaders(ErpEstablishment $issuer): array
    {
        return [
            'X-Company-Id' => (string) $issuer->erp_company_id,
            'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'),
        ];
    }

    private function fiscalCertificatePayload(ErpEstablishment $issuer): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => $issuer->cnpj], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($certificate);
        $pkcs12 = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pkcs12, $key, ''));

        return ['erp_establishment_id' => $issuer->id, 'label' => 'Synthetic isolated harness issuer', 'certificate_content' => base64_encode($pkcs12), 'password' => ''];
    }

    private function configureFiscalHarness(ErpEstablishment $issuer, string $environment = 'homologation', int $nextNumber = 1): ErpOperationNature
    {
        Storage::fake('local');
        config(['erp.fiscal_certificate_relaxed_validation' => false]);
        $issuer->update(['cnpj' => '30288513000100']);
        $nature = ErpOperationNature::factory()->create(['erp_company_id' => $issuer->erp_company_id, 'cfop' => '5102', 'movement' => 'out']);
        $headers = $this->fiscalHarnessHeaders($issuer);
        $this->withHeaders($headers)->patchJson('/api/v1/erp/fiscal/configuration/'.$issuer->id, [
            'environment' => $environment,
            'issuer' => ['tax_regime' => 3, 'state_registration' => '110042490114', 'uf' => 'SP',
                'address' => ['street' => 'Rua Um', 'number' => '10', 'district' => 'Centro', 'city_code' => '3550308', 'city_name' => 'Sao Paulo', 'zip_code' => '01001000']],
            'models' => ['55' => ['series' => 1, 'next_number' => $nextNumber, 'operation_nature_id' => $nature->id]],
        ])->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/certificates', $this->fiscalCertificatePayload($issuer))->assertCreated();

        return $nature;
    }

    private function prepareFiscalVariant(ErpProductVariant $variant): void
    {
        $variant->product->update(['ncm' => '12345678', 'origin_code' => '0', 'icms_cst' => '00', 'pis_cst' => '07', 'cofins_cst' => '07']);
        $variant->product->unit->update(['short_name' => 'UN']);
    }

    private function fiscalHarnessCustomer(ErpEstablishment $issuer, string $name = 'Original recipient'): ErpContact
    {
        $recipient = ErpFiscalModelBuilderSchemaTest::builderProvider()['NF-e 55 posted sale'][2]['recipient'];

        return ErpContact::factory()->create(['erp_company_id' => $issuer->erp_company_id, 'name' => $name, 'tax_id' => $recipient['cnpj'], 'address' => $recipient['address'], 'is_customer' => true]);
    }

    /** Derive commercial fields exclusively from evidence accepted by real sale posting. */
    private function fiscalSourceFromAcceptedSale(ErpSale $sale): array
    {
        $this->assertSame('posted', $sale->status);
        $snapshot = $sale->commercial_snapshot;
        $this->assertArrayHasKey('accepted', $snapshot);
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NF-e 55 posted sale'][2];
        $source['recipient'] = ['cnpj' => $snapshot['party']['tax_id'], 'legal_name' => $snapshot['party']['name'], 'state_registration_indicator' => 9, 'address' => $snapshot['party']['address']];
        $source['items'] = array_map(static function (array $line): array {
            $saved = $line['product_snapshot'];
            $product = $saved['product'];

            return [
                'source_line_id' => $line['id'], 'erp_product_variant_id' => $line['erp_product_variant_id'],
                'code' => $saved['variant']['sku'] ?? $product['sku'], 'description' => $product['name'], 'ncm' => $product['ncm'],
                'cfop' => $saved['operation_nature']['cfop'], 'unit' => $saved['unit']['short_name'], 'ind_total' => 1,
                'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'gross_total' => bcadd($line['gross_amount'], '0', 2), 'commercial_gtin' => 'SEM GTIN',
                'tax' => ['origin' => $product['origin_code'], 'icms_cst' => $product['icms_cst'], 'icms_base' => bcadd($line['gross_amount'], '0', 2),
                    'icms_rate' => '0.00', 'icms_value' => '0.00', 'pis_cst' => $product['pis_cst'], 'pis_value' => '0.00', 'cofins_cst' => $product['cofins_cst'], 'cofins_value' => '0.00'],
            ];
        }, $snapshot['lines']);
        $source['totals'] = array_fill_keys(array_keys($source['totals']), '0.00');
        $source['totals']['products'] = bcadd($snapshot['totals']['subtotal_amount'], '0', 2);
        $source['totals']['icms_base'] = $source['totals']['products'];
        $source['totals']['invoice'] = bcadd($snapshot['totals']['total_amount'], '0', 2);
        $source['payment'] = [['indicator' => 0, 'method' => '01', 'amount' => $source['totals']['invoice']]];

        return $source;
    }

    private function postFiscalHarnessSale(ErpEstablishment $issuer, ErpOperationNature $nature, string $key): ErpSale
    {
        $variant = ErpProductVariant::factory()->create(['erp_product_id' => ErpProduct::factory()->create([
            'erp_company_id' => $issuer->erp_company_id, 'erp_unit_id' => ErpUnit::factory()->create(['erp_company_id' => $issuer->erp_company_id])->id, 'track_stock' => false,
        ])->id]);
        $this->prepareFiscalVariant($variant);
        $customer = $this->fiscalHarnessCustomer($issuer);
        $headers = $this->fiscalHarnessHeaders($issuer);
        $sale = $this->withHeaders($headers)->postJson('/api/v1/erp/sales', [
            'erp_establishment_id' => $issuer->id, 'erp_contact_id' => $customer->id, 'erp_operation_nature_id' => $nature->id,
            'lines' => [['erp_product_variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '100']],
        ], ['Idempotency-Key' => $key.'-sale'])->assertCreated()->json('data.id');
        $this->withHeaders($headers)->postJson('/api/v1/erp/sales/'.$sale.'/post', ['draft_revision' => 1], ['Idempotency-Key' => $key.'-post'])->assertOk();

        return ErpSale::findOrFail($sale);
    }

    /** Build signed, schema-valid synthetic acceptance evidence without dispatching or opening a production gate. */
    private function publishSyntheticFiscalEvidence(ErpFiscalDocument $document): void
    {
        $draft = $document->attempts()->where('action', 'document:draft')->sole();
        $snapshot = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($draft);
        $source = $snapshot['request']['source'];
        $material = app(ErpFiscalCertificateRevisionService::class)->material($draft->certificateRevision, $document->establishment);
        $pkcs12 = [];
        $this->assertTrue(openssl_pkcs12_read($material['certificate'], $pkcs12, $material['password']));
        $signer = app(ErpFiscalXmlSigner::class);
        $signed = $signer->sign(app(Nfe55DocumentBuilder::class)->build($source), $pkcs12['pkey'], $pkcs12['cert']);
        $schemas = app(ErpFiscalSchemaValidator::class);
        $schemas->assertValid($signed, '55', 'request');
        $protocol = '135260000000001';
        $environment = $document->environment === 'production' ? 1 : 2;
        $xml = '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'.preg_replace('/^<\?xml[^>]*>\s*/', '', $signed)
            .'<protNFe versao="4.00"><infProt><tpAmb>'.$environment.'</tpAmb><verAplic>TESTE</verAplic><chNFe>'.$document->access_key.'</chNFe><dhRecbto>'.now()->toIso8601String().'</dhRecbto><nProt>'.$protocol.'</nProt><digVal>YWJjZA==</digVal><cStat>100</cStat><xMotivo>Autorizado o uso</xMotivo></infProt></protNFe></nfeProc>';
        $schemas->assertValid($xml, '55', 'processed');
        app(ErpFiscalCorrelationValidator::class)->assertProcessedMatches($xml, [
            'model' => '55', 'access_key' => $document->access_key, 'issuer_cnpj' => $source['issuer']['cnpj'], 'environment' => $document->environment,
            'series' => $document->series, 'number' => $document->number, 'protocol' => $protocol,
        ]);
        $this->assertTrue($signer->verify($xml, $pkcs12['cert']));
        $store = app(ErpFiscalArtifactStore::class);
        $artifact = $store->persistAuthorizedXml($document, $xml);
        $attempt = app(ErpFiscalAttemptService::class)->createIntent([
            ...$draft->only(['erp_company_id', 'erp_establishment_id', 'erp_fiscal_document_id', 'model', 'environment', 'series', 'number', 'actor_user_id', 'erp_fiscal_configuration_revision_id', 'erp_fiscal_certificate_revision_id', 'schema_release']),
            'action' => 'document:transmit', 'idempotency_key' => $document->idempotency_key.'-synthetic-evidence', 'request' => $snapshot['request'],
        ]);
        $attempt->forceFill(['status' => 'accepted', 'outbox_state' => 'completed', 'completed_at' => now(), 'provider_protocol' => $protocol,
            'safe_result' => ['artifact_ready' => true, 'artifact_sha256' => $artifact['artifact_sha256'], 'artifact_byte_length' => $artifact['artifact_byte_length']]])->save();
        $document->update([...$artifact, 'status' => 'authorized', 'authorized_at' => now(), 'metadata' => [...$document->metadata, 'provider_protocol' => $protocol]]);
        $this->assertSame(hash('sha256', $xml), $document->fresh()->artifact_sha256);
        $this->assertSame(strlen($xml), $document->fresh()->artifact_byte_length);
        $this->assertTrue($store->verifyIntegrity($document->fresh()));
        $this->assertTrue($store->verifiedFiscalArtifactReady($document->fresh()));
        $this->assertSame($xml, $store->readXml($document->fresh()));
    }
}
