<?php

namespace Tests\Feature;

use App\Erp\Fiscal\ErpFiscalAttemptAuthorization;
use App\Erp\Fiscal\ErpFiscalNumberAllocator;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\Fiscal\ErpFiscalConfiguration;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Sales\ErpSale;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsErpFiscalHarness;
use Tests\Support\BuildsReviewedCommercialTax;
use Tests\Support\ErpCommercialTestCase;

class ErpFiscalFrozenCommercialSourceTest extends ErpCommercialTestCase
{
    use BuildsErpFiscalHarness;
    use BuildsReviewedCommercialTax;

    private array $issuerConfiguration = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials()->withCookie(config('session.cookie'), $this->app['session']->getId());
    }

    /** @return iterable<string, array{string}> */
    public static function goodsModels(): iterable
    {
        yield 'NF-e 55' => ['55'];
        yield 'NFC-e 65' => ['65'];
    }

    #[DataProvider('goodsModels')]
    public function test_linked_fiscal_source_preserves_reviewed_exception_and_tax_facts_after_catalog_edits(string $model): void
    {
        $sale = $this->createReadyPostedSale($model, 'frozen-'.$model);
        $saleId = $sale->id;
        $contact = ErpContact::query()->findOrFail($sale->erp_contact_id);
        $frozen = $sale->commercial_snapshot;
        $source = $this->sourceFromFrozenSale($sale, $model);

        $this->changeFiscalProfileAfterPosting();
        $this->variant->product()->update(['status' => 'archived']);
        $this->deleteJson('/api/v1/erp/tax-settings/product-tax-profiles/'.$this->taxProfileId)->assertOk();
        $contact->update(['status' => 'archived', 'name' => 'Destinatário alterado']);

        $documentId = $this->send('fiscal/documents', [
            'erp_sale_id' => $saleId,
            'erp_establishment_id' => $this->establishment->id,
            'model' => $model,
            'number' => 17,
            'idempotency_key' => 'frozen-source-'.$model,
            'source' => $source,
        ])->assertCreated()->json('data.id');

        $document = ErpFiscalDocument::query()->findOrFail($documentId);
        $this->assertSame($frozen, $document->commercial_snapshot);
        $this->assertSame('5101', $source['items'][0]['cfop']);
        $this->assertSame('5102', $frozen['lines'][0]['product_snapshot']['operation_nature']['default_cfop']);
        $this->assertSame('product_exception', $frozen['lines'][0]['product_snapshot']['cfop_resolution']['source']);
        $this->assertSame('profile', $frozen['lines'][0]['product_snapshot']['product_tax_resolution']['sources']['pis_cst']['source']);
        $this->assertSame('07', $frozen['lines'][0]['product_snapshot']['product']['pis_cst']);
        $this->assertSame($frozen, $sale->fresh()->commercial_snapshot);

        $attempt = $document->attempts()->where('action', 'document:draft')->sole();
        $request = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($attempt)['request'];
        $this->assertSame($source['items'][0]['tax']['icms_value'], $request['source']['items'][0]['tax']['icms_value']);
        $this->assertSame($source['items'][0]['tax']['pis_value'], $request['source']['items'][0]['tax']['pis_value']);
        $this->assertSame($source['items'][0]['tax']['cofins_value'], $request['source']['items'][0]['tax']['cofins_value']);
        $this->assertDatabaseMissing('erp_fiscal_attempts', ['action' => 'document:transmit']);
    }

    public function test_linked_sale_rejects_an_issuer_nature_that_does_not_match_the_frozen_sale_before_persistence(): void
    {
        $sale = $this->createReadyPostedSale('55', 'nature');
        $saleId = $sale->id;
        $source = $this->sourceFromFrozenSale($sale, '55');

        $otherNature = ErpOperationNature::factory()->create([
            'erp_company_id' => $this->company->id,
            'cfop' => '5102',
            'movement' => 'out',
        ]);
        $this->configureFiscalNature($otherNature->id, '55');

        $this->send('fiscal/documents', [
            'erp_sale_id' => $saleId,
            'erp_establishment_id' => $this->establishment->id,
            'model' => '55',
            'number' => 17,
            'idempotency_key' => 'nature-mismatch',
            'source' => $source,
        ])->assertUnprocessable()->assertJsonValidationErrors('source.identity.operation_nature');

        $this->assertDatabaseCount('erp_fiscal_documents', 0);
        $this->assertDatabaseCount('erp_fiscal_attempts', 0);
        $this->assertSame(17, app(ErpFiscalNumberAllocator::class)->nextNumber($this->company->id, '55', 'homologation', 1));
    }

    public function test_linked_source_rejects_each_existing_party_item_tax_and_amount_divergence(): void
    {
        $sale = $this->createReadyPostedSale('55', 'equality');
        $saleId = $sale->id;
        $source = $this->sourceFromFrozenSale($sale, '55');

        $mutations = [
            'recipient' => static function (array &$candidate): void {
                $candidate['recipient']['cnpj'] = '12345678000195';
            },
            'line id' => static function (array &$candidate): void {
                $candidate['items'][0]['source_line_id']++;
            },
            'variant' => static function (array &$candidate): void {
                $candidate['items'][0]['erp_product_variant_id']++;
            },
            'code' => static function (array &$candidate): void {
                $candidate['items'][0]['code'] = 'OTHER-SKU';
            },
            'NCM' => static function (array &$candidate): void {
                $candidate['items'][0]['ncm'] = '87654321';
            },
            'CFOP' => static function (array &$candidate): void {
                $candidate['items'][0]['cfop'] = '5102';
            },
            'unit' => static function (array &$candidate): void {
                $candidate['items'][0]['unit'] = 'CX';
            },
            'origin' => static function (array &$candidate): void {
                $candidate['items'][0]['tax']['origin'] = '1';
            },
            'ICMS rate' => static function (array &$candidate): void {
                $candidate['items'][0]['tax']['icms_rate'] = '17.0000';
            },
            'PIS classification' => static function (array &$candidate): void {
                $candidate['items'][0]['tax']['pis_cst'] = '08';
            },
            'COFINS classification' => static function (array &$candidate): void {
                $candidate['items'][0]['tax']['cofins_cst'] = '08';
            },
            'quantity' => static function (array &$candidate): void {
                $candidate['items'][0]['quantity'] = '3.0000';
            },
            'unit price' => static function (array &$candidate): void {
                $candidate['items'][0]['unit_price'] = '11.0000000000';
            },
            'gross total' => static function (array &$candidate): void {
                $candidate['items'][0]['gross_total'] = bcadd($candidate['items'][0]['gross_total'], '1.00', 2);
                $candidate['totals']['products'] = bcadd($candidate['totals']['products'], '1.00', 2);
                $candidate['totals']['icms_base'] = bcadd($candidate['totals']['icms_base'], '1.00', 2);
            },
            'invoice total' => static function (array &$candidate): void {
                $candidate['totals']['invoice'] = bcadd($candidate['totals']['invoice'], '1.00', 2);
            },
        ];
        foreach ($mutations as $label => $mutate) {
            $candidate = $source;
            $mutate($candidate);
            $response = $this->send('fiscal/documents', [
                'erp_sale_id' => $saleId,
                'erp_establishment_id' => $this->establishment->id,
                'model' => '55',
                'number' => 17,
                'idempotency_key' => 'equality-'.$label,
                'source' => $candidate,
            ]);
            $this->assertSame(422, $response->status(), 'The linked fiscal source must reject divergent '.$label.'.');
        }

        $this->assertDatabaseCount('erp_fiscal_documents', 0);
        $this->assertDatabaseCount('erp_fiscal_attempts', 0);
        $this->assertSame($source, $this->sourceFromFrozenSale($sale->fresh(), '55'));
    }

    public function test_incomplete_legacy_sale_snapshot_is_not_repaired_from_the_current_nature_or_product(): void
    {
        $sale = $this->createReadyPostedSale('55', 'legacy')->load('lines');
        $saleId = $sale->id;
        $line = $sale->lines->sole();
        $lineSnapshot = $line->product_snapshot;
        $lineSnapshot['version'] = 'commercial-v1';
        $lineSnapshot['operation_nature']['cfop'] = null;
        unset($lineSnapshot['operation_nature']['default_cfop'], $lineSnapshot['operation_nature']['configuration_revision']);
        unset($lineSnapshot['product_tax_resolution'], $lineSnapshot['product_tax_configuration'], $lineSnapshot['cfop_resolution']);
        $line->update(['product_snapshot' => $lineSnapshot]);

        $legacy = $sale->commercial_snapshot;
        $legacy['version'] = 'commercial-v1';
        unset($legacy['document']['fiscal_model']);
        $legacy['fiscal_context'] = ['model' => null, 'schema_version' => null, 'readiness' => 'unverified', 'issuer_resolution' => null];
        $legacy['lines'] = $sale->fresh('lines')->lines->map(fn ($saved): array => $saved->attributesToArray())->all();
        $sale->update(['fiscal_model' => null, 'commercial_snapshot' => $legacy]);
        $source = $this->sourceFromFrozenSale($sale->fresh(), '55', submittedCfop: '5102');

        $this->send('fiscal/documents', [
            'erp_sale_id' => $saleId,
            'erp_establishment_id' => $this->establishment->id,
            'model' => '55',
            'number' => 17,
            'idempotency_key' => 'legacy-incomplete-source',
            'source' => $source,
        ])->assertUnprocessable()->assertJsonValidationErrors('source.items.0');

        $this->assertNull($sale->fresh()->commercial_snapshot['lines'][0]['product_snapshot']['operation_nature']['cfop']);
        $this->assertDatabaseCount('erp_fiscal_documents', 0);
        $this->assertDatabaseCount('erp_fiscal_attempts', 0);
        $this->assertSame(17, app(ErpFiscalNumberAllocator::class)->nextNumber($this->company->id, '55', 'homologation', 1));
    }

    public function test_complete_historical_single_cfop_snapshot_is_accepted_without_fabricated_provenance(): void
    {
        $sale = $this->createReadyPostedSale('55', 'legacy-complete');
        $sale->load('lines');
        $line = $sale->lines->sole();
        $lineFacts = $line->product_snapshot;
        $lineFacts['version'] = 'commercial-v1';
        $lineFacts['variant'] = null;
        unset($lineFacts['product_tax_resolution'], $lineFacts['product_tax_configuration'], $lineFacts['cfop_resolution']);
        $lineFacts['operation_nature'] = array_intersect_key($lineFacts['operation_nature'], array_flip(['id', 'cfop', 'description', 'movement', 'erp_establishment_id']));
        $line->update(['erp_product_variant_id' => null, 'product_snapshot' => $lineFacts]);

        $frozen = $sale->commercial_snapshot;
        $frozen['version'] = 'commercial-v1';
        $frozen['document']['fiscal_model'] = null;
        $frozen['fiscal_context'] = ['model' => null, 'schema_version' => null, 'readiness' => 'unverified', 'issuer_resolution' => null];
        $frozen['lines'] = $sale->fresh('lines')->lines->map(fn ($saved): array => $saved->attributesToArray())->all();
        $sale->update(['fiscal_model' => null, 'commercial_snapshot' => $frozen]);
        $sale = $sale->fresh();
        $source = $this->sourceFromHistoricalSale($sale);
        $this->assertNull($source['items'][0]['erp_product_variant_id']);
        $this->assertSame('5101', $source['items'][0]['cfop']);
        $this->assertSame('5102', ErpOperationNature::query()->findOrFail($this->natureId)->cfop);

        $this->changeFiscalProfileAfterPosting();
        $this->variant->product()->update(['status' => 'archived']);
        $this->deleteJson('/api/v1/erp/tax-settings/product-tax-profiles/'.$this->taxProfileId)->assertOk();
        ErpContact::query()->findOrFail($sale->erp_contact_id)->update(['status' => 'archived']);

        $documentId = $this->send('fiscal/documents', [
            'erp_sale_id' => $sale->id,
            'erp_establishment_id' => $this->establishment->id,
            'model' => '55',
            'number' => 17,
            'idempotency_key' => 'legacy-complete-source',
            'source' => $source,
        ])->assertCreated()->json('data.id');

        $document = ErpFiscalDocument::query()->findOrFail($documentId);
        $this->assertSame($frozen, $document->commercial_snapshot);
        $this->assertSame($frozen, $sale->fresh()->commercial_snapshot);
        $this->assertArrayNotHasKey('provenance', $source['items'][0]);
        $this->assertArrayNotHasKey('cfop_resolution', $document->commercial_snapshot['lines'][0]['product_snapshot']);
        $this->assertArrayNotHasKey('product_tax_resolution', $document->commercial_snapshot['lines'][0]['product_snapshot']);
        $this->assertDatabaseMissing('erp_fiscal_attempts', ['action' => 'document:transmit']);
    }

    private function createReadyPostedSale(string $model, string $key): ErpSale
    {
        $this->configureReviewedCommercialTax();
        $this->patchJson('/api/v1/erp/tax-settings/product-tax-profiles/'.$this->taxProfileId, [
            'fields' => ['icms_cst' => '00', 'pis_cst' => '07', 'cofins_cst' => '07', 'icms_rate' => '18.0000'],
            'expected_revision' => 1,
        ])->assertOk();
        $this->patchJson('/api/v1/erp/products/'.$this->variant->erp_product_id, [
            'ncm' => '12345678', 'origin_code' => '0', 'sku' => 'READY-SKU', 'track_stock' => false,
            'tax_field_intents' => ['icms_cst' => 'inherit', 'pis_cst' => 'inherit', 'cofins_cst' => 'inherit', 'icms_rate' => 'inherit'],
        ])->assertOk();
        $this->variant->product->unit->update(['short_name' => 'UN']);

        $typed = ErpFiscalModelBuilderSchemaTest::builderProvider()['NF-e 55 posted sale'][2];
        $customer = ErpContact::factory()->create([
            'erp_company_id' => $this->company->id, 'name' => $typed['recipient']['legal_name'],
            'tax_id' => $typed['recipient']['cnpj'], 'address' => $typed['recipient']['address'], 'is_customer' => true,
        ]);
        $issuerAddress = $typed['issuer']['address'];
        $this->establishment->update(['cnpj' => $typed['issuer']['cnpj']]);
        $this->issuerConfiguration = [
            'environment' => 'homologation',
            'issuer' => ['tax_regime' => 3, 'state_registration' => $typed['issuer']['state_registration'], 'uf' => $issuerAddress['uf'],
                'address' => ['street' => $issuerAddress['street'], 'number' => $issuerAddress['number'], 'district' => $issuerAddress['district'],
                    'city_code' => $issuerAddress['city_code'], 'city_name' => $issuerAddress['city'], 'zip_code' => $issuerAddress['postal_code']]],
            'models' => [$model => ['series' => 1, 'next_number' => 17, 'operation_nature_id' => $this->natureId,
                ...($model === '65' ? ['nfce_qr' => ['version' => 3, 'mode' => 'online', 'consultation_url' => 'https://test.invalid/consulta']] : [])]],
        ];
        $headers = $this->fiscalHarnessHeaders($this->establishment);
        $this->withHeaders($headers)->patchJson('/api/v1/erp/fiscal/configuration/'.$this->establishment->id, $this->issuerConfiguration)->assertOk();
        Storage::fake('local');
        config(['erp.fiscal_certificate_relaxed_validation' => false]);
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/certificates', $this->fiscalCertificatePayload($this->establishment))->assertCreated();

        $saleId = $this->send('sales', $this->reviewedBody([
            'fiscal_model' => $model,
            'erp_contact_id' => $customer->id,
            'tax_context' => $this->classificationContext(['model' => $model, 'final_consumer' => true, 'presence' => '1']),
            'lines' => [$this->reviewedLine(['quantity' => '1', 'unit_price' => '100', 'discount_amount' => '0', 'erp_tax_rate_id' => null])],
        ]), $key.'-sale')->assertCreated()->json('data.id');
        $this->send('sales/'.$saleId.'/post', ['draft_revision' => 1], $key.'-post')->assertOk();
        $sale = ErpSale::query()->findOrFail($saleId);
        $this->assertSame($this->natureId, (int) $sale->erp_operation_nature_id);
        $this->assertSame($this->natureId, (int) $sale->commercial_snapshot['lines'][0]['product_snapshot']['operation_nature']['id']);
        $this->assertSame($this->natureId, (int) $this->issuerConfiguration['models'][$model]['operation_nature_id']);
        $configuration = ErpFiscalConfiguration::query()->where('erp_establishment_id', $this->establishment->id)->firstOrFail()->currentRevision->configuration;
        $this->assertSame($this->natureId, (int) $configuration['models'][$model]['operation_nature_id']);
        $this->assertSame($model, $sale->commercial_snapshot['fiscal_context']['model']);
        $this->assertSame('source_prerequisites_checked', $sale->commercial_snapshot['fiscal_context']['readiness']);

        return $sale->fresh();
    }

    private function configureFiscalNature(int $natureId, string $model): void
    {
        $this->issuerConfiguration['models'][$model]['operation_nature_id'] = $natureId;
        $this->patchJson('/api/v1/erp/fiscal/configuration/'.$this->establishment->id, $this->issuerConfiguration)->assertOk();
    }

    private function changeFiscalProfileAfterPosting(): void
    {
        $this->patchJson('/api/v1/erp/tax-settings/product-tax-profiles/'.$this->taxProfileId, [
            'fields' => ['pis_cst' => '06', 'pis_rate' => '0.0000'],
            'expected_revision' => 2,
        ])->assertOk()->assertJsonPath('data.current_revision', 3);
    }

    private function sourceFromFrozenSale(ErpSale $sale, string $model, ?string $submittedCfop = null): array
    {
        $this->assertSame('posted', $sale->status);
        $snapshot = $sale->commercial_snapshot;
        $this->assertArrayHasKey('accepted', $snapshot);
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()[$model === '65' ? 'NFC-e 65 posted sale' : 'NF-e 55 posted sale'][2];
        $source['recipient'] = [
            'cnpj' => $snapshot['party']['tax_id'],
            'legal_name' => $snapshot['party']['name'],
            'state_registration_indicator' => $source['recipient']['state_registration_indicator'],
            'address' => $snapshot['party']['address'],
        ];
        $source['items'] = array_map(function (array $line) use ($submittedCfop): array {
            $facts = $line['product_snapshot'];
            $product = $facts['product'];
            $typed = [
                'source_line_id' => $line['id'],
                'erp_product_variant_id' => $line['erp_product_variant_id'],
                'code' => $facts['variant']['sku'] ?? $product['sku'],
                'description' => $product['name'],
                'ncm' => $product['ncm'],
                'cfop' => $submittedCfop ?? $facts['operation_nature']['cfop'],
                'unit' => $facts['unit']['short_name'],
                'ind_total' => 1,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'gross_total' => bcadd($line['gross_amount'], '0', 2),
                'commercial_gtin' => 'SEM GTIN',
                'tax' => [
                    'origin' => $product['origin_code'] ?? '0',
                    'icms_cst' => $product['icms_cst'] ?? '00',
                    'icms_base' => bcadd($line['gross_amount'], '0', 2),
                    'icms_rate' => $product['icms_rate'] ?? '0.0000',
                    'icms_value' => '18.00',
                    'pis_cst' => $product['pis_cst'] ?? '07',
                    'pis_value' => '0.00',
                    'cofins_cst' => $product['cofins_cst'] ?? '07',
                    'cofins_value' => '0.00',
                ],
            ];
            if (($facts['version'] ?? null) !== 'commercial-v2') {
                $typed['tax'] = [
                    ...$typed['tax'],
                    'origin' => '0', 'icms_cst' => '00', 'pis_cst' => '07', 'cofins_cst' => '07',
                ];
            }

            return $typed;
        }, $snapshot['lines']);
        $source['identity']['operation_nature'] = $snapshot['lines'][0]['product_snapshot']['operation_nature']['description'];
        $source['totals']['products'] = bcadd($snapshot['totals']['subtotal_amount'], '0', 2);
        $source['totals']['icms_base'] = $source['totals']['products'];
        $source['totals']['invoice'] = bcadd($snapshot['totals']['total_amount'], '0', 2);
        $source['payment'] = [['indicator' => 0, 'method' => '01', 'amount' => $source['totals']['invoice']]];

        return $source;
    }

    private function sourceFromHistoricalSale(ErpSale $sale): array
    {
        $snapshot = $sale->commercial_snapshot;
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NF-e 55 posted sale'][2];
        $source['recipient'] = [
            'cnpj' => $snapshot['party']['tax_id'], 'legal_name' => $snapshot['party']['name'],
            'state_registration_indicator' => $source['recipient']['state_registration_indicator'], 'address' => $snapshot['party']['address'],
        ];
        $facts = $snapshot['lines'][0]['product_snapshot'];
        $source['identity']['operation_nature'] = $facts['operation_nature']['description'];
        $source['items'] = array_map(function (array $line) use ($facts, $source): array {
            $product = $facts['product'];
            $typed = $source['items'][0];

            return [
                ...$typed,
                'source_line_id' => $line['id'], 'erp_product_variant_id' => $line['erp_product_variant_id'],
                'code' => $facts['variant']['sku'] ?? $product['sku'], 'description' => $product['name'],
                'ncm' => $product['ncm'], 'cfop' => $facts['operation_nature']['cfop'], 'unit' => $facts['unit']['short_name'],
                'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'gross_total' => bcadd($line['gross_amount'], '0', 2),
                'tax' => [...$typed['tax'], 'origin' => $product['origin_code'], 'icms_cst' => $product['icms_cst'],
                    'icms_rate' => $product['icms_rate'], 'pis_cst' => $product['pis_cst'], 'cofins_cst' => $product['cofins_cst']],
            ];
        }, $snapshot['lines']);
        $source['totals']['products'] = bcadd($snapshot['totals']['subtotal_amount'], '0', 2);
        $source['totals']['invoice'] = bcadd($snapshot['totals']['total_amount'], '0', 2);
        $source['totals']['icms_base'] = $source['totals']['products'];
        $source['payment'] = [['indicator' => 0, 'method' => '01', 'amount' => $source['totals']['invoice']]];

        return $source;
    }
}
