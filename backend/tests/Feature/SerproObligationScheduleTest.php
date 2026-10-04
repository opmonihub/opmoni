<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\SerproObligationSchedule;
use App\Models\User;
use App\Services\SerproObligationScheduleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A agenda por documento: a lista inteira que Settings manda, no lugar da
 * que existe. Ler é de qualquer Membro; escrever é do par que dispara a
 * rotina — a agenda decide quando o gateway é cobrado pela rotina.
 */
class SerproObligationScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_le_a_agenda_da_conta(): void
    {
        $account = Account::factory()->create();
        SerproObligationSchedule::factory()->create([
            'account_id' => $account->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'day' => 5,
        ]);

        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->getJson('/api/serpro/obligation-schedules')
            ->assertOk()
            ->assertJsonPath('data.0.obligation', 'declaracoes/pgdas')
            ->assertJsonPath('data.0.day', 5);
    }

    public function test_admin_salva_a_lista_e_o_que_ficou_de_fora_apaga(): void
    {
        $account = Account::factory()->create();
        SerproObligationSchedule::factory()->create([
            'account_id' => $account->getKey(),
            'obligation' => 'caixas-postais/e-cac',
            'day' => 20,
        ]);

        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum')
            ->putJson('/api/serpro/obligation-schedules', [
                'schedules' => [
                    ['obligation' => 'declaracoes/pgdas', 'day' => 5],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.obligation', 'declaracoes/pgdas')
            ->assertJsonCount(1, 'data');

        // O replace é a forma de editar: a agenda inteira é a lista, e o
        // documento que saiu dela deixa de ter busca automática.
        $this->assertDatabaseMissing('serpro_obligation_schedules', [
            'account_id' => $account->getKey(),
            'obligation' => 'caixas-postais/e-cac',
        ]);
        $this->assertDatabaseHas('serpro_obligation_schedules', [
            'account_id' => $account->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'day' => 5,
        ]);
    }

    public function test_a_lista_vazia_remove_todo_o_agendamento(): void
    {
        $account = Account::factory()->create();
        SerproObligationSchedule::factory()->create(['account_id' => $account->getKey()]);

        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->putJson('/api/serpro/obligation-schedules', ['schedules' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(0, SerproObligationSchedule::count());
    }

    public function test_user_recebe_403_na_escrita(): void
    {
        $account = Account::factory()->create();

        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->putJson('/api/serpro/obligation-schedules', [
                'schedules' => [['obligation' => 'declaracoes/pgdas', 'day' => 5]],
            ])
            ->assertForbidden();

        $this->assertSame(0, SerproObligationSchedule::count());
    }

    public function test_dia_fora_do_teto_e_obrigacao_sem_leitura_respondem_422(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');

        // O dia 29 não existe em fevereiro: o teto é 28.
        $this->actingAs($member, 'sanctum')
            ->putJson('/api/serpro/obligation-schedules', [
                'schedules' => [['obligation' => 'declaracoes/pgdas', 'day' => 29]],
            ])
            ->assertUnprocessable();

        // `unavailable` não tem leitura servida: agendaria uma execução
        // mensal nascida para falhar.
        $this->actingAs($member, 'sanctum')
            ->putJson('/api/serpro/obligation-schedules', [
                'schedules' => [['obligation' => 'parcelamentos/pgfn', 'day' => 5]],
            ])
            ->assertUnprocessable();

        $this->assertSame(0, SerproObligationSchedule::count());
    }

    public function test_a_agenda_de_uma_conta_nao_vaza_para_outra(): void
    {
        $account = Account::factory()->create();
        $outra = Account::factory()->create();
        resolve(SerproObligationScheduleManager::class)->replace($outra->getKey(), [
            ['obligation' => 'declaracoes/pgdas', 'day' => 7],
        ]);

        $this->actingAs($this->memberOf($account, 'user'), 'sanctum')
            ->getJson('/api/serpro/obligation-schedules')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
