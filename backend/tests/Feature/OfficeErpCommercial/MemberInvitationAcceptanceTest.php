<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpMemberInvitationContinuation;
use App\Erp\Commercial\OfficeErpMemberInvitations;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpMemberInvitation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\OfficeErpCommercialTestCase;

class MemberInvitationAcceptanceTest extends OfficeErpCommercialTestCase
{
    public function test_new_recipient_creates_identity_membership_and_consumption_together(): void
    {
        [$company, $invitation, $token] = $this->invitationFor('new.member@example.test');
        $request = $this->requestWithSession();
        $continuations = app(OfficeErpMemberInvitationContinuation::class);
        $resolved = $continuations->exchange($request, $token);

        self::assertSame('valid', $resolved['state']);
        $result = app(OfficeErpMemberInvitations::class)->accept($request, [
            'name' => 'New Member', 'password' => 'new-member-password', 'password_confirmation' => 'new-member-password',
        ], $resolved['operation_key']);

        self::assertSame('accepted', $result['status']);
        $user = User::query()->where('email', 'new.member@example.test')->firstOrFail();
        self::assertSame('New Member', $user->name);
        self::assertNull($user->email_verified_at);
        self::assertTrue(Hash::check('new-member-password', $user->password));
        self::assertDatabaseHas('erp_company_memberships', [
            'erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'viewer', 'access_all_establishments' => true,
        ]);
        self::assertSame('accepted', $invitation->fresh()->status);
        self::assertSame($user->id, $invitation->fresh()->accepted_by_user_id);
        self::assertSame(1, ErpCompanyMembership::query()->where('erp_company_id', $company->id)->where('user_id', $user->id)->count());
        self::assertSame('accepted', $continuations->current($request)['state']);
    }

    public function test_existing_recipient_authentication_preserves_global_identity_and_credentials(): void
    {
        [$company, $invitation, $token] = $this->invitationFor('existing.member@example.test');
        $user = User::factory()->create(['name' => 'Existing Name', 'email' => 'existing.member@example.test', 'email_verified_at' => now()]);
        $password = $user->password;
        $verifiedAt = $user->email_verified_at;
        $this->actingAs($user);
        $request = $this->requestWithSession();
        $resolved = app(OfficeErpMemberInvitationContinuation::class)->exchange($request, $token);

        self::assertSame('ready', $resolved['state']);
        $result = app(OfficeErpMemberInvitations::class)->accept($request, [], $resolved['operation_key']);

        self::assertSame('accepted', $result['status']);
        self::assertSame('Existing Name', $user->fresh()->name);
        self::assertSame($password, $user->fresh()->password);
        self::assertSame($verifiedAt->toDateTimeString(), $user->fresh()->email_verified_at->toDateTimeString());
        self::assertDatabaseHas('erp_company_memberships', ['erp_company_id' => $company->id, 'user_id' => $user->id]);
        self::assertSame($user->id, $invitation->fresh()->accepted_by_user_id);
    }

    public function test_unauthenticated_existing_recipient_is_sent_to_login_instead_of_password_setup(): void
    {
        [, , $token] = $this->invitationFor('known.member@example.test');
        User::factory()->create(['email' => 'known.member@example.test']);
        $request = $this->requestWithSession();

        $resolved = app(OfficeErpMemberInvitationContinuation::class)->exchange($request, $token);

        self::assertSame('login_required', $resolved['state']);
        self::assertArrayHasKey('data', $resolved);
        self::assertArrayNotHasKey('token', $resolved);
    }

    public function test_logged_in_mismatched_identity_receives_no_invitation_data(): void
    {
        [, , $token] = $this->invitationFor('intended.member@example.test');
        $other = User::factory()->create(['email' => 'other.member@example.test']);
        $this->actingAs($other);
        $request = $this->requestWithSession();

        $resolved = app(OfficeErpMemberInvitationContinuation::class)->exchange($request, $token);

        self::assertSame('identity_mismatch', $resolved['state']);
        self::assertArrayNotHasKey('data', $resolved);
        self::assertArrayHasKey('operation_key', $resolved);
    }

    public function test_invitation_acceptance_fails_after_the_company_management_relationship_is_lost(): void
    {
        [$company, $invitation, $token] = $this->invitationFor('lost.authority@example.test');
        $request = $this->requestWithSession();
        $resolved = app(OfficeErpMemberInvitationContinuation::class)->exchange($request, $token);
        ErpSubscription::query()->where('erp_company_id', $company->id)->update(['office_erp_contract_id' => null]);

        try {
            app(OfficeErpMemberInvitations::class)->accept($request, [
                'name' => 'Lost Authority', 'password' => 'new-member-password', 'password_confirmation' => 'new-member-password',
            ], $resolved['operation_key']);
            self::fail('Lost current authority must prevent recipient acceptance.');
        } catch (HttpException) {
            self::assertTrue(true);
        }

        self::assertSame('pending', $invitation->fresh()->status);
        self::assertDatabaseCount('erp_company_memberships', 0);
    }

    public function test_issuer_losing_office_admin_role_invalidates_invitation_acceptance(): void
    {
        [$company, $invitation, $token] = $this->invitationFor('issuer-lost@example.test');
        $this->withSession([]);
        $request = $this->requestWithSession();
        $resolved = app(OfficeErpMemberInvitationContinuation::class)->exchange($request, $token);
        AccountMembership::query()->where('user_id', $invitation->created_by_user_id)->update(['role' => 'user']);

        self::assertSame('invalid', app(OfficeErpMemberInvitationContinuation::class)->current($request)['state']);

        try {
            app(OfficeErpMemberInvitations::class)->accept($request, [
                'name' => 'Issuer Lost', 'password' => 'new-member-password', 'password_confirmation' => 'new-member-password',
            ], $resolved['operation_key']);
            self::fail('Invitation issuer authority must be current at acceptance.');
        } catch (HttpException) {
            self::assertTrue(true);
        }

        self::assertSame('pending', $invitation->fresh()->status);
        self::assertDatabaseMissing('erp_company_memberships', ['erp_company_id' => $company->id, 'user_id' => User::query()->where('email', 'issuer-lost@example.test')->value('id')]);
    }

    public function test_recipient_promoted_to_office_admin_before_acceptance_is_not_implicitly_enrolled(): void
    {
        [$company, $invitation, $token] = $this->invitationFor('recipient-admin@example.test');
        $recipient = User::factory()->create(['email' => 'recipient-admin@example.test']);
        AccountMembership::factory()->create([
            'account_id' => $invitation->account_id, 'user_id' => $recipient->id, 'role' => 'admin',
        ]);
        $this->actingAs($recipient);
        $request = $this->requestWithSession();
        $resolved = app(OfficeErpMemberInvitationContinuation::class)->exchange($request, str_repeat('a', 64));
        self::assertSame('ready', $resolved['state']);

        try {
            app(OfficeErpMemberInvitations::class)->accept($request, [], $resolved['operation_key']);
            self::fail('Office administrators cannot be implicitly enrolled as Company members.');
        } catch (HttpException) {
            self::assertTrue(true);
        }

        self::assertSame('pending', $invitation->fresh()->status);
        self::assertDatabaseMissing('erp_company_memberships', ['erp_company_id' => $company->id, 'user_id' => $recipient->id]);
    }

    /** @return array{ErpCompany, OfficeErpMemberInvitation, string} */
    private function invitationFor(string $email): array
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'office_erp_contract_id' => $contract->id]);
        $registration = OfficeErpCompanyRegistration::factory()->create([
            'account_id' => $ctx->accountId, 'client_id' => $contract->client_id, 'erp_company_id' => $company->id,
        ]);
        $token = str_repeat('a', 64);
        $invitation = OfficeErpMemberInvitation::factory()->create([
            'account_id' => $ctx->accountId, 'erp_company_id' => $company->id, 'registration_id' => $registration->id,
            'name' => 'Invited Member', 'email' => $email, 'role' => 'viewer', 'permissions_mode' => 'defaults',
            'permissions' => [], 'establishment_scope' => 'all', 'establishment_ids' => [],
            'token_hash' => hash('sha256', $token), 'status' => 'pending', 'expires_at' => now()->addDays(7),
            'created_by_user_id' => $ctx->actor->id,
        ]);

        return [$company, $invitation, $token];
    }

    private function requestWithSession(): Request
    {
        $request = Request::create('/');
        $session = app('session')->driver();
        $session->start();
        $request->setLaravelSession($session);
        $request->setUserResolver(static fn (): ?User => auth()->user());

        return $request;
    }
}
