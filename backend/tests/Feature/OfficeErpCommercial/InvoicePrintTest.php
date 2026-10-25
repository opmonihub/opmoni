<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\OfficeErpCommercialTestCase;

class InvoicePrintTest extends OfficeErpCommercialTestCase
{
    public function test_admin_can_print_only_display_snapshots_and_authoritative_current_balance(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'America/Sao_Paulo'));
        [$ctx, $contract, $company, $account] = $this->effectiveOffice();

        $draft = $this->postJson('/api/v1/erp-commercial/invoices', [
            'contract_id' => $contract->id,
            'items' => [
                ['description' => 'Item grande exato', 'amount' => '9007199254740993.01', 'discount' => '9007199254740993.01'],
                ['description' => 'Serviço congelado', 'amount' => '100.00', 'discount' => '0.00'],
            ],
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_on' => '2026-11-01',
        ], ['Idempotency-Key' => 'print-exact-draft'])->assertCreated()->json('data');

        $this->postJson('/api/v1/erp-commercial/invoices/'.$draft['id'].'/issue', [], [
            'Idempotency-Key' => 'print-exact-issue',
        ])->assertOk();
        $this->postJson('/api/v1/erp-commercial/invoices/'.$draft['id'].'/adjustments', [
            'direction' => 'credit',
            'amount' => '3.01',
            'reason' => 'Crédito registrado',
        ], ['Idempotency-Key' => 'print-current-credit'])->assertCreated();
        $this->postJson('/api/v1/erp-commercial/receipts', [
            'amount' => '40.00',
            'received_on' => '2026-10-25',
            'allocations' => [['invoice_id' => $draft['id'], 'amount' => '40.00']],
        ], ['Idempotency-Key' => 'print-current-receipt'])->assertCreated();

        $this->withOfficeAccount($ctx, function () use ($contract, $company, $account): void {
            $contract->client()->update(['name' => 'Nome do cliente atualizado']);
            $company->update(['name' => 'Nome da empresa atualizado']);
            $account->update(['name' => 'Nome do escritório atualizado']);
        });

        $response = $this->getJson('/api/v1/erp-commercial/invoices/'.$draft['id'].'/print')->assertOk();
        self::assertStringContainsString('no-store', strtolower((string) $response->headers->get('Cache-Control')));
        $response->assertJsonPath('data.document_status', 'issued')
            ->assertJsonPath('data.items_snapshot.0.amount', '9007199254740993.01')
            ->assertJsonPath('data.seller_snapshot.account.name', 'Escritório de teste')
            ->assertJsonPath('data.bill_to_snapshot.client.name', 'Cliente de teste')
            ->assertJsonPath('data.bill_to_snapshot.company.name', 'Empresa ERP de teste')
            ->assertJsonPath('data.items_snapshot.0.discount', '9007199254740993.01')
            ->assertJsonPath('data.balance.original_total', '100.00')
            ->assertJsonPath('data.balance.adjusted_charge', '96.99')
            ->assertJsonPath('data.balance.effective_received', '40.00')
            ->assertJsonPath('data.balance.outstanding', '56.99')
            ->assertJsonPath('data.adjustments.0.direction', 'credit')
            ->assertJsonPath('data.adjustments.0.amount', '3.01')
            ->assertJsonPath('data.adjustments.0.reason', 'Crédito registrado')
            ->assertJsonMissingPath('data.contract_id')
            ->assertJsonMissingPath('data.contract_revision_id')
            ->assertJsonMissingPath('data.seller_snapshot.account.id')
            ->assertJsonMissingPath('data.seller_snapshot.contract_terms')
            ->assertJsonMissingPath('data.bill_to_snapshot.client.id')
            ->assertJsonMissingPath('data.bill_to_snapshot.company.id')
            ->assertJsonMissingPath('data.payment_instrument')
            ->assertJsonMissingPath('data.payment_details')
            ->assertJsonMissingPath('data.pix_payload')
            ->assertJsonMissingPath('data.boleto')
            ->assertJsonMissingPath('data.proof_url')
            ->assertJsonMissingPath('data.proof_token')
            ->assertJsonMissingPath('data.operation_key');
        self::assertMatchesRegularExpression('/^ERP-2026-\d{6}$/', (string) $response->json('data.number'));
        self::assertStringNotContainsString('Nome do cliente atualizado', $response->getContent());
        self::assertStringNotContainsString('Nome da empresa atualizado', $response->getContent());
        self::assertStringNotContainsString('Nome do escritório atualizado', $response->getContent());
    }

    public function test_draft_invoice_is_not_printable(): void
    {
        [, $contract] = $this->effectiveOffice();
        $draft = $this->postJson('/api/v1/erp-commercial/invoices', [
            'contract_id' => $contract->id,
            'items' => [['description' => 'Rascunho', 'amount' => '100.00', 'discount' => '0.00']],
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_on' => '2026-11-01',
        ], ['Idempotency-Key' => 'print-draft-only'])->assertCreated()->json('data');

        $this->getJson('/api/v1/erp-commercial/invoices/'.$draft['id'].'/print')->assertNotFound();
    }

    public function test_foreign_invoice_and_non_numeric_identifiers_are_not_resolvable(): void
    {
        [, $contract] = $this->effectiveOffice();
        $foreignContext = $this->officeContext();
        $foreignContract = $this->withOfficeAccount($foreignContext, fn (): OfficeErpContract => $this->acceptedContract($foreignContext));
        $foreignCompany = ErpCompany::factory()->create(['name' => 'Empresa estrangeira']);
        $this->withOfficeAccount($foreignContext, function () use ($foreignContext, $foreignContract, $foreignCompany): void {
            $foreignContract->client()->update(['name' => 'Cliente estrangeiro']);
            $foreignContract->forceFill([
                'erp_company_id' => $foreignCompany->id,
                'agreement_status' => 'effective',
                'service_anchor_on' => '2026-10-01',
                'effective_at' => now(),
            ])->save();
            OfficeErpLicenceAllocation::factory()->create([
                'account_id' => $foreignContext->accountId,
                'contract_id' => $foreignContract->id,
                'erp_company_id' => $foreignCompany->id,
                'state' => 'occupied',
                'expires_at' => null,
            ]);
        });
        $this->selectBusinessAccount($foreignContext->actor, $foreignContext->accountId);
        $this->actingAs($foreignContext->actor);
        $foreignDraft = $this->postJson('/api/v1/erp-commercial/invoices', [
            'contract_id' => $foreignContract->id,
            'items' => [['description' => 'Privado', 'amount' => '100.00', 'discount' => '0.00']],
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_on' => '2026-11-01',
        ], ['Idempotency-Key' => 'foreign-print-draft'])->assertCreated()->json('data');
        $this->postJson('/api/v1/erp-commercial/invoices/'.$foreignDraft['id'].'/issue', [], [
            'Idempotency-Key' => 'foreign-print-issue',
        ])->assertOk();

        $this->actingAs($this->officeContextActorForContract($contract));
        $this->getJson('/api/v1/erp-commercial/invoices/'.$foreignDraft['id'].'/print')->assertNotFound();
        $this->getJson('/api/v1/erp-commercial/invoices/not-a-number/print')->assertNotFound();
    }

    public function test_non_admin_and_anonymous_users_cannot_print(): void
    {
        [$ctx, $contract] = $this->effectiveOffice();
        $draft = $this->postJson('/api/v1/erp-commercial/invoices', [
            'contract_id' => $contract->id,
            'items' => [['description' => 'Privado', 'amount' => '100.00', 'discount' => '0.00']],
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'due_on' => '2026-11-01',
        ], ['Idempotency-Key' => 'print-role-draft'])->assertCreated()->json('data');
        $this->postJson('/api/v1/erp-commercial/invoices/'.$draft['id'].'/issue', [], [
            'Idempotency-Key' => 'print-role-issue',
        ])->assertOk();

        AccountMembership::query()
            ->where('account_id', $ctx->accountId)
            ->where('user_id', $ctx->actor->id)
            ->update(['role' => 'operador']);
        $this->getJson('/api/v1/erp-commercial/invoices/'.$draft['id'].'/print')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/erp-commercial/invoices/'.$draft['id'].'/print')->assertUnauthorized();
    }

    /** @return array{OfficeErpExecutionContext, OfficeErpContract, ErpCompany, Account} */
    private function effectiveOffice(): array
    {
        $ctx = $this->officeContext();
        $contract = $this->withOfficeAccount($ctx, fn (): OfficeErpContract => $this->acceptedContract($ctx));
        $company = ErpCompany::factory()->create(['name' => 'Empresa ERP de teste']);
        $account = $ctx->account;
        $this->withOfficeAccount($ctx, function () use ($ctx, $contract, $company, $account): void {
            $contract->client()->update(['name' => 'Cliente de teste']);
            $contract->forceFill([
                'erp_company_id' => $company->id,
                'agreement_status' => 'effective',
                'service_anchor_on' => '2026-10-01',
                'effective_at' => now(),
            ])->save();
            $account->update(['name' => 'Escritório de teste']);
            OfficeErpLicenceAllocation::factory()->create([
                'account_id' => $ctx->accountId,
                'contract_id' => $contract->id,
                'erp_company_id' => $company->id,
                'state' => 'occupied',
                'expires_at' => null,
            ]);
        });

        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        return [$ctx, $contract->refresh(), $company, $account];
    }

    private function officeContextActorForContract(OfficeErpContract $contract): User
    {
        $membership = AccountMembership::query()->where('account_id', $contract->account_id)->where('role', 'admin')->firstOrFail();
        $this->selectBusinessAccount($membership->user, (int) $contract->account_id);

        return $membership->user;
    }
}
