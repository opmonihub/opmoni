<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpMemberInvitations;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpAccessDelivery;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpMemberInvitation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDOException;
use Tests\OfficeErpCommercialTestCase;
use Throwable;

class CompanyMemberRoutesTest extends OfficeErpCommercialTestCase
{
    public function test_real_selected_office_routes_list_show_and_update_only_current_company_members(): void
    {
        [$ctx, $company, $member] = $this->managedCompanyWithMember();
        $this->actingAs($ctx->actor);

        $this->getJson('/api/v1/erp-commercial/companies/'.$company->id.'/members?search='.$member->user->name)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.kind', 'membership')
            ->assertJsonPath('data.0.id', $member->id)->assertJsonPath('data.0.access_auth_revision', 0);
        $this->getJson('/api/v1/erp-commercial/companies/'.$company->id.'/members?unknown=1')->assertUnprocessable();
        $this->getJson('/api/v1/erp-commercial/companies/'.$company->id.'/member-options')
            ->assertOk()->assertJsonPath('data.roles.0.role', 'manager')->assertJsonPath('data.permissions.0.permission', 'erp.read');
        $this->getJson('/api/v1/erp-commercial/companies/'.$company->id.'/members/'.$member->id)
            ->assertOk()->assertJsonPath('data.id', $member->id)->assertJsonPath('data.access_auth_revision', 0);

        $this->patchJson('/api/v1/erp-commercial/companies/'.$company->id.'/members/'.$member->id, [
            'role' => 'viewer', 'action_permissions' => ['erp.contacts.store'],
        ], ['Idempotency-Key' => 'member-update-'.Str::uuid()])->assertUnprocessable();
        $this->patchJson('/api/v1/erp-commercial/companies/'.$company->id.'/members/'.$member->id, [
            'role' => 'manager', 'permissions_mode' => 'defaults', 'action_permissions' => [],
            'establishment_scope' => 'all', 'establishment_ids' => [],
        ], ['Idempotency-Key' => 'member-update-'.Str::uuid()])->assertOk()
            ->assertJsonPath('data.role', 'manager')->assertJsonPath('data.action_permissions', null);
        $this->deleteJson('/api/v1/erp-commercial/companies/'.$company->id.'/members/'.$member->id, [
            'confirmed' => true,
        ], ['Idempotency-Key' => 'member-remove-'.Str::uuid()])->assertOk()->assertJsonPath('data.status', 'removed');
    }

    public function test_selected_establishment_scope_requires_a_nonempty_company_owned_list(): void
    {
        [$ctx, $company, $member] = $this->managedCompanyWithMember();
        $this->actingAs($ctx->actor);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);

        $this->patchJson('/api/v1/erp-commercial/companies/'.$company->id.'/members/'.$member->id, [
            'role' => 'operator', 'establishment_scope' => 'selected', 'establishment_ids' => [],
        ], ['Idempotency-Key' => 'member-update-empty-scope-'.Str::uuid()])->assertUnprocessable();
        self::assertTrue($member->fresh()->hasAllEstablishmentAccess());
        self::assertSame(0, DB::table('office_erp_commercial_operations')->where('account_id', $ctx->accountId)->where('action', 'member.update')->count());

        $this->postJson('/api/v1/erp-commercial/companies/'.$company->id.'/member-invitations', [
            'name' => 'Empty Scope Invitee', 'email' => 'empty-scope-invitee@example.test', 'role' => 'viewer',
            'permissions_mode' => 'defaults', 'permissions' => [], 'establishment_scope' => 'selected', 'establishment_ids' => [],
        ], ['Idempotency-Key' => 'member-invite-empty-scope-'.Str::uuid()])->assertUnprocessable();
        self::assertSame(0, OfficeErpMemberInvitation::query()->where('account_id', $ctx->accountId)->where('email', 'empty-scope-invitee@example.test')->count());
        self::assertSame(0, OfficeErpAccessDelivery::query()->where('account_id', $ctx->accountId)->count());
        self::assertSame(0, DB::table('office_erp_commercial_operations')->where('account_id', $ctx->accountId)->where('action', 'member.invitation.issue')->count());

        $this->patchJson('/api/v1/erp-commercial/companies/'.$company->id.'/members/'.$member->id, [
            'role' => 'viewer', 'establishment_scope' => 'selected', 'establishment_ids' => [$establishment->id],
        ], ['Idempotency-Key' => 'member-update-valid-scope-'.Str::uuid()])->assertOk()
            ->assertJsonPath('data.establishment_scope', 'selected')->assertJsonPath('data.establishment_ids.0', $establishment->id);
        $this->postJson('/api/v1/erp-commercial/companies/'.$company->id.'/member-invitations', [
            'name' => 'Selected Scope Invitee', 'email' => 'selected-scope-invitee@example.test', 'role' => 'viewer',
            'permissions_mode' => 'defaults', 'permissions' => [], 'establishment_scope' => 'selected', 'establishment_ids' => [$establishment->id],
        ], ['Idempotency-Key' => 'member-invite-valid-scope-'.Str::uuid()])->assertCreated()
            ->assertJsonPath('data.invitation.establishment_scope', 'selected')
            ->assertJsonPath('data.invitation.establishment_ids.0', $establishment->id);
    }

    public function test_real_private_invitation_routes_issue_reissue_revoke_and_keep_token_out_of_responses(): void
    {
        [$ctx, $company] = $this->managedCompanyWithMember();
        $this->actingAs($ctx->actor);
        $operationKey = 'member-invitation-'.Str::uuid();
        $issued = $this->postJson('/api/v1/erp-commercial/companies/'.$company->id.'/member-invitations', [
            'name' => 'Invitee Name', 'email' => 'route-invitee@example.test', 'role' => 'viewer',
            'permissions_mode' => 'defaults', 'permissions' => [], 'establishment_scope' => 'all', 'establishment_ids' => [],
        ], ['Idempotency-Key' => $operationKey])->assertCreated()->assertJsonPath('data.invitation.status', 'pending')
            ->assertJsonPath('data.delivery.state', 'prepared')->assertJsonMissingPath('data.url')->assertJsonMissingPath('data.token');
        $invitationId = (int) $issued->json('data.invitation.id');
        $firstInvitation = OfficeErpMemberInvitation::query()->findOrFail($invitationId);
        $firstDelivery = OfficeErpAccessDelivery::query()->where('member_invitation_id', $invitationId)->firstOrFail();

        $reissued = $this->postJson('/api/v1/erp-commercial/companies/'.$company->id.'/member-invitations/'.$invitationId.'/reissue', [], [
            'Idempotency-Key' => 'member-reissue-'.Str::uuid(),
        ])->assertOk()->assertJsonPath('data.invitation.status', 'pending')->assertJsonMissingPath('data.token');
        self::assertNotSame($invitationId, (int) $reissued->json('data.invitation.id'));
        self::assertSame('revoked', $firstInvitation->fresh()->status);
        self::assertNotNull($firstDelivery->fresh()->authority_invalidated_at);
        self::assertNull($firstDelivery->fresh()->payload);
        $this->postJson('/api/v1/erp-commercial/companies/'.$company->id.'/member-invitations/'.$reissued->json('data.invitation.id').'/revoke', [], [
            'Idempotency-Key' => 'member-revoke-'.Str::uuid(),
        ])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->postJson('/api/v1/erp-commercial/companies/'.$company->id.'/member-invitations/'.$reissued->json('data.invitation.id').'/revoke?foreign=1', [], [
            'Idempotency-Key' => 'member-revoke-unknown-'.Str::uuid(),
        ])->assertUnprocessable();
    }

    public function test_public_invitation_receiver_works_without_workspace_and_preserves_accepted_receipt(): void
    {
        [$ctx, $company] = $this->managedCompanyWithMember();
        $this->withSelectedOffice(
            $ctx->account,
            AccountMembership::query()->where('account_id', $ctx->accountId)->where('user_id', $ctx->actor->id)->firstOrFail(),
            function () use ($ctx, $company): void {
                $result = app(OfficeErpMemberInvitations::class)->issue(
                    $ctx, (int) $company->id,
                    ['name' => 'Public Invitee', 'email' => 'public-invitee@example.test', 'role' => 'viewer', 'permissions_mode' => 'defaults', 'permissions' => [], 'establishment_scope' => 'all', 'establishment_ids' => []],
                    'public-member-invite-'.Str::uuid(),
                );
                $delivery = OfficeErpAccessDelivery::query()->findOrFail($result['delivery']['id']);
                $token = $delivery->payload['token'];

                $csrf = bin2hex(random_bytes(20));
                $this->withSession(['_token' => $csrf, 'access_authenticated' => false, 'access_deadline' => time() + 1800])
                    ->withHeader('X-CSRF-Token', $csrf)
                    ->postJson('/api/v1/erp-member-invitations/resolve', ['token' => $token])
                    ->assertOk()->assertJsonPath('state', 'valid')->assertJsonPath('purpose', 'member_access');
                self::assertStringNotContainsString($token, serialize(session()->all()));
                $this->withHeader('X-CSRF-Token', session()->token())
                    ->postJson('/api/v1/erp-member-invitations/resolve', ['token' => 'invalid-token-marker'])
                    ->assertUnprocessable();
                self::assertSame([], request()->request->all());
                self::assertSame([], request()->json()->all());
                self::assertSame([], request()->query->all());
                $current = $this->getJson('/api/v1/erp-member-invitations/current')->assertOk()->assertJsonPath('state', 'valid');
                $key = $current->json('operation_key');
                $accepted = $this->postJson('/api/v1/erp-member-invitations/accept', [
                    'name' => 'Public Invitee', 'password' => 'public-invitee-password', 'password_confirmation' => 'public-invitee-password',
                ], ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.status', 'accepted');
                $this->getJson('/api/v1/erp-member-invitations/current')->assertOk()->assertJsonPath('state', 'accepted');
                $this->withHeader('X-CSRF-Token', session()->token())
                    ->postJson('/api/v1/erp-member-invitations/accept', [], ['Idempotency-Key' => $key])
                    ->assertOk()->assertJsonPath('data.membership_id', $accepted->json('data.membership_id'));
                self::assertNull(session()->get('current_account_id'));
            },
        );
    }

    public function test_existing_invitee_login_preserves_the_public_continuation_and_password(): void
    {
        [$ctx, $company] = $this->managedCompanyWithMember();
        $invitee = User::factory()->create(['email' => 'existing-route-invitee@example.test']);
        $passwordHash = $invitee->password;
        $this->withSelectedOffice(
            $ctx->account,
            AccountMembership::query()->where('account_id', $ctx->accountId)->where('user_id', $ctx->actor->id)->firstOrFail(),
            function () use ($ctx, $company, $invitee, $passwordHash): void {
                $result = app(OfficeErpMemberInvitations::class)->issue(
                    $ctx, (int) $company->id,
                    ['name' => 'Existing Route Invitee', 'email' => $invitee->email, 'role' => 'viewer', 'permissions_mode' => 'defaults', 'permissions' => [], 'establishment_scope' => 'all', 'establishment_ids' => []],
                    'existing-route-invite-'.Str::uuid(),
                );
                $token = OfficeErpAccessDelivery::query()->findOrFail($result['delivery']['id'])->payload['token'];
                $csrf = bin2hex(random_bytes(20));
                $this->withSession(['_token' => $csrf, 'access_authenticated' => false, 'access_deadline' => time() + 1800])
                    ->withHeader('X-CSRF-Token', $csrf)
                    ->postJson('/api/v1/erp-member-invitations/resolve', ['token' => $token])
                    ->assertOk()->assertJsonPath('state', 'login_required');
                $this->withHeader('X-CSRF-Token', session()->token())->postJson('/api/v1/login', [
                    'email' => $invitee->email, 'password' => 'password',
                ])->assertOk();
                $current = $this->getJson('/api/v1/erp-member-invitations/current')->assertOk()->assertJsonPath('state', 'ready');
                $key = $current->json('operation_key');
                $this->withHeader('X-CSRF-Token', session()->token());
                $acceptance = $this->postJson('/api/v1/erp-member-invitations/accept', [], [
                    'Idempotency-Key' => $key,
                ]);
                $acceptance->assertOk()->assertJsonPath('data.status', 'accepted');
                self::assertSame($passwordHash, $invitee->fresh()->password);
                self::assertSame($invitee->id, auth()->id());
                self::assertNull(session()->get('current_account_id'));
            },
        );
    }

    public function test_identity_mismatch_logout_and_login_resume_the_member_invitation(): void
    {
        [$ctx, $company] = $this->managedCompanyWithMember();
        $invitee = User::factory()->create(['email' => 'logout-resume-invitee@example.test']);
        $wrongUser = User::factory()->create(['email' => 'logout-resume-other@example.test']);
        $this->withSelectedOffice(
            $ctx->account,
            AccountMembership::query()->where('account_id', $ctx->accountId)->where('user_id', $ctx->actor->id)->firstOrFail(),
            function () use ($ctx, $company, $invitee, $wrongUser): void {
                $result = app(OfficeErpMemberInvitations::class)->issue(
                    $ctx, (int) $company->id,
                    ['name' => 'Logout Resume Invitee', 'email' => $invitee->email, 'role' => 'viewer', 'permissions_mode' => 'defaults', 'permissions' => [], 'establishment_scope' => 'all', 'establishment_ids' => []],
                    'logout-resume-invite-'.Str::uuid(),
                );
                $token = OfficeErpAccessDelivery::query()->findOrFail($result['delivery']['id'])->payload['token'];
                $csrf = bin2hex(random_bytes(20));
                $this->withSession(['_token' => $csrf, 'access_authenticated' => false, 'access_deadline' => time() + 1800])
                    ->withHeader('X-CSRF-Token', $csrf)
                    ->postJson('/api/v1/erp-member-invitations/resolve', ['token' => $token])
                    ->assertOk()->assertJsonPath('state', 'login_required');
                $this->withHeader('X-CSRF-Token', session()->token())->postJson('/api/v1/login', [
                    'email' => $wrongUser->email, 'password' => 'password',
                ])->assertOk();
                $this->getJson('/api/v1/erp-member-invitations/current')->assertOk()->assertJsonPath('state', 'identity_mismatch');
                $this->withHeader('X-CSRF-Token', session()->token())->postJson('/api/v1/logout')->assertNoContent();
                $csrfResponse = $this->getJson('/api/v1/csrf')->assertOk();
                $this->withHeader('X-CSRF-Token', $csrfResponse->json('csrf_token'))->postJson('/api/v1/login', [
                    'email' => $invitee->email, 'password' => 'password',
                ])->assertOk();
                $current = $this->getJson('/api/v1/erp-member-invitations/current')->assertOk()->assertJsonPath('state', 'ready');
                $this->withHeader('X-CSRF-Token', session()->token())->postJson('/api/v1/erp-member-invitations/accept', [], [
                    'Idempotency-Key' => $current->json('operation_key'),
                ])->assertOk()->assertJsonPath('data.status', 'accepted');
                self::assertNull(session()->get('current_account_id'));
            },
        );
    }

    public function test_public_accept_failure_does_not_report_password_token_or_password_hash(): void
    {
        [$ctx, $company] = $this->managedCompanyWithMember();
        $email = 'route-failing-new-user@example.test';
        $password = 'secret-member-password';
        $this->withSelectedOffice(
            $ctx->account,
            AccountMembership::query()->where('account_id', $ctx->accountId)->where('user_id', $ctx->actor->id)->firstOrFail(),
            function () use ($ctx, $company, $email): void {
                $result = app(OfficeErpMemberInvitations::class)->issue(
                    $ctx, (int) $company->id,
                    ['name' => 'Failing New User', 'email' => $email, 'role' => 'viewer', 'permissions_mode' => 'defaults', 'permissions' => [], 'establishment_scope' => 'all', 'establishment_ids' => []],
                    'failing-new-user-'.Str::uuid(),
                );
                $this->assertNotEmpty($result['delivery']['id']);
            },
        );
        $delivery = OfficeErpAccessDelivery::query()->where('recipient_email', $email)->firstOrFail();
        $token = $delivery->payload['token'];
        $csrf = bin2hex(random_bytes(20));
        $this->withSession(['_token' => $csrf, 'access_authenticated' => false, 'access_deadline' => time() + 1800])
            ->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp-member-invitations/resolve', ['token' => $token])
            ->assertOk()->assertJsonPath('state', 'valid');
        $key = $this->getJson('/api/v1/erp-member-invitations/current')->assertOk()->json('operation_key');
        $passwordHash = null;
        User::creating(function (User $user) use (&$passwordHash, $email): void {
            if ($user->email === $email) {
                $passwordHash = (string) $user->password;
                throw new QueryException('pgsql', 'insert into "users" ("password") values (?)', [$passwordHash], new PDOException('test database failure'));
            }
        });
        $reported = [];
        app(ExceptionHandler::class)->reportable(function (Throwable $exception) use (&$reported): void {
            $request = request();
            $reported[] = [
                'type' => $exception::class, 'message' => $exception->getMessage(),
                'body' => $request->request->all(), 'json' => $request->json()->all(), 'query' => $request->query->all(),
            ];
        });
        Log::spy();

        $response = $this->withHeader('X-CSRF-Token', session()->token())
            ->postJson('/api/v1/erp-member-invitations/accept', [
                'name' => 'Failing New User', 'password' => $password, 'password_confirmation' => $password,
            ], ['Idempotency-Key' => $key])->assertServiceUnavailable();

        $response->assertJsonMissing(['message' => $password])->assertJsonMissing(['message' => $token]);
        self::assertNotNull($passwordHash);
        self::assertStringNotContainsString($password, serialize($reported));
        self::assertStringNotContainsString($token, serialize($reported));
        self::assertStringNotContainsString($passwordHash, serialize($reported));
        self::assertStringNotContainsString('QueryException', serialize($reported));
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
        self::assertSame([], request()->request->all());
        self::assertSame([], request()->json()->all());
        self::assertSame([], request()->query->all());
        self::assertSame(0, User::query()->where('email', $email)->count());
        self::assertSame(0, ErpCompanyMembership::query()->where('erp_company_id', $company->id)->whereHas('user', fn ($query) => $query->where('email', $email))->count());
        self::assertSame('pending', OfficeErpMemberInvitation::query()->where('account_id', $ctx->accountId)->where('email', $email)->value('status'));
    }

    /** @return array{OfficeErpExecutionContext, ErpCompany, ErpCompanyMembership} */
    private function managedCompanyWithMember(): array
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'office_erp_contract_id' => $contract->id]);
        OfficeErpCompanyRegistration::factory()->create([
            'account_id' => $ctx->accountId, 'client_id' => $contract->client_id, 'erp_company_id' => $company->id,
        ]);
        $membership = ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id, 'role' => 'operator',
        ]);

        return [$ctx, $company, $membership];
    }
}
