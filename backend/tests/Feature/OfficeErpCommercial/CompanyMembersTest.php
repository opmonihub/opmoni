<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpCompanyMembers;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpMemberAuthority;
use App\Erp\Commercial\OfficeErpOperations;
use App\Models\Erp\Commercial\OfficeErpAccessDelivery;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpMemberInvitation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\OfficeErpCommercialTestCase;

class CompanyMembersTest extends OfficeErpCommercialTestCase
{
    public function test_member_authority_requires_the_current_accepted_company_relationship(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'office_erp_contract_id' => $contract->id]);
        OfficeErpCompanyRegistration::factory()->create([
            'account_id' => $ctx->accountId, 'client_id' => $contract->client_id, 'erp_company_id' => $company->id,
        ]);
        $member = ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'role' => 'viewer']);

        $resolved = $this->withOfficeAccount($ctx, fn (): array => app(OfficeErpMemberAuthority::class)
            ->resolve($ctx, (int) $company->id, (int) $member->id, true));

        self::assertSame($contract->id, $resolved['contract']->id);
        self::assertSame($member->id, $resolved['membership']->id);
        self::assertSame($member->user_id, $resolved['user']->id);
        try {
            $this->withOfficeAccount($ctx, fn () => app(OfficeErpMemberAuthority::class)->resolve($ctx, (int) ErpCompany::factory()->create()->id));
            self::fail('An unregistered Company must not resolve through this office.');
        } catch (ModelNotFoundException) {
            self::assertTrue(true);
        }
    }

    public function test_super_admin_with_a_real_selected_office_admin_membership_is_authorized_by_that_membership(): void
    {
        $ctx = $this->officeContext();
        $ctx->actor->forceFill(['is_super_admin' => true])->save();
        $contract = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'office_erp_contract_id' => $contract->id]);
        OfficeErpCompanyRegistration::factory()->create([
            'account_id' => $ctx->accountId, 'client_id' => $contract->client_id, 'erp_company_id' => $company->id,
        ]);

        $resolved = $this->withOfficeAccount($ctx, fn (): array => app(OfficeErpMemberAuthority::class)
            ->resolve($ctx, (int) $company->id, null, true));

        self::assertSame($company->id, $resolved['company']->id);
    }

    public function test_member_directory_discriminates_real_members_and_pending_invitations_with_pagination(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'office_erp_contract_id' => $contract->id]);
        $registration = OfficeErpCompanyRegistration::factory()->create([
            'account_id' => $ctx->accountId, 'client_id' => $contract->client_id, 'erp_company_id' => $company->id,
        ]);
        $owner = User::factory()->create(['name' => 'Owner User']);
        $member = User::factory()->create(['name' => 'Bruna Member']);
        $ownerMembership = ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $memberMembership = ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $member->id, 'role' => 'operator']);
        $invitation = OfficeErpMemberInvitation::factory()->create([
            'account_id' => $ctx->accountId, 'erp_company_id' => $company->id, 'registration_id' => $registration->id,
            'name' => 'Zed Pending', 'email' => 'zed.pending@example.test', 'role' => 'viewer',
            'status' => 'pending', 'permissions_mode' => 'defaults', 'permissions' => [],
        ]);

        $result = $this->withOfficeAccount($ctx, fn (): array => app(OfficeErpCompanyMembers::class)->index($ctx, (int) $company->id, ['per_page' => 2]));
        self::assertSame(3, $result['meta']['total']);
        self::assertCount(2, $result['data']);
        self::assertSame('membership', $result['data'][0]['kind']);
        self::assertSame($memberMembership->id, $result['data'][0]['id']);
        self::assertSame('membership', $result['data'][1]['kind']);
        self::assertSame($ownerMembership->id, $result['data'][1]['id']);
        $secondPage = $this->withOfficeAccount($ctx, fn (): array => app(OfficeErpCompanyMembers::class)->index($ctx, (int) $company->id, ['per_page' => 2, 'page' => 2]));
        self::assertSame('invitation', $secondPage['data'][0]['kind']);
        self::assertSame($invitation->id, $secondPage['data'][0]['id']);
        self::assertArrayNotHasKey('token_hash', $secondPage['data'][0]);
    }

    public function test_stale_historical_registration_cannot_read_the_other_offices_member_directory(): void
    {
        $ctx = $this->officeContext();
        $foreign = $this->officeContext();
        $contract = $this->acceptedContract($foreign);
        $company = ErpCompany::factory()->create();
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'office_erp_contract_id' => $contract->id]);
        OfficeErpCompanyRegistration::factory()->create([
            'account_id' => $ctx->accountId, 'client_id' => $this->clientFor($ctx)->id, 'erp_company_id' => $company->id,
        ]);

        $this->expectException(ModelNotFoundException::class);
        $this->withOfficeAccount($ctx, fn () => app(OfficeErpMemberAuthority::class)->resolve($ctx, (int) $company->id));
    }

    public function test_removal_keeps_global_and_other_company_memberships_and_recovers_the_removed_receipt(): void
    {
        [$ctx, $company, $member, $otherCompany] = $this->managedCompanyWithMember();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $otherCompany->id, 'user_id' => $member->user_id, 'role' => 'operator',
        ]);
        $recovery = OfficeErpAccessDelivery::query()->create([
            'account_id' => $ctx->accountId, 'erp_company_id' => $company->id,
            'registration_id' => OfficeErpCompanyRegistration::query()->where('erp_company_id', $company->id)->value('id'),
            'operation_id' => OfficeErpCommercialOperation::factory()->create(['account_id' => $ctx->accountId])->id,
            'purpose' => 'password_recovery', 'membership_id' => $member->id,
            'recipient_user_id' => $member->user_id, 'recipient_email' => $member->user->email,
            'access_auth_revision' => $member->user->access_auth_revision, 'state' => 'prepared',
            'payload' => ['token' => 'secret-test-token'], 'expires_at' => now()->addHour(),
        ]);
        $key = 'member-remove-'.bin2hex(random_bytes(8));

        $result = $this->withOfficeAccount($ctx, fn (): array => app(OfficeErpCompanyMembers::class)
            ->remove($ctx, (int) $company->id, (int) $member->id, ['confirmed' => true], $key));
        self::assertSame('removed', $result['status']);
        self::assertDatabaseMissing('erp_company_memberships', ['id' => $member->id]);
        self::assertDatabaseHas('users', ['id' => $member->user_id]);
        self::assertDatabaseHas('erp_company_memberships', ['erp_company_id' => $otherCompany->id, 'user_id' => $member->user_id]);
        self::assertNotNull($recovery->fresh()->authority_invalidated_at);
        self::assertNull($recovery->fresh()->payload);

        $receipt = $this->withOfficeAccount($ctx, fn (): array => app(OfficeErpOperations::class)
            ->lookup($ctx, $key, fn (string $action, array $payload) => app(OfficeErpCompanyMembers::class)->authorizeOperation($ctx, $action, $payload, $key)));
        self::assertSame('removed', $receipt['data']['status']);
        self::assertSame($member->id, $receipt['data']['membership_id']);
    }

    public function test_commercial_authority_cannot_remove_the_company_owner(): void
    {
        [$ctx, $company, $owner] = $this->managedCompanyWithMember('owner');
        $this->expectException(HttpException::class);
        $this->withOfficeAccount($ctx, fn () => app(OfficeErpCompanyMembers::class)
            ->remove($ctx, (int) $company->id, (int) $owner->id, ['confirmed' => true], 'owner-removal-key'));
    }

    public function test_member_update_rejects_viewer_writes_and_preserves_unrelated_grants(): void
    {
        [$ctx, $company, $member] = $this->managedCompanyWithMember('operator');
        $member->forceFill(['action_permissions' => ['erp.read'], 'access_all_establishments' => true])->save();
        $member->user()->update(['access_auth_revision' => 7]);
        $userBefore = $member->user->getAttributes();
        try {
            $this->withOfficeAccount($ctx, fn () => app(OfficeErpCompanyMembers::class)->update(
                $ctx, (int) $company->id, (int) $member->id,
                ['role' => 'viewer', 'action_permissions' => ['erp.contacts.store']],
                'member-update-denied-'.bin2hex(random_bytes(4)),
            ));
            self::fail('Viewer write access must be refused.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        self::assertSame('operator', $member->fresh()->role);
        self::assertSame(['erp.read'], $member->fresh()->action_permissions);

        $result = $this->withOfficeAccount($ctx, fn (): array => app(OfficeErpCompanyMembers::class)->update(
            $ctx, (int) $company->id, (int) $member->id,
            ['role' => 'manager', 'permissions_mode' => 'defaults', 'action_permissions' => [], 'establishment_scope' => 'all', 'establishment_ids' => []],
            'member-update-success-'.bin2hex(random_bytes(4)),
        ));
        self::assertSame('manager', $result['role']);
        self::assertNull($member->fresh()->action_permissions);
        self::assertTrue($member->fresh()->access_all_establishments);
        self::assertSame($userBefore['email'], $member->fresh()->user->email);
        self::assertSame(7, $result['access_auth_revision']);
    }

    public function test_member_directory_search_is_literal_and_rejects_unknown_filters(): void
    {
        [$ctx, $company, $membership] = $this->managedCompanyWithMember();
        $membership->user()->update(['name' => 'Percent % Operator']);
        $service = app(OfficeErpCompanyMembers::class);
        $literal = $this->withOfficeAccount($ctx, fn (): array => $service->index($ctx, (int) $company->id, ['search' => '%']));
        self::assertSame(1, $literal['meta']['total']);
        try {
            $this->withOfficeAccount($ctx, fn () => $service->index($ctx, (int) $company->id, ['unscoped' => true]));
            self::fail('Unknown directory filters must not be ignored.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        $pendingOnly = $this->withOfficeAccount($ctx, fn (): array => $service->index($ctx, (int) $company->id, ['status' => 'pending']));
        self::assertSame(0, $pendingOnly['meta']['total']);
    }

    /** @return array{OfficeErpExecutionContext, ErpCompany, ErpCompanyMembership, ?ErpCompany} */
    private function managedCompanyWithMember(string $role = 'operator'): array
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'office_erp_contract_id' => $contract->id]);
        OfficeErpCompanyRegistration::factory()->create([
            'account_id' => $ctx->accountId, 'client_id' => $contract->client_id, 'erp_company_id' => $company->id,
        ]);
        $member = ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'role' => $role]);
        $otherCompany = $role === 'owner' ? null : ErpCompany::factory()->create();

        return [$ctx, $company, $member, $otherCompany];
    }
}
