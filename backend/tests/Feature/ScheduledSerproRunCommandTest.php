<?php

namespace Tests\Feature;

use App\Enums\SerproSyncRunTrigger;
use App\Models\Account;
use App\Models\SerproConnection;
use App\Models\SerproObligationSchedule;
use App\Models\SerproSyncRun;
use App\Services\SerproAccountEnablement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * A varredura agendada por documento: no dia marcado para cada obrigação, a
 * conta habilitada recebe uma execução única com o escopo do dia —
 * `trigger=scheduled`, sem operador. Documento sem dia é documento sem busca
 * automática, e a conta sem agenda nenhuma cai no dia de fallback da config.
 */
class ScheduledSerproRunCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_o_dia_do_documento_dispara_run_com_escopo_e_trigger_scheduled(): void
    {
        CarbonImmutable::setTestNow('2026-10-05 03:00:00');
        $account = $this->contaHabilitada();
        SerproObligationSchedule::factory()->create([
            'account_id' => $account->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'day' => 5,
        ]);

        $this->artisan('serpro:scheduled-run')->assertSuccessful();

        $run = SerproSyncRun::withoutGlobalScope('account')->sole();
        $this->assertSame($account->getKey(), $run->account_id);
        $this->assertSame(SerproSyncRunTrigger::Scheduled, $run->trigger);
        $this->assertSame(['declaracoes/pgdas'], $run->obligations);
        $this->assertNull($run->requested_by);
    }

    public function test_o_dia_sem_linha_e_silencio_para_a_conta_com_agenda(): void
    {
        CarbonImmutable::setTestNow('2026-10-05 03:00:00');
        $account = $this->contaHabilitada();
        SerproObligationSchedule::factory()->create([
            'account_id' => $account->getKey(),
            'obligation' => 'declaracoes/pgdas',
            // Fallback da config é 15: o dia 5 tem agenda própria, e o dia
            // errado não dispara nada.
            'day' => 15,
        ]);

        $this->artisan('serpro:scheduled-run')->assertSuccessful();

        $this->assertSame(0, SerproSyncRun::withoutGlobalScope('account')->count());
    }

    public function test_dois_documentos_no_mesmo_dia_saem_numa_execucao_so(): void
    {
        CarbonImmutable::setTestNow('2026-10-07 03:00:00');
        $account = $this->contaHabilitada();
        SerproObligationSchedule::factory()->create([
            'account_id' => $account->getKey(),
            'obligation' => 'declaracoes/pgdas',
            'day' => 7,
        ]);
        SerproObligationSchedule::factory()->create([
            'account_id' => $account->getKey(),
            'obligation' => 'caixas-postais/e-cac',
            'day' => 7,
        ]);

        $this->artisan('serpro:scheduled-run')->assertSuccessful();

        // Uma run por conta e dia: disparar duas brigaria com a guarda de
        // uma-execução-por-conta, e o 409 da segunda seria a agenda brigando
        // consigo mesma. A lista é comparada ordenada: a ordem é do dia, e
        // não da agenda.
        $run = SerproSyncRun::withoutGlobalScope('account')->sole();
        $this->assertSame(
            ['caixas-postais/e-cac', 'declaracoes/pgdas'],
            collect($run->obligations)->sort()->values()->all(),
        );
    }

    public function test_a_conta_sem_agenda_cai_no_dia_de_fallback_da_config(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 03:00:00');
        $this->contaHabilitada();

        $this->artisan('serpro:scheduled-run')->assertSuccessful();

        // Sem agenda, o escopo é `null` — todas as sincronizáveis, como o
        // botão manual sempre fez.
        $run = SerproSyncRun::withoutGlobalScope('account')->sole();
        $this->assertNull($run->obligations);
        $this->assertSame(SerproSyncRunTrigger::Scheduled, $run->trigger);
    }

    public function test_conta_desabilitada_nao_dispara(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 03:00:00');
        Account::factory()->create();

        $this->artisan('serpro:scheduled-run')->assertSuccessful();

        $this->assertSame(0, SerproSyncRun::withoutGlobalScope('account')->count());
    }

    /**
     * Conta com o flag ligado e a credencial da plataforma configurada — os
     * caminhos de verdade, pelo mesmo desenho de `SerproSyncRunApiTest`.
     */
    private function contaHabilitada(): Account
    {
        $account = Account::factory()->create();

        if (SerproConnection::current() === null) {
            SerproConnection::factory()->create([
                'consumer_key' => 'chave-de-integracao',
                'consumer_secret_encrypted' => Crypt::encryptString('segredo'),
            ]);
        }

        resolve(SerproAccountEnablement::class)->set($account->getKey(), true);

        return $account->refresh();
    }
}
