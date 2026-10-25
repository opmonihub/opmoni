<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\ErpCommercialCatalog;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Tests\OfficeErpCommercialTestCase;

class CompanyInvoiceAccessTest extends OfficeErpCommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.operating_writes_enabled' => true]);
    }

    public function test_direct_owner_reads_only_the_linked_company_terms_and_invoice_while_subscription_is_pending(): void
    {
        $ctx = $this->officeContext();
        $owner = User::factory()->create();
        [$contract, $company, $invoice] = $this->linkedCompanyInvoice($ctx, $owner, 'pending');

        $this->loginAndSelectCompany($owner, $company);
        self::assertSame($contract->id, (int) ErpSubscription::query()->where('erp_company_id', $company->id)->value('office_erp_contract_id'));

        $subscription = $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/subscription')
            ->assertOk();
        $subscription->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.retail_contract.agreement_status', 'accepted')
            ->assertJsonPath('data.retail_contract.terms.price', '125.00');
        self::assertStringContainsString('no-store', (string) $subscription->headers->get('Cache-Control'));

        self::assertSame(['id', 'status', 'company', 'plan', 'retail_contract'], array_keys($subscription->json('data')));
        self::assertSame(0, AccountMembership::query()->where('user_id', $owner->id)->count());

        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/commercial-invoices')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.number', $invoice->number)
            ->assertJsonPath('data.0.balance.original_total', '125.00')
            ->assertJsonPath('data.0.balance.outstanding', '125.00');

        $invoiceResponse = $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/commercial-invoices/'.$invoice->id)
            ->assertOk()
            ->assertJsonPath('data.balance.outstanding', '125.00')
            ->assertJsonMissingPath('data.seller_snapshot')
            ->assertJsonMissingPath('data.bill_to_snapshot');
        self::assertStringContainsString('no-store', (string) $invoiceResponse->headers->get('Cache-Control'));

        $this->getJson('/api/v1/erp-commercial/receipts')->assertForbidden();
        self::assertSame((int) $company->id, (int) $contract->erp_company_id);
    }

    public function test_owner_can_consult_an_active_linked_contract(): void
    {
        $ctx = $this->officeContext();
        $owner = User::factory()->create();
        [, $company] = $this->linkedCompanyInvoice($ctx, $owner, 'active');
        $this->loginAndSelectCompany($owner, $company);

        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/commercial-invoices')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_foreign_company_invoice_is_not_found_in_the_current_owner_context(): void
    {
        $seller = $this->officeContext();
        $foreignSeller = $this->officeContext();
        $owner = User::factory()->create();
        [, $company] = $this->linkedCompanyInvoice($seller, $owner, 'active');
        [, , $foreignInvoice] = $this->linkedCompanyInvoice($foreignSeller, User::factory()->create(), 'active');
        $this->loginAndSelectCompany($owner, $company);

        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/commercial-invoices/'.$foreignInvoice->id)
            ->assertNotFound();
    }

    public function test_another_company_invoice_under_the_same_seller_account_is_not_found(): void
    {
        $ctx = $this->officeContext();
        $owner = User::factory()->create();
        [, $company] = $this->linkedCompanyInvoice($ctx, $owner, 'active');
        [, , $otherCompanyInvoice] = $this->linkedCompanyInvoice($ctx, User::factory()->create(), 'active');
        $this->loginAndSelectCompany($owner, $company);

        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/commercial-invoices/'.$otherCompanyInvoice->id)
            ->assertNotFound();
    }

    public function test_delegated_office_admin_cannot_read_owner_terms_or_invoice_data(): void
    {
        $ctx = $this->officeContext();
        $officeAdmin = $ctx->actor;
        self::assertInstanceOf(User::class, $officeAdmin);
        [, $company, $invoice] = $this->linkedCompanyInvoice($ctx, User::factory()->create(), 'active');
        ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $company->id,
            'account_id' => $ctx->accountId,
            'authorized_by_user_id' => $officeAdmin->id,
            'permission_allowlist' => ['erp.read'],
        ]);

        $this->loginAndSelectDelegatedCompany($officeAdmin, $ctx->accountId, $company);
        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/subscription')
            ->assertForbidden();
        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/commercial-invoices')
            ->assertForbidden();
        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/commercial-invoices/'.$invoice->id)
            ->assertForbidden();
    }

    public function test_non_owner_cannot_read_linked_contract_terms_or_invoices(): void
    {
        $ctx = $this->officeContext();
        $owner = User::factory()->create();
        [$contract, $company, $invoice] = $this->linkedCompanyInvoice($ctx, $owner, 'active');
        $manager = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $manager->id,
            'role' => 'manager',
        ]);
        $this->loginAndSelectCompany($manager, $company);

        $subscription = $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/subscription')
            ->assertOk();
        self::assertSame(['id', 'status', 'company', 'plan'], array_keys($subscription->json('data')));
        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/commercial-invoices')
            ->assertForbidden();
        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/commercial-invoices/'.$invoice->id)
            ->assertForbidden();

    }

    public function test_unlinked_direct_subscription_keeps_the_legacy_response_and_has_no_retail_invoice_list(): void
    {
        $unlinkedOwner = User::factory()->create();
        $unlinkedCompany = ErpCompany::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $unlinkedCompany->id,
            'user_id' => $unlinkedOwner->id,
            'role' => 'owner',
        ]);
        ErpSubscription::factory()->create([
            'erp_company_id' => $unlinkedCompany->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
            'status' => 'pending',
        ]);
        $this->loginAndSelectCompany($unlinkedOwner, $unlinkedCompany);

        $legacy = $this->withHeader('X-Company-Id', (string) $unlinkedCompany->id)
            ->getJson('/api/v1/erp/subscription')
            ->assertOk();
        self::assertSame(['id', 'status', 'company', 'plan'], array_keys($legacy->json('data')));
        $this->withHeader('X-Company-Id', (string) $unlinkedCompany->id)
            ->getJson('/api/v1/erp/commercial-invoices')
            ->assertNotFound();
    }

    /** @return array{OfficeErpContract, ErpCompany, OfficeErpInvoice} */
    private function linkedCompanyInvoice(OfficeErpExecutionContext $ctx, User $owner, string $status): array
    {
        $contract = $this->acceptedContract($ctx, ['price' => '125.00']);
        $company = ErpCompany::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        $contract->forceFill([
            'erp_company_id' => $company->id,
            'agreement_status' => $status === 'pending' ? 'accepted' : 'effective',
            'service_anchor_on' => now()->toDateString(),
        ])->save();
        ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
            'status' => $status,
            'office_erp_contract_id' => $contract->id,
        ]);
        $invoice = $this->issuedInvoice($ctx, $contract, '125.00');

        return [$contract->fresh(), $company, $invoice];
    }

    private function loginAndSelectCompany(User $user, ErpCompany $company): void
    {
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])
            ->assertOk();
    }

    private function loginAndSelectDelegatedCompany(User $user, int $accountId, ErpCompany $company): void
    {
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/account/switch', ['account_id' => $accountId])
            ->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])
            ->assertOk()
            ->assertJsonPath('current_company.access_kind', 'delegated');
    }
}
