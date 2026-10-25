<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpBalances;
use App\Erp\Commercial\OfficeErpDelinquency;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Models\Erp\Commercial\OfficeErpCommercialSetting;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpInvoiceAdjustment;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use App\Models\Erp\Commercial\OfficeErpReceiptReversal;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\OfficeErpCommercialTestCase;

class DelinquencyTest extends OfficeErpCommercialTestCase
{
    public function test_due_day_and_grace_are_inclusive(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->effectiveContract($ctx);
        $invoice = $this->invoiceFor($ctx, $contract, '100.00', '2026-10-24');
        $this->automaticPolicy($ctx, 2);
        $service = app(OfficeErpDelinquency::class);

        self::assertSame('active', $this->reconcileAt($ctx, $service, $contract, CarbonImmutable::parse('2026-10-26T23:59:59-03:00')));
        self::assertSame('past_due', $this->reconcileAt($ctx, $service, $contract, CarbonImmutable::parse('2026-10-27T00:00:00-03:00')));
        self::assertSame('issued', $invoice->fresh()->document_status);
    }

    public function test_settlement_preserves_manual_hold(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->effectiveContract($ctx, ['manual_hold' => true]);
        $invoice = $this->invoiceFor($ctx, $contract, '100.00', '2026-10-01');
        $this->automaticPolicy($ctx, 0);
        $this->receiptFor($ctx, $invoice, '100.00');

        self::assertSame('settled', OfficeErpBalances::forInvoice($invoice->fresh())['settlement_status']);
        self::assertSame('suspended', $this->reconcileAt(
            $ctx,
            app(OfficeErpDelinquency::class),
            $contract,
            CarbonImmutable::parse('2026-10-27T00:00:00-03:00'),
        ));
    }

    public function test_canceled_and_unactivated_contracts_keep_precedence_over_debt(): void
    {
        $ctx = $this->officeContext();
        $canceled = $this->effectiveContract($ctx);
        $canceled->forceFill(['agreement_status' => 'canceled'])->save();
        $this->invoiceFor($ctx, $canceled, '100.00', '2026-10-01');
        $pending = $this->effectiveContract($ctx);
        $pending->forceFill(['agreement_status' => 'accepted'])->save();
        $this->invoiceFor($ctx, $pending, '100.00', '2026-10-01');
        $this->automaticPolicy($ctx, 0);
        $service = app(OfficeErpDelinquency::class);
        $now = CarbonImmutable::parse('2026-10-27T00:00:00-03:00');

        self::assertSame('canceled', $this->reconcileAt($ctx, $service, $canceled, $now));
        self::assertSame('pending', $this->reconcileAt($ctx, $service, $pending, $now));
    }

    public function test_legacy_direct_subscription_is_not_adopted_and_unknown_status_is_preserved(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->effectiveContract($ctx);
        $subscription = ErpSubscription::query()->where('office_erp_contract_id', $contract->id)->firstOrFail();
        $subscription->forceFill(['office_erp_contract_id' => null, 'status' => 'active'])->save();
        $service = app(OfficeErpDelinquency::class);
        $now = CarbonImmutable::parse('2026-10-27T00:00:00-03:00');

        self::assertSame('pending', $this->reconcileAt($ctx, $service, $contract, $now));
        self::assertNull($subscription->fresh()->office_erp_contract_id);
        self::assertSame('active', $subscription->fresh()->status);

        $subscription->forceFill(['office_erp_contract_id' => $contract->id, 'status' => 'legacy_unknown'])->save();
        self::assertSame('legacy_unknown', $this->reconcileAt($ctx, $service, $contract->fresh(), $now));
        self::assertSame('legacy_unknown', $subscription->fresh()->status);
    }

    public function test_automatic_policy_requires_explicit_confirmation_without_effects(): void
    {
        $ctx = $this->officeContext();
        $this->authenticateOffice($ctx);
        $beforeSettings = OfficeErpCommercialSetting::query()->where('account_id', $ctx->accountId)->first();

        $response = $this->withHeader('Idempotency-Key', 'policy-unconfirmed-key-0001')->patchJson('/api/v1/erp-commercial/settings', [
            'mode' => 'automatic',
            'grace_days' => 2,
            'billing_timezone' => 'America/Sao_Paulo',
            'confirm_automatic' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('confirm_automatic');

        self::assertSame(422, $response->status());
        self::assertSame($beforeSettings?->getAttributes(), OfficeErpCommercialSetting::query()->where('account_id', $ctx->accountId)->first()?->getAttributes());
        self::assertDatabaseCount('office_erp_commercial_operations', 0);
        self::assertDatabaseCount('office_erp_audit_events', 0);
    }

    public function test_invalid_idempotency_keys_are_rejected_without_settings_operation_or_audit_effects(): void
    {
        $ctx = $this->officeContext();
        $this->authenticateOffice($ctx);
        $beforeSettings = OfficeErpCommercialSetting::query()->where('account_id', $ctx->accountId)->first();
        $payload = [
            'mode' => 'manual',
            'grace_days' => 3,
            'billing_timezone' => 'America/Sao_Paulo',
            'confirm_automatic' => false,
        ];

        foreach ([str_repeat('k', 129), 'policy,invalid-key'] as $key) {
            $this->withHeader('Idempotency-Key', $key)
                ->patchJson('/api/v1/erp-commercial/settings', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('source_operation_key');
        }

        self::assertSame($beforeSettings?->getAttributes(), OfficeErpCommercialSetting::query()->where('account_id', $ctx->accountId)->first()?->getAttributes());
        self::assertDatabaseCount('office_erp_commercial_operations', 0);
        self::assertDatabaseCount('office_erp_audit_events', 0);
    }

    public function test_default_policy_is_manual_and_security_suspension_is_not_rewritten(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->effectiveContract($ctx, [], 'suspended');
        $this->authenticateOffice($ctx);

        $this->getJson('/api/v1/erp-commercial/settings')
            ->assertOk()
            ->assertJsonPath('data.mode', 'manual')
            ->assertJsonPath('data.grace_days', 0)
            ->assertJsonPath('data.billing_timezone', 'America/Sao_Paulo');

        self::assertSame('active', $this->reconcileAt(
            $ctx,
            app(OfficeErpDelinquency::class),
            $contract,
            CarbonImmutable::parse('2026-10-27T00:00:00-03:00'),
        ));
        self::assertSame('suspended', $contract->company->fresh()->status);
    }

    public function test_authoritative_partial_debit_credit_receipt_reversal_and_canceled_invoice_balances_drive_status(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->effectiveContract($ctx);
        $invoice = $this->invoiceFor($ctx, $contract, '100.00', '2026-10-01');
        $this->automaticPolicy($ctx, 0);
        OfficeErpInvoiceAdjustment::factory()->create([
            'account_id' => $ctx->accountId,
            'invoice_id' => $invoice->id,
            'direction' => 'debit',
            'amount' => '10.00',
        ]);
        OfficeErpInvoiceAdjustment::factory()->create([
            'account_id' => $ctx->accountId,
            'invoice_id' => $invoice->id,
            'direction' => 'credit',
            'amount' => '5.00',
        ]);
        $receipt = $this->receiptFor($ctx, $invoice, '50.00');

        self::assertSame('105.00', OfficeErpBalances::forInvoice($invoice->fresh())['adjusted_charge']);
        self::assertSame('55.00', OfficeErpBalances::forInvoice($invoice->fresh())['outstanding']);
        $service = app(OfficeErpDelinquency::class);
        self::assertSame('past_due', $this->reconcileAt($ctx, $service, $contract, CarbonImmutable::parse('2026-10-27T00:00:00-03:00')));

        $this->receiptFor($ctx, $invoice, '55.00');
        self::assertSame('active', $this->reconcileAt($ctx, $service, $contract, CarbonImmutable::parse('2026-10-27T00:00:00-03:00')));

        OfficeErpReceiptReversal::factory()->create([
            'account_id' => $ctx->accountId,
            'receipt_id' => $receipt->id,
        ]);
        self::assertSame('past_due', $this->reconcileAt($ctx, $service, $contract, CarbonImmutable::parse('2026-10-27T00:00:00-03:00')));

        $invoice->forceFill(['document_status' => 'canceled'])->save();
        self::assertSame('active', $this->reconcileAt($ctx, $service, $contract, CarbonImmutable::parse('2026-10-27T00:00:00-03:00')));
    }

    public function test_preview_is_account_scoped_counted_paginated_and_uses_contract_timezone(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25T07:30:00Z'));
        try {
            $ctx = $this->officeContext();
            $contracts = [];
            for ($index = 0; $index < 21; $index++) {
                $contract = $this->effectiveContract($ctx, ['billing_timezone' => 'America/Los_Angeles']);
                $this->invoiceFor($ctx, $contract, '100.00', '2026-10-24');
                $contracts[] = $contract;
            }
            $this->automaticPolicy($ctx, 0, 'America/Los_Angeles');
            $foreign = $this->officeContext();
            $foreignContract = $this->effectiveContract($foreign);
            $this->invoiceFor($foreign, $foreignContract, '100.00', '2026-10-01');
            $this->automaticPolicy($foreign, 0);
            $this->authenticateOffice($ctx);

            $response = $this->postJson('/api/v1/erp-commercial/settings/preview', [
                'mode' => 'automatic',
                'grace_days' => 0,
                'billing_timezone' => 'America/Los_Angeles',
                'page' => 1,
            ])->assertOk();

            self::assertSame(21, $response->json('affected_count'));
            self::assertSame(21, $response->json('meta.total'));
            self::assertSame(1, $response->json('meta.current_page'));
            self::assertSame(2, $response->json('meta.last_page'));
            self::assertSame(20, count($response->json('data')));
            self::assertSame((int) $contracts[0]->id, $response->json('data.0.contract_id'));

            $secondPage = $this->postJson('/api/v1/erp-commercial/settings/preview', [
                'mode' => 'automatic',
                'grace_days' => 0,
                'billing_timezone' => 'America/Los_Angeles',
                'page' => 2,
            ])->assertOk();
            self::assertSame(2, $secondPage->json('meta.current_page'));
            self::assertCount(1, $secondPage->json('data'));
            self::assertSame((int) $contracts[20]->id, $secondPage->json('data.0.contract_id'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_confirmed_settings_save_is_idempotent_and_changed_payload_conflicts(): void
    {
        $ctx = $this->officeContext();
        $this->authenticateOffice($ctx);
        $payload = [
            'mode' => 'automatic',
            'grace_days' => 2,
            'billing_timezone' => 'America/Sao_Paulo',
            'confirm_automatic' => true,
        ];

        $first = $this->withHeader('Idempotency-Key', 'policy-settings-key-0001')
            ->patchJson('/api/v1/erp-commercial/settings', $payload)
            ->assertOk();
        $replay = $this->withHeader('Idempotency-Key', 'policy-settings-key-0001')
            ->patchJson('/api/v1/erp-commercial/settings', $payload)
            ->assertOk();
        $this->withHeader('Idempotency-Key', 'policy-settings-key-0001')
            ->patchJson('/api/v1/erp-commercial/settings', [...$payload, 'grace_days' => 3])
            ->assertConflict();

        self::assertSame($first->json(), $replay->json());
        $lookup = $this->getJson('/api/v1/erp-commercial/operations/policy-settings-key-0001')->assertOk();
        self::assertSame($first->json(), $lookup->json());
        self::assertSame(2, OfficeErpCommercialSetting::query()->where('account_id', $ctx->accountId)->value('grace_days'));
        self::assertDatabaseCount('office_erp_commercial_operations', 1);
        self::assertDatabaseCount('office_erp_audit_events', 1);
    }

    public function test_policy_save_reconciles_company_subscription_and_manual_mode_clears_only_automatic_delinquency(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-27T00:00:00-03:00'));
        try {
            $ctx = $this->officeContext();
            $contract = $this->effectiveContract($ctx);
            $this->invoiceFor($ctx, $contract, '100.00', '2026-10-01');
            $this->authenticateOffice($ctx);

            $this->withHeader('Idempotency-Key', 'policy-activate-key-0001')
                ->patchJson('/api/v1/erp-commercial/settings', [
                    'mode' => 'automatic',
                    'grace_days' => 0,
                    'billing_timezone' => 'America/Sao_Paulo',
                    'confirm_automatic' => true,
                ])->assertOk()->assertJsonPath('data.mode', 'automatic');
            self::assertSame('past_due', $contract->company->subscription->fresh()->status);

            $this->withHeader('Idempotency-Key', 'policy-manual-key-0001')
                ->patchJson('/api/v1/erp-commercial/settings', [
                    'mode' => 'manual',
                    'grace_days' => 0,
                    'billing_timezone' => 'America/Sao_Paulo',
                    'confirm_automatic' => false,
                ])->assertOk()->assertJsonPath('data.mode', 'manual');
            self::assertSame('active', $contract->company->subscription->fresh()->status);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_invalid_policy_values_and_foreign_contract_are_rejected(): void
    {
        $ctx = $this->officeContext();
        $this->authenticateOffice($ctx);

        $this->patchJson('/api/v1/erp-commercial/settings', [
            'mode' => 'automatic',
            'grace_days' => -1,
            'billing_timezone' => 'Not/A_Timezone',
            'confirm_automatic' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['grace_days', 'billing_timezone']);

        $foreign = $this->officeContext();
        $foreignContract = $this->effectiveContract($foreign);
        try {
            $this->reconcileAt($ctx, app(OfficeErpDelinquency::class), $foreignContract, CarbonImmutable::now());
            self::fail('A foreign account contract must not be reconciled through this office context.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }
    }

    private function effectiveContract(OfficeErpExecutionContext $ctx, array $attributes = [], string $companyStatus = 'active'): OfficeErpContract
    {
        $contract = $this->withOfficeAccount($ctx, fn (): OfficeErpContract => $this->acceptedContract($ctx));
        $company = ErpCompany::factory()->create(['status' => $companyStatus]);
        $contract->forceFill([
            'agreement_status' => 'effective',
            'effective_at' => now(),
            'service_anchor_on' => '2026-10-01',
            'erp_company_id' => $company->id,
            ...$attributes,
        ])->save();
        ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => ErpPlan::factory()->create()->id,
            'office_erp_contract_id' => $contract->id,
            'status' => 'active',
        ]);

        return $contract->refresh();
    }

    private function invoiceFor(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, string $amount, string $dueOn): OfficeErpInvoice
    {
        $invoice = $this->withOfficeAccount($ctx, fn (): OfficeErpInvoice => $this->issuedInvoice($ctx, $contract, $amount));
        $invoice->forceFill(['due_on' => $dueOn])->save();

        return $invoice->refresh();
    }

    private function automaticPolicy(OfficeErpExecutionContext $ctx, int $graceDays, string $timezone = 'America/Sao_Paulo'): void
    {
        OfficeErpCommercialSetting::factory()->create([
            'account_id' => $ctx->accountId,
            'delinquency_policy' => 'automatic',
            'grace_days' => $graceDays,
            'billing_timezone' => $timezone,
        ]);
    }

    private function receiptFor(OfficeErpExecutionContext $ctx, OfficeErpInvoice $invoice, string $amount): OfficeErpReceipt
    {
        $receipt = OfficeErpReceipt::factory()->create([
            'account_id' => $ctx->accountId,
            'amount' => $amount,
        ]);
        OfficeErpReceiptAllocation::factory()->create([
            'account_id' => $ctx->accountId,
            'receipt_id' => $receipt->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
        ]);

        return $receipt;
    }

    private function authenticateOffice(OfficeErpExecutionContext $ctx): void
    {
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);
    }

    private function reconcileAt(OfficeErpExecutionContext $ctx, OfficeErpDelinquency $service, OfficeErpContract $contract, CarbonImmutable $now): string
    {
        return $this->withOfficeAccount($ctx, fn (): string => DB::transaction(
            fn (): string => $service->reconcile($ctx, $contract, $now),
        ));
    }
}
