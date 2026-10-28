<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Erp\Commercial\OfficeErpSalesPlanRevision;
use App\Models\User;
use Tests\OfficeErpCommercialTestCase;

class ListOrderingTest extends OfficeErpCommercialTestCase
{
    public function test_clients_are_sorted_before_pagination_and_remain_office_scoped(): void
    {
        $ctx = $this->officeContext();
        $alphaClientOne = $this->clientFor($ctx)->forceFill(['name' => 'Alpha client']);
        $alphaClientOne->save();
        $alphaClientTwo = $this->clientFor($ctx)->forceFill(['name' => 'Alpha client']);
        $alphaClientTwo->save();
        $zuluClient = $this->clientFor($ctx)->forceFill(['name' => 'Zulu client']);
        $zuluClient->save();
        $foreignClient = $this->clientFor($this->officeContext())->forceFill(['name' => 'Foreign client']);
        $foreignClient->save();

        $response = $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/clients?sort=name&direction=desc&per_page=25&page=1')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Zulu client')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.current_page', 1);

        self::assertSame(3, $response->json('meta.total'));
        self::assertSame([$zuluClient->id, $alphaClientTwo->id, $alphaClientOne->id], array_column($response->json('data'), 'id'));
        self::assertNotContains($foreignClient->id, array_column($response->json('data'), 'id'));
    }

    public function test_office_offer_and_contract_lists_sort_on_allowlisted_fields(): void
    {
        $ctx = $this->officeContext();
        foreach ([['Alpha offer', 'archived'], ['Zulu offer', 'active']] as [$name, $status]) {
            $offer = OfficeErpSalesPlan::factory()->create(['account_id' => $ctx->accountId, 'name' => $name, 'status' => $status]);
            $revision = OfficeErpSalesPlanRevision::factory()->create([
                'account_id' => $ctx->accountId,
                'sales_plan_id' => $offer->id,
                'name' => $name,
            ]);
            $offer->forceFill(['current_revision_id' => $revision->id])->save();
        }

        $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/plans?status=archived&sort=name')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Alpha offer');

        $alphaContract = $this->withOfficeAccount($ctx, fn () => $this->acceptedContract($ctx));
        $alphaContract->client()->update(['name' => 'Alpha contract client']);
        $zuluContract = $this->withOfficeAccount($ctx, fn () => $this->acceptedContract($ctx));
        $zuluContract->client()->update(['name' => 'Zulu contract client']);

        $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/contracts?sort=client&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $zuluContract->id)
            ->assertJsonPath('data.1.id', $alphaContract->id)
            ->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/erp-commercial/contracts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $zuluContract->id);
    }

    public function test_contract_client_ordering_keeps_deleted_clients_last_in_both_directions(): void
    {
        $ctx = $this->officeContext();
        $contracts = [];
        foreach (['Alpha', 'Zulu', 'Zulu', 'Deleted'] as $name) {
            $contract = $this->withOfficeAccount($ctx, fn () => $this->acceptedContract($ctx));
            $this->withOfficeAccount($ctx, fn () => $contract->client()->update(['name' => $name]));
            $contracts[] = $contract;
        }
        $this->withOfficeAccount($ctx, fn () => $contracts[3]->client()->firstOrFail()->delete());

        foreach (['asc' => [0, 1, 2, 3], 'desc' => [2, 1, 0, 3]] as $direction => $indices) {
            $response = $this->asOffice($ctx)
                ->getJson('/api/v1/erp-commercial/contracts?sort=client&direction='.$direction)
                ->assertOk()
                ->assertJsonPath('meta.total', 4);

            self::assertSame(
                array_map(fn (int $index): int => $contracts[$index]->id, $indices),
                array_column($response->json('data'), 'id'),
            );
        }
    }

    public function test_invoice_and_receipt_lists_sort_by_dates_and_null_references_remain_last(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->withOfficeAccount($ctx, fn () => $this->acceptedContract($ctx));
        $late = $this->issuedInvoice($ctx, $contract, '123.45');
        $early = $this->issuedInvoice($ctx, $contract, '456.78');
        $late->forceFill(['due_on' => '2026-12-10'])->save();
        $early->forceFill(['due_on' => '2026-11-10'])->save();

        $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/invoices?sort=due_on&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $early->id)
            ->assertJsonPath('data.0.original_total', '456.78')
            ->assertJsonPath('data.1.id', $late->id)
            ->assertJsonPath('data.1.original_total', '123.45');
        $draft = $this->withOfficeAccount($ctx, fn (): OfficeErpInvoice => OfficeErpInvoice::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'contract_revision_id' => $contract->current_revision_id,
            'document_status' => 'draft',
            'number' => null,
        ]));
        $this->getJson('/api/v1/erp-commercial/invoices?sort=number&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.2.id', $draft->id)
            ->assertJsonPath('data.2.number', null);

        $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts', [
            'amount' => '10.00', 'received_on' => '2026-11-12',
            'allocations' => [['invoice_id' => $late->id, 'amount' => '10.00']],
        ], ['Idempotency-Key' => 'sort-receipt-null'])->assertCreated();
        $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts', [
            'amount' => '10.00', 'received_on' => '2026-11-12', 'reference' => 'A-100',
            'allocations' => [['invoice_id' => $late->id, 'amount' => '10.00']],
        ], ['Idempotency-Key' => 'sort-receipt-reference'])->assertCreated();
        $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/receipts?sort=reference&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'A-100')
            ->assertJsonPath('data.1.reference', null);
        $this->getJson('/api/v1/erp-commercial/receipts')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'A-100');
    }

    public function test_commercial_sort_validation_rejects_unknown_fields_and_direction_without_sort(): void
    {
        $ctx = $this->officeContext();

        $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/clients?sort=tax_id')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');
        $this->getJson('/api/v1/erp-commercial/receipts?direction=desc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('direction');
    }

    public function test_commercial_sorting_is_applied_before_pagination_for_clients_invoices_and_receipts(): void
    {
        $ctx = $this->officeContext();
        $clients = [];
        for ($index = 0; $index < 26; $index++) {
            $clients[] = $this->clientFor($ctx)->forceFill(['name' => 'Page Client']);
            $clients[$index]->save();
        }

        $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/clients?sort=name&direction=asc&per_page=25&page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.0.id', $clients[25]->id);

        $contract = $this->withOfficeAccount($ctx, fn () => $this->acceptedContract($ctx));
        $invoices = [];
        for ($index = 0; $index < 26; $index++) {
            $invoices[] = $this->issuedInvoice($ctx, $contract, '100.00');
            $invoices[$index]->forceFill(['due_on' => now()->addDays($index)->toDateString()])->save();
        }
        $this->getJson('/api/v1/erp-commercial/invoices?sort=due_on&direction=asc&per_page=25&page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('data.0.id', $invoices[25]->id)
            ->assertJsonPath('data.0.original_total', '100.00');

        $receipts = [];
        foreach (range(0, 25) as $index) {
            $receipt = OfficeErpReceipt::factory()->create([
                'account_id' => $ctx->accountId,
                'reference' => 'Page Receipt',
                'received_on' => '2026-11-12',
            ]);
            OfficeErpReceiptAllocation::factory()->forInvoice($invoices[0])->forReceipt($receipt)->create([
                'account_id' => $ctx->accountId,
                'amount' => '1.00',
            ]);
            $receipts[] = $receipt;
        }
        $this->getJson('/api/v1/erp-commercial/receipts?sort=reference&direction=asc&per_page=25&page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('data.0.id', $receipts[25]->id);
    }

    public function test_office_offer_and_contract_sorting_is_applied_before_pagination(): void
    {
        $ctx = $this->officeContext();
        $offers = [];
        $contracts = [];
        for ($index = 0; $index < 26; $index++) {
            $name = sprintf('Page Offer %02d', $index);
            $offer = $this->withOfficeAccount($ctx, function () use ($ctx, $name): OfficeErpSalesPlan {
                $record = OfficeErpSalesPlan::factory()->create(['account_id' => $ctx->accountId, 'name' => $name]);
                $revision = OfficeErpSalesPlanRevision::factory()->create([
                    'account_id' => $ctx->accountId,
                    'sales_plan_id' => $record->id,
                    'name' => $name,
                ]);
                $record->forceFill(['current_revision_id' => $revision->id])->save();

                return $record;
            });
            $offers[] = $offer;

            $contract = $this->withOfficeAccount($ctx, fn () => $this->acceptedContract($ctx));
            $this->withOfficeAccount($ctx, fn () => $contract->client()->update(['name' => sprintf('Page Contract %02d', $index)]));
            $contracts[] = $contract;
        }

        $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/plans?search=Page%20Offer&sort=name&direction=asc&per_page=25&page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('data.0.id', $offers[25]->id);
        $this->getJson('/api/v1/erp-commercial/contracts?sort=client&direction=asc&per_page=25&page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('data.0.id', $contracts[25]->id);
    }

    public function test_sort_parameters_do_not_bypass_office_or_platform_authority(): void
    {
        $ctx = $this->officeContext();
        $this->asOffice($ctx)
            ->getJson('/api/v1/erp-commercial/clients?sort=name')
            ->assertOk();

        $ordinaryUser = User::factory()->create();
        $this->actingAs($ordinaryUser, 'web')
            ->getJson('/api/v1/admin/users?sort=email&direction=asc')
            ->assertForbidden();
    }

    private function asOffice(OfficeErpExecutionContext $ctx): self
    {
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        return $this->withHeader('X-Account-Id', (string) $ctx->accountId);
    }
}
