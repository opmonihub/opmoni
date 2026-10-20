<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Finance\ErpCashRegister;
use App\Models\Erp\Finance\ErpFinancialAccount;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ErpOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CNPJ = '30288513000100';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
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

    /** @return array{user: User, company: ErpCompany, establishment: ErpEstablishment, headers: array<string, string>} */
    private function ownerContext(): array
    {
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => self::VALID_CNPJ]);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
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
            ->commercialPost('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }

    public function test_finance_list_create_allocate_and_cash_session_close(): void
    {
        $ctx = $this->ownerContext();
        $otherCompany = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $foreignAccount = ErpFinancialAccount::query()->create([
            'erp_company_id' => $otherCompany->id,
            'name' => 'Conta estrangeira',
        ]);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/financial-accounts')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => 'finance-basic'])
            ->commercialPost('/api/v1/erp/financial-accounts', [
                'name' => 'Conta principal',
                'erp_establishment_id' => $ctx['establishment']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Conta principal');

        $accountId = ErpFinancialAccount::query()->where('erp_company_id', $ctx['company']->id)->value('id');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => 'finance-basic'])
            ->commercialPost('/api/v1/erp/obligations', [
                'direction' => 'receivable',
                'category' => 'manual',
                'amount' => '100.00',
                'erp_financial_account_id' => $foreignAccount->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('erp_financial_account_id');

        $obligationResponse = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => 'finance-basic'])
            ->commercialPost('/api/v1/erp/obligations', [
                'direction' => 'receivable',
                'category' => 'manual',
                'amount' => '100.00',
                'erp_financial_account_id' => $accountId,
                'erp_establishment_id' => $ctx['establishment']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', '100.0000')
            ->assertJsonPath('data.open_amount', '100.0000');

        $obligationId = $obligationResponse->json('data.id');

        $paymentResponse = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => 'finance-basic'])
            ->commercialPost('/api/v1/erp/payments', [
                'amount' => '40.00',
                'method' => 'manual',
                'direction' => 'in',
                'erp_financial_account_id' => $accountId,
                'erp_establishment_id' => $ctx['establishment']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', '40.0000');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => 'finance-basic'])
            ->commercialPost('/api/v1/erp/payments/'.$paymentResponse->json('data.id').'/allocate', [
                'erp_obligation_id' => $obligationId,
                'amount' => '40.00',
            ])
            ->assertOk()
            ->assertJsonPath('data.obligation_open_amount', '60.0000');

        $registerResponse = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => 'finance-basic'])
            ->commercialPost('/api/v1/erp/cash-registers', [
                'name' => 'PDV 1',
                'erp_establishment_id' => $ctx['establishment']->id,
            ])
            ->assertCreated();

        $sessionResponse = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => 'finance-basic'])
            ->commercialPost('/api/v1/erp/cash-sessions', [
                'erp_cash_register_id' => $registerResponse->json('data.id'),
                'opening_amount' => '100.00',
                'currency' => 'BRL',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.opening_amount', '100.0000');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => 'finance-basic'])
            ->commercialPost('/api/v1/erp/cash-sessions/'.$sessionResponse->json('data.id').'/close', [
                'declared_closing_amount' => '130.00',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.expected_closing_amount', '100.0000')
            ->assertJsonPath('data.closing_difference', '30.0000');
    }

    public function test_purchase_post_updates_stock_and_foreign_company_isolation(): void
    {
        $ctx = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $ctx['company']->id]);
        $product = ErpProduct::factory()->create([
            'erp_company_id' => $ctx['company']->id,
            'erp_unit_id' => $unit->id,
        ]);
        $variant = ErpProductVariant::factory()->create(['erp_product_id' => $product->id]);

        $other = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $foreignEstablishment = ErpEstablishment::factory()->create(['erp_company_id' => $other->id]);
        $foreignPurchase = ErpPurchase::query()->create([
            'erp_company_id' => $other->id,
            'erp_establishment_id' => $foreignEstablishment->id,
            'status' => 'draft',
            'total_amount' => '10',
        ]);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/purchases/'.$foreignPurchase->id)
            ->assertNotFound();

        $purchaseResponse = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->commercialPost('/api/v1/erp/purchases', [
                'erp_establishment_id' => $ctx['establishment']->id,
                'document_no' => 'NF-100',
                'lines' => [
                    [
                        'erp_product_variant_id' => $variant->id,
                        'quantity' => '5',
                        'unit_cost' => '10,50',
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', '52.5000')
            ->assertJsonPath('data.status', 'draft');

        $purchaseId = $purchaseResponse->json('data.id');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->commercialPost('/api/v1/erp/purchases/'.$purchaseId.'/post')
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/stock/balances')
            ->assertOk()
            ->assertJsonPath('data.0.quantity_on_hand', '5.0000');

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/stock/movements')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_sale_post_and_fiscal_document_queue(): void
    {
        $ctx = $this->ownerContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $ctx['company']->id]);
        $product = ErpProduct::factory()->create([
            'erp_company_id' => $ctx['company']->id,
            'erp_unit_id' => $unit->id,
        ]);
        $variant = ErpProductVariant::factory()->create(['erp_product_id' => $product->id]);

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->commercialPost('/api/v1/erp/purchases', [
                'erp_establishment_id' => $ctx['establishment']->id,
                'lines' => [
                    [
                        'erp_product_variant_id' => $variant->id,
                        'quantity' => '10',
                        'unit_cost' => '5',
                    ],
                ],
            ])
            ->assertCreated();

        $purchaseId = ErpPurchase::query()->value('id');
        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->commercialPost('/api/v1/erp/purchases/'.$purchaseId.'/post')
            ->assertOk();

        $quoteResponse = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->commercialPost('/api/v1/erp/quotes', [
                'erp_establishment_id' => $ctx['establishment']->id,
                'quote_no' => 'Q-1',
                'expected_total' => '50',
                'lines' => [['description' => 'Cotação', 'quantity' => '1', 'unit_price' => '50']],
            ])
            ->assertCreated();

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->commercialPost('/api/v1/erp/quotes/'.$quoteResponse->json('data.id').'/convert', [
                'order_no' => 'O-1',
                'draft_revision' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.quote.status', 'converted')
            ->assertJsonPath('data.order.order_no', 'O-1');

        $saleResponse = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->commercialPost('/api/v1/erp/sales', [
                'erp_establishment_id' => $ctx['establishment']->id,
                'invoice_no' => 'V-1',
                'lines' => [
                    [
                        'erp_product_variant_id' => $variant->id,
                        'quantity' => '2',
                        'unit_price' => '10.50',
                    ],
                ],
                'discount' => ['discount_type' => 'fixed', 'discount_amount' => '2.00'],
                'tax_rate_percent' => '10',
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', '20.9000')
            ->assertJsonPath('data.status', 'draft');

        $saleId = $saleResponse->json('data.id');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->commercialPost('/api/v1/erp/sales/'.$saleId.'/post')
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame(1, ErpObligation::query()->where('source_type', 'erp_sale')->count());

        $certificateResponse = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->commercialPost('/api/v1/erp/fiscal/certificates', [
                'label' => 'Certificado A1',
                'certificate_content' => base64_encode('fake-cert-bytes'),
                'password' => 'secret',
            ])
            ->assertCreated()
            ->assertJsonPath('data.label', 'Certificado A1')
            ->assertJsonMissing(['encrypted_password', 'encrypted_certificate']);

        $documentResponse = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->commercialPost('/api/v1/erp/fiscal/documents', [
                'erp_establishment_id' => $ctx['establishment']->id,
                'erp_fiscal_certificate_id' => $certificateResponse->json('data.id'),
                'erp_sale_id' => $saleId,
                'model' => '55',
                'number' => 1001,
                'idempotency_key' => 'doc-key-1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->commercialPost('/api/v1/erp/fiscal/documents/'.$documentResponse->json('data.id').'/queue')
            ->assertOk()
            ->assertJsonPath('data.status', 'queued');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->commercialPost('/api/v1/erp/fiscal/events', [
                'erp_fiscal_document_id' => $documentResponse->json('data.id'),
                'event_type' => 'cancel',
                'idempotency_key' => 'event-key-1',
                'justification' => 'Erro de digitação',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'queued');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->commercialPost('/api/v1/erp/fiscal/invalidations', [
                'erp_establishment_id' => $ctx['establishment']->id,
                'model' => '55',
                'series' => 1,
                'number_from' => 1001,
                'number_to' => 1001,
                'justification' => 'Numeração não utilizada na homologação',
                'idempotency_key' => 'inut-key-1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'queued');
    }

    public function test_foreign_cash_register_returns_404(): void
    {
        $ctx = $this->ownerContext();
        $other = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $foreignEstablishment = ErpEstablishment::factory()->create(['erp_company_id' => $other->id]);
        $foreignRegister = ErpCashRegister::query()->create([
            'erp_company_id' => $other->id,
            'erp_establishment_id' => $foreignEstablishment->id,
            'name' => 'Outro caixa',
        ]);

        $this->withHeaders($ctx['headers'])
            ->getJson('/api/v1/erp/cash-registers/'.$foreignRegister->id)
            ->assertNotFound();
    }

    private int $commercialSequence = 0;

    private function commercialPost($uri, array $data = [], array $headers = [], $options = 0): TestResponse
    {
        if (! isset($headers['Idempotency-Key']) && ! isset($data['source_operation_key'])) {
            $headers['Idempotency-Key'] = 'task9-regression-'.++$this->commercialSequence;
        }
        if (preg_match('#/erp/sales/([0-9]+)/post$#', $uri, $matches) && ! isset($data['draft_revision'])) {
            $data['draft_revision'] = ErpSale::findOrFail($matches[1])->draft_revision;
        }

        return $this->postJson($uri, $data, $headers, $options);
    }
}
