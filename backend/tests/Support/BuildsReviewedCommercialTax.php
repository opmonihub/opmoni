<?php

namespace Tests\Support;

use App\Erp\Sales\ErpCommercialDraftService;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpTaxRate;

trait BuildsReviewedCommercialTax
{
    protected int $taxProfileId;

    protected int $natureId;

    protected int $exceptionId;

    protected ErpTaxRate $commercialTaxRate;

    protected function configureReviewedCommercialTax(): void
    {
        $this->company->update(['operating_settings' => ['regime' => '3']]);
        $this->variant->update(['sale_price' => '10.0000']);
        $this->taxProfileId = $this->postJson('/api/v1/erp/tax-settings/product-tax-profiles', [
            'name' => 'Perfil revisado',
            'fields' => ['icms_cst' => '00', 'pis_cst' => '01', 'pis_rate' => '0.0000', 'icms_rate' => '18.0000'],
            'applicability' => ['kind' => 'goods', 'tax_regime' => '3', 'movement' => 'out'],
        ])->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/erp/products/'.$this->variant->erp_product_id, [
            'erp_product_tax_profile_id' => $this->taxProfileId,
            'tax_field_intents' => ['icms_cst' => 'inherit', 'pis_cst' => 'inherit', 'pis_rate' => 'inherit', 'icms_rate' => 'inherit'],
        ])->assertOk();
        $this->natureId = $this->postJson('/api/v1/erp/tax-settings/operation-natures', [
            'cfop' => '5102', 'description' => 'Venda de mercadorias', 'movement' => 'out',
        ])->assertCreated()->json('data.id');
        $choice = $this->postJson('/api/v1/erp/tax-settings/operation-natures/'.$this->natureId.'/choices', [
            'cfop' => '5101', 'expected_revision' => 1,
        ])->assertCreated()->json('data.id');
        $this->exceptionId = $this->postJson('/api/v1/erp/tax-settings/operation-natures/'.$this->natureId.'/product-exceptions', [
            'erp_product_id' => $this->variant->erp_product_id, 'erp_operation_nature_cfop_choice_id' => $choice, 'expected_revision' => 2,
        ])->assertCreated()->json('data.id');
        $this->commercialTaxRate = ErpTaxRate::factory()->create([
            'erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'rate' => '10.0000',
        ]);
    }

    protected function classificationContext(array $extra = []): array
    {
        return ['origin_uf' => 'SP', 'destination_uf' => 'SP', 'model' => '55', 'purpose' => 'normal', ...$extra];
    }

    protected function reviewedLine(array $extra = []): array
    {
        return [
            'erp_product_variant_id' => $this->variant->id, 'quantity' => '2.0000',
            'discount_amount' => '1.0000', 'erp_tax_rate_id' => $this->commercialTaxRate->id,
            ...$extra,
        ];
    }

    protected function reviewedBody(array $extra = []): array
    {
        return [
            'erp_establishment_id' => $this->establishment->id, 'erp_operation_nature_id' => $this->natureId,
            'tax_context' => $this->classificationContext(), 'lines' => [$this->reviewedLine()],
            ...$extra,
        ];
    }

    protected function commercialDetail(string $plural, int $id): array
    {
        return $this->getJson('/api/v1/erp/'.$plural.'/'.$id)->assertOk()->json('data');
    }

    protected function updateTaxProfile(): void
    {
        $this->patchJson('/api/v1/erp/tax-settings/product-tax-profiles/'.$this->taxProfileId, [
            'fields' => ['pis_cst' => '02', 'pis_rate' => '2.0000'], 'expected_revision' => 1,
        ])->assertOk()->assertJsonPath('data.current_revision', 2);
    }

    protected function secondVariant(): ErpProductVariant
    {
        return ErpProductVariant::factory()->create(['erp_product_id' => $this->variant->erp_product_id, 'sale_price' => '10.0000']);
    }

    protected function assertNoCommercialEffects(): void
    {
        foreach (['erp_stock_movements', 'erp_obligations', 'erp_payments', 'erp_fiscal_documents'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    protected function restoreLegacySnapshotShape(string $kind, int $id): void
    {
        $class = ErpCommercialDraftService::modelClass($kind);
        $document = $class::query()->findOrFail($id)->load('lines');
        foreach ($document->lines as $line) {
            $snapshot = $line->product_snapshot;
            $snapshot['version'] = 'commercial-v1';
            unset($snapshot['product_tax_resolution'], $snapshot['product_tax_configuration'], $snapshot['cfop_resolution']);
            if ($snapshot['operation_nature'] !== null) {
                $snapshot['operation_nature']['cfop'] = '5102';
                unset($snapshot['operation_nature']['default_cfop'], $snapshot['operation_nature']['configuration_revision']);
            }
            foreach (['icms_cst', 'pis_cst', 'pis_rate', 'icms_rate'] as $field) {
                $snapshot['product'][$field] = null;
            }
            $line->update(['product_snapshot' => $snapshot]);
        }
        $header = $document->commercial_snapshot;
        $header['version'] = 'commercial-v1';
        unset($header['tax_review'], $header['classification_fingerprint']);
        $header['lines'] = $document->fresh('lines')->lines->map(fn ($line): array => $line->attributesToArray())->all();
        $input = $document->draft_input;
        unset($input['tax_context'], $input['tax_refresh']);
        $document->update(['commercial_snapshot' => $header, 'draft_input' => $input]);
    }
}
