<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientDispatchContact;
use App\Models\SupportAccessLog;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BusinessTestCase;

/**
 * A API dos Contatos de envio do cliente: qualquer Membro lê a lista,
 * `admin`/`operador` cadastram e removem, `user` recebe 403 nas escritas.
 * A validação do canal é a porta do Disparo — `whatsapp` exige telefone
 * plausível (10–13 dígitos, a mesma régua do serviço) e `email` exige um
 * endereço válido; outro canal é 422. O isolamento é por conta: id de
 * cliente ou de contato alheio é 404, nunca 403.
 */
class ClientDispatchContactApiTest extends BusinessTestCase
{
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        resolve(CurrentTenant::class)->accountId = null;
        config()->set('whatsapp.enabled', true);

        $this->account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $this->account->getKey();
        $this->client = Client::factory()->company()->create([
            'account_id' => $this->account->getKey(),
        ]);
    }

    protected function tearDown(): void
    {
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public function test_qualquer_membro_lista_os_contatos_do_cliente(): void
    {
        ClientDispatchContact::factory()->whatsapp()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'label' => 'Sócio 1',
            'value' => '5511987654321',
        ]);
        ClientDispatchContact::factory()->email()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
        ]);
        // O contato de outra conta não pode vazar na listagem.
        $outraConta = Account::factory()->create();
        $clienteAlheio = Client::factory()->company()->create(['account_id' => $outraConta->getKey()]);
        ClientDispatchContact::factory()->whatsapp()->create([
            'account_id' => $outraConta->getKey(),
            'client_id' => $clienteAlheio->getKey(),
        ]);

        $response = $this->actingAs($this->memberOf($this->account, 'user'), 'web')
            ->getJson($this->contactsUrl())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.channel', 'whatsapp')
            ->assertJsonPath('data.0.label', 'Sócio 1')
            ->assertJsonPath('data.0.value', '5511987654321')
            ->assertJsonPath('data.1.channel', 'email');
    }

    public function test_admin_cadastra_contato_whatsapp_e_recebe_201(): void
    {
        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->contactsUrl(), [
                'label' => 'Sócio 1',
                'channel' => 'whatsapp',
                'value' => '(11) 98765-4321',
            ])
            ->assertCreated()
            ->assertJsonPath('data.channel', 'whatsapp')
            ->assertJsonPath('data.value', '11987654321')
            ->assertJsonPath('data.label', 'Sócio 1')
            ->assertJsonPath('data.client_id', $this->client->getKey());

        $contato = ClientDispatchContact::query()->sole();
        $this->assertSame($this->account->getKey(), $contato->account_id);
        $this->assertSame('11987654321', $contato->value);
    }

    public function test_operador_cadastra_contato_de_email(): void
    {
        $this->actingAs($this->memberOf($this->account, 'operador'), 'web')
            ->postJson($this->contactsUrl(), [
                'channel' => 'email',
                'value' => 'Socio@Empresa.COM.br',
            ])
            ->assertCreated()
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.value', 'socio@empresa.com.br')
            ->assertJsonPath('data.label', null);
    }

    public function test_user_recebe_403_nas_escritas_e_nada_muda(): void
    {
        $contato = ClientDispatchContact::factory()->whatsapp()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
        ]);

        $user = $this->memberOf($this->account, 'user');

        $this->actingAs($user, 'web')
            ->postJson($this->contactsUrl(), [
                'channel' => 'whatsapp',
                'value' => '11987654321',
            ])
            ->assertForbidden();

        $this->actingAs($user, 'web')
            ->putJson("{$this->contactsUrl()}/{$contato->getKey()}", [
                'label' => 'Tentativa',
            ])
            ->assertForbidden();

        $this->actingAs($user, 'web')
            ->deleteJson("{$this->contactsUrl()}/{$contato->getKey()}")
            ->assertForbidden();

        $this->assertSame(1, ClientDispatchContact::count());
        $this->assertNull($contato->refresh()->label);
    }

    public function test_atualizar_muda_rotulo_e_valor_normalizado(): void
    {
        $contato = ClientDispatchContact::factory()->whatsapp()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'label' => 'Sócio 1',
        ]);

        $this->actingAs($this->memberOf($this->account, 'operador'), 'web')
            ->putJson("{$this->contactsUrl()}/{$contato->getKey()}", [
                'label' => 'Sócio 2',
                'value' => '+55 (21) 3333-4444',
            ])
            ->assertOk()
            ->assertJsonPath('data.label', 'Sócio 2')
            ->assertJsonPath('data.value', '552133334444')
            // O canal não troca na atualização — trocar de canal é um
            // contato novo, não uma edição.
            ->assertJsonPath('data.channel', 'whatsapp');
    }

    public function test_atualizar_nao_troca_o_canal(): void
    {
        $contato = ClientDispatchContact::factory()->whatsapp()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
        ]);

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->putJson("{$this->contactsUrl()}/{$contato->getKey()}", [
                'channel' => 'email',
                'value' => 'socio@empresa.com.br',
            ])
            ->assertStatus(422);

        $this->assertSame('whatsapp', $contato->refresh()->channel);
    }

    public function test_telefone_legado_fora_da_regua_aceita_patch_so_de_rotulo(): void
    {
        // O backfill semeia qualquer `clients.phone` não vazio — um
        // telefone legado fora dos 10–13 dígitos não pode tornar o
        // contato ineditável: editar o rótulo não revalida o valor
        // que já estava gravado.
        $contato = ClientDispatchContact::factory()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'channel' => 'whatsapp',
            'value' => '33334444',
            'label' => 'Fixo antigo',
        ]);

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->patchJson("{$this->contactsUrl()}/{$contato->getKey()}", [
                'label' => 'Fixo da recepção',
            ])
            ->assertOk()
            ->assertJsonPath('data.label', 'Fixo da recepção')
            ->assertJsonPath('data.value', '33334444');
    }

    public function test_patch_com_label_nulo_limpa_o_rotulo(): void
    {
        $contato = ClientDispatchContact::factory()->whatsapp()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'label' => 'Sócio 1',
        ]);

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->putJson("{$this->contactsUrl()}/{$contato->getKey()}", [
                'label' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.label', null);

        $this->assertNull($contato->refresh()->label);
    }

    public function test_remover_apaga_o_contato(): void
    {
        $contato = ClientDispatchContact::factory()->whatsapp()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
        ]);

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->deleteJson("{$this->contactsUrl()}/{$contato->getKey()}")
            ->assertNoContent();

        $this->assertSame(0, ClientDispatchContact::count());
    }

    public function test_whatsapp_exige_telefone_plausivel(): void
    {
        $admin = $this->memberOf($this->account, 'admin');

        $this->actingAs($admin, 'web')
            ->postJson($this->contactsUrl(), [
                'channel' => 'whatsapp',
                'value' => '12345',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('value');

        $this->actingAs($admin, 'web')
            ->postJson($this->contactsUrl(), [
                'channel' => 'whatsapp',
                'value' => '1234567890123456',
            ])
            ->assertStatus(422);

        $this->assertSame(0, ClientDispatchContact::count());
    }

    public function test_email_exige_endereco_valido(): void
    {
        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->contactsUrl(), [
                'channel' => 'email',
                'value' => 'nao-e-email',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('value');
    }

    public function test_canal_desconhecido_responde_422(): void
    {
        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->contactsUrl(), [
                'channel' => 'telegram',
                'value' => '11987654321',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('channel');
    }

    public function test_contato_duplicado_mesmo_canal_e_valor_responde_422(): void
    {
        ClientDispatchContact::factory()->whatsapp()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'value' => '11987654321',
        ]);

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->contactsUrl(), [
                'channel' => 'whatsapp',
                'value' => '(11) 98765-4321',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('value');

        $this->assertSame(1, ClientDispatchContact::count());
    }

    public function test_mesmo_valor_em_canal_diferente_nao_conflita(): void
    {
        // Endereços distintos por natureza, mas a regra é a da tupla
        // (cliente, canal, valor): o mesmo número sob `whatsapp` e outro
        // canal são dois contatos — a unique não olha só o valor.
        ClientDispatchContact::factory()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $this->client->getKey(),
            'channel' => 'whatsapp',
            'value' => '11987654321',
        ]);

        $outroCliente = Client::factory()->company()->create([
            'account_id' => $this->account->getKey(),
        ]);

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson("/api/v1/clients/{$outroCliente->getKey()}/dispatch-contacts", [
                'channel' => 'whatsapp',
                'value' => '11987654321',
            ])
            ->assertCreated();
    }

    public function test_cliente_de_outra_conta_responde_404_em_todas_as_rotas(): void
    {
        $outraConta = Account::factory()->create();
        $clienteAlheio = Client::factory()->company()->create(['account_id' => $outraConta->getKey()]);
        $url = "/api/v1/clients/{$clienteAlheio->getKey()}/dispatch-contacts";

        $admin = $this->memberOf($this->account, 'admin');

        $this->actingAs($admin, 'web')->getJson($url)->assertNotFound();
        $this->actingAs($admin, 'web')
            ->postJson($url, ['channel' => 'whatsapp', 'value' => '11987654321'])
            ->assertNotFound();
    }

    public function test_contato_de_outro_cliente_responde_404(): void
    {
        $outroCliente = Client::factory()->company()->create([
            'account_id' => $this->account->getKey(),
        ]);
        $contatoAlheio = ClientDispatchContact::factory()->whatsapp()->create([
            'account_id' => $this->account->getKey(),
            'client_id' => $outroCliente->getKey(),
        ]);

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->putJson("{$this->contactsUrl()}/{$contatoAlheio->getKey()}", [
                'label' => 'Invasão',
            ])
            ->assertNotFound();

        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->deleteJson("{$this->contactsUrl()}/{$contatoAlheio->getKey()}")
            ->assertNotFound();
    }

    public function test_flag_desligada_responde_404(): void
    {
        config()->set('whatsapp.enabled', false);

        $admin = $this->memberOf($this->account, 'admin');

        $this->actingAs($admin, 'web')->getJson($this->contactsUrl())->assertNotFound();
        $this->actingAs($admin, 'web')
            ->postJson($this->contactsUrl(), ['channel' => 'whatsapp', 'value' => '11987654321'])
            ->assertNotFound();
    }

    public function test_suporte_escreve_em_conta_alheia_com_auditoria_requires_real_membership(): void
    {
        $targetAccount = Account::factory()->create();
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->selectBusinessAccount($superAdmin, $targetAccount->id);
        $this->actingAs($superAdmin)->postJson('/api/v1/clients/1/dispatch-contacts', [])
            ->assertForbidden()->assertJsonPath('code', 'membership_required');
    }

    public function test_membro_escreve_sem_gerar_log_de_suporte(): void
    {
        $this->actingAs($this->memberOf($this->account, 'admin'), 'web')
            ->postJson($this->contactsUrl(), [
                'channel' => 'whatsapp',
                'value' => '11987654321',
            ])
            ->assertCreated();

        $this->assertSame(0, SupportAccessLog::count());
    }

    private function contactsUrl(): string
    {
        return "/api/v1/clients/{$this->client->getKey()}/dispatch-contacts";
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
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

        $this->selectBusinessAccount($superAdmin, $home->getKey());

        return $superAdmin->refresh();
    }
}
