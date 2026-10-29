<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpCompanyMembers;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpMemberAuthority;
use App\Erp\Commercial\OfficeErpMemberInvitations;
use App\Models\Erp\Commercial\OfficeErpAccessDelivery;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpMemberInvitation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use Illuminate\Validation\ValidationException;
use Tests\OfficeErpCommercialTestCase;

class MemberInvitationIssueTest extends OfficeErpCommercialTestCase
{
    public function test_invitation_issue_creates_no_membership_and_returns_only_delivery_state(): void
    {
        [$ctx, $company, $registration] = $this->managedCompany();
        $key = 'member-invite-'.bin2hex(random_bytes(8));

        $result = $this->withOfficeAccount($ctx, fn (): array => app(OfficeErpMemberInvitations::class)
            ->issue($ctx, (int) $company->id, $this->body('invitee@example.test'), $key));

        self::assertSame((int) $company->id, $result['company_id']);
        self::assertSame('pending', $result['invitation']['status']);
        self::assertSame('prepared', $result['delivery']['state']);
        self::assertArrayNotHasKey('token', $result);
        self::assertArrayNotHasKey('url', $result);
        $invitation = OfficeErpMemberInvitation::query()->findOrFail($result['invitation']['id']);
        self::assertSame($registration->id, $invitation->registration_id);
        self::assertSame([], $invitation->permissions);
        self::assertSame('defaults', $invitation->permissions_mode);
        self::assertSame(0, $company->memberships()->count());
        $delivery = OfficeErpAccessDelivery::query()->findOrFail($result['delivery']['id']);
        self::assertSame('member_invitation', $delivery->purpose);
        self::assertSame($invitation->id, $delivery->member_invitation_id);
        $operation = OfficeErpCommercialOperation::query()->where('idempotency_key', $key)->firstOrFail();
        self::assertStringNotContainsString('token', json_encode($operation->payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('token', json_encode($operation->outcome, JSON_THROW_ON_ERROR));
    }

    public function test_delivery_accepts_a_super_admin_with_real_selected_office_admin_authority(): void
    {
        [$ctx, $company] = $this->managedCompany();
        $ctx->actor->forceFill(['is_super_admin' => true])->save();
        $result = $this->withOfficeAccount($ctx, fn (): array => app(OfficeErpMemberInvitations::class)
            ->issue($ctx, (int) $company->id, $this->body('dual-role@example.test'), 'dual-role-'.bin2hex(random_bytes(6))));
        $delivery = OfficeErpAccessDelivery::query()->findOrFail($result['delivery']['id']);

        app(OfficeErpMemberAuthority::class)->assertDelivery($delivery);

        self::assertSame('member_invitation', $delivery->purpose);
    }

    public function test_expired_pending_email_invitation_is_expired_and_replaced_without_unique_index_failure(): void
    {
        [$ctx, $company, $registration] = $this->managedCompany();
        $expired = OfficeErpMemberInvitation::factory()->create([
            'account_id' => $ctx->accountId, 'erp_company_id' => $company->id, 'registration_id' => $registration->id,
            'email' => 'expired.invitee@example.test', 'status' => 'pending', 'expires_at' => now()->subSecond(),
        ]);
        $delivery = OfficeErpAccessDelivery::factory()->forInvitation($expired)->create();

        $result = $this->withOfficeAccount($ctx, fn (): array => app(OfficeErpMemberInvitations::class)
            ->issue($ctx, (int) $company->id, $this->body(' EXPIRED.INVITEE@example.test '), 'replacement-'.bin2hex(random_bytes(6))));

        self::assertSame('expired', $expired->fresh()->status);
        self::assertNotNull($delivery->fresh()->authority_invalidated_at);
        self::assertNull($delivery->fresh()->payload);
        self::assertSame('pending', OfficeErpMemberInvitation::query()->findOrFail($result['invitation']['id'])->status);
        self::assertSame('expired.invitee@example.test', $result['invitation']['email']);
    }

    public function test_foreign_establishment_is_rejected_before_invitation_or_delivery_is_persisted(): void
    {
        [$ctx, $company] = $this->managedCompany();
        $foreign = ErpCompany::factory()->create();
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $foreign->id]);
        $body = array_replace($this->body('foreign.scope@example.test'), [
            'permissions_mode' => 'explicit', 'permissions' => ['erp.read'],
            'establishment_scope' => 'selected', 'establishment_ids' => [$establishment->id],
        ]);
        $key = 'foreign-scope-'.bin2hex(random_bytes(6));

        try {
            $this->withOfficeAccount($ctx, fn () => app(OfficeErpMemberInvitations::class)->issue($ctx, (int) $company->id, $body, $key));
            self::fail('Foreign establishments must not be invited.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        self::assertSame(0, OfficeErpMemberInvitation::query()->count());
        self::assertSame(0, OfficeErpAccessDelivery::query()->count());
        self::assertDatabaseMissing('office_erp_commercial_operations', ['idempotency_key' => $key]);
    }

    public function test_explicit_permissions_reject_empty_sets_and_defaults_reject_contradictory_grants(): void
    {
        $members = app(OfficeErpCompanyMembers::class);
        foreach ([
            array_replace($this->body('empty@example.test'), ['permissions_mode' => 'explicit', 'permissions' => []]),
            array_replace($this->body('contradiction@example.test'), ['permissions_mode' => 'defaults', 'permissions' => ['erp.read']]),
        ] as $body) {
            try {
                $members->validateInvitation($body);
                self::fail('Contradictory invitation permission mode must be rejected.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    /** @return array{OfficeErpExecutionContext, ErpCompany, OfficeErpCompanyRegistration} */
    private function managedCompany(): array
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'office_erp_contract_id' => $contract->id]);
        $registration = OfficeErpCompanyRegistration::factory()->create([
            'account_id' => $ctx->accountId, 'client_id' => $contract->client_id, 'erp_company_id' => $company->id,
        ]);

        return [$ctx, $company, $registration];
    }

    /** @return array<string, mixed> */
    private function body(string $email): array
    {
        return [
            'name' => 'Invitee Name', 'email' => $email, 'role' => 'viewer', 'permissions_mode' => 'defaults',
            'permissions' => [], 'establishment_scope' => 'all', 'establishment_ids' => [],
        ];
    }
}
