<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Erp\Fiscal\Contracts\ErpFiscalProvider;
use App\Erp\Fiscal\ErpFiscalActionCatalog;
use App\Erp\Fiscal\ErpFiscalDocumentStateMachine;
use App\Erp\Fiscal\ErpFiscalProviderResult;
use App\Jobs\ProcessErpFiscalAttempt;
use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Sales\ErpSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApprovesErpAccountingMappings;
use Tests\TestCase;

class ErpFiscalIssuanceCompleteTest extends TestCase
{
    use ApprovesErpAccountingMappings;
    use RefreshDatabase;

    private array $pendingSigned = [];

    private const VALID_CNPJ = '30288513000100';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $provider = \Mockery::mock(ErpFiscalProvider::class);
        $provider->shouldReceive('transmit')->andReturnUsing(function ($document, $payload) {
            $this->pendingSigned[$document->id] = $payload['signed_xml'];
            if (str_contains($document->idempotency_key, 'force-reject')) {
                return ErpFiscalProviderResult::rejected('synthetic rejection');
            }
            if (str_contains($document->idempotency_key, 'force-indeterminate')) {
                return ErpFiscalProviderResult::indeterminate('synthetic-receipt');
            }

            return ErpFiscalProviderResult::authorized('135260000000001', $this->processedXml($document, $payload['signed_xml']));
        });
        $provider->shouldReceive('consult')->andReturnUsing(fn ($document) => ErpFiscalProviderResult::authorized('135260000000001', $this->processedXml($document, $this->pendingSigned[$document->id])));
        $provider->shouldReceive('submitEvent')->andReturn(ErpFiscalProviderResult::eventRejected('synthetic cancellation rejection'));
        $this->app->instance(ErpFiscalProvider::class, $provider);
        Storage::fake('local');
        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
            'erp.release_enabled' => true,
            'erp.fiscal_certificate_relaxed_validation' => false,
            'erp.fiscal_production_enabled' => false,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'session.store' => null,
        ]);
        $this->withHeaders([
            'X-Opmoni-Ingress-Key' => 'test-key',
            'X-Opmoni-Client-IP' => '127.0.0.1',
            'X-Opmoni-Client-Proto' => 'http',
            'Origin' => 'http://localhost:3000',
        ]);
    }

    /** @return array{user: User, company: ErpCompany, establishment: ErpEstablishment, headers: array<string, string>} */
    private function ownerContext(string $role = 'owner'): array
    {
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create([
            'cnpj' => self::VALID_CNPJ,
            'operating_settings' => ['uf' => 'SP'],
        ]);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
        ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
            'status' => 'active',
        ]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id, 'cnpj' => self::VALID_CNPJ]);
        $this->login($user);

        return [
            'user' => $user,
            'company' => $company,
            'establishment' => $establishment,
            'headers' => ['X-Company-Id' => (string) $company->id],
        ];
    }

    private function csrf(): string
    {
        return $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
    }

    private function login(User $user): void
    {
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }

    public function test_action_catalog_covers_core_models_and_fixtures(): void
    {
        $rows = ErpFiscalActionCatalog::rows();
        $this->assertNotEmpty($rows);
        $this->assertTrue(collect($rows)->contains(fn (array $row): bool => $row['model'] === '55' && $row['action'] === 'transmit'));
        $this->assertTrue(collect($rows)->contains(fn (array $row): bool => $row['model'] === '65' && $row['action'] === 'contingency'));
    }

    #[DataProvider('fiscalModelProvider')]
    public function test_transmit_authorizes_document_and_persists_private_xml(string $model, string $idempotencyKey): void
    {
        $ctx = $this->ownerContext();
        $document = $this->createQueuedDocument($ctx, $model, $idempotencyKey);

        $this->executeDocumentAction($ctx, $document['id'], 'transmit')
            ->assertOk()
            ->assertJsonPath('data.status', ErpFiscalDocumentStateMachine::AUTHORIZED)
            ->assertJsonPath('data.artifact_ready', true)
            ->assertJsonPath('data.claims_provider_authorization', true);

        $stored = ErpFiscalDocument::query()->findOrFail($document['id']);
        $this->assertNotNull($stored->storage_path);
        Storage::disk('local')->assertExists($stored->storage_path);
        $this->withHeaders($ctx['headers'])->get('/api/v1/erp/fiscal/documents/'.$stored->id.'/xml')->assertOk()->assertContent(Storage::disk('local')->get($stored->storage_path));
    }

    /** @return list<array{0: string, 1: string}> */
    public static function fiscalModelProvider(): array
    {
        return [
            ['55', 'nfe-complete-1'],
            ['65', 'nfce-complete-1'],
            ['57', 'cte-complete-1'],
            ['58', 'mdfe-complete-1'],
        ];
    }

    public function test_indeterminate_consult_reconciles_without_duplicate_authorization(): void
    {
        $ctx = $this->ownerContext();
        $document = $this->createQueuedDocument($ctx, '55', 'force-indeterminate-key');

        $this->executeDocumentAction($ctx, $document['id'], 'transmit')
            ->assertOk()
            ->assertJsonPath('data.status', ErpFiscalDocumentStateMachine::INDETERMINATE);

        $this->executeDocumentAction($ctx, $document['id'], 'consult')
            ->assertOk()
            ->assertJsonPath('data.status', ErpFiscalDocumentStateMachine::AUTHORIZED);

        $this->assertSame(1, ErpFiscalDocument::query()->where('number', $document['number'])->count());
    }

    public function test_rejected_transmission_preserves_document_history(): void
    {
        $ctx = $this->ownerContext();
        $document = $this->createQueuedDocument($ctx, '55', 'force-reject-key');

        $this->executeDocumentAction($ctx, $document['id'], 'transmit')
            ->assertOk()
            ->assertJsonPath('data.status', ErpFiscalDocumentStateMachine::REJECTED);

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents/'.$document['id'].'/queue', ['idempotency_key' => 'queue-after-rejection'])
            ->assertOk()
            ->assertJsonPath('data.status', ErpFiscalDocumentStateMachine::QUEUED);
    }

    public function test_nfce_contingency_never_claims_provider_authorization(): void
    {
        $ctx = $this->ownerContext();
        $document = $this->createDraftDocument($ctx, '65', 'nfce-contingency-1');

        $this->executeDocumentAction($ctx, $document['id'], 'contingency')
            ->assertOk()
            ->assertJsonPath('data.status', ErpFiscalDocumentStateMachine::CONTINGENCY_LOCAL)
            ->assertJsonPath('data.claims_provider_authorization', false);

        $this->withHeaders($ctx['headers'])
            ->get('/api/v1/erp/fiscal/documents/'.$document['id'].'/xml')
            ->assertOk();
    }

    public function test_cancel_event_rejection_keeps_document_authorized(): void
    {
        $ctx = $this->ownerContext();
        $document = $this->createQueuedDocument($ctx, '55', 'cancel-base-1');
        $this->executeDocumentAction($ctx, $document['id'], 'transmit')
            ->assertOk();

        $event = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/events', [
                'erp_fiscal_document_id' => $document['id'],
                'event_type' => 'cancel',
                'idempotency_key' => 'force-reject-cancel',
                'justification' => 'Cancelamento indevido solicitado',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->executeEventAction($ctx, $event)
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(ErpFiscalDocumentStateMachine::AUTHORIZED, ErpFiscalDocument::query()->findOrFail($document['id'])->status);
    }

    public function test_authorization_triggers_accounting_handoff_when_delegation_exists(): void
    {
        $ctx = $this->ownerContext();
        $office = Account::factory()->create();
        $delegation = ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $ctx['company']->id,
            'account_id' => $office->id,
            'authorized_by_user_id' => $ctx['user']->id,
        ]);
        $client = Client::factory()->company()->create([
            'account_id' => $office->id,
            'tax_id' => self::VALID_CNPJ,
        ]);

        $this->approveAccountingMapping($delegation, $ctx['establishment'], $client);
        $document = $this->createQueuedDocument($ctx, '55', 'handoff-on-auth-1');
        $this->executeDocumentAction($ctx, $document['id'], 'transmit')
            ->assertOk()
            ->assertJsonPath('data.status', ErpFiscalDocumentStateMachine::AUTHORIZED);

        $intent = ErpAccountingDelivery::query()->sole();
        $this->assertSame('pending', $intent->status);
        $this->assertSame('accounting_worker_not_configured', $intent->reason_code);
        $this->assertSame($delegation->id, $intent->delegation_id);
        $this->assertNotNull($intent->mapping_id);
    }

    public function test_viewer_cannot_transmit(): void
    {
        $owner = $this->ownerContext('owner');
        $document = $this->createQueuedDocument($owner, '55', 'viewer-block-1');

        $viewer = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $owner['company']->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
        ]);
        $this->login($viewer);

        $this->withHeaders(['X-Company-Id' => (string) $owner['company']->id, 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents/'.$document['id'].'/transmit')
            ->assertForbidden();
    }

    public function test_foreign_document_xml_download_returns_404(): void
    {
        $ctx = $this->ownerContext();
        $other = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $foreignEstablishment = ErpEstablishment::factory()->create(['erp_company_id' => $other->id]);
        $path = 'erp/fiscal/'.$other->id.'/999/authorized.xml';
        Storage::disk('local')->put($path, '<nfeProc/>');
        $foreign = ErpFiscalDocument::query()->create([
            'erp_company_id' => $other->id,
            'erp_establishment_id' => $foreignEstablishment->id,
            'model' => '55',
            'environment' => 'homologation',
            'series' => 1,
            'number' => 5001,
            'access_key' => '35251011222333000181550010000050011234567890',
            'status' => ErpFiscalDocumentStateMachine::AUTHORIZED,
            'idempotency_key' => 'foreign-doc',
            'storage_path' => $path,
            'artifact_byte_length' => Storage::disk('local')->size($path),
            'artifact_sha256' => hash('sha256', Storage::disk('local')->get($path)),
            'authorized_at' => now(),
        ]);

        $this->withHeaders($ctx['headers'])
            ->get('/api/v1/erp/fiscal/documents/'.$foreign->id.'/xml')
            ->assertNotFound();
    }

    #[DataProvider('fiscalModelProvider')]
    public function test_production_transmission_requires_canary_gate(string $model, string $idempotencyKey): void
    {
        $ctx = $this->ownerContext();
        $document = $this->createQueuedDocument($ctx, $model, 'production-block-'.$idempotencyKey, 'production');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents/'.$document['id'].'/transmit')
            ->assertForbidden()
            ->assertJsonPath('code', 'fiscal_production_blocked')
            ->assertJsonPath('live_verification', 'unverified');
    }

    public function test_operator_canary_status_reports_unverified_without_credentials(): void
    {
        config(['erp.fiscal_canary_operator_token' => 'secret-token']);
        $ctx = $this->ownerContext();

        $this->withHeaders([...$ctx['headers'], 'X-Erp-Fiscal-Canary-Token' => 'secret-token'])
            ->getJson('/api/v1/erp/fiscal/operator-canary/status')
            ->assertOk()
            ->assertJsonPath('data.live_verification', 'unverified');
    }

    /**
     * @param  array{company: ErpCompany, establishment: ErpEstablishment, headers: array<string, string>}  $ctx
     * @return array{id: int, number: int}
     */
    private function createQueuedDocument(array $ctx, string $model, string $idempotencyKey, string $environment = 'homologation'): array
    {
        $draft = $this->createDraftDocument($ctx, $model, $idempotencyKey, $environment);
        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents/'.$draft['id'].'/queue')
            ->assertOk();

        return $draft;
    }

    /**
     * @param  array{company: ErpCompany, establishment: ErpEstablishment, headers: array<string, string>}  $ctx
     * @return array{id: int, number: int}
     */
    private function createDraftDocument(array $ctx, string $model, string $idempotencyKey, string $environment = 'homologation'): array
    {
        $source = match ($model) {
            '55' => ErpFiscalModelBuilderSchemaTest::builderProvider()['NF-e 55 posted sale'][2],
            '65' => ErpFiscalModelBuilderSchemaTest::builderProvider()['NFC-e 65 posted sale'][2],
            '57' => ErpFiscalModelBuilderSchemaTest::builderProvider()['CT-e 57 typed transport service'][2],
            '58' => ErpFiscalModelBuilderSchemaTest::builderProvider()['MDF-e 58 typed manifest'][2],
        };
        $nature = ErpOperationNature::factory()->create(['erp_company_id' => $ctx['company']->id]);
        $settings = ['series' => 1, 'next_number' => 1, 'operation_nature_id' => $nature->id];
        if (in_array($model, ['57', '58'], true)) {
            $settings['transport'] = $source[$model === '57' ? 'transport_service' : 'transport_manifest'];
        }
        if ($model === '65') {
            $settings['nfce_qr'] = ['version' => 3, 'mode' => str_contains($idempotencyKey, 'contingency') ? 'offline' : 'online', 'consultation_url' => 'https://test.invalid/consulta'];
            if ($settings['nfce_qr']['mode'] === 'offline') {
                $source['identity']['contingency_started_at'] = now()->toIso8601String();
                $source['identity']['contingency_justification'] = 'Falha real de comunicacao com autorizador';
            }
        }
        $headers = [...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()];
        $this->withHeaders($headers)->patchJson('/api/v1/erp/fiscal/configuration/'.$ctx['establishment']->id, [
            'environment' => $environment, 'issuer' => ['tax_regime' => 3, 'state_registration' => '110042490114', 'uf' => 'SP',
                'address' => ['street' => 'Rua Um', 'number' => '10', 'district' => 'Centro', 'city_code' => '3550308', 'city_name' => 'Sao Paulo', 'zip_code' => '01001000']], 'models' => [$model => $settings],
        ])->assertOk();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => self::VALID_CNPJ], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256']);
        $pkcs12 = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pkcs12, $key, ''));
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/certificates', ['erp_establishment_id' => $ctx['establishment']->id, 'label' => 'Real isolated test issuer', 'certificate_content' => base64_encode($pkcs12), 'password' => ''])->assertCreated();
        $saleId = null;
        if (in_array($model, ['55', '65'], true)) {
            $sale = ErpSale::query()->create(['erp_company_id' => $ctx['company']->id, 'erp_establishment_id' => $ctx['establishment']->id, 'invoice_no' => 'source-'.Str::uuid(), 'status' => 'posted', 'total_amount' => '100.00']);
            $line = $sale->lines()->create(['erp_company_id' => $ctx['company']->id, 'ordinal' => 1, 'quantity' => '1.0000', 'unit_price' => '100.0000', 'gross_amount' => '100.00', 'line_total' => '100.00',
                'product_snapshot' => ['product' => ['sku' => 'SKU-1', 'ncm' => '12345678', 'origin_code' => '0', 'icms_cst' => '00', 'pis_cst' => '07', 'cofins_cst' => '07'], 'unit' => ['short_name' => 'UN'], 'operation_nature' => ['cfop' => '5102']]]);
            $source['items'][0]['source_line_id'] = $line->id;
            $sale->update(['commercial_snapshot' => ['accepted' => ['actor_id' => $ctx['user']->id], 'party' => ['tax_id' => $source['recipient']['cnpj']], 'totals' => ['total_amount' => '100.00'], 'lines' => [$line->attributesToArray()]]]);
            $saleId = $sale->id;
        }
        $response = $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents', ['erp_establishment_id' => $ctx['establishment']->id, 'model' => $model, 'environment' => $environment, 'erp_sale_id' => $saleId, 'idempotency_key' => $idempotencyKey, 'source' => $source])->assertCreated();

        return ['id' => (int) $response->json('data.id'), 'number' => (int) $response->json('data.number')];
    }

    private function executeDocumentAction(array $ctx, int $documentId, string $action): TestResponse
    {
        $receipt = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])->postJson('/api/v1/erp/fiscal/documents/'.$documentId.'/'.$action)->assertOk();
        $this->assertSame('pending', $receipt->json('data.attempt.status'));
        app()->call([new ProcessErpFiscalAttempt($receipt->json('data.attempt.id')), 'handle']);

        return $this->withHeaders($ctx['headers'])->getJson('/api/v1/erp/fiscal/documents/'.$documentId);
    }

    private function executeEventAction(array $ctx, int $eventId): TestResponse
    {
        $receipt = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])->postJson('/api/v1/erp/fiscal/events/'.$eventId.'/submit')->assertOk();
        $this->assertSame('pending', $receipt->json('data.attempt.status'));
        app()->call([new ProcessErpFiscalAttempt($receipt->json('data.attempt.id')), 'handle']);

        return $this->withHeaders($ctx['headers'])->getJson('/api/v1/erp/fiscal/events/'.$eventId);
    }

    private function processedXml(ErpFiscalDocument $document, string $signed): string
    {
        $model = $document->model;
        $version = $model === '58' ? '3.00' : '4.00';
        [$root, $protocol, $keyName, $namespace] = match ($model) {
            '55', '65' => ['nfeProc', 'protNFe', 'chNFe', 'http://www.portalfiscal.inf.br/nfe'],
            '57' => ['cteProc', 'protCTe', 'chCTe', 'http://www.portalfiscal.inf.br/cte'],
            '58' => ['mdfeProc', 'protMDFe', 'chMDFe', 'http://www.portalfiscal.inf.br/mdfe'],
        };
        $unsignedDeclaration = preg_replace('/^<\?xml[^>]*>\s*/', '', $signed);
        $amb = $document->environment === 'production' ? 1 : 2;

        return '<'.$root.' xmlns="'.$namespace.'" versao="'.$version.'">'.$unsignedDeclaration.'<'.$protocol.' versao="'.$version.'"><infProt><tpAmb>'.$amb.'</tpAmb><verAplic>TESTE</verAplic><'.$keyName.'>'.$document->access_key.'</'.$keyName.'><dhRecbto>2026-10-21T10:00:01-03:00</dhRecbto><nProt>135260000000001</nProt><digVal>YWJjZA==</digVal><cStat>100</cStat><xMotivo>Autorizado o uso</xMotivo></infProt></'.$protocol.'></'.$root.'>';
    }
}
