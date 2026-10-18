<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Erp\Fiscal\Contracts\ErpFiscalProvider;
use App\Erp\Fiscal\ErpFiscalActionCatalog;
use App\Erp\Fiscal\ErpFiscalDocumentStateMachine;
use App\Erp\Fiscal\FakeErpFiscalProvider;
use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpFiscalIssuanceCompleteTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CNPJ = '30288513000100';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(ErpFiscalProvider::class, FakeErpFiscalProvider::class);
        Storage::fake('local');
        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
            'erp.release_enabled' => true,
            'erp.fiscal_certificate_relaxed_validation' => true,
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
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
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

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents/'.$document['id'].'/transmit')
            ->assertOk()
            ->assertJsonPath('data.status', ErpFiscalDocumentStateMachine::AUTHORIZED)
            ->assertJsonPath('data.artifact_ready', true)
            ->assertJsonPath('data.claims_provider_authorization', true);

        $stored = ErpFiscalDocument::query()->findOrFail($document['id']);
        $this->assertNotNull($stored->storage_path);
        Storage::disk('local')->assertExists($stored->storage_path);
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

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents/'.$document['id'].'/transmit')
            ->assertOk()
            ->assertJsonPath('data.status', ErpFiscalDocumentStateMachine::INDETERMINATE);

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents/'.$document['id'].'/consult')
            ->assertOk()
            ->assertJsonPath('data.status', ErpFiscalDocumentStateMachine::AUTHORIZED);

        $this->assertSame(1, ErpFiscalDocument::query()->where('number', $document['number'])->count());
    }

    public function test_rejected_transmission_preserves_document_history(): void
    {
        $ctx = $this->ownerContext();
        $document = $this->createQueuedDocument($ctx, '55', 'force-reject-key');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents/'.$document['id'].'/transmit')
            ->assertOk()
            ->assertJsonPath('data.status', ErpFiscalDocumentStateMachine::REJECTED);

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents/'.$document['id'].'/queue')
            ->assertOk()
            ->assertJsonPath('data.status', ErpFiscalDocumentStateMachine::QUEUED);
    }

    public function test_nfce_contingency_never_claims_provider_authorization(): void
    {
        $ctx = $this->ownerContext();
        $document = $this->createDraftDocument($ctx, '65', 'nfce-contingency-1');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents/'.$document['id'].'/contingency')
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
        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents/'.$document['id'].'/transmit')
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

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/events/'.$event.'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(ErpFiscalDocumentStateMachine::AUTHORIZED, ErpFiscalDocument::query()->findOrFail($document['id'])->status);
    }

    public function test_authorization_triggers_accounting_handoff_when_delegation_exists(): void
    {
        $ctx = $this->ownerContext();
        $office = Account::factory()->create();
        ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $ctx['company']->id,
            'account_id' => $office->id,
            'authorized_by_user_id' => $ctx['user']->id,
        ]);
        Client::factory()->company()->create([
            'account_id' => $office->id,
            'tax_id' => self::VALID_CNPJ,
        ]);

        $document = $this->createQueuedDocument($ctx, '55', 'handoff-on-auth-1');
        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents/'.$document['id'].'/transmit')
            ->assertOk()
            ->assertJsonPath('data.status', ErpFiscalDocumentStateMachine::AUTHORIZED);

        $this->assertSame(1, ErpAccountingDelivery::query()->where('status', 'delivered')->count());
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
        $number = random_int(10000, 99999);
        $response = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents', [
                'erp_establishment_id' => $ctx['establishment']->id,
                'model' => $model,
                'environment' => $environment,
                'series' => 1,
                'number' => $number,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertCreated();

        return ['id' => (int) $response->json('data.id'), 'number' => $number];
    }
}
