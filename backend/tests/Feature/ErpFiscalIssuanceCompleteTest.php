<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Erp\Fiscal\Contracts\ErpFiscalProvider;
use App\Erp\Fiscal\ErpFiscalActionCatalog;
use App\Erp\Fiscal\ErpFiscalAttemptAuthorization;
use App\Erp\Fiscal\ErpFiscalAttemptService;
use App\Erp\Fiscal\ErpFiscalDocumentStateMachine;
use App\Erp\Fiscal\ErpFiscalNumberAllocator;
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
use App\Models\Erp\Fiscal\ErpFiscalAttempt;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Sales\ErpSale;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
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
    public function test_review_contingency_intent_is_blocked_while_transmission_is_unresolved(): void
    {
        $ctx = $this->ownerContext();
        $document = $this->createQueuedDocument($ctx, '65', 'review-contingency-unresolved');
        $headers = [...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()];
        $attempt = $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents/'.$document['id'].'/transmit')->assertOk()->json('data.attempt.id');
        $count = ErpFiscalAttempt::query()->count();
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents/'.$document['id'].'/contingency')->assertUnprocessable();
        $this->assertSame($count, ErpFiscalAttempt::query()->count());
        $this->assertSame('pending', ErpFiscalAttempt::findOrFail($attempt)->status);
    }

    public function test_review_local_worker_finishing_after_authorization_preserves_processed_artifact_and_both_outcomes(): void
    {
        $ctx = $this->ownerContext();
        $draft = $this->createQueuedDocument($ctx, '65', 'review-contingency-interleaving');
        $document = ErpFiscalDocument::findOrFail($draft['id']);
        $transmitId = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])->postJson('/api/v1/erp/fiscal/documents/'.$document->id.'/transmit')->assertOk()->json('data.attempt.id');
        $transmit = ErpFiscalAttempt::findOrFail($transmitId);
        $snapshot = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($transmit);
        // Reproduce an already committed concurrent local intent independently of the new HTTP admission fence.
        $local = app(ErpFiscalAttemptService::class)->createIntent([
            ...$transmit->only(['erp_company_id', 'erp_establishment_id', 'erp_fiscal_document_id', 'erp_fiscal_configuration_revision_id', 'erp_fiscal_certificate_revision_id', 'model', 'environment', 'series', 'number', 'actor_user_id', 'schema_release']),
            'action' => 'document:contingency', 'idempotency_key' => 'review-existing-local', 'request' => $snapshot['request'],
        ]);
        $disk = Storage::disk('local');
        $proxy = \Mockery::mock(FilesystemAdapter::class);
        $proxy->shouldReceive('exists')->andReturnUsing(fn ($path) => $disk->exists($path));
        $proxy->shouldReceive('get')->andReturnUsing(fn ($path) => $disk->get($path));
        $published = null;
        $proxy->shouldReceive('put')->andReturnUsing(function ($path, $bytes) use ($disk, $transmitId, $document, &$published) {
            $written = $disk->put($path, $bytes);
            if (str_contains($path, 'contingency-local-')) {
                app()->call([new ProcessErpFiscalAttempt($transmitId), 'handle']);
                $published = $document->fresh()->only(['status', 'storage_path', 'artifact_sha256', 'artifact_byte_length', 'authorized_at', 'metadata']);
                $this->assertSame('authorized', $published['status']);
            }

            return $written;
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        app()->call([new ProcessErpFiscalAttempt($local->id), 'handle']);
        $this->assertNotNull($published);
        $this->assertEquals($published, $document->fresh()->only(array_keys($published)));
        $this->assertSame('accepted', $transmit->fresh()->status);
        $this->assertSame('accepted', $local->fresh()->status);
        $this->assertSame('completed', $local->fresh()->outbox_state);
        $this->assertSame('not_dispatched', $local->fresh()->safe_result['provider_outcome']);
        $this->assertSame('superseded', $local->fresh()->safe_result['document_transition']);
    }

    #[DataProvider('reviewConsultOutcomes')]
    public function test_review_consult_finishing_after_accepted_cancellation_never_undoes_it(string $model, string $outcome): void
    {
        $ctx = $this->ownerContext();
        $draft = $this->createQueuedDocument($ctx, $model, 'review-consult-cancel');
        $this->executeDocumentAction($ctx, $draft['id'], 'transmit')->assertOk();
        $document = ErpFiscalDocument::findOrFail($draft['id']);
        $published = $document->only(['storage_path', 'artifact_sha256', 'artifact_byte_length', 'authorized_at']);
        $provider = \Mockery::mock(ErpFiscalProvider::class);
        $provider->shouldReceive('consult')->once()->andReturnUsing(function ($actual) use ($ctx, $outcome, $model) {
            $body = ['erp_fiscal_document_id' => $actual->id, 'event_type' => $model === '58' ? 'close' : 'cancel', 'justification' => 'Cancelamento aceito enquanto consulta esta em andamento', 'idempotency_key' => 'review-cancel-race', 'closed_on' => now()->toDateString(), 'closing_uf' => 'SP', 'closing_city_code' => '3550308'];
            $event = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])->postJson('/api/v1/erp/fiscal/events', $body)->assertCreated()->json('data.id');
            $this->executeEventAction($ctx, $event)->assertJsonPath('data.status', 'accepted');
            $this->assertSame($model === '58' ? 'authorized' : 'canceled', $actual->fresh()->status);
            if ($model === '58') {
                $this->assertNotNull($actual->fresh()->metadata['closed_at']);
            }

            return match ($outcome) {
                'authorized' => ErpFiscalProviderResult::authorized('135260000000001', $this->processedXml($actual, $this->pendingSigned[$actual->id])),
                'artifact_pending' => ErpFiscalProviderResult::authorized('135260000000001', '<invalid/>'),
                'rejected' => ErpFiscalProviderResult::rejected('synthetic rejected consultation'),
                'unknown' => ErpFiscalProviderResult::indeterminate(),
                default => throw new \RuntimeException('synthetic consultation timeout'),
            };
        });
        $provider->shouldReceive('submitEvent')->once()->andReturnUsing(function ($actual, $event) {
            $request = new \DOMDocument;
            $request->loadXML($event->payload_snapshot['signed_xml']);
            $mdfe = $actual->model === '58';
            $node = $mdfe ? $request->documentElement : (new \DOMXPath($request))->query('//*[local-name()="evento"]')->item(0);
            $root = $mdfe ? 'procEventoMDFe' : 'procEventoNFe';
            $ret = $mdfe ? 'retEventoMDFe' : 'retEvento';
            $key = $mdfe ? 'chMDFe' : 'chNFe';
            $version = $mdfe ? '3.00' : '1.00';
            $namespace = $mdfe ? 'http://www.portalfiscal.inf.br/mdfe' : 'http://www.portalfiscal.inf.br/nfe';
            $xml = '<'.$root.' xmlns="'.$namespace.'" versao="'.$version.'">'.$request->saveXML($node).'<'.$ret.' versao="'.$version.'"><infEvento><tpAmb>2</tpAmb><verAplic>TESTE</verAplic><cOrgao>35</cOrgao><cStat>135</cStat><xMotivo>Evento registrado e vinculado</xMotivo><'.$key.'>'.$actual->access_key.'</'.$key.'><tpEvento>'.($mdfe ? '110112' : '110111').'</tpEvento><nSeqEvento>1</nSeqEvento><dhRegEvento>2026-10-21T10:00:01-03:00</dhRegEvento><nProt>135260000000002</nProt></infEvento></'.$ret.'></'.$root.'>';

            return new ErpFiscalProviderResult('event_accepted', '135260000000002', xml: $xml);
        });
        $this->app->instance(ErpFiscalProvider::class, $provider);
        $this->executeDocumentAction($ctx, $document->id, 'consult')->assertOk()->assertJsonPath('data.status', $model === '58' ? 'authorized' : 'canceled');
        if ($model === '58') {
            $this->assertNotNull($document->fresh()->metadata['closed_at']);
        }
        $this->assertEquals($published, $document->fresh()->only(array_keys($published)));
        $ledger = ErpFiscalAttempt::query()->where('action', 'document:consult')->firstOrFail();
        $this->assertSame(match ($outcome) {
            'authorized' => 'accepted', 'artifact_pending' => 'artifact_pending', 'rejected' => 'rejected', default => 'indeterminate'
        }, $ledger->status);
        $this->assertNull($ledger->lease_token);
    }

    public static function reviewConsultOutcomes(): array
    {
        return array_merge(...array_map(static fn ($model) => array_map(static fn ($outcome) => [$model, $outcome], ['authorized', 'artifact_pending', 'rejected', 'unknown', 'timeout']), ['55', '58']));
    }

    public function test_review_artifact_pending_repair_creates_the_first_accounting_handoff_exactly_once(): void
    {
        $ctx = $this->ownerContext();
        $office = Account::factory()->create();
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $ctx['company']->id, 'account_id' => $office->id, 'authorized_by_user_id' => $ctx['user']->id]);
        $client = Client::factory()->company()->create(['account_id' => $office->id, 'tax_id' => self::VALID_CNPJ]);
        $this->approveAccountingMapping($delegation, $ctx['establishment'], $client);
        $draft = $this->createQueuedDocument($ctx, '55', 'review-repair-handoff');
        $provider = \Mockery::mock(ErpFiscalProvider::class);
        $provider->shouldReceive('transmit')->once()->andReturnUsing(function ($actual, $payload) {
            $this->pendingSigned[$actual->id] = $payload['signed_xml'];

            return ErpFiscalProviderResult::authorized('135260000000001', '<invalid/>');
        });
        $provider->shouldReceive('consult')->twice()->andReturnUsing(fn ($actual) => ErpFiscalProviderResult::authorized('135260000000001', $this->processedXml($actual, $this->pendingSigned[$actual->id])));
        $this->app->instance(ErpFiscalProvider::class, $provider);
        $this->executeDocumentAction($ctx, $draft['id'], 'transmit')->assertJsonPath('data.attempt.status', 'artifact_pending');
        $this->assertSame(0, ErpAccountingDelivery::query()->count());
        $this->executeDocumentAction($ctx, $draft['id'], 'consult')->assertJsonPath('data.artifact_ready', true);
        $delivery = ErpAccountingDelivery::query()->sole();
        $attempt = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])->postJson('/api/v1/erp/fiscal/documents/'.$draft['id'].'/consult', ['idempotency_key' => 'review-repair-again'])->assertOk()->json('data.attempt.id');
        app()->call([new ProcessErpFiscalAttempt($attempt), 'handle']);
        $this->assertSame($delivery->id, ErpAccountingDelivery::query()->sole()->id);
        $this->assertSame('accepted', ErpFiscalAttempt::findOrFail($attempt)->status);
    }

    public function test_review_cte_substitution_checks_frozen_relationship_and_authorization_window_before_allocation(): void
    {
        $ctx = $this->ownerContext();
        $draft = $this->createQueuedDocument($ctx, '57', 'review-original-cte');
        $this->executeDocumentAction($ctx, $draft['id'], 'transmit')->assertOk();
        $original = ErpFiscalDocument::findOrFail($draft['id']);
        $source = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($original->attempts()->where('action', 'document:draft')->firstOrFail())['request']['source'];
        $body = ['erp_establishment_id' => $ctx['establishment']->id, 'model' => '57', 'document_kind' => 'substitution', 'source_document_id' => $original->id, 'source' => [...$source, 'external_prerequisites' => ['service_disagreement' => 'accepted']], 'idempotency_key' => 'review-valid-substitution'];
        foreach (['transport_service.sender.cnpj' => '30288513000100', 'transport_service.receiver.cnpj' => '30288513000100', 'transport_service.service_type' => 1, 'transport_service.route.destination_uf' => 'SP', 'transport_service.taker.role' => 3] as $path => $value) {
            $invalid = $body;
            data_set($invalid['source'], $path, $value);
            $before = [ErpFiscalDocument::query()->count(), ErpFiscalAttempt::query()->count()];
            $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])->postJson('/api/v1/erp/fiscal/documents', $invalid)->assertUnprocessable()->assertJsonValidationErrors('source_document_id');
            $this->assertSame($before, [ErpFiscalDocument::query()->count(), ErpFiscalAttempt::query()->count()]);
            $this->assertSame(2, app(ErpFiscalNumberAllocator::class)->nextNumber($ctx['company']->id, '57', 'homologation', 1));
        }
        $original->update(['authorized_at' => now()->subDays(61)]);
        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])->postJson('/api/v1/erp/fiscal/documents', $body)->assertUnprocessable()->assertJsonValidationErrors('source_document_id');
        $this->assertSame(2, app(ErpFiscalNumberAllocator::class)->nextNumber($ctx['company']->id, '57', 'homologation', 1));
        $original->update(['authorized_at' => now()->subDays(59)]);
        $created = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])->postJson('/api/v1/erp/fiscal/documents', $body)->assertCreated();
        $substitute = ErpFiscalDocument::findOrFail($created->json('data.id'));
        $frozen = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot(ErpFiscalAttempt::findOrFail($created->json('data.attempt.id')))['request']['source'];
        $this->assertSame('unverified', $frozen['external_prerequisites']['service_disagreement']);
        $this->executeDocumentAction($ctx, $substitute->id, 'queue')->assertOk();
        $provider = \Mockery::mock(ErpFiscalProvider::class);
        $provider->shouldNotReceive('transmit');
        $provider->shouldNotReceive('consult');
        $this->app->instance(ErpFiscalProvider::class, $provider);
        $this->executeDocumentAction($ctx, $substitute->id, 'transmit')->assertOk()->assertJsonPath('data.attempt.status', 'attention_required')->assertJsonPath('data.attempt.safe_result.code', 'cte_substitution_disagreement_evidence_unavailable');
    }

    #[DataProvider('reviewMandatoryLeafCases')]
    public function test_review_mandatory_leaf_failure_has_no_number_document_or_outbox_effect(string $model, string $path, mixed $value, bool $remove = false): void
    {
        $ctx = $this->ownerContext();
        $draft = $this->createDraftDocument($ctx, $model, 'review-leaf-base');
        $original = ErpFiscalDocument::findOrFail($draft['id']);
        $source = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($original->attempts()->where('action', 'document:draft')->firstOrFail())['request']['source'];
        if ($remove) {
            data_forget($source, $path);
        } else {
            data_set($source, $path, $value);
        }
        $before = [ErpFiscalDocument::query()->count(), ErpFiscalAttempt::query()->count()];
        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])->postJson('/api/v1/erp/fiscal/documents', ['erp_establishment_id' => $ctx['establishment']->id, 'model' => $model, 'erp_sale_id' => $original->erp_sale_id, 'source' => $source, 'idempotency_key' => 'review-invalid-leaf'])->assertUnprocessable()->assertJsonValidationErrors('source');
        $this->assertSame($before, [ErpFiscalDocument::query()->count(), ErpFiscalAttempt::query()->count()]);
        $this->assertSame(2, app(ErpFiscalNumberAllocator::class)->nextNumber($ctx['company']->id, $model, 'homologation', 1));
    }

    public static function reviewMandatoryLeafCases(): array
    {
        return [
            ['57', 'transport_service.sender.legal_name', null],
            ['57', 'transport_service.receiver.legal_name', ''],
            ['57', 'transport_service.cargo.description', null],
            ['57', 'transport_service.cargo.quantity', ''],
            ['57', 'transport_service.route.pickup', null],
            ['57', 'transport_service.components', [['name' => null, 'value' => '10.00']]],
            ['58', 'transport_manifest.driver.name', null],
            ['58', 'transport_manifest.vehicle.registry', ''],
            ['58', 'transport_manifest.vehicle.tare', null],
            ['58', 'transport_manifest.vehicle.road_type', '99'],
            ['58', 'transport_manifest.cargo.product_description', null],
            ['58', 'transport_manifest.cargo.quantity', ''],
            ['58', 'transport_manifest.municipalities.0.name', null],
            ['55', 'payment.0.method', null, true],
        ];
    }

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
