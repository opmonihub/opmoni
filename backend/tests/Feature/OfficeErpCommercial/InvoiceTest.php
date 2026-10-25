<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpInvoices;
use App\Erp\Commercial\OfficeErpOperations;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpAuditEvent;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpInvoiceCounter;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\OfficeErpCommercialTestCase;

class InvoiceTest extends OfficeErpCommercialTestCase
{
    public function test_effective_contract_invoice_issues_immutable_party_terms_and_item_snapshots(): void
    {
        [$ctx, $contract, $company] = $this->effectiveContract();

        $draft = $this->createDraft($contract, [
            'items' => [['description' => 'Serviço mensal', 'amount' => '100', 'discount' => '5.00']],
            'period_start' => '2026-10-01',
            'period_end' => '2026-11-01',
            'due_on' => '2026-10-11',
        ], 'invoice-create')->assertCreated()->json('data');

        self::assertSame('draft', $draft['document_status']);
        self::assertSame('95.00', $draft['original_total']);
        $this->postJson('/api/v1/erp-commercial/invoices/'.$draft['id'].'/issue', [], ['Idempotency-Key' => 'invoice-issue'])
            ->assertOk()->assertJsonPath('data.document_status', 'issued')->assertJsonPath('data.currency', 'BRL');

        $client = $contract->client()->firstOrFail();
        $client->update(['name' => 'Cliente alterado depois da emissão']);
        $company->update(['name' => 'Empresa alterada depois da emissão']);
        $this->withOfficeAccount($ctx, function () use ($ctx, $contract): void {
            $revision = OfficeErpContractRevision::factory()->create([
                'account_id' => $ctx->accountId,
                'contract_id' => $contract->id,
                'revision_number' => 2,
                'price' => '999.00',
                'currency' => 'BRL',
                'interval_months' => 1,
                'due_in_days' => 10,
                'module_keys' => ['purchases'],
            ]);
            $contract->forceFill(['current_revision_id' => $revision->id])->save();
        });

        $detail = $this->getJson('/api/v1/erp-commercial/invoices/'.$draft['id'])->assertOk()->json('data');
        self::assertSame('95.00', $detail['original_total']);
        self::assertSame('Cliente de teste', $detail['bill_to_snapshot']['client']['name']);
        self::assertSame('Empresa ERP de teste', $detail['bill_to_snapshot']['company']['name']);
        self::assertSame('100.00', $detail['seller_snapshot']['contract_terms']['price']);
        self::assertSame('100.00', $detail['items_snapshot'][0]['amount']);
        self::assertSame('5.00', $detail['items_snapshot'][0]['discount']);
        self::assertSame('2026-10-01', $detail['period_start']);
        self::assertSame('2026-11-01', $detail['period_end']);
        self::assertSame('2026-10-11', $detail['due_on']);
    }

    public function test_zero_invoice_is_settled_without_a_synthetic_receipt(): void
    {
        [$ctx, $contract] = $this->effectiveContract();
        $draft = $this->createDraft($contract, [
            'items' => [['description' => 'Sem cobrança', 'amount' => '0.00', 'discount' => '0.00']],
            'period_start' => '2026-10-01',
            'period_end' => '2026-11-01',
            'due_on' => '2026-10-11',
        ], 'zero-invoice')->assertCreated()->json('data');

        $this->postJson('/api/v1/erp-commercial/invoices/'.$draft['id'].'/issue', [], ['Idempotency-Key' => 'zero-issue'])->assertOk();

        $detail = $this->getJson('/api/v1/erp-commercial/invoices/'.$draft['id'])->assertOk()->json('data');
        self::assertSame('0.00', $detail['balance']['original_total']);
        self::assertSame('0.00', $detail['balance']['adjusted_charge']);
        self::assertSame('0.00', $detail['balance']['outstanding']);
        self::assertSame('settled', $detail['balance']['settlement_status']);
        $invoice = OfficeErpInvoice::query()->findOrFail($draft['id']);
        self::assertSame(0, $invoice->allocations()->count());
    }

    public function test_manual_draft_can_be_edited_then_is_locked_after_issue(): void
    {
        [, $contract] = $this->effectiveContract();
        $draft = $this->createDraft($contract, [
            'items' => [['description' => 'Rascunho', 'amount' => '100.00', 'discount' => '0.00']],
            'period_start' => '2026-10-01', 'period_end' => '2026-11-01', 'due_on' => '2026-10-11',
        ], 'draft-edit')->assertCreated()->json('data');

        $this->patchJson('/api/v1/erp-commercial/invoices/'.$draft['id'], [
            'items' => [['description' => 'Serviço revisado', 'amount' => '120.00', 'discount' => '0.00']],
        ], ['Idempotency-Key' => 'draft-edit-save'])->assertOk()->assertJsonPath('data.original_total', '120.00');
        $this->createDraft($contract, [
            'items' => [['description' => 'Rascunho', 'amount' => '100.00', 'discount' => '0.00']],
            'period_start' => '2026-10-01', 'period_end' => '2026-11-01', 'due_on' => '2026-10-11',
        ], 'draft-edit')->assertCreated()->assertJsonPath('data.original_total', '100.00');
        $this->postJson('/api/v1/erp-commercial/invoices/'.$draft['id'].'/issue', [], ['Idempotency-Key' => 'draft-edit-issue'])->assertOk();
        $this->patchJson('/api/v1/erp-commercial/invoices/'.$draft['id'], [
            'period_end' => '2026-12-01',
        ], ['Idempotency-Key' => 'issued-edit'])->assertConflict();
    }

    public function test_invoice_input_rejects_noncanonical_money_dates_and_office_identity_fields(): void
    {
        [, $contract] = $this->effectiveContract();
        $valid = [
            'items' => [['description' => 'Serviço', 'amount' => '1.00', 'discount' => '0.00']],
            'period_start' => '2026-10-01',
            'period_end' => '2026-11-01',
            'due_on' => '2026-10-11',
        ];
        $invalid = [
            array_replace_recursive($valid, ['items' => [['amount' => 1.0]]]),
            array_replace_recursive($valid, ['items' => [['amount' => '1e2']]]),
            array_replace_recursive($valid, ['items' => [['amount' => '-1.00']]]),
            array_replace_recursive($valid, ['items' => [['amount' => '1.001']]]),
            array_replace_recursive($valid, ['items' => [['amount' => '1000000000000000000.00']]]),
            array_replace_recursive($valid, ['items' => [['amount' => '1.00', 'discount' => '1.01']]]),
            array_replace($valid, ['period_start' => '2026-02-30']),
            array_replace($valid, ['account_id' => $contract->account_id]),
            array_replace($valid, ['currency' => 'USD']),
        ];

        foreach ($invalid as $index => $payload) {
            $this->postJson('/api/v1/erp-commercial/invoices', ['contract_id' => $contract->id] + $payload, [
                'Idempotency-Key' => 'invalid-invoice-'.$index,
            ])->assertUnprocessable();
        }

        self::assertSame(0, OfficeErpInvoice::query()->where('contract_id', $contract->id)->count());
    }

    public function test_only_effective_contracts_can_create_standalone_invoices(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->withOfficeAccount($ctx, fn (): OfficeErpContract => $this->acceptedContract($ctx));
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        $this->createDraft($contract, [
            'items' => [['description' => 'Serviço', 'amount' => '100.00', 'discount' => '0.00']],
            'period_start' => '2026-10-01',
            'period_end' => '2026-11-01',
            'due_on' => '2026-10-11',
        ], 'accepted-contract-invoice')->assertConflict();

        self::assertSame(0, OfficeErpInvoice::query()->where('contract_id', $contract->id)->count());
    }

    public function test_foreign_invoice_is_hidden_from_detail_and_paginated_list(): void
    {
        [$officeCtx] = $this->effectiveContract();
        [$foreignCtx, $foreignContract] = $this->effectiveContract();
        $invoices = app(OfficeErpInvoices::class);
        $foreign = $this->withOfficeAccount($foreignCtx, function () use ($invoices, $foreignCtx, $foreignContract): OfficeErpInvoice {
            $draft = $invoices->draft($foreignCtx, $foreignContract, [
                'items' => [['description' => 'Serviço privado', 'amount' => '100.00', 'discount' => '0.00']],
                'period_start' => '2026-10-01', 'period_end' => '2026-11-01', 'due_on' => '2026-10-11',
            ], 'foreign-invoice-draft');

            return $invoices->issue($foreignCtx, $draft, 'foreign-invoice-issue');
        });

        $this->selectBusinessAccount($officeCtx->actor, $officeCtx->accountId);
        $this->actingAs($officeCtx->actor);

        $this->getJson('/api/v1/erp-commercial/invoices/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/v1/erp-commercial/invoices')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
    }

    public function test_issue_replay_keeps_one_number_and_lookup_returns_snapshot_only_while_membership_is_current(): void
    {
        [$ctx, $contract] = $this->effectiveContract();
        $draft = $this->createDraft($contract, [
            'items' => [['description' => 'Serviço mensal', 'amount' => '100.00', 'discount' => '0.00']],
            'period_start' => '2026-10-01', 'period_end' => '2026-11-01', 'due_on' => '2026-10-11',
        ], 'lookup-snapshot-draft')->assertCreated()->json('data');
        $this->createDraft($contract, [
            'items' => [['description' => 'Serviço mensal', 'amount' => '100.00', 'discount' => '0.00']],
            'period_start' => '2026-10-01', 'period_end' => '2026-11-01', 'due_on' => '2026-10-11',
        ], 'lookup-snapshot-draft')->assertCreated()->assertJsonPath('data.id', $draft['id']);
        $this->createDraft($contract, [
            'items' => [['description' => 'Serviço mensal', 'amount' => '101.00', 'discount' => '0.00']],
            'period_start' => '2026-10-01', 'period_end' => '2026-11-01', 'due_on' => '2026-10-11',
        ], 'lookup-snapshot-draft')->assertConflict();

        $issued = $this->postJson('/api/v1/erp-commercial/invoices/'.$draft['id'].'/issue', [], ['Idempotency-Key' => 'lookup-snapshot-issue'])
            ->assertOk()->json('data');
        $this->postJson('/api/v1/erp-commercial/invoices/'.$draft['id'].'/issue', [], ['Idempotency-Key' => 'lookup-snapshot-issue'])
            ->assertOk()->assertJsonPath('data.number', $issued['number']);
        self::assertSame(2, OfficeErpInvoiceCounter::query()->where('account_id', $ctx->accountId)->where('year', (int) substr($issued['number'], 4, 4))->value('next_number'));
        $this->getJson('/api/v1/erp-commercial/operations/lookup-snapshot-issue', ['Idempotency-Key' => 'lookup-snapshot-issue'])
            ->assertOk()->assertJsonPath('data.bill_to_snapshot.client.person_type', 'company');

        $membership = AccountMembership::query()->where('account_id', $ctx->accountId)->where('user_id', $ctx->actor->id)->firstOrFail();
        $membership->update(['role' => 'operador']);
        $this->getJson('/api/v1/erp-commercial/operations/lookup-snapshot-issue', ['Idempotency-Key' => 'lookup-snapshot-issue'])->assertForbidden();
    }

    public function test_scheduler_can_issue_effective_contract_period_after_resale_rights_are_lost_but_cannot_make_manual_invoices(): void
    {
        [$ctx, $contract] = $this->effectiveContract();
        $subscription = $ctx->account->subscription()->firstOrFail();
        $subscription->forceFill(['status' => 'canceled'])->save();
        $system = $this->systemContext($ctx->account, 'billing:office-erp-contract:'.$contract->id);
        $revision = $contract->currentRevision()->firstOrFail();
        $period = ['start' => '2026-10-01', 'end' => '2026-11-01', 'due_on' => '2026-10-11'];
        $invoices = app(OfficeErpInvoices::class);

        $issued = $invoices->issuePeriod($system, $contract, $revision, $period, 'system-period-after-resale-loss');
        self::assertSame('issued', $issued->document_status);
        self::assertSame('billing.generate', OfficeErpCommercialOperation::query()
            ->where('account_id', $ctx->accountId)
            ->where('idempotency_key', 'system-period-after-resale-loss')
            ->value('action'));
        $lookup = app(OfficeErpOperations::class)->lookup(
            $system,
            'system-period-after-resale-loss',
            fn (string $action, array $payload) => $invoices->authorizeOperation($system, $action, $payload),
        );
        self::assertSame($issued->id, $lookup['data']['id']);

        try {
            $invoices->draft($system, $contract, [
                'items' => [['description' => 'Manual system invoice', 'amount' => '10.00', 'discount' => '0.00']],
                'period_start' => '2026-10-01', 'period_end' => '2026-11-01', 'due_on' => '2026-10-11',
            ], 'system-manual-invoice-denied');
            self::fail('System context must not create manual invoices.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        self::assertSame(1, OfficeErpInvoice::query()->where('contract_id', $contract->id)->count());
        self::assertSame(0, OfficeErpCommercialOperation::query()->where('account_id', $ctx->accountId)
            ->where('idempotency_key', 'system-manual-invoice-denied')->count());

        $inactivePeriod = ['start' => '2026-11-01', 'end' => '2026-12-01', 'due_on' => '2026-11-11'];
        Account::query()->whereKey($ctx->accountId)->update(['status' => 'suspended']);
        try {
            $invoices->issuePeriod($system, $contract, $revision, $inactivePeriod, 'system-period-inactive-account');
            self::fail('System billing must reject an inactive account.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        self::assertSame(0, OfficeErpInvoice::query()->where('contract_id', $contract->id)
            ->whereDate('period_start', $inactivePeriod['start'])->count());
        self::assertSame(0, OfficeErpCommercialOperation::query()->where('account_id', $ctx->accountId)
            ->where('idempotency_key', 'system-period-inactive-account')->count());
        try {
            app(OfficeErpOperations::class)->lookup(
                $system,
                'system-period-after-resale-loss',
                fn (string $action, array $payload) => $invoices->authorizeOperation($system, $action, $payload),
            );
            self::fail('System operation lookup must recheck active account authority.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_recurring_period_before_service_anchor_has_no_invoice_counter_or_journal_effects(): void
    {
        [$ctx, $contract] = $this->effectiveContract();
        $revision = $contract->currentRevision()->firstOrFail();
        $period = ['start' => '2026-09-01', 'end' => '2026-10-01', 'due_on' => '2026-09-11'];
        $invoices = app(OfficeErpInvoices::class);

        try {
            $this->withOfficeAccount($ctx, fn () => $invoices->issuePeriod($ctx, $contract, $revision, $period, 'period-before-anchor'));
            self::fail('A recurring period before service activation must be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }

        self::assertSame(0, OfficeErpInvoice::query()->where('contract_id', $contract->id)->count());
        self::assertSame(0, OfficeErpInvoiceCounter::query()->where('account_id', $ctx->accountId)->count());
        self::assertSame(0, OfficeErpCommercialOperation::query()->where('account_id', $ctx->accountId)
            ->where('idempotency_key', 'period-before-anchor')->count());
        self::assertSame(0, OfficeErpAuditEvent::query()->where('actor_identity', $ctx->actorIdentity)->count());
    }

    public function test_recurring_issue_period_is_idempotent_and_canceled_period_is_reserved(): void
    {
        [$ctx, $contract] = $this->effectiveContract();
        $period = ['start' => '2026-10-01', 'end' => '2026-11-01', 'due_on' => '2026-10-11'];
        $revision = $contract->currentRevision()->firstOrFail();
        $invoices = app(OfficeErpInvoices::class);

        $invoice = $this->withOfficeAccount($ctx, fn (): OfficeErpInvoice => $invoices->issuePeriod($ctx, $contract, $revision, $period, 'period-first'));
        $replay = $this->withOfficeAccount($ctx, fn (): OfficeErpInvoice => $invoices->issuePeriod($ctx, $contract, $revision, $period, 'period-replay'));
        self::assertSame($invoice->id, $replay->id);
        self::assertSame(1, OfficeErpInvoice::query()->where('contract_id', $contract->id)->where('kind', 'recurring')->count());

        $this->withOfficeAccount($ctx, function () use ($invoices, $ctx, $invoice): void {
            $invoices->cancel($ctx, $invoice, 'Período cancelado', 'period-cancel');
        });
        try {
            $this->withOfficeAccount($ctx, fn () => $invoices->issuePeriod($ctx, $contract, $revision, $period, 'period-regenerate'));
            self::fail('A canceled recurring period must stay reserved.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_highest_supported_money_value_roundtrips_on_postgresql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('NUMERIC(20,2) exact roundtrip is verified by the isolated PostgreSQL gate.');
        }

        [$ctx, $contract] = $this->effectiveContract();
        $draft = $this->createDraft($contract, [
            'items' => [['description' => 'Valor máximo', 'amount' => '999999999999999999.99', 'discount' => '0.00']],
            'period_start' => '2026-10-01',
            'period_end' => '2026-11-01',
            'due_on' => '2026-10-11',
        ], 'maximum-invoice')->assertCreated()->json('data');
        $this->postJson('/api/v1/erp-commercial/invoices/'.$draft['id'].'/issue', [], ['Idempotency-Key' => 'maximum-issue'])->assertOk();

        self::assertSame('999999999999999999.99', (string) OfficeErpInvoice::query()->findOrFail($draft['id'])->getRawOriginal('original_total'));
    }

    /** @return array{OfficeErpExecutionContext, OfficeErpContract, ErpCompany} */
    private function effectiveContract(): array
    {
        $ctx = $this->officeContext();
        $contract = $this->withOfficeAccount($ctx, fn (): OfficeErpContract => $this->acceptedContract($ctx));
        $company = ErpCompany::factory()->create(['name' => 'Empresa ERP de teste']);
        $this->withOfficeAccount($ctx, function () use ($ctx, $contract, $company): void {
            $contract->client()->update(['name' => 'Cliente de teste']);
            $contract->forceFill([
                'erp_company_id' => $company->id,
                'agreement_status' => 'effective',
                'service_anchor_on' => '2026-10-01',
                'effective_at' => now(),
            ])->save();
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

        return [$ctx, $contract->refresh(), $company];
    }

    private function createDraft(OfficeErpContract $contract, array $payload, string $key)
    {
        return $this->postJson('/api/v1/erp-commercial/invoices', ['contract_id' => $contract->id] + $payload, ['Idempotency-Key' => $key]);
    }
}
