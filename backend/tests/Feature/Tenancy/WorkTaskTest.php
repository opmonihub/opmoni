<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\Department;
use App\Models\Process;
use App\Models\ProcessTemplate;
use App\Models\SupportAccessLog;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_task_lifecycle_requires_reason_and_cascade_blocks_sequence(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $this->actingAs($member, 'sanctum');

        $template = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(),
            'cascade' => true,
        ]);
        $process = Process::factory()->create([
            'account_id' => $account->getKey(),
            'template_id' => $template->getKey(),
        ]);
        $task1 = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'order' => 1,
            'title' => 'Etapa 1',
        ]);
        $task2 = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'order' => 2,
            'title' => 'Etapa 2',
        ]);

        $this->patchJson("/api/tasks/{$task2->getKey()}", ['status' => 'doing'])->assertStatus(422);

        $this->patchJson("/api/tasks/{$task1->getKey()}", ['status' => 'dismissed'])->assertStatus(422);

        $this->patchJson("/api/tasks/{$task1->getKey()}", [
            'status' => 'dismissed',
            'dismissal_reason' => 'Sem movimento no mês',
        ])->assertOk()->assertJsonPath('data.status', 'dismissed');

        $this->patchJson("/api/tasks/{$task2->getKey()}", ['status' => 'doing'])
            ->assertOk()->assertJsonPath('data.status', 'doing');
    }

    public function test_dismiss_without_reason_is_rejected_and_user_is_forbidden(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');
        $user = $this->memberOf($account, 'user');

        $process = Process::factory()->create([
            'account_id' => $account->getKey(),
            'name' => 'Avulso',
        ]);
        $task = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'title' => 'Etapa única',
        ]);

        $this->actingAs($admin, 'sanctum');
        $this->patchJson("/api/tasks/{$task->getKey()}", ['status' => 'dismissed'])->assertStatus(422);

        $this->actingAs($user, 'sanctum');
        $this->patchJson("/api/tasks/{$task->getKey()}", ['status' => 'doing'])->assertForbidden();
        $this->getJson('/api/tasks')->assertOk();
    }

    public function test_calendar_omits_undated_and_grouped_nests_client_process_task(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $this->actingAs($member, 'sanctum');

        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'status' => 'active',
        ]);
        $template = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(),
            'name' => 'Fechamento mensal',
            'cascade' => true,
        ]);
        $process = Process::factory()->create([
            'account_id' => $account->getKey(),
            'name' => 'Março',
            'client_id' => $client->getKey(),
            'template_id' => $template->getKey(),
            'reference_month' => '2026-03-01',
            'status' => 'open',
            'due_on' => '2026-03-20',
        ]);
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'title' => 'Sem data',
            'due_on' => null,
            'order' => 1,
        ]);
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'title' => 'Com data',
            'due_on' => '2026-03-10',
            'order' => 2,
        ]);

        $this->getJson('/api/tasks?status=todo')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.process.cascade', true);

        $this->getJson('/api/work/calendar?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Com data')
            ->assertJsonPath('data.0.process.cascade', true);

        $this->getJson('/api/work/grouped?reference_month=2026-03')
            ->assertOk()
            ->assertJsonPath('data.0.client.id', $client->getKey())
            ->assertJsonPath('data.0.processes.0.process.status', 'open')
            ->assertJsonPath('data.0.processes.0.process.due_on', '2026-03-20')
            ->assertJsonPath('data.0.processes.0.process.reference_month', '2026-03')
            ->assertJsonPath('data.0.processes.0.process.template.id', $template->getKey())
            ->assertJsonPath('data.0.processes.0.process.template.name', 'Fechamento mensal')
            ->assertJsonPath('data.0.processes.0.process.template.cascade', true)
            ->assertJsonPath('data.0.processes.0.tasks.0.title', 'Com data')
            ->assertJsonPath('data.0.processes.0.totals.tasks', 2)
            ->assertJsonPath('data.0.processes.0.totals.done', 0)
            ->assertJsonPath('data.0.processes.0.totals.dismissed', 0)
            ->assertJsonPath('data.0.processes.0.totals.open', 2)
            ->assertJsonPath('data.0.processes.0.progress.total', 2)
            ->assertJsonPath('data.0.processes.0.progress.done', 0)
            ->assertJsonPath('data.0.processes.0.progress.dismissed', 0)
            ->assertJsonPath('data.0.processes.0.progress.open', 2)
            ->assertJsonPath('data.0.processes.0.ratio', 0);
    }

    public function test_unscoped_tasks_appear_in_the_month_of_their_due_date(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $this->actingAs($member, 'sanctum');

        $unscoped = Process::factory()->create([
            'account_id' => $account->getKey(),
            'reference_month' => null,
        ]);
        $marchTask = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $unscoped->getKey(),
            'due_on' => '2026-03-10',
        ]);
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $unscoped->getKey(),
            'due_on' => '2026-04-10',
        ]);
        $undatedTask = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $unscoped->getKey(),
            'due_on' => null,
        ]);
        $scoped = Process::factory()->create([
            'account_id' => $account->getKey(),
            'reference_month' => '2026-03-01',
        ]);
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $scoped->getKey(),
            'due_on' => '2026-03-15',
        ]);
        $otherAccount = Account::factory()->create();
        $otherProcess = Process::factory()->create([
            'account_id' => $otherAccount->getKey(),
            'reference_month' => null,
        ]);
        Task::factory()->create([
            'account_id' => $otherAccount->getKey(),
            'process_id' => $otherProcess->getKey(),
            'due_on' => '2026-03-20',
        ]);

        $this->getJson('/api/work/tasks/unscoped?reference_month=2026-03')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $marchTask->getKey())
            ->assertJsonPath('data.0.process.id', $unscoped->getKey())
            ->assertJsonPath('data.0.process.cascade', false);

        $this->getJson('/api/work/tasks/unscoped?reference_month=2026-03&include_undated=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.id', $undatedTask->getKey())
            ->assertJsonPath('data.1.due_on', null);

        $this->getJson('/api/work/tasks/unscoped?reference_month=2026-13')
            ->assertUnprocessable();
    }

    public function test_unscoped_task_reports_cascade_lock_from_an_earlier_month(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $this->actingAs($member, 'sanctum');

        $template = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(),
            'cascade' => true,
        ]);
        $process = Process::factory()->create([
            'account_id' => $account->getKey(),
            'template_id' => $template->getKey(),
            'reference_month' => null,
        ]);
        $earlier = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'order' => 1,
            'status' => 'todo',
            'due_on' => '2026-08-20',
        ]);
        $later = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'order' => 2,
            'status' => 'todo',
            'due_on' => '2026-09-20',
        ]);

        $this->getJson('/api/work/tasks/unscoped?reference_month=2026-09')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $later->getKey())
            ->assertJsonPath('data.0.cascade_locked', true);

        $earlier->update(['status' => 'done']);

        $this->getJson('/api/work/tasks/unscoped?reference_month=2026-09')
            ->assertOk()
            ->assertJsonPath('data.0.cascade_locked', false);
    }

    public function test_grouped_totals_align_with_process_progress(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $this->actingAs($member, 'sanctum');

        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'status' => 'active',
        ]);
        $process = Process::factory()->create([
            'account_id' => $account->getKey(),
            'name' => 'Março',
            'client_id' => $client->getKey(),
            'reference_month' => '2026-03-01',
            'status' => 'open',
        ]);
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'title' => 'Feita',
            'status' => 'done',
            'due_on' => '2026-03-05',
            'order' => 1,
        ]);
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'title' => 'Dispensada',
            'status' => 'dismissed',
            'dismissal_reason' => 'Sem movimento',
            'due_on' => '2026-03-06',
            'order' => 2,
        ]);
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'title' => 'Aberta',
            'status' => 'todo',
            'due_on' => '2026-03-07',
            'order' => 3,
        ]);
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'title' => 'Fazendo',
            'status' => 'doing',
            'due_on' => '2026-03-08',
            'order' => 4,
        ]);

        $this->getJson("/api/processes/{$process->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.progress.total', 4)
            ->assertJsonPath('data.progress.done', 1)
            ->assertJsonPath('data.progress.dismissed', 1)
            ->assertJsonPath('data.progress.open', 2)
            ->assertJsonPath('data.progress.ratio', 0.25);

        $this->getJson('/api/work/grouped?reference_month=2026-03')
            ->assertOk()
            ->assertJsonPath('data.0.processes.0.totals.tasks', 4)
            ->assertJsonPath('data.0.processes.0.totals.done', 1)
            ->assertJsonPath('data.0.processes.0.totals.dismissed', 1)
            ->assertJsonPath('data.0.processes.0.totals.open', 2)
            ->assertJsonPath('data.0.processes.0.progress.total', 4)
            ->assertJsonPath('data.0.processes.0.progress.done', 1)
            ->assertJsonPath('data.0.processes.0.progress.dismissed', 1)
            ->assertJsonPath('data.0.processes.0.progress.open', 2)
            ->assertJsonPath('data.0.processes.0.progress.ratio', 0.25)
            ->assertJsonPath('data.0.processes.0.ratio', 0.25);
    }

    public function test_task_full_lifecycle_concludes_reopens_and_clears_stamp(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');
        $this->actingAs($admin, 'sanctum');

        $process = Process::factory()->create([
            'account_id' => $account->getKey(),
            'name' => 'Avulso',
        ]);
        $task = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'title' => 'Etapa única',
        ]);

        $this->patchJson("/api/tasks/{$task->getKey()}", ['status' => 'doing'])->assertOk();

        $done = $this->patchJson("/api/tasks/{$task->getKey()}", ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->json('data');

        $this->assertNotNull($done['completed_at']);

        $reopened = $this->patchJson("/api/tasks/{$task->getKey()}", ['status' => 'doing'])
            ->assertOk()
            ->assertJsonPath('data.status', 'doing')
            ->json('data');

        $this->assertNull($reopened['completed_at']);
        $this->assertNull($reopened['dismissal_reason']);
    }

    public function test_reassign_without_status_change_skips_reason_and_cascade_guards(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');
        $assignee = $this->memberOf($account, 'operador');
        $this->actingAs($admin, 'sanctum');

        $template = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(),
            'cascade' => true,
        ]);
        $process = Process::factory()->create([
            'account_id' => $account->getKey(),
            'template_id' => $template->getKey(),
        ]);
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'order' => 1,
            'title' => 'Etapa 1',
        ]);
        $blockedDoing = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'order' => 2,
            'title' => 'Etapa 2',
            'status' => 'doing',
        ]);
        $dismissed = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'order' => 3,
            'title' => 'Etapa 3',
            'status' => 'dismissed',
            'dismissal_reason' => 'Sem movimento',
        ]);

        $this->patchJson("/api/tasks/{$dismissed->getKey()}", ['assignee_member_id' => $assignee->getKey()])
            ->assertOk()
            ->assertJsonPath('data.status', 'dismissed')
            ->assertJsonPath('data.assignee_member_id', $assignee->getKey());

        $this->patchJson("/api/tasks/{$blockedDoing->getKey()}", ['assignee_member_id' => $assignee->getKey()])
            ->assertOk()
            ->assertJsonPath('data.status', 'doing')
            ->assertJsonPath('data.assignee_member_id', $assignee->getKey());
    }

    public function test_task_filter_por_department_id_de_outra_account_responde_422(): void
    {
        $account = Account::factory()->create();
        $other = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $this->actingAs($member, 'sanctum');

        $foreign = Department::withoutGlobalScopes()
            ->where('account_id', $other->getKey())
            ->where('name', 'Fiscal')
            ->sole();

        $this->getJson("/api/tasks?department_id={$foreign->getKey()}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);

        $this->getJson("/api/work/calendar?from=2026-03-01&to=2026-03-31&department_id={$foreign->getKey()}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);
    }

    public function test_task_filter_por_department_id_retorna_so_as_tasks_do_departamento(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $this->actingAs($member, 'sanctum');

        $fiscal = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();
        $pessoal = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Pessoal')
            ->sole();

        $process = Process::factory()->create(['account_id' => $account->getKey()]);
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'title' => 'Etapa fiscal',
            'department_id' => $fiscal->getKey(),
        ]);
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'title' => 'Etapa pessoal',
            'department_id' => $pessoal->getKey(),
        ]);
        // Task sem departamento fica de fora do filtro por departamento.
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'title' => 'Sem departamento',
            'department_id' => null,
        ]);

        $this->getJson("/api/tasks?department_id={$pessoal->getKey()}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Etapa pessoal');
    }

    public function test_task_payload_embuti_o_departamento_com_nome_atual(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $this->actingAs($member, 'sanctum');

        $department = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();

        $process = Process::factory()->create(['account_id' => $account->getKey()]);
        $task = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'department_id' => $department->getKey(),
        ]);

        $this->getJson("/api/tasks/{$task->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.department_id', $department->getKey())
            ->assertJsonPath('data.department.id', $department->getKey())
            ->assertJsonPath('data.department.name', 'Fiscal')
            ->assertJsonPath('data.department.color', 'success');

        // Renomear o departamento reflete no payload da task já gerada.
        $department->update(['name' => 'Tributário']);

        $this->getJson("/api/tasks/{$task->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.department.name', 'Tributário');
    }

    public function test_task_sem_departamento_devolve_department_null(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $this->actingAs($member, 'sanctum');

        $process = Process::factory()->create(['account_id' => $account->getKey()]);
        $task = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'department_id' => null,
        ]);

        $this->getJson("/api/tasks/{$task->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.department_id', null)
            ->assertJsonPath('data.department', null);
    }

    public function test_task_reassign_rejects_assignee_outside_task_department(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');
        $this->actingAs($admin, 'sanctum');

        $outsider = $this->memberOf($account, 'operador');
        $insider = $this->memberOf($account, 'operador');
        $department = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();
        $department->members()->attach($insider->getKey(), ['account_id' => $account->getKey()]);

        $process = Process::factory()->create(['account_id' => $account->getKey()]);
        $task = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'department_id' => $department->getKey(),
        ]);

        $this->patchJson("/api/tasks/{$task->getKey()}", ['assignee_member_id' => $outsider->getKey()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'assignee_member_id' => 'O responsável precisa pertencer ao departamento da tarefa.',
            ]);

        $this->patchJson("/api/tasks/{$task->getKey()}", ['assignee_member_id' => $insider->getKey()])
            ->assertOk()
            ->assertJsonPath('data.assignee_member_id', $insider->getKey());

        $this->patchJson("/api/tasks/{$task->getKey()}", ['assignee_member_id' => null])->assertOk();
    }

    public function test_operador_can_reschedule_due_on_without_changing_status(): void
    {
        $account = Account::factory()->create();
        $operador = $this->memberOf($account, 'operador');
        $this->actingAs($operador, 'sanctum');

        $process = Process::factory()->create(['account_id' => $account->getKey()]);
        $task = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'status' => 'doing',
            'due_on' => '2026-03-10',
            'completed_at' => null,
            'dismissal_reason' => null,
        ]);

        $this->patchJson("/api/tasks/{$task->getKey()}", ['due_on' => '2026-04-15'])
            ->assertOk()
            ->assertJsonPath('data.due_on', '2026-04-15')
            ->assertJsonPath('data.status', 'doing')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.dismissal_reason', null);

        $this->assertSame('2026-04-15', $task->refresh()->due_on?->toDateString());
        $this->assertSame('doing', $task->status->value);
    }

    public function test_clearing_due_on_removes_task_from_calendar_feed(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');
        $this->actingAs($admin, 'sanctum');

        $process = Process::factory()->create(['account_id' => $account->getKey()]);
        $task = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'title' => 'Com prazo',
            'due_on' => '2026-03-10',
        ]);

        $this->patchJson("/api/tasks/{$task->getKey()}", ['due_on' => null])
            ->assertOk()
            ->assertJsonPath('data.due_on', null);

        $this->assertNull($task->refresh()->due_on);

        $this->getJson('/api/work/calendar?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_user_cannot_reschedule_due_on(): void
    {
        $account = Account::factory()->create();
        $user = $this->memberOf($account, 'user');
        $this->actingAs($user, 'sanctum');

        $process = Process::factory()->create(['account_id' => $account->getKey()]);
        $task = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'due_on' => '2026-03-10',
        ]);

        $this->patchJson("/api/tasks/{$task->getKey()}", ['due_on' => '2026-04-15'])
            ->assertForbidden();

        $this->assertSame('2026-03-10', $task->refresh()->due_on?->toDateString());
    }

    public function test_malformed_due_on_is_rejected(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');
        $this->actingAs($admin, 'sanctum');

        $process = Process::factory()->create(['account_id' => $account->getKey()]);
        $task = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'due_on' => '2026-03-10',
        ]);

        $this->patchJson("/api/tasks/{$task->getKey()}", ['due_on' => 'not-a-date'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_on']);

        $this->assertSame('2026-03-10', $task->refresh()->due_on?->toDateString());
    }

    public function test_cascade_does_not_block_due_on_reschedule(): void
    {
        $account = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');
        $this->actingAs($admin, 'sanctum');

        $template = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(),
            'cascade' => true,
        ]);
        $process = Process::factory()->create([
            'account_id' => $account->getKey(),
            'template_id' => $template->getKey(),
        ]);
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'order' => 1,
            'title' => 'Etapa 1',
            'status' => 'todo',
        ]);
        $task2 = Task::factory()->create([
            'account_id' => $account->getKey(),
            'process_id' => $process->getKey(),
            'order' => 2,
            'title' => 'Etapa 2',
            'status' => 'todo',
            'due_on' => '2026-03-10',
        ]);

        $this->patchJson("/api/tasks/{$task2->getKey()}", ['due_on' => '2026-04-20'])
            ->assertOk()
            ->assertJsonPath('data.due_on', '2026-04-20')
            ->assertJsonPath('data.status', 'todo');
    }

    public function test_support_mode_reschedule_is_audited(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $target = Account::factory()->create();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/support/accounts/{$target->getKey()}/enter")
            ->assertOk();

        $process = Process::factory()->create(['account_id' => $target->getKey()]);
        $task = Task::factory()->create([
            'account_id' => $target->getKey(),
            'process_id' => $process->getKey(),
            'due_on' => '2026-03-10',
        ]);

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/tasks/{$task->getKey()}", ['due_on' => '2026-05-01'])
            ->assertOk()
            ->assertJsonPath('data.due_on', '2026-05-01');

        $this->assertDatabaseHas('support_access_logs', [
            'super_admin_user_id' => $superAdmin->getKey(),
            'account_id' => $target->getKey(),
            'action' => 'update',
        ]);

        $log = SupportAccessLog::firstWhere([
            'action' => 'update',
            'account_id' => $target->getKey(),
        ]);
        $this->assertSame('tasks', $log->metadata['resource']);
        $this->assertSame($task->getKey(), $log->metadata['resource_id']);
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

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

        $superAdmin->forceFill(['current_account_id' => $home->getKey()])->save();

        return $superAdmin->refresh();
    }
}
