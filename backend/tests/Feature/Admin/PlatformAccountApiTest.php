<?php

namespace Tests\Feature\Admin;

use App\Actions\AccessAccounts;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\OwnerActivation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BusinessTestCase;

class PlatformAccountApiTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_detail_and_list_share_safe_combined_projection_without_membership(): void
    {
        $this->admin();
        [$account] = app(AccessAccounts::class)->issue(['name' => 'Office', 'owner_name' => 'Pending', 'login_email' => 'pending@example.test']);
        $account->update(['settings' => ['private' => 'secret-marker', 'platform' => ['billing_contact' => ['phone' => '11999999999', 'phone_whatsapp' => true, 'state' => 'SP', 'city' => 'Sao Paulo'], 'owner_invite' => ['token_hash' => 'private-marker']]]]);
        $detail = $this->getJson('/api/v1/admin/accounts/'.$account->id)->assertOk()
            ->assertJsonPath('data.members_count', 0)->assertJsonPath('data.phone', '11999999999')
            ->assertJsonPath('data.phone_whatsapp', true)->assertJsonPath('data.state', 'SP')
            ->assertJsonPath('data.city', 'Sao Paulo')->assertJsonPath('data.pending_owner.email', 'pending@example.test')
            ->assertJsonPath('data.subscription.status', 'active')->assertJsonPath('data.subscription.plan.slug', 'basico')
            ->assertJsonMissingPath('data.settings')->assertJsonMissingPath('data.first_access');
        $this->assertSame(['id', 'name', 'status', 'members_count', 'pending_owner', 'phone', 'phone_whatsapp', 'state', 'city', 'subscription'], array_keys($detail->json('data')));
        $this->assertSame(['id', 'slug', 'name'], array_keys($detail->json('data.subscription.plan')));
        $this->assertStringNotContainsString('secret-marker', $detail->getContent());
        $this->assertStringNotContainsString('token_hash', $detail->getContent());
        $this->assertStringNotContainsString('primeiro-acesso', $detail->getContent());
        $this->getJson('/api/v1/admin/accounts')->assertOk()->assertJsonPath('data.0', $detail->json('data'))->assertJsonPath('meta.last_page', 1);
        $this->getJson('/api/v1/admin/accounts/999999')->assertNotFound();
    }

    public function test_detail_requires_super_admin_and_valid_bounded_id(): void
    {
        $account = Account::factory()->create();
        $this->getJson('/api/v1/admin/accounts/'.$account->id)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/accounts/'.$account->id)->assertForbidden();
        $this->admin();
        $this->getJson('/api/v1/admin/accounts/9007199254740992')->assertUnprocessable()->assertJsonValidationErrors('id');
    }

    public function test_create_accepts_contacts_preserving_foundational_envelopes(): void
    {
        $this->admin();
        $response = $this->postJson('/api/v1/admin/accounts', ['name' => 'New Office', 'owner_name' => 'Owner', 'login_email' => 'OWNER@example.test', 'phone' => '1133334444', 'phone_whatsapp' => true, 'state' => 'sp', 'city' => 'Campinas'])
            ->assertCreated()->assertJsonPath('data.phone', '1133334444')->assertJsonPath('data.state', 'SP')
            ->assertJsonPath('data.subscription.plan.slug', 'basico')->assertJsonStructure(['data', 'first_access' => ['url', 'expires_at']]);
        $this->assertSame(['data', 'first_access'], array_keys($response->json()));
        $id = $response->json('data.id');
        $this->postJson('/api/v1/admin/accounts/'.$id.'/first-access', ['owner_name' => 'New Owner', 'login_email' => 'other@example.test'])
            ->assertOk()->assertJsonCount(1)->assertJsonStructure(['first_access' => ['url', 'expires_at']]);
        $this->assertDatabaseCount('users', 1);
    }

    public static function contactEndpoints(): array
    {
        return ['root' => [''], 'commercial' => ['/commercial']];
    }

    #[DataProvider('contactEndpoints')]
    public function test_contact_merge_clearing_and_identity_preservation(string $suffix): void
    {
        $this->admin();
        $owner = User::factory()->create();
        [$account] = app(AccessAccounts::class)->issue(['name' => 'Office', 'owner_name' => 'Pending', 'login_email' => 'pending@example.test']);
        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $owner->id]);
        $account->update(['settings' => ['integration' => ['enabled' => true], 'platform' => ['owner_invite' => ['token_hash' => 'preserved'], 'billing_contact' => ['phone' => '11999999999', 'phone_whatsapp' => true, 'state' => 'SP', 'city' => 'Old City']]]]);
        $identity = $owner->fresh()->getAttributes();
        $activation = OwnerActivation::where('account_id', $account->id)->first()->getAttributes();
        $url = '/api/v1/admin/accounts/'.$account->id.$suffix;
        $this->patchJson($url, ['city' => 'New City'])->assertOk();
        $this->assertSame('11999999999', $account->fresh()->platformBillingContact()['phone']);
        $this->patchJson($url, ['phone' => null, 'phone_whatsapp' => true, 'state' => 'rj'])->assertOk();
        $this->assertSame(['state' => 'RJ', 'city' => 'New City'], $account->fresh()->platformBillingContact());
        $this->getJson('/api/v1/admin/accounts/'.$account->id)->assertOk()->assertJsonPath('data.phone', null)->assertJsonPath('data.phone_whatsapp', false);
        $this->patchJson($url, ['city' => null, 'state' => null])->assertOk();
        $this->assertNull($account->fresh()->platformBillingContact());
        $this->assertSame(['integration' => ['enabled' => true], 'platform' => ['owner_invite' => ['token_hash' => 'preserved']]], $account->fresh()->settings);
        $this->assertSame($identity, $owner->fresh()->getAttributes());
        $this->assertSame($activation, OwnerActivation::where('account_id', $account->id)->first()->getAttributes());
        $this->patchJson($url, ['password' => 'new-password', 'owner_name' => 'Changed'])->assertUnprocessable();
    }

    #[DataProvider('contactEndpoints')]
    public function test_sparse_patch_preserves_omitted_contact_bytes(string $suffix): void
    {
        $this->admin();
        $account = Account::factory()->create();
        $url = '/api/v1/admin/accounts/'.$account->id.$suffix;
        foreach ([
            ['phone' => ' 1133334444 ', 'phone_whatsapp' => true, 'state' => 'sp', 'city' => ' São Paulo '],
            ['phone' => null, 'phone_whatsapp' => false, 'state' => '', 'city' => '  '],
        ] as $contact) {
            $account->update(['settings' => ['integration' => true, 'platform' => ['billing_contact' => $contact]]]);
            $this->patchJson($url, ['phone_whatsapp' => false])->assertOk();
            $this->assertSame([...$contact, 'phone_whatsapp' => false], $account->fresh()->platformBillingContact());
            $this->assertTrue($account->fresh()->settings['integration']);
        }
    }

    #[DataProvider('contactEndpoints')]
    public function test_zero_string_phone_has_consistent_whatsapp_projection(string $suffix): void
    {
        $this->admin();
        $account = Account::factory()->create();
        $this->patchJson('/api/v1/admin/accounts/'.$account->id.$suffix, ['phone' => '0', 'phone_whatsapp' => true])->assertOk();
        $this->getJson('/api/v1/admin/accounts/'.$account->id)->assertOk()
            ->assertJsonPath('data.phone', '0')->assertJsonPath('data.phone_whatsapp', true);
        $this->getJson('/api/v1/admin/accounts/'.$account->id.'/commercial')->assertOk()
            ->assertJsonPath('data.platform_billing_contact.phone', '0')->assertJsonPath('data.platform_billing_contact.phone_whatsapp', true);
    }

    #[DataProvider('contactEndpoints')]
    public function test_invalid_contacts_reject_the_entire_update(string $suffix): void
    {
        $this->admin();
        $account = Account::factory()->create(['settings' => ['integration' => true]]);
        $before = $account->fresh()->getAttributes();
        $url = '/api/v1/admin/accounts/'.$account->id.$suffix;
        foreach ([['state' => 'XX'], ['phone_whatsapp' => 'yes'], ['phone_whatsapp' => null], ['phone' => str_repeat('1', 21)], ['city' => str_repeat('a', 256)], ['phone_whatsapp' => true]] as $invalid) {
            $this->patchJson($url, ['name' => 'Changed', 'city' => 'Changed', ...$invalid])->assertUnprocessable();
            $this->assertSame($before, $account->fresh()->getAttributes());
        }
    }

    public function test_failed_contact_creation_does_not_persist_any_issuance_rows(): void
    {
        $this->admin();
        $this->postJson('/api/v1/admin/accounts', ['name' => 'New Office', 'owner_name' => 'Owner', 'login_email' => 'owner@example.test', 'phone_whatsapp' => true])->assertUnprocessable()->assertJsonValidationErrors('phone_whatsapp');
        foreach (['accounts', 'subscriptions', 'owner_activations', 'account_memberships'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    public function test_list_query_count_is_constant_as_page_grows_with_combined_metadata(): void
    {
        $this->admin();
        $first = Account::factory()->create();
        AccountMembership::factory()->create(['account_id' => $first->id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/admin/accounts')->assertOk()->assertJsonPath('data.0.members_count', 1);
        $one = count(DB::getQueryLog());
        Account::factory()->count(14)->create();
        DB::flushQueryLog();
        $this->getJson('/api/v1/admin/accounts')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('data.0.subscription.plan.slug', 'basico');
        $fifteen = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($one, $fifteen, 'Account list queries must not grow with row count.');
    }

    public function test_literal_search_and_pagination_bounds_are_preserved(): void
    {
        $this->admin();
        foreach (['%', '_', '\\'] as $character) {
            $account = Account::factory()->create(['name' => 'Literal'.$character.'Office']);
            $this->getJson('/api/v1/admin/accounts?'.http_build_query(['q' => $character]))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $account->id);
        }
        Account::factory()->count(13)->create();
        $this->getJson('/api/v1/admin/accounts?page=2')->assertOk()->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.total', 16)->assertJsonCount(1, 'data');
        foreach (['0', '-1', '1.5', '9007199254740992'] as $page) {
            $this->getJson('/api/v1/admin/accounts?page='.$page)->assertUnprocessable()->assertJsonValidationErrors('page');
        }
    }
}
