<?php

namespace Tests\Support;

use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Sales\ErpSale;

trait BuildsReviewedFiscalHarnessSale
{
    use BuildsErpFiscalHarness;

    private function prepareFiscalVariant(ErpProductVariant $variant): void
    {
        $this->withCredentials()->withCookie(config('session.cookie'), $this->app['session']->getId());
        $headers = [
            'X-Company-Id' => (string) $variant->product->erp_company_id,
            'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'),
        ];
        $this->withHeaders($headers)->patchJson('/api/v1/erp/products/'.$variant->erp_product_id, [
            'ncm' => '12345678', 'origin_code' => '0',
            'icms_cst' => '00', 'pis_cst' => '07', 'cofins_cst' => '07', 'icms_rate' => '0.0000',
            'tax_field_intents' => ['icms_cst' => 'value', 'pis_cst' => 'value', 'cofins_cst' => 'value', 'icms_rate' => 'value'],
        ])->assertOk();
        $variant->product->unit->update(['short_name' => 'UN']);
    }

    private function prepareReviewedFiscalNature(ErpEstablishment $issuer, ErpOperationNature $nature): void
    {
        $nature->refresh();
        $this->withHeaders($this->fiscalHarnessHeaders($issuer))
            ->patchJson('/api/v1/erp/tax-settings/operation-natures/'.$nature->id, [
                'cfop' => $nature->cfop, 'expected_revision' => $nature->configuration_revision,
            ])->assertOk()->assertJsonPath('data.id', $nature->id)->assertJsonPath('data.cfop', $nature->cfop);
    }

    private function postFiscalHarnessSale(ErpEstablishment $issuer, ErpOperationNature $nature, string $key): ErpSale
    {
        $variant = ErpProductVariant::factory()->create(['erp_product_id' => ErpProduct::factory()->create([
            'erp_company_id' => $issuer->erp_company_id,
            'erp_unit_id' => ErpUnit::factory()->create(['erp_company_id' => $issuer->erp_company_id])->id,
            'track_stock' => false,
        ])->id]);
        $this->prepareFiscalVariant($variant);
        $this->prepareReviewedFiscalNature($issuer, $nature);
        $customer = $this->fiscalHarnessCustomer($issuer);
        $headers = $this->fiscalHarnessHeaders($issuer);
        $sale = $this->withHeaders($headers)->postJson('/api/v1/erp/sales', [
            'erp_establishment_id' => $issuer->id, 'erp_contact_id' => $customer->id, 'erp_operation_nature_id' => $nature->id,
            'fiscal_model' => '55',
            'tax_context' => ['origin_uf' => 'SP', 'destination_uf' => 'SP', 'model' => '55', 'purpose' => 'normal'],
            'lines' => [['erp_product_variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '100']],
        ], ['Idempotency-Key' => $key.'-sale'])->assertCreated()->json('data.id');
        $this->withHeaders($headers)->postJson('/api/v1/erp/sales/'.$sale.'/post', [
            'draft_revision' => 1,
        ], ['Idempotency-Key' => $key.'-post'])->assertOk();

        return ErpSale::findOrFail($sale);
    }
}
