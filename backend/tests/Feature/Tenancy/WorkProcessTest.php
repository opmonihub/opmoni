<?php

namespace Tests\Feature\Tenancy;

use App\Enums\TaskStatus;
use App\Enums\TaxRegime;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\Department;
use App\Models\Process;
use App\Models\ProcessTemplate;
use App\Models\Task;
use App\Models\User;
use App\Services\ProcessGenerationService;
use App\Tenant\CurrentTenant;
use Carbon\Carbon;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkProcessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_tasks_order_due_nulls_last_then_order(): void
    {
        $template = ProcessTemplate::factory()->create();
        $process = Process::factory()->create([
            'account_id' => $template->account_id,
            'template_id' => $template->getKey(),
        ]);

        Task::factory()->create(['process_id' => $process->getKey(), 'account_id' => $process->account_id, 'due_on' => null, 'order' => 1, 'title' => 'Sem prazo']);
        Task::factory()->create(['process_id' => $process->getKey(), 'account_id' => $process->account_id, 'due_on' => '2026-03-05', 'order' => 2, 'title' => 'Dia 5']);
        Task::factory()->create(['process_id' => $process->getKey(), 'account_id' => $process->account_id, 'due_on' => '2026-03-03', 'order' => 3, 'title' => 'Dia 3']);

        $titles = Task::query()->ordered()->where('process_id', $process->getKey())->pluck('title')->all();

        $this->assertSame(['Dia 3', 'Dia 5', 'Sem prazo'], $titles);
    }

    public function test_processes_filter_by_month_and_expose_progress(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $this->actingAs($member, 'sanctum');
        resolve(CurrentTenant::class)->accountId = $account->getKey();

        $template = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(),
            'name' => 'PGDAS',
            'regimes' => null,
        ]);
        $template->steps()->create(['account_id' => $account->getKey(), 'title' => 'Etapa 1', 'department' => 'Fiscal', 'due_day' => 3, 'priority' => 'medium', 'order' => 1]);
        $template->steps()->create(['account_id' => $account->getKey(), 'title' => 'Etapa 2', 'department' => 'Fiscal', 'due_day' => 5, 'priority' => 'medium', 'order' => 2]);
        $template->steps()->create(['account_id' => $account->getKey(), 'title' => 'Etapa 3', 'department' => 'Fiscal', 'due_day' => 7, 'priority' => 'medium', 'order' => 3]);
        $template->steps()->create(['account_id' => $account->getKey(), 'title' => 'Etapa 4', 'department' => 'Fiscal', 'due_day' => 9, 'priority' => 'medium', 'order' => 4]);

        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_regime' => TaxRegime::SimpleNational,
            'status' => 'active',
        ]);

        $month = Carbon::create(2026, 3, 1)->startOfDay();
        $processes = app(ProcessGenerationService::class)->generate($template->refresh(), $month);

        $this->assertCount(1, $processes);
        $process = $processes->first();
        $this->assertSame($client->getKey(), $process->client_id);

        $otherMonth = Carbon::create(2026, 4, 1)->startOfDay();
        app(ProcessGenerationService::class)->generate($template->refresh(), $otherMonth);

        $tasks = Task::query()->where('process_id', $process->getKey())->ordered()->get();
        $tasks[0]->update(['status' => TaskStatus::Done->value, 'completed_at' => now()]);
        $tasks[1]->update(['status' => TaskStatus::Dismissed->value, 'completed_at' => now(), 'dismissal_reason' => 'Sem movimento']);

        $this->getJson("/api/processes?template_id={$template->getKey()}&reference_month=2026-03")
            ->assertOk()
            ->assertJsonPath('data.0.id', $process->getKey())
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/processes?template_id={$template->getKey()}&reference_month=2026-04")
            ->assertOk()
            ->assertJsonPath('data.0.reference_month', '2026-04')
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/processes/{$process->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.id', $process->getKey())
            ->assertJsonPath('data.progress.total', 4)
            ->assertJsonPath('data.progress.done', 1)
            ->assertJsonPath('data.progress.dismissed', 1)
            ->assertJsonPath('data.progress.open', 2)
            ->assertJsonPath('data.progress.ratio', 0.25);
    }

    public function test_detalhe_do_processo_retorna_o_departamento_atual_das_tarefas_e_null_para_tarefas_sem_departamento(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $department = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();
        $process = Process::factory()->create(['account_id' => $account->getKey()]);
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'department_id' => $department->getKey(),
            'due_on' => '2026-03-03',
            'order' => 1,
        ]);
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'department_id' => null,
            'due_on' => '2026-03-05',
            'order' => 2,
        ]);
        $department->update(['name' => 'Tributário']);

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/processes/{$process->getKey()}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['tasks' => ['*' => ['department_id', 'department']]]])
            ->assertJsonPath('data.tasks.0.department_id', $department->getKey())
            ->assertJsonPath('data.tasks.0.department', [
                'id' => $department->getKey(),
                'name' => 'Tributário',
                'color' => 'success',
            ])
            ->assertJsonPath('data.tasks.1.department_id', null)
            ->assertJsonPath('data.tasks.1.department', null);
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
