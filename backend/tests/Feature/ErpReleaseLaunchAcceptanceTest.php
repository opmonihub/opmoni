<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Erp\ErpReleaseGate;
use App\Erp\Fiscal\Contracts\ErpFiscalProvider;
use App\Erp\Fiscal\ErpFiscalDocumentStateMachine;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsReviewedFiscalHarnessSale;
use Tests\TestCase;

class ErpReleaseLaunchAcceptanceTest extends TestCase
{
    use BuildsReviewedFiscalHarnessSale;
    use RefreshDatabase;

    private const VALID_CNPJ = '30288513000100';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
            'erp.release_enabled' => true,
            'erp.fiscal_certificate_relaxed_validation' => true,
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

    public function test_production_erp_release_does_not_broaden_fiscal_provider_scope(): void
    {
        config([
            'erp.release_mode' => 'production',
            'erp.release_enabled' => true,
            'erp.operating_writes_enabled' => true,
            'erp.fiscal_production_enabled' => false,
            'erp.fiscal_canary_scope' => [],
        ]);

        $gate = app(ErpReleaseGate::class);
        $this->assertSame('production', $gate->mode());
        $this->assertTrue($gate->isReleased());

        $ctx = $this->ownerContext();
        $document = $this->createQueuedProductionDocument($ctx, '55', 'release-scope-1');
        $provider = $this->mock(ErpFiscalProvider::class);
        $provider->shouldNotReceive('transmit');
        $provider->shouldNotReceive('consult');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents/'.$document['id'].'/transmit')
            ->assertForbidden()
            ->assertJsonPath('code', 'fiscal_production_blocked')
            ->assertJsonPath('live_verification', 'unverified');
    }

    public function test_complete_release_requires_explicit_production_flags(): void
    {
        config([
            'erp.release_mode' => 'production',
            'erp.release_enabled' => false,
        ]);

        $gate = app(ErpReleaseGate::class);
        $this->assertSame('disabled', $gate->mode());
        $this->assertFalse($gate->isReleased());
    }

    public function test_preview_with_empty_allowlist_fails_closed(): void
    {
        config([
            'erp.release_mode' => 'preview',
            'erp.release_enabled' => false,
            'erp.preview_allowlist' => [],
        ]);

        $gate = app(ErpReleaseGate::class);
        $this->assertSame('preview', $gate->mode());

        $ctx = $this->ownerContext();

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/accounting-deliveries')
            ->assertForbidden()
            ->assertJsonPath('code', 'erp_release_disabled');
    }

    public function test_operator_canary_records_unverified_without_authorized_scope(): void
    {
        config([
            'erp.fiscal_production_enabled' => false,
            'erp.fiscal_canary_scope' => [],
            'erp.fiscal_canary_operator_token' => 'launch-token',
        ]);

        $ctx = $this->ownerContext();

        $this->withHeaders([...$ctx['headers'], 'X-Erp-Fiscal-Canary-Token' => 'launch-token'])
            ->getJson('/api/v1/erp/fiscal/operator-canary/status')
            ->assertOk()
            ->assertJsonPath('data.fiscal_production_enabled', false)
            ->assertJsonPath('data.canary_scope_configured', false)
            ->assertJsonPath('data.live_verification', 'unverified');
    }

    /** @return array{user: User, company: ErpCompany, establishment: ErpEstablishment, headers: array<string, string>} */
    private function ownerContext(): array
    {
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create([
            'cnpj' => self::VALID_CNPJ,
            'operating_settings' => ['uf' => 'SP'],
        ]);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
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

    /** @param array{company: ErpCompany, establishment: ErpEstablishment, headers: array<string, string>} $ctx
     * @return array{id: int}
     */
    private function createQueuedProductionDocument(array $ctx, string $model, string $idempotencyKey): array
    {
        Queue::fake();
        $number = 10001;
        $nature = $this->configureFiscalHarness($ctx['establishment'], 'production', $number);
        $sale = $this->postFiscalHarnessSale($ctx['establishment'], $nature, $idempotencyKey);
        $draft = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents', [
                'erp_establishment_id' => $ctx['establishment']->id,
                'erp_sale_id' => $sale->id,
                'source' => $this->fiscalSourceFromAcceptedSale($sale),
                'model' => $model,
                'environment' => 'production',
                'series' => 1,
                'number' => $number,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertCreated()
            ->json('data');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents/'.$draft['id'].'/queue')
            ->assertOk()
            ->assertJsonPath('data.status', ErpFiscalDocumentStateMachine::QUEUED);

        return ['id' => (int) $draft['id']];
    }
}
