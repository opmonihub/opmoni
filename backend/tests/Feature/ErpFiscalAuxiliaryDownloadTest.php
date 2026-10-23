<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Erp\Fiscal\ErpFiscalArtifactStore;
use App\Erp\Fiscal\ErpFiscalAuxiliaryRenderer;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsErpFiscalHarness;
use Tests\TestCase;

class ErpFiscalAuxiliaryDownloadTest extends TestCase
{
    use BuildsErpFiscalHarness;
    use RefreshDatabase;

    public function test_protected_pdf_checks_authority_and_integrity_before_real_upstream_rendering(): void
    {
        [$document, $company, $user] = $this->authorizedDocument();
        $store = app(ErpFiscalArtifactStore::class);
        $this->assertTrue($store->verifiedFiscalArtifactReady($document));
        $xml = $store->readXml($document);
        $this->assertIsString($xml);
        $path = $document->storage_path;
        $metadata = $document->only(['artifact_sha256', 'artifact_byte_length']);
        $accepted = $document->attempts()->where('action', 'document:transmit')->sole();
        $safeResult = $accepted->safe_result;
        $url = '/api/v1/erp/fiscal/documents/'.$document->id.'/pdf';
        $renderCalls = 0;
        $realRenderer = new ErpFiscalAuxiliaryRenderer;
        app()->instance(ErpFiscalAuxiliaryRenderer::class, new ErpFiscalAuxiliaryRenderer(function (string $model, string $bytes) use (&$renderCalls, $realRenderer): string {
            $renderCalls++;

            return $realRenderer->render($model, $bytes);
        }));

        $this->getJson('/api/v1/erp/fiscal/documents/999999/pdf')->assertNotFound();
        Storage::disk('local')->delete($path);
        $this->getJson($url)->assertConflict();
        Storage::disk('local')->put($path, '<corrupt/>');
        $this->getJson($url)->assertConflict();

        // Matching stored hashes do not make another document's protocol acceptable.
        $foreign = str_replace('<chNFe>'.$document->access_key.'</chNFe>', '<chNFe>'.str_repeat('1', 44).'</chNFe>', $xml);
        Storage::disk('local')->put($path, $foreign);
        $foreignMetadata = ['artifact_sha256' => hash('sha256', $foreign), 'artifact_byte_length' => strlen($foreign)];
        $document->update($foreignMetadata);
        $accepted->update(['safe_result' => [...$safeResult, ...$foreignMetadata]]);
        $this->getJson($url)->assertConflict();
        Storage::disk('local')->put($path, $xml);
        $document->update($metadata);
        $accepted->update(['safe_result' => $safeResult]);

        $foreignCompany = ErpCompany::factory()->create();
        $document->update(['erp_company_id' => $foreignCompany->id]);
        $this->getJson($url)->assertNotFound();
        $document->update(['erp_company_id' => $company->id]);
        ErpCompanyMembership::query()->where('erp_company_id', $company->id)->where('user_id', $user->id)->delete();
        $this->getJson($url)->assertForbidden();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->assertSame(0, $renderCalls);
        $this->assertSame($path, $document->fresh()->storage_path);
        $this->assertSame($metadata, $document->fresh()->only(array_keys($metadata)));

        $response = $this->get($url);
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="fiscal-'.$document->id.'.pdf"');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertMatchesRegularExpression('/%%EOF\s*$/', $response->getContent());
        $this->assertGreaterThan(100, strlen($response->getContent()));
        $this->assertSame(1, $renderCalls);
        $this->assertSame($xml, $store->readXml($document->fresh()));
    }

    /** @return array{ErpFiscalDocument, ErpCompany, User} */
    private function authorizedDocument(): array
    {
        config(['access.ingress_key' => 'test-key', 'erp.operating_writes_enabled' => true, 'erp.release_enabled' => true]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
        $company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        $issuer = ErpEstablishment::factory()->create(['erp_company_id' => $company->id, 'cnpj' => $company->cnpj]);
        $user = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'erp_plan_id' => $plan->id, 'status' => 'active']);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->withHeader('X-Company-Id', (string) $company->id);
        $nature = $this->configureFiscalHarness($issuer);
        $sale = $this->postFiscalHarnessSale($issuer, $nature, 'task20-pdf');
        $source = $this->fiscalSourceFromAcceptedSale($sale);
        $created = $this->withHeaders($this->fiscalHarnessHeaders($issuer))->postJson('/api/v1/erp/fiscal/documents', [
            'erp_establishment_id' => $issuer->id, 'erp_sale_id' => $sale->id, 'model' => '55', 'environment' => 'homologation',
            'idempotency_key' => 'task20-pdf-document', 'document_kind' => 'posted_sale', 'source' => $source,
        ])->assertCreated();
        $document = ErpFiscalDocument::query()->findOrFail($created->json('data.id'));
        $this->publishSyntheticFiscalEvidence($document);

        return [$document->fresh(), $company, $user];
    }
}
