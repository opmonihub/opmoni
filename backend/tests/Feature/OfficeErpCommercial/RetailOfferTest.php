<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpAuditEvent;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Erp\Commercial\OfficeErpSalesPlanRevision;
use Tests\OfficeErpCommercialTestCase;

class RetailOfferTest extends OfficeErpCommercialTestCase
{
    public function test_list_is_office_scoped_searchable_and_paginated_with_typed_metadata(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 100, 'module_keys' => ['purchases']]);
        $foreign = $this->officeContext(['enabled' => true, 'licence_limit' => 100, 'module_keys' => ['purchases']]);

        for ($number = 1; $number <= 26; $number++) {
            $this->planWithRevision($ctx->accountId, sprintf('Plano Loja %02d', $number));
        }
        $this->planWithRevision($foreign->accountId, 'Plano Loja estrangeiro');

        $response = $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/plans?q=Plano%20Loja&per_page=25&page=2');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 26);
        self::assertCount(1, $response->json('data'));
        self::assertSame('Plano Loja 26', $response->json('data.0.name'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/plans?per_page=10')->assertUnprocessable();
        $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/plans?page=0')->assertUnprocessable();
    }

    public function test_create_accepts_large_exact_brl_strings_and_returns_contract_shape(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $terms = $this->terms(['price' => '9007199254740993.01', 'published' => true]);
        unset($terms['currency']);

        $response = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'retail-create-exact')
            ->postJson('/api/v1/erp-commercial/plans', $terms);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['id', 'name', 'description', 'status', 'archived_at', 'current_revision' => ['id', 'revision_number', 'price', 'currency', 'interval_months', 'due_in_days', 'module_keys', 'published_at', 'published']]])
            ->assertJsonPath('data.current_revision.price', '9007199254740993.01')
            ->assertJsonPath('data.current_revision.currency', 'BRL')
            ->assertJsonPath('data.current_revision.published', true);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'retail-create-exact')
            ->getJson('/api/v1/erp-commercial/operations/retail-create-exact')
            ->assertOk()
            ->assertJsonPath('data.current_revision.price', '9007199254740993.01');
    }

    public function test_invalid_offer_terms_are_rejected_without_effects(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 50, 'module_keys' => ['purchases']]);
        $invalidTerms = [
            ['interval_months' => 2],
            ['due_in_days' => -1],
            ['due_in_days' => 65536],
            ['price' => '1.001'],
            ['price' => '1000000000000000000.00'],
            ['price' => 1.25],
            ['price' => '1e3'],
            ['currency' => 'USD'],
            ['module_keys' => ['not_a_module']],
            ['module_keys' => ['purchases', 'purchases']],
            ['published' => 'true'],
            ['name' => str_repeat('x', 256)],
        ];

        foreach ($invalidTerms as $index => $invalid) {
            $this->asOffice($ctx)->withHeader('Idempotency-Key', 'retail-invalid-'.$index)
                ->postJson('/api/v1/erp-commercial/plans', $this->terms($invalid))
                ->assertUnprocessable();
        }

        self::assertSame(0, OfficeErpSalesPlan::query()->where('account_id', $ctx->accountId)->count());
        self::assertSame(0, OfficeErpSalesPlanRevision::query()->where('account_id', $ctx->accountId)->count());
        self::assertSame(0, OfficeErpCommercialOperation::query()->where('account_id', $ctx->accountId)->count());
        self::assertSame(0, OfficeErpAuditEvent::query()->where('account_id', $ctx->accountId)->count());
    }

    public function test_offices_can_publish_the_same_offer_name_at_independent_prices(): void
    {
        $firstOffice = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $secondOffice = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);

        $first = $this->asOffice($firstOffice)->withHeader('Idempotency-Key', 'independent-price-one')
            ->postJson('/api/v1/erp-commercial/plans', $this->terms(['price' => '100.00', 'published' => true]))
            ->assertOk();
        $second = $this->asOffice($secondOffice)->withHeader('Idempotency-Key', 'independent-price-two')
            ->postJson('/api/v1/erp-commercial/plans', $this->terms(['price' => '120.00', 'published' => true]))
            ->assertOk();

        self::assertSame('100.00', $first->json('data.current_revision.price'));
        self::assertSame('120.00', $second->json('data.current_revision.price'));
        self::assertNotSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_foreign_office_plan_detail_and_archive_are_not_found(): void
    {
        $owner = $this->officeContext(['enabled' => true, 'licence_limit' => 5, 'module_keys' => ['purchases']]);
        $foreign = $this->officeContext(['enabled' => true, 'licence_limit' => 5, 'module_keys' => ['purchases']]);
        $plan = $this->planWithRevision($foreign->accountId, 'Oferta privada');

        $detail = $this->asOffice($owner)->getJson('/api/v1/erp-commercial/plans/'.$plan->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
        self::assertStringNotContainsString('Oferta privada', $detail->getContent());
        $archive = $this->asOffice($owner)->withHeader('Idempotency-Key', 'foreign-archive')
            ->postJson('/api/v1/erp-commercial/plans/'.$plan->id.'/archive', [])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        self::assertStringNotContainsString('Oferta privada', $archive->getContent());
        self::assertNull($plan->fresh()->archived_at);
        self::assertSame(0, OfficeErpCommercialOperation::query()->where('account_id', $owner->accountId)->count());
    }

    public function test_revising_offer_keeps_accepted_contract_terms_immutable_and_archive_needs_no_contract(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 10, 'module_keys' => ['purchases']]);
        $created = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'offer-100')
            ->postJson('/api/v1/erp-commercial/plans', $this->terms(['price' => '100.00', 'published' => true]))
            ->assertOk();
        $planId = (int) $created->json('data.id');
        $plan = OfficeErpSalesPlan::query()->findOrFail($planId);
        $oldOfferRevision = $plan->currentRevision;

        $client = $this->clientFor($ctx);
        $contract = OfficeErpContract::factory()->create([
            'account_id' => $ctx->accountId,
            'client_id' => $client->id,
            'sales_plan_id' => $plan->id,
            'agreement_status' => 'accepted',
        ]);
        $contractRevision = OfficeErpContractRevision::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'sales_plan_revision_id' => $oldOfferRevision->id,
            'price' => '100.00',
            'currency' => 'BRL',
            'interval_months' => 1,
            'due_in_days' => 10,
            'module_keys' => ['purchases'],
        ]);
        $contract->forceFill(['current_revision_id' => $contractRevision->id])->save();

        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'offer-120')
            ->postJson('/api/v1/erp-commercial/plans/'.$planId.'/revisions', $this->terms(['price' => '120.00', 'published' => true]))
            ->assertOk()
            ->assertJsonPath('data.current_revision.price', '120.00');

        self::assertSame('100.00', $contractRevision->fresh()->price);
        self::assertSame('120.00', $plan->fresh()->currentRevision->price);
        self::assertNotSame($oldOfferRevision->id, $plan->fresh()->currentRevision->id);

        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'archive-used-plan')
            ->postJson('/api/v1/erp-commercial/plans/'.$planId.'/archive', [])
            ->assertOk();
        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'offer-120')
            ->getJson('/api/v1/erp-commercial/operations/offer-120')
            ->assertConflict();

        $contractless = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'archive-empty-plan')
            ->postJson('/api/v1/erp-commercial/plans', $this->terms(['published' => false]))
            ->assertOk();
        $emptyPlanId = (int) $contractless->json('data.id');
        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'archive-empty')
            ->postJson('/api/v1/erp-commercial/plans/'.$emptyPlanId.'/archive', [])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
        self::assertNotNull(OfficeErpSalesPlan::query()->findOrFail($emptyPlanId)->archived_at);
        $revisionCount = OfficeErpSalesPlanRevision::query()->where('sales_plan_id', $emptyPlanId)->count();
        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'revise-archived')
            ->postJson('/api/v1/erp-commercial/plans/'.$emptyPlanId.'/revisions', $this->terms(['published' => true]))
            ->assertConflict();
        self::assertSame($revisionCount, OfficeErpSalesPlanRevision::query()->where('sales_plan_id', $emptyPlanId)->count());
    }

    public function test_idempotent_replay_lookup_and_current_membership_authorization(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 10, 'module_keys' => ['purchases']]);
        $terms = $this->terms(['published' => true]);

        $first = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'offer-replay')
            ->postJson('/api/v1/erp-commercial/plans', $terms)
            ->assertOk();
        $second = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'offer-replay')
            ->postJson('/api/v1/erp-commercial/plans', $terms)
            ->assertOk();

        self::assertSame($first->json('data.id'), $second->json('data.id'));
        self::assertSame(1, OfficeErpSalesPlan::query()->where('account_id', $ctx->accountId)->count());
        self::assertSame(1, OfficeErpCommercialOperation::query()->where('account_id', $ctx->accountId)->count());
        self::assertSame(1, OfficeErpAuditEvent::query()->where('account_id', $ctx->accountId)->count());

        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'offer-replay')
            ->postJson('/api/v1/erp-commercial/plans', $this->terms(['name' => 'Different offer', 'published' => true]))
            ->assertConflict();

        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'offer-replay')
            ->getJson('/api/v1/erp-commercial/operations/offer-replay')
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        AccountMembership::query()->where('account_id', $ctx->accountId)->where('user_id', $ctx->actor->id)->delete();
        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'offer-replay')
            ->getJson('/api/v1/erp-commercial/operations/offer-replay')
            ->assertForbidden();
        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'offer-replay')
            ->postJson('/api/v1/erp-commercial/plans', $terms)
            ->assertForbidden();
        self::assertSame(1, OfficeErpSalesPlan::query()->where('account_id', $ctx->accountId)->count());
    }

    public function test_malformed_idempotency_key_is_rejected_before_any_operation(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);

        $this->asOffice($ctx)->withHeader('Idempotency-Key', 'bad,key')
            ->postJson('/api/v1/erp-commercial/plans', $this->terms())
            ->assertUnprocessable();

        self::assertSame(0, OfficeErpSalesPlan::query()->where('account_id', $ctx->accountId)->count());
        self::assertSame(0, OfficeErpCommercialOperation::query()->where('account_id', $ctx->accountId)->count());
    }

    /** @return array<string, mixed> */
    private function terms(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Plano Loja',
            'description' => 'Oferta comercial para o varejo.',
            'price' => '100.00',
            'currency' => 'BRL',
            'interval_months' => 1,
            'due_in_days' => 10,
            'module_keys' => ['purchases'],
            'published' => false,
        ], $overrides);
    }

    private function planWithRevision(int $accountId, string $name): OfficeErpSalesPlan
    {
        $plan = OfficeErpSalesPlan::factory()->create(['account_id' => $accountId, 'name' => $name]);
        $revision = OfficeErpSalesPlanRevision::factory()->create([
            'account_id' => $accountId,
            'sales_plan_id' => $plan->id,
            'name' => $name,
            'published_at' => now(),
        ]);
        $plan->forceFill(['current_revision_id' => $revision->id])->save();

        return $plan->fresh(['currentRevision']);
    }

    private function asOffice(OfficeErpExecutionContext $ctx): self
    {
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        return $this->withHeader('X-Account-Id', (string) $ctx->accountId);
    }
}
