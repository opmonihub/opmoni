<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpRecords;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Erp\Commercial\OfficeErpSalesPlanRevision;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpSubscription;
use App\Tenant\CurrentTenant;
use Illuminate\Database\QueryException;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\OfficeErpCommercialTestCase;
use Throwable;

class CommercialRecordsTest extends OfficeErpCommercialTestCase
{
    public function test_records_are_scoped_to_the_verified_account_and_cross_account_relationships_are_rejected(): void
    {
        $ctx = $this->officeContext();
        $foreign = $this->officeContext();
        $records = app(OfficeErpRecords::class);

        $plan = $records->persist($ctx, new OfficeErpSalesPlan([
            'name' => 'Plano do escritório',
            'status' => 'active',
        ]));

        self::assertSame($ctx->accountId, (int) $plan->account_id);
        self::assertTrue($records->query($ctx, OfficeErpSalesPlan::class)->whereKey($plan->id)->exists());
        self::assertFalse($records->query($foreign, OfficeErpSalesPlan::class)->whereKey($plan->id)->exists());

        $rejectedDeclaredAccount = false;
        try {
            $records->persist($ctx, new OfficeErpSalesPlan([
                'account_id' => $foreign->accountId,
                'name' => 'Plano estrangeiro',
                'status' => 'active',
            ]));
        } catch (HttpExceptionInterface) {
            $rejectedDeclaredAccount = true;
        }
        self::assertTrue($rejectedDeclaredAccount);

        $foreignClient = Client::factory()->company()->create(['account_id' => $foreign->accountId]);
        $crossAccountRelationshipCount = OfficeErpContract::query()
            ->where('account_id', $ctx->accountId)
            ->where('client_id', $foreignClient->id)
            ->count();
        if ($crossAccountRelationshipCount === 0) {
            try {
                OfficeErpContract::factory()->create([
                    'account_id' => $ctx->accountId,
                    'client_id' => $foreignClient->id,
                ]);
                $crossAccountRelationshipCount = 1;
            } catch (QueryException) {
                $crossAccountRelationshipCount = OfficeErpContract::query()
                    ->where('account_id', $ctx->accountId)
                    ->where('client_id', $foreignClient->id)
                    ->count();
            }
        }

        self::assertSame(0, $crossAccountRelationshipCount);
    }

    public function test_owner_and_system_records_restore_tenant_context_after_an_exception(): void
    {
        $previousTenantId = app(CurrentTenant::class)->accountId;
        $ctx = $this->officeContext();
        $system = $this->systemContext($ctx->account, 'contract:987');
        $records = app(OfficeErpRecords::class);

        foreach ([$ctx, $system] as $context) {
            $seenTenant = null;
            OfficeErpSalesPlan::creating(function () use (&$seenTenant): void {
                $seenTenant = app(CurrentTenant::class)->accountId;
                throw new RuntimeException('Falha de fixture durante a criação');
            });

            try {
                $records->persist($context, new OfficeErpSalesPlan([
                    'name' => 'Plano interrompido',
                    'status' => 'active',
                ]));
                self::fail('Expected the creating listener to abort the record persistence.');
            } catch (RuntimeException) {
                self::assertSame($context->accountId, $seenTenant);
                self::assertSame($previousTenantId, app(CurrentTenant::class)->accountId);
            } finally {
                OfficeErpSalesPlan::flushEventListeners();
            }
        }
    }

    public function test_contract_revisions_are_immutable_and_the_billing_cursor_starts_unset(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $revision = $contract->currentRevision;

        self::assertNotNull($revision);
        self::assertNull($contract->next_period_month_offset);
        self::assertNull($contract->service_anchor_on);

        $contract->forceFill(['next_period_month_offset' => 3])->save();
        self::assertSame(3, $contract->refresh()->next_period_month_offset);

        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    $revision->forceFill(['price' => '99.00'])->save();
                } else {
                    $revision->delete();
                }
                self::fail('Expected immutable contract revisions to reject '.$operation.'.');
            } catch (LogicException) {
                self::assertSame('100.00', $revision->refresh()->price);
            }
        }
    }

    public function test_licence_allocation_keeps_one_current_allocation_per_company_and_contract(): void
    {
        $ctx = $this->officeContext();
        $firstContract = $this->acceptedContract($ctx);
        $secondContract = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $otherCompany = ErpCompany::factory()->create();

        $first = OfficeErpLicenceAllocation::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $firstContract->id,
            'erp_company_id' => $company->id,
            'state' => 'occupied',
        ]);
        self::assertNull($first->expires_at);

        self::assertTrue($this->databaseRejected(function () use ($ctx, $firstContract, $otherCompany): void {
            OfficeErpLicenceAllocation::factory()->create([
                'account_id' => $ctx->accountId,
                'contract_id' => $firstContract->id,
                'erp_company_id' => $otherCompany->id,
                'state' => 'reserved',
            ]);
        }));

        self::assertTrue($this->databaseRejected(function () use ($ctx, $secondContract, $company): void {
            OfficeErpLicenceAllocation::factory()->create([
                'account_id' => $ctx->accountId,
                'contract_id' => $secondContract->id,
                'erp_company_id' => $company->id,
                'state' => 'reserved',
                'expires_at' => now()->addDays(7),
            ]);
        }));

        $first->forceFill(['state' => 'released', 'released_at' => now()])->save();
        self::assertTrue($this->databaseRejected(function () use ($ctx, $firstContract, $otherCompany): void {
            OfficeErpLicenceAllocation::factory()->create([
                'account_id' => $ctx->accountId,
                'contract_id' => $firstContract->id,
                'erp_company_id' => $otherCompany->id,
                'state' => 'reserved',
            ]);
        }));

        $replacement = OfficeErpLicenceAllocation::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $secondContract->id,
            'erp_company_id' => $company->id,
            'state' => 'reserved',
            'expires_at' => now()->addDays(7),
        ]);

        self::assertSame('reserved', $replacement->state);
        self::assertSame(1, OfficeErpLicenceAllocation::query()->where('erp_company_id', $company->id)->whereIn('state', ['reserved', 'occupied'])->count());
    }

    public function test_existing_direct_subscriptions_stay_unlinked_and_linked_contract_must_match_the_company(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $contract->forceFill(['erp_company_id' => ErpCompany::factory()->create()->id])->save();
        $plan = ErpPlan::factory()->create();

        $ownSubscription = ErpSubscription::factory()->create([
            'erp_company_id' => $contract->erp_company_id,
            'erp_plan_id' => $plan->id,
        ]);
        self::assertNull($ownSubscription->office_erp_contract_id);
        $ownSubscription->forceFill(['office_erp_contract_id' => $contract->id])->save();
        self::assertSame($contract->id, $ownSubscription->refresh()->office_erp_contract_id);

        $otherCompany = ErpCompany::factory()->create();
        $otherSubscription = ErpSubscription::factory()->create([
            'erp_company_id' => $otherCompany->id,
            'erp_plan_id' => $plan->id,
        ]);

        self::assertTrue($this->databaseRejected(function () use ($otherSubscription, $contract): void {
            $otherSubscription->forceFill(['office_erp_contract_id' => $contract->id])->save();
        }));
        self::assertNull($otherSubscription->refresh()->office_erp_contract_id);
        self::assertSame(1, ErpSubscription::query()->whereKey($otherSubscription->id)->count());
    }

    public function test_plan_current_revision_must_belong_to_the_same_plan_and_account(): void
    {
        $ctx = $this->officeContext();
        $plan = OfficeErpSalesPlan::factory()->create(['account_id' => $ctx->accountId]);
        $otherPlan = OfficeErpSalesPlan::factory()->create(['account_id' => $ctx->accountId]);
        $foreignRevision = OfficeErpSalesPlanRevision::factory()->create([
            'account_id' => $otherPlan->account_id,
            'sales_plan_id' => $otherPlan->id,
        ]);

        self::assertTrue($this->databaseRejected(function () use ($plan, $foreignRevision): void {
            $plan->forceFill(['current_revision_id' => $foreignRevision->id])->save();
        }));
        self::assertNull($plan->refresh()->current_revision_id);
    }

    public function test_commercial_operation_keeps_the_canonical_payload_for_recovery(): void
    {
        $ctx = $this->officeContext();
        $payload = ['plan_id' => 41, 'price' => '9007199254740993.01', 'module_keys' => ['erp.sales']];

        $operation = OfficeErpCommercialOperation::factory()->create([
            'account_id' => $ctx->accountId,
            'payload' => $payload,
        ]);

        self::assertSame($payload, $operation->refresh()->payload);
        self::assertSame($payload, OfficeErpCommercialOperation::query()->findOrFail($operation->id)->payload);
    }

    private function databaseRejected(callable $callback): bool
    {
        try {
            $callback();
        } catch (QueryException|Throwable) {
            return true;
        }

        return false;
    }
}
