<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\SupportAccessLog;
use App\Models\User;
use App\Models\WhatsAppChannel;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suporte no Canal WhatsApp: um `is_super_admin` operando em conta onde não
 * é Membro pareia e desconecta como `admin`, e cada escrita deixa registro
 * em `support_access_logs`.
 */
class WhatsAppSupportAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Account $target;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        resolve(CurrentTenant::class)->accountId = null;

        config()->set('whatsapp.wzap.base_url', 'http://wzap.test');
        config()->set('whatsapp.wzap.api_key', 'chave-global');
        config()->set('whatsapp.wzap.webhook_url', 'https://opmoni.test/api/webhooks/wzap');

        $this->superAdmin = $this->superAdminWithOwnAccount();
        $this->target = Account::factory()->create();
    }

    protected function tearDown(): void
    {
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public function test_suporte_pareia_canal_em_conta_alheia_e_gera_auditoria(): void
    {
        Http::fake([
            'wzap.test/instances' => Http::response(['data' => [
                'id' => 'inst-alvo',
                'external_ref' => (string) $this->target->getKey(),
                'status' => 'disconnected',
                'instance_api_key' => 'chave-instancia',
            ]], 201),
            'wzap.test/instances/inst-alvo/connect' => Http::response(['data' => [
                'status' => 'pairing',
                'qr_code' => '2@qr-do-teste',
                'qr_expires_at' => '2026-10-04T12:00:00Z',
            ]]),
        ]);

        $this->entrarEmSuporte();

        $this->postJson('/api/whatsapp/channel/pairing')
            ->assertOk()
            ->assertJsonPath('data.status', 'pairing');

        // O canal nasceu na conta alvo — nunca na conta própria do suporte.
        $channel = WhatsAppChannel::forAccount($this->target->getKey());
        $this->assertNotNull($channel);
        $this->assertSame(WhatsAppChannel::STATUS_PAIRING, $channel->status);

        $this->assertLog('whatsapp_channels', 'pairing', $channel->getKey());
    }

    public function test_suporte_desconecta_canal_em_conta_alheia_e_gera_auditoria(): void
    {
        Http::fake([
            'wzap.test/instances/inst-alvo/disconnect' => Http::response(null, 204),
        ]);

        $channel = WhatsAppChannel::factory()->connected('chave-instancia')->create([
            'account_id' => $this->target->getKey(),
            'wzap_instance_id' => 'inst-alvo',
        ]);

        $this->entrarEmSuporte();

        $this->deleteJson('/api/whatsapp/channel')
            ->assertOk()
            ->assertJsonPath('data.status', 'disconnected');

        $this->assertSame(
            WhatsAppChannel::STATUS_DISCONNECTED,
            $channel->refresh()->status,
        );

        $this->assertLog('whatsapp_channels', 'disconnect', $channel->getKey());
    }

    public function test_membro_pareia_e_desconecta_sem_gerar_log_de_suporte(): void
    {
        Http::fake([
            'wzap.test/instances' => Http::response(['data' => [
                'id' => 'inst-alvo',
                'external_ref' => (string) $this->target->getKey(),
                'status' => 'disconnected',
                'instance_api_key' => 'chave-instancia',
            ]], 201),
            'wzap.test/instances/inst-alvo/connect' => Http::response(['data' => [
                'status' => 'pairing',
                'qr_code' => '2@qr-do-teste',
                'qr_expires_at' => '2026-10-04T12:00:00Z',
            ]]),
            'wzap.test/instances/inst-alvo/disconnect' => Http::response(null, 204),
        ]);

        $this->actingAs($this->memberOf($this->target, 'admin'), 'sanctum');

        $this->postJson('/api/whatsapp/channel/pairing')->assertOk();
        $this->deleteJson('/api/whatsapp/channel')->assertOk();

        $this->assertSame(0, SupportAccessLog::count());
    }

    private function entrarEmSuporte(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/support/accounts/{$this->target->getKey()}/enter")
            ->assertOk();
    }

    private function assertLog(string $resource, string $action, ?int $resourceId = null): void
    {
        $query = SupportAccessLog::query()
            ->where('super_admin_user_id', $this->superAdmin->getKey())
            ->where('account_id', $this->target->getKey())
            ->where('action', $action)
            ->where('metadata->resource', $resource);

        if ($resourceId !== null) {
            $query->where('metadata->resource_id', $resourceId);
        }

        $this->assertTrue($query->exists(), "Faltou log {$resource}.{$action} em modo suporte.");
    }

    private function superAdminWithOwnAccount(): User
    {
        $superAdmin = User::factory()->create();
        $superAdmin->forceFill(['is_super_admin' => true])->save();
        $home = Account::factory()->create();

        AccountUser::create([
            'account_id' => $home->getKey(),
            'user_id' => $superAdmin->getKey(),
            'role' => 'admin',
        ]);

        $superAdmin->forceFill(['current_account_id' => $home->getKey()])->save();

        return $superAdmin->refresh();
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
