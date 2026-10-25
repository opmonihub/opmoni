<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\User;
use Tests\OfficeErpCommercialTestCase;

final class CommercialApiContractsTest extends OfficeErpCommercialTestCase
{
    public function test_production_offer_routes_replay_changed_payload_and_protect_operation_lookup(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 8, 'module_keys' => ['purchases']]);
        $key = '11111111-1111-4111-8111-111111111111';
        $terms = $this->offerTerms();

        $created = $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/plans', $terms, [
            'Idempotency-Key' => $key,
        ])->assertOk()->assertJsonStructure(['data' => ['id', 'current_revision' => ['price', 'currency']]]);
        $firstOutcome = $created->json('data');

        $replayed = $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/plans', $terms, [
            'Idempotency-Key' => $key,
        ])->assertOk()->json('data');
        self::assertSame($firstOutcome['id'], $replayed['id']);

        $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/plans', [
            ...$terms,
            'price' => '101.00',
        ], ['Idempotency-Key' => $key])->assertConflict();
        self::assertSame(1, OfficeErpSalesPlan::query()->where('account_id', $ctx->accountId)->count());

        $lookup = $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/operations/'.$key)
            ->assertOk()
            ->assertJsonPath('data.id', $firstOutcome['id']);
        self::assertStringContainsString('no-store', (string) $lookup->headers->get('Cache-Control'));

        $otherAdmin = User::factory()->create();
        AccountMembership::factory()->create([
            'account_id' => $ctx->accountId,
            'user_id' => $otherAdmin->id,
            'role' => 'admin',
        ]);
        $this->selectBusinessAccount($otherAdmin, $ctx->accountId);
        $this->actingAs($otherAdmin);
        $this->getJson('/api/v1/erp-commercial/context')
            ->assertOk()
            ->assertJsonPath('data.can_read', true);
        $otherActorLookup = $this->getJson('/api/v1/erp-commercial/operations/'.$key)->assertNotFound();
        self::assertArrayNotHasKey('data', $otherActorLookup->json());
        self::assertStringNotContainsString('Plano do contrato', $otherActorLookup->getContent());
        self::assertSame(1, OfficeErpSalesPlan::query()->where('account_id', $ctx->accountId)->count());
    }

    public function test_receipt_conflict_returns_the_exact_master_current_balance_shape(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->withOfficeAccount($ctx, fn (): OfficeErpContract => $this->acceptedContract($ctx));
        $invoice = $this->issuedInvoice($ctx, $contract, '100.00');
        $receiptInput = [
            'amount' => '100.00',
            'received_on' => '2026-10-15',
            'allocations' => [['invoice_id' => (int) $invoice->id, 'amount' => '100.00']],
        ];

        $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts', $receiptInput, [
            'Idempotency-Key' => '22222222-2222-4222-8222-222222222222',
        ])->assertCreated();

        $conflict = $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts', [
            'amount' => '1.00',
            'received_on' => '2026-10-15',
            'allocations' => [['invoice_id' => (int) $invoice->id, 'amount' => '1.00']],
        ], ['Idempotency-Key' => '33333333-3333-4333-8333-333333333333'])->assertConflict();

        $currentBalances = $conflict->json('data.current_balances');
        self::assertCount(1, $currentBalances);
        self::assertSame(['invoice_id', 'balance'], array_keys($currentBalances[0]));
        self::assertSame((int) $invoice->id, $currentBalances[0]['invoice_id']);
        self::assertSame([
            'original_total',
            'adjusted_charge',
            'effective_received',
            'outstanding',
            'settlement_status',
            'overdue',
        ], array_keys($currentBalances[0]['balance']));
        self::assertSame([
            'original_total' => '100.00',
            'adjusted_charge' => '100.00',
            'effective_received' => '100.00',
            'outstanding' => '0.00',
            'settlement_status' => 'settled',
            'overdue' => false,
        ], $currentBalances[0]['balance']);
    }

    private function asOffice(OfficeErpExecutionContext $ctx): self
    {
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        return $this->withHeader('X-Account-Id', (string) $ctx->accountId);
    }

    /** @return array{name:string,description:string,price:string,currency:string,interval_months:int,due_in_days:int,module_keys:list<string>,published:bool} */
    private function offerTerms(): array
    {
        return [
            'name' => 'Plano do contrato',
            'description' => 'Oferta contratual de teste.',
            'price' => '100.00',
            'currency' => 'BRL',
            'interval_months' => 1,
            'due_in_days' => 10,
            'module_keys' => ['purchases'],
            'published' => true,
        ];
    }
}
