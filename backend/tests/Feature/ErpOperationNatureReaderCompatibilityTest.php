<?php

namespace Tests\Feature;

use App\Erp\Fiscal\ErpFiscalAttemptAuthorization;
use App\Erp\Fiscal\ErpFiscalSourceSnapshotFactory;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Fiscal\ErpFiscalAttempt;
use App\Models\Erp\Fiscal\ErpFiscalConfiguration;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleReturn;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpPurchaseLine;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsErpFiscalHarness;
use Tests\Support\ErpCommercialTestCase;

class ErpOperationNatureReaderCompatibilityTest extends ErpCommercialTestCase
{
    use BuildsErpFiscalHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials()->withCookie(config('session.cookie'), $this->app['session']->getId());
    }

    public function test_purchase_lines_keep_only_their_explicit_company_operation_nature_without_cfop_resolution(): void
    {
        $firstNature = ErpOperationNature::factory()->create([
            'erp_company_id' => $this->company->id,
            'cfop' => '5102',
            'movement' => 'out',
        ]);
        $secondNature = ErpOperationNature::factory()->create([
            'erp_company_id' => $this->company->id,
            'cfop' => '5202',
            'movement' => 'out',
        ]);
        $secondVariant = ErpProductVariant::factory()->create(['erp_product_id' => $this->variant->erp_product_id]);
        $purchaseId = $this->send('purchases', [
            'erp_establishment_id' => $this->establishment->id,
            'erp_contact_id' => $this->manualPurchaseSupplier()->id,
            'lines' => [
                ['erp_product_variant_id' => $this->variant->id, 'quantity' => '1', 'unit_cost' => '10', 'erp_operation_nature_id' => $firstNature->id],
                ['erp_product_variant_id' => $secondVariant->id, 'quantity' => '2', 'unit_cost' => '15', 'erp_operation_nature_id' => $secondNature->id],
                ['erp_product_variant_id' => ErpProductVariant::factory()->create(['erp_product_id' => $this->variant->erp_product_id])->id, 'quantity' => '1', 'unit_cost' => '20'],
            ],
        ])->assertCreated()->json('data.id');

        $purchase = $this->getJson('/api/v1/erp/purchases/'.$purchaseId)->assertOk()->json('data');
        $this->assertSame($firstNature->id, $purchase['lines'][0]['product_snapshot']['operation_nature']['id']);
        $this->assertSame('5102', $purchase['lines'][0]['product_snapshot']['operation_nature']['cfop']);
        $this->assertSame($secondNature->id, $purchase['lines'][1]['product_snapshot']['operation_nature']['id']);
        $this->assertSame('5202', $purchase['lines'][1]['product_snapshot']['operation_nature']['cfop']);
        $this->assertNull($purchase['lines'][2]['product_snapshot']['operation_nature']);
        foreach ($purchase['lines'] as $line) {
            $this->assertArrayNotHasKey('cfop_resolution', $line['product_snapshot']);
        }

        $foreignNature = ErpOperationNature::factory()->create(['cfop' => '5102']);
        $this->send('purchases', [
            'erp_establishment_id' => $this->establishment->id,
            'erp_contact_id' => $this->manualPurchaseSupplier()->id,
            'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '1', 'unit_cost' => '10', 'erp_operation_nature_id' => $foreignNature->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.erp_operation_nature_id');
        $this->assertSame(1, ErpPurchase::query()->count());
        $this->assertSame(3, ErpPurchaseLine::query()->count());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function saleReturnCodes(): iterable
    {
        yield 'legacy supported local return' => ['1202', true];
        yield 'legacy supported interstate return' => ['2202', true];
        yield 'other incoming CFOP remains unsupported' => ['1102', false];
    }

    #[DataProvider('saleReturnCodes')]
    public function test_sale_return_reader_preserves_legacy_single_cfop_and_only_supported_return_codes(string $cfop, bool $supported): void
    {
        $saleNature = $this->configureFiscalHarness($this->establishment, nextNumber: 17);
        $sale = $this->postHistoricalFiscalHarnessSale($this->establishment, $saleNature, 'reader-return-'.$cfop);
        $sale->load('lines');
        $legacyLine = $sale->lines->sole();
        $legacyLineSnapshot = $legacyLine->product_snapshot;
        $legacyLineSnapshot['version'] = 'commercial-v1';
        $legacyLineSnapshot['variant'] = null;
        $legacyLineSnapshot['operation_nature'] = $saleNature->only(['id', 'cfop', 'description', 'movement', 'erp_establishment_id']);
        unset($legacyLineSnapshot['product_tax_resolution'], $legacyLineSnapshot['product_tax_configuration'], $legacyLineSnapshot['cfop_resolution']);
        $legacyLine->update(['erp_product_variant_id' => null, 'product_snapshot' => $legacyLineSnapshot]);
        $legacyCommercialSnapshot = $sale->commercial_snapshot;
        $legacyCommercialSnapshot['version'] = 'commercial-v1';
        $legacyCommercialSnapshot['document']['fiscal_model'] = null;
        $legacyCommercialSnapshot['fiscal_context'] = [
            'model' => null,
            'schema_version' => null,
            'readiness' => 'unverified',
            'issuer_resolution' => null,
        ];
        $legacyCommercialSnapshot['lines'] = $sale->fresh('lines')->lines->map(fn ($line): array => $line->attributesToArray())->all();
        $sale->update(['fiscal_model' => null, 'commercial_snapshot' => $legacyCommercialSnapshot]);
        $sale = $sale->fresh();
        $this->assertSame('commercial-v1', $sale->commercial_snapshot['version']);
        $saleLineSnapshot = $sale->commercial_snapshot['lines'][0]['product_snapshot'];
        $this->assertSame('5102', $saleLineSnapshot['operation_nature']['cfop']);
        $this->assertArrayNotHasKey('cfop_resolution', $saleLineSnapshot);

        $saleSource = $this->fiscalSourceFromAcceptedSale($sale);
        $saleSource['items'][0]['tax']['icms_rate'] = $saleLineSnapshot['product']['icms_rate'];
        $originalId = $this->send('fiscal/documents', [
            'erp_sale_id' => $sale->id,
            'erp_establishment_id' => $this->establishment->id,
            'model' => '55',
            'number' => 17,
            'idempotency_key' => 'reader-return-original-'.$cfop,
            'source' => $saleSource,
        ])->assertCreated()->json('data.id');
        $original = ErpFiscalDocument::query()->findOrFail($originalId);
        $original->update(['status' => 'authorized', 'authorized_at' => now(), 'metadata' => ['document_kind' => 'posted_sale', 'provider_protocol' => 'synthetic-reader-contract']]);
        $this->assertSame($sale->commercial_snapshot, $original->fresh()->commercial_snapshot);
        $this->assertArrayNotHasKey('cfop_resolution', $original->fresh()->commercial_snapshot['lines'][0]['product_snapshot']);

        $line = $sale->lines()->firstOrFail();
        $returnId = $this->send('sale-returns', [
            'erp_sale_id' => $sale->id,
            'reason' => 'Leitura de compatibilidade',
            'lines' => [['source_line_id' => $line->id, 'quantity' => '1']],
        ])->assertCreated()->json('data.id');
        $this->send('sale-returns/'.$returnId.'/post', [])->assertOk();
        $return = ErpSaleReturn::query()->with('lines')->findOrFail($returnId);
        $returnLine = $return->lines->sole();

        $returnNature = ErpOperationNature::factory()->create([
            'erp_company_id' => $this->company->id,
            'cfop' => $cfop,
            'description' => 'Natureza de devolução de teste',
            'movement' => 'in',
        ]);
        $configuration = ErpFiscalConfiguration::query()->where('erp_establishment_id', $this->establishment->id)->firstOrFail()->currentRevision->configuration;
        $configuration['models']['55']['next_number'] = 18;
        $configuration['models']['55']['operation_nature_id'] = $returnNature->id;
        $this->withHeaders($this->fiscalHarnessHeaders($this->establishment))
            ->patchJson('/api/v1/erp/fiscal/configuration/'.$this->establishment->id, $configuration)->assertOk();

        $source = $saleSource;
        $source['identity']['operation_type'] = 0;
        $source['payment'] = [['indicator' => 0, 'method' => '90', 'amount' => '0.00']];
        $source['items'][0]['cfop'] = $cfop;
        $source['items'][0]['source_return_line_id'] = $returnLine->id;
        $source['items'][0]['quantity'] = $returnLine->quantity;
        $source['items'][0]['gross_total'] = bcadd($returnLine->subtotal_amount, '0', 2);
        $source['totals']['products'] = bcadd($return->subtotal_amount, '0', 2);
        $source['totals']['icms_base'] = $source['totals']['products'];
        $source['totals']['invoice'] = bcadd($return->total_amount, '0', 2);
        $body = [
            'erp_establishment_id' => $this->establishment->id,
            'model' => '55',
            'document_kind' => 'return',
            'source_document_id' => $original->id,
            'erp_sale_return_id' => $return->id,
            'number' => 18,
            'idempotency_key' => 'reader-return-document-'.$cfop,
            'source' => $source,
        ];

        $response = $this->send('fiscal/documents', $body);
        if (! $supported) {
            $response->assertUnprocessable()->assertJsonValidationErrors('source');
            $this->assertSame(1, ErpFiscalDocument::query()->count());
            $this->assertSame(1, ErpFiscalAttempt::query()->count());

            return;
        }

        $documentId = $response->assertCreated()->json('data.id');
        $attempt = ErpFiscalDocument::query()->findOrFail($documentId)->attempts()->where('action', 'document:draft')->sole();
        $frozen = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($attempt)['request']['source'];
        $this->assertSame($cfop, $frozen['operation_nature']['cfop']);
        $this->assertSame($cfop, $frozen['items'][0]['cfop']);
        $this->assertSame($returnLine->id, $frozen['items'][0]['source_return_line_id']);
        $this->assertSame($return->id, $frozen['commercial_return']['return_id']);
        $this->assertSame(2, ErpFiscalDocument::query()->count());
    }

    public function test_cte_and_mdfe_keep_their_native_typed_source_groups_and_required_checks(): void
    {
        $providers = ErpFiscalModelBuilderSchemaTest::builderProvider();
        $factory = app(ErpFiscalSourceSnapshotFactory::class);
        foreach ([
            ['CT-e 57 typed transport service', 'transport_service'],
            ['MDF-e 58 typed manifest', 'transport_manifest'],
        ] as [$providerName, $group]) {
            [, $model, $source] = $providers[$providerName];
            $accepted = $factory->create($model, $source);
            $this->assertArrayHasKey($group, $accepted);
            $this->assertArrayNotHasKey('items', $accepted);
            if ($model === '57') {
                $this->assertSame($source['transport_service']['operation_nature'], $accepted['transport_service']['operation_nature']);
                $this->assertSame($source['transport_service']['cfop'], $accepted['transport_service']['cfop']);
            } else {
                $this->assertSame($source['transport_manifest']['documents'][0]['model'], $accepted['transport_manifest']['documents'][0]['model']);
                $this->assertSame($source['transport_manifest']['documents'][0]['access_key'], $accepted['transport_manifest']['documents'][0]['access_key']);
                $this->assertSame($source['transport_manifest']['documents'][0]['municipality_code'], $accepted['transport_manifest']['documents'][0]['municipality_code']);
                $this->assertArrayNotHasKey('cfop', $accepted['transport_manifest']);
            }

            unset($source[$group]);
            try {
                $factory->create($model, $source);
                $this->fail($model.' must reject a missing native typed source group.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($group, $exception->getMessage());
            }
        }
    }

    private function postHistoricalFiscalHarnessSale(ErpEstablishment $issuer, ErpOperationNature $nature, string $key): ErpSale
    {
        $unit = ErpUnit::factory()->create(['erp_company_id' => $issuer->erp_company_id]);
        $product = ErpProduct::factory()->create([
            'erp_company_id' => $issuer->erp_company_id,
            'erp_unit_id' => $unit->id,
            'track_stock' => false,
            'icms_rate' => '18.0000',
        ]);
        $variant = ErpProductVariant::factory()->create(['erp_product_id' => $product->id]);
        $this->prepareFiscalVariant($variant);
        $customer = $this->fiscalHarnessCustomer($issuer);
        $headers = $this->fiscalHarnessHeaders($issuer);
        $saleId = $this->withHeaders($headers)->postJson('/api/v1/erp/sales', [
            'erp_establishment_id' => $issuer->id,
            'erp_contact_id' => $customer->id,
            'erp_operation_nature_id' => $nature->id,
            'lines' => [['erp_product_variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '100']],
        ], ['Idempotency-Key' => $key.'-sale'])->assertCreated()->json('data.id');
        $this->withHeaders($headers)->postJson('/api/v1/erp/sales/'.$saleId.'/post', ['draft_revision' => 1], ['Idempotency-Key' => $key.'-post'])->assertOk();

        return ErpSale::findOrFail($saleId);
    }
}
