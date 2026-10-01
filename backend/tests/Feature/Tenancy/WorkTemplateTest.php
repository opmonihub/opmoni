<?php

namespace Tests\Feature\Tenancy;

use App\Enums\TaskStatus;
use App\Enums\TaxRegime;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\Department;
use App\Models\ProcessTemplate;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_work_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('process_templates'));
        $this->assertTrue(Schema::hasColumns('process_templates', ['id', 'account_id', 'name', 'cascade', 'generate_day', 'due_day', 'is_active', 'regimes']));
        $this->assertTrue(Schema::hasTable('process_template_tasks'));
        $this->assertTrue(Schema::hasTable('tasks'));
        $this->assertTrue(Schema::hasColumns('processes', ['client_id', 'template_id', 'reference_month', 'status', 'due_on']));
        $this->assertSame(['todo', 'doing', 'done', 'dismissed'], TaskStatus::values());
    }

    public function test_template_tags_attach_and_retrieve(): void
    {
        $template = ProcessTemplate::factory()->create();
        $tag = Tag::factory()->create(['account_id' => $template->account_id]);

        $template->tags()->attach($tag->getKey(), ['account_id' => $template->account_id]);

        $this->assertTrue($template->tags()->whereKey($tag->getKey())->exists());
        $this->assertSame($template->account_id, $template->tags()->first()->pivot->account_id);
    }

    public function test_operador_creates_template_with_blueprint_and_user_is_forbidden(): void
    {
        $account = Account::factory()->create();
        $operador = $this->memberOf($account, 'operador');
        $user = $this->memberOf($account, 'user');

        // O departamento Fiscal já vem semeado com a Account.
        $payload = [
            'name' => 'PGDAS',
            'cascade' => true,
            'generate_day' => 1,
            'due_day' => 20,
            'regimes' => ['simple_national'],
            'tag_ids' => [],
            'exceptions' => [],
            'steps' => [
                ['title' => 'Apurar', 'department' => 'Fiscal', 'due_day' => 3, 'priority' => 'medium', 'order' => 1],
                ['title' => 'Transmitir', 'department' => 'Fiscal', 'due_day' => 5, 'priority' => 'high', 'order' => 2],
            ],
        ];

        $id = $this->actingAs($operador, 'sanctum')->postJson('/api/process-templates', $payload)
            ->assertCreated()->json('data.id');

        $this->assertDatabaseHas('process_templates', ['id' => $id, 'account_id' => $account->getKey(), 'name' => 'PGDAS']);
        $this->assertDatabaseCount('process_template_tasks', 2);

        $this->getJson("/api/process-templates/{$id}")->assertOk()->assertJsonPath('data.steps.0.title', 'Apurar');

        $this->actingAs($user, 'sanctum')->postJson('/api/process-templates', $payload)->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/process-templates')->assertOk();
    }

    public function test_preview_returns_eligible_without_creating(): void
    {
        $account = Account::factory()->create();
        $operador = $this->memberOf($account, 'operador');
        $this->actingAs($operador, 'sanctum');

        $template = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(),
            'name' => 'PGDAS',
            'regimes' => [TaxRegime::SimpleNational->value],
        ]);

        Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_regime' => TaxRegime::SimpleNational,
            'status' => 'active',
            'name' => 'Elegivel SA',
        ]);
        Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_regime' => TaxRegime::PresumedProfit,
            'status' => 'active',
            'name' => 'Fora SA',
        ]);

        $this->getJson("/api/process-templates/{$template->getKey()}/preview")
            ->assertOk()
            ->assertJsonPath('data.0.reason', 'rule')
            ->assertJsonPath('data.0.client.name', 'Elegivel SA');

        $this->assertDatabaseCount('processes', 0);
    }

    public function test_preview_returns_removed_added_and_tag_rule_reasons(): void
    {
        $account = Account::factory()->create();
        $operador = $this->memberOf($account, 'operador');
        $this->actingAs($operador, 'sanctum');

        $tag = Tag::factory()->create(['account_id' => $account->getKey()]);

        $template = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(),
            'name' => 'PGDAS',
            'regimes' => [TaxRegime::SimpleNational->value],
        ]);
        $template->tags()->attach($tag->getKey(), ['account_id' => $account->getKey()]);

        $ruleClient = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_regime' => TaxRegime::SimpleNational,
            'status' => 'active',
            'name' => 'Regra SA',
        ]);
        $ruleClient->tags()->attach($tag->getKey(), ['account_id' => $account->getKey()]);

        $removedClient = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_regime' => TaxRegime::SimpleNational,
            'status' => 'active',
            'name' => 'Removido SA',
        ]);
        $removedClient->tags()->attach($tag->getKey(), ['account_id' => $account->getKey()]);

        $addedClient = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_regime' => TaxRegime::PresumedProfit,
            'status' => 'active',
            'name' => 'Avulso SA',
        ]);

        $template->exceptions()->create([
            'account_id' => $account->getKey(),
            'client_id' => $removedClient->getKey(),
            'kind' => 'removed',
        ]);
        $template->exceptions()->create([
            'account_id' => $account->getKey(),
            'client_id' => $addedClient->getKey(),
            'kind' => 'added',
        ]);

        $response = $this->getJson("/api/process-templates/{$template->getKey()}/preview")->assertOk();

        $reasons = collect($response->json('data'))->mapWithKeys(fn (array $row): array => [$row['client']['name'] => $row['reason']])->all();

        $this->assertSame('rule', $reasons['Regra SA'] ?? null);
        $this->assertSame('added', $reasons['Avulso SA'] ?? null);
        $this->assertArrayNotHasKey('Removido SA', $reasons);
        $this->assertArrayNotHasKey('Fora SA', $reasons);

        $this->assertDatabaseCount('processes', 0);
    }

    public function test_template_rejects_exception_with_foreign_client(): void
    {
        $account = Account::factory()->create();
        $foreign = Account::factory()->create();
        $operador = $this->memberOf($account, 'operador');
        $this->actingAs($operador, 'sanctum');

        $foreignClient = Client::factory()->company()->create(['account_id' => $foreign->getKey()]);

        $this->postJson('/api/process-templates', [
            'name' => 'PGDAS',
            'exceptions' => [
                ['client_id' => $foreignClient->getKey(), 'kind' => 'removed'],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('process_templates', 0);
    }

    public function test_etapa_com_department_id_da_account_e_persistida_e_retornada(): void
    {
        $account = Account::factory()->create();
        $operador = $this->memberOf($account, 'operador');
        $this->actingAs($operador, 'sanctum');

        $department = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();

        $id = $this->postJson('/api/process-templates', [
            'name' => 'PGDAS',
            'steps' => [
                ['title' => 'Apurar', 'department_id' => $department->getKey(), 'due_day' => 3, 'priority' => 'medium', 'order' => 1],
            ],
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('process_template_tasks', [
            'template_id' => $id,
            'title' => 'Apurar',
            'department_id' => $department->getKey(),
        ]);

        // O payload devolve o id e o objeto com o nome atual.
        $this->getJson("/api/process-templates/{$id}")
            ->assertOk()
            ->assertJsonPath('data.steps.0.department_id', $department->getKey())
            ->assertJsonPath('data.steps.0.department.id', $department->getKey())
            ->assertJsonPath('data.steps.0.department.name', 'Fiscal')
            ->assertJsonPath('data.steps.0.department.color', 'success');
    }

    public function test_etapa_sem_departamento_e_aceita_e_retorna_null(): void
    {
        $account = Account::factory()->create();
        $operador = $this->memberOf($account, 'operador');
        $this->actingAs($operador, 'sanctum');

        $id = $this->postJson('/api/process-templates', [
            'name' => 'PGDAS',
            'steps' => [
                ['title' => 'Apurar', 'department_id' => null, 'due_day' => 3, 'priority' => 'medium', 'order' => 1],
            ],
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('process_template_tasks', [
            'template_id' => $id,
            'title' => 'Apurar',
            'department_id' => null,
        ]);

        $this->getJson("/api/process-templates/{$id}")
            ->assertOk()
            ->assertJsonPath('data.steps.0.department_id', null)
            ->assertJsonPath('data.steps.0.department', null);
    }

    public function test_etapa_com_department_id_de_outra_account_responde_422(): void
    {
        $account = Account::factory()->create();
        $other = Account::factory()->create();
        $operador = $this->memberOf($account, 'operador');
        $this->actingAs($operador, 'sanctum');

        $foreign = Department::withoutGlobalScopes()
            ->where('account_id', $other->getKey())
            ->where('name', 'Fiscal')
            ->sole();

        $this->postJson('/api/process-templates', [
            'name' => 'PGDAS',
            'steps' => [
                ['title' => 'Apurar', 'department_id' => $foreign->getKey(), 'due_day' => 3, 'priority' => 'medium', 'order' => 1],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.department_id']);

        $this->assertDatabaseCount('process_templates', 0);
        $this->assertDatabaseCount('process_template_tasks', 0);
    }

    public function test_template_update_rejeita_department_id_de_outra_account(): void
    {
        $account = Account::factory()->create();
        $other = Account::factory()->create();
        $operador = $this->memberOf($account, 'operador');
        $this->actingAs($operador, 'sanctum');

        $foreign = Department::withoutGlobalScopes()
            ->where('account_id', $other->getKey())
            ->where('name', 'Fiscal')
            ->sole();

        $template = ProcessTemplate::factory()->create(['account_id' => $account->getKey()]);

        $this->patchJson("/api/process-templates/{$template->getKey()}", [
            'steps' => [
                ['title' => 'Apurar', 'department_id' => $foreign->getKey(), 'due_day' => 3, 'priority' => 'medium', 'order' => 1],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('process_template_tasks', 0);
    }

    public function test_template_rejects_assignee_outside_step_department(): void
    {
        $account = Account::factory()->create();
        $operador = $this->memberOf($account, 'operador');
        $this->actingAs($operador, 'sanctum');

        $outsider = $this->memberOf($account, 'user');
        $insider = $this->memberOf($account, 'user');
        $department = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();
        $department->members()->attach($insider->getKey(), ['account_id' => $account->getKey()]);

        $this->postJson('/api/process-templates', [
            'name' => 'PGDAS',
            'steps' => [
                ['title' => 'Apurar', 'department_id' => $department->getKey(), 'due_day' => 3, 'priority' => 'medium', 'order' => 1, 'default_assignee_member_id' => $outsider->getKey()],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'steps.0.default_assignee_member_id' => 'O responsável precisa pertencer ao departamento da etapa.',
            ]);

        $this->assertDatabaseCount('process_templates', 0);
    }

    public function test_template_accepts_assignee_inside_step_department(): void
    {
        $account = Account::factory()->create();
        $operador = $this->memberOf($account, 'operador');
        $this->actingAs($operador, 'sanctum');

        $insider = $this->memberOf($account, 'user');
        $department = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();
        $department->members()->attach($insider->getKey(), ['account_id' => $account->getKey()]);

        $this->postJson('/api/process-templates', [
            'name' => 'PGDAS',
            'steps' => [
                ['title' => 'Apurar', 'department_id' => $department->getKey(), 'due_day' => 3, 'priority' => 'medium', 'order' => 1, 'default_assignee_member_id' => $insider->getKey()],
            ],
        ])->assertCreated();
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
