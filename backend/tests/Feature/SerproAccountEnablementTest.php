<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\SerproConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * A habilitação da integração por escritório: um flag em `Account.settings`
 * que só `admin` escreve, que exige a credencial de plataforma íntegra para
 * ligar, e que desligar não apaga nada do histórico.
 */
class SerproAccountEnablementTest extends TestCase
{
    use RefreshDatabase;

    public function test_leitura_responde_desabilitado_por_padrao(): void
    {
        $account = Account::factory()->create();

        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->getJson('/api/serpro/enablement')
            ->assertOk()
            ->assertJsonPath('data.enabled', false);
    }

    public function test_admin_habilita_com_a_conexao_inteira(): void
    {
        $account = Account::factory()->create();
        $this->conexao();

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->putJson('/api/serpro/enablement', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->assertTrue((bool) ($account->fresh()->settings['serpro_enabled'] ?? false));
    }

    public function test_habilitar_sem_conexao_recusa_e_nao_grava(): void
    {
        $account = Account::factory()->create();

        // Sem credencial de plataforma não há o que habilitar: a resposta é
        // de validação e o flag não nasce, para que um GET depois não
        // precise distinguir "habilitado quebrado" de "habilitado".
        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->putJson('/api/serpro/enablement', ['enabled' => true])
            ->assertUnprocessable();

        $this->assertNull($account->fresh()->settings['serpro_enabled'] ?? null);
    }

    public function test_operador_e_user_recebem_403_e_nada_muda(): void
    {
        $account = Account::factory()->create();
        $this->conexao();

        foreach (['operador', 'user'] as $role) {
            $this->actingAs($this->memberOf($account, $role), 'sanctum')
                ->putJson('/api/serpro/enablement', ['enabled' => true])
                ->assertForbidden();

            $this->assertNull($account->fresh()->settings['serpro_enabled'] ?? null);
        }
    }

    public function test_desligar_preserva_historico_e_demais_chaves_de_settings(): void
    {
        $account = Account::factory()->create([
            'settings' => ['serpro_enabled' => true, 'other_key' => 'preservado'],
        ]);
        $this->conexao();

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->putJson('/api/serpro/enablement', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $settings = $account->fresh()->settings;
        $this->assertFalse($settings['serpro_enabled']);
        $this->assertSame('preservado', $settings['other_key']);
    }

    public function test_o_flag_de_uma_conta_nao_toca_no_da_outra(): void
    {
        $account = Account::factory()->create();
        $outraConta = Account::factory()->create(['settings' => ['serpro_enabled' => true]]);
        $this->conexao();

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->putJson('/api/serpro/enablement', ['enabled' => true])
            ->assertOk();

        $this->assertTrue($account->fresh()->settings['serpro_enabled']);
        $this->assertTrue($outraConta->fresh()->settings['serpro_enabled']);
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }

    /**
     * A credencial de plataforma no estado mínimo que `isConfigured()`
     * aceita: chave e segredo cifrado, sem certificado — a habilitação
     * pergunta pela configuração, e a autenticidade é do teste de
     * conectividade.
     */
    private function conexao(): void
    {
        SerproConnection::factory()->create([
            'consumer_key' => 'chave-de-integracao',
            'consumer_secret_encrypted' => Crypt::encryptString('segredo'),
        ]);
    }
}
