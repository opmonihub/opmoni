<?php

namespace Tests\Feature\Tenancy;

use App\Enums\TaxRegime;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\Department;
use App\Models\ProcessTemplate;
use App\Models\Tag;
use App\Models\User;
use App\Services\ProcessGenerationService;
use App\Tenant\CurrentTenant;
use Carbon\Carbon;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_generate_creates_one_process_per_eligible_and_is_idempotent(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $this->actingAs($member, 'sanctum');
        resolve(CurrentTenant::class)->accountId = $account->getKey();

        $tag = Tag::factory()->create(['account_id' => $account->getKey()]);
        $template = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(), 'name' => 'PGDAS',
            'regimes' => [TaxRegime::SimpleNational->value], 'cascade' => true,
        ]);
        $template->tags()->attach($tag->getKey(), ['account_id' => $account->getKey()]);
        $fiscal = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();
        $template->steps()->create(['account_id' => $account->getKey(), 'title' => 'Apurar', 'department_id' => $fiscal->getKey(), 'due_day' => 3, 'priority' => 'medium', 'order' => 1]);
        $template->steps()->create(['account_id' => $account->getKey(), 'title' => 'Transmitir', 'department_id' => $fiscal->getKey(), 'due_day' => 31, 'priority' => 'high', 'order' => 2]);

        $ok = Client::factory()->company()->create(['account_id' => $account->getKey(), 'tax_regime' => TaxRegime::SimpleNational, 'status' => 'active']);
        $ok->tags()->attach($tag->getKey(), ['account_id' => $account->getKey()]);
        $out = Client::factory()->company()->create(['account_id' => $account->getKey(), 'tax_regime' => TaxRegime::PresumedProfit, 'status' => 'active']);

        $month = Carbon::create(2026, 2, 1)->startOfDay();
        $first = app(ProcessGenerationService::class)->generate($template, $month);
        $second = app(ProcessGenerationService::class)->generate($template, $month);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame($first->first()->getKey(), $second->first()->getKey());
        $this->assertDatabaseCount('processes', 1);
        // due_day 31 em fevereiro limita ao dia 28 (coluna date persiste como datetime no SQLite)
        $this->assertDatabaseHas('tasks', ['process_id' => $first->first()->getKey(), 'due_on' => '2026-02-28 00:00:00', 'status' => 'todo']);
        $this->assertDatabaseMissing('processes', ['client_id' => $out->getKey()]);
    }

    public function test_manual_generate_endpoint_creates_one_process_per_client_and_is_idempotent(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $this->actingAs($member, 'sanctum');

        $template = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(), 'name' => 'PGDAS',
            'regimes' => [TaxRegime::SimpleNational->value],
        ]);
        $fiscal = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();
        $template->steps()->create([
            'account_id' => $account->getKey(), 'title' => 'Apurar', 'department_id' => $fiscal->getKey(),
            'due_day' => 3, 'priority' => 'medium', 'order' => 1,
        ]);

        foreach (['Alfa SA', 'Beta SA', 'Gama SA'] as $name) {
            Client::factory()->company()->create([
                'account_id' => $account->getKey(), 'tax_regime' => TaxRegime::SimpleNational,
                'status' => 'active', 'name' => $name,
            ]);
        }

        $first = $this->postJson("/api/process-templates/{$template->getKey()}/generate", ['reference_month' => '2026-03'])
            ->assertCreated();

        $this->assertCount(3, $first->json('data'));
        $this->assertDatabaseCount('processes', 3);
        $this->assertDatabaseCount('tasks', 3);

        $second = $this->postJson("/api/process-templates/{$template->getKey()}/generate", ['reference_month' => '2026-03'])
            ->assertCreated();

        $this->assertCount(3, $second->json('data'));
        $this->assertDatabaseCount('processes', 3);
        $this->assertDatabaseCount('tasks', 3);
        $this->assertSame(
            collect($first->json('data'))->pluck('id')->sort()->values()->all(),
            collect($second->json('data'))->pluck('id')->sort()->values()->all()
        );
    }

    public function test_generate_nulls_assignee_outside_step_department(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $this->actingAs($member, 'sanctum');
        resolve(CurrentTenant::class)->accountId = $account->getKey();

        $outsider = $this->memberOf($account, 'operador');
        $insider = $this->memberOf($account, 'operador');
        $department = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();
        $department->members()->attach($insider->getKey(), ['account_id' => $account->getKey()]);

        $template = ProcessTemplate::factory()->create(['account_id' => $account->getKey(), 'name' => 'PGDAS']);
        $template->steps()->create([
            'account_id' => $account->getKey(), 'title' => 'Apurar', 'department_id' => $department->getKey(),
            'due_day' => 3, 'priority' => 'medium', 'order' => 1,
            'default_assignee_member_id' => $outsider->getKey(),
        ]);
        $template->steps()->create([
            'account_id' => $account->getKey(), 'title' => 'Transmitir', 'department_id' => $department->getKey(),
            'due_day' => 5, 'priority' => 'medium', 'order' => 2,
            'default_assignee_member_id' => $insider->getKey(),
        ]);

        Client::factory()->company()->create([
            'account_id' => $account->getKey(), 'tax_regime' => TaxRegime::SimpleNational, 'status' => 'active',
        ]);

        $processes = app(ProcessGenerationService::class)->generate($template, Carbon::create(2026, 3, 1)->startOfDay());

        $this->assertCount(1, $processes);
        $tasks = $processes->first()->tasks()->ordered()->get();
        $this->assertNull($tasks[0]->assignee_member_id);
        $this->assertSame($insider->getKey(), $tasks[1]->assignee_member_id);
    }

    public function test_geracao_copia_o_department_id_da_etapa_para_a_task(): void
    {
        $account = Account::factory()->create();
        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum');
        resolve(CurrentTenant::class)->accountId = $account->getKey();

        $fiscal = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();

        $template = ProcessTemplate::factory()->create(['account_id' => $account->getKey(), 'name' => 'PGDAS']);
        $template->steps()->create([
            'account_id' => $account->getKey(), 'title' => 'Apurar', 'department_id' => $fiscal->getKey(),
            'due_day' => 3, 'priority' => 'medium', 'order' => 1,
        ]);
        $template->steps()->create([
            'account_id' => $account->getKey(), 'title' => 'Sem depto', 'department_id' => null,
            'due_day' => 5, 'priority' => 'medium', 'order' => 2,
        ]);

        Client::factory()->company()->create([
            'account_id' => $account->getKey(), 'tax_regime' => TaxRegime::SimpleNational, 'status' => 'active',
        ]);

        $processes = app(ProcessGenerationService::class)->generate($template->refresh(), Carbon::create(2026, 3, 1)->startOfDay());

        $tasks = $processes->first()->tasks()->ordered()->get();
        $this->assertSame($fiscal->getKey(), $tasks[0]->department_id);
        $this->assertNull($tasks[1]->department_id);
    }

    public function test_renomear_departamento_reflete_na_task_gerada(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account, 'admin');
        $this->actingAs($member, 'sanctum');
        resolve(CurrentTenant::class)->accountId = $account->getKey();

        $fiscal = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();

        $template = ProcessTemplate::factory()->create(['account_id' => $account->getKey(), 'name' => 'PGDAS']);
        $template->steps()->create([
            'account_id' => $account->getKey(), 'title' => 'Apurar', 'department_id' => $fiscal->getKey(),
            'due_day' => 3, 'priority' => 'medium', 'order' => 1,
        ]);

        Client::factory()->company()->create([
            'account_id' => $account->getKey(), 'tax_regime' => TaxRegime::SimpleNational, 'status' => 'active',
        ]);

        $processes = app(ProcessGenerationService::class)->generate($template->refresh(), Carbon::create(2026, 3, 1)->startOfDay());
        $task = $processes->first()->tasks()->sole();

        $fiscal->update(['name' => 'Tributário']);

        // O nome é sempre o atual: a task gerada acompanha a renomeação.
        $this->getJson("/api/tasks/{$task->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.department_id', $fiscal->getKey())
            ->assertJsonPath('data.department.id', $fiscal->getKey())
            ->assertJsonPath('data.department.name', 'Tributário')
            ->assertJsonPath('data.department.color', 'success');
    }

    public function test_trocar_o_departamento_da_etapa_nao_altera_o_gerado(): void
    {
        $account = Account::factory()->create();
        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum');
        resolve(CurrentTenant::class)->accountId = $account->getKey();

        $fiscal = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();
        $pessoal = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Pessoal')
            ->sole();

        $template = ProcessTemplate::factory()->create(['account_id' => $account->getKey(), 'name' => 'PGDAS']);
        $step = $template->steps()->create([
            'account_id' => $account->getKey(), 'title' => 'Apurar', 'department_id' => $fiscal->getKey(),
            'due_day' => 3, 'priority' => 'medium', 'order' => 1,
        ]);

        Client::factory()->company()->create([
            'account_id' => $account->getKey(), 'tax_regime' => TaxRegime::SimpleNational, 'status' => 'active',
        ]);

        $march = Carbon::create(2026, 3, 1)->startOfDay();
        $generated = app(ProcessGenerationService::class)->generate($template->refresh(), $march);
        $task = $generated->first()->tasks()->sole();

        $step->update(['department_id' => $pessoal->getKey()]);

        // Março continua apontando para Fiscal; abril usa Pessoal.
        $this->assertSame($fiscal->getKey(), $task->refresh()->department_id);

        $april = app(ProcessGenerationService::class)->generate($template->refresh(), Carbon::create(2026, 4, 1)->startOfDay());
        $this->assertSame($pessoal->getKey(), $april->first()->tasks()->sole()->department_id);
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
