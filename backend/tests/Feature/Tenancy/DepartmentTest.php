<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Department;
use App\Models\ProcessTemplate;
use App\Models\Task;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BusinessTestCase;

class DepartmentTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_account_nova_nasce_com_os_quatro_departamentos_padrao(): void
    {
        // O observer roda sem tenant: o account_id precisa ser explícito.
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();

        $departments = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->get()
            ->keyBy('name');

        $this->assertSame(
            ['Contábil', 'Fiscal', 'Pessoal', 'Societário'],
            $departments->keys()->sort()->values()->all()
        );

        $this->assertSame('success', $departments['Fiscal']->color);
        $this->assertSame('info', $departments['Pessoal']->color);
        $this->assertSame('primary', $departments['Contábil']->color);
        $this->assertSame('warning', $departments['Societário']->color);

        foreach ($departments as $department) {
            $this->assertSame($account->getKey(), $department->account_id);
        }
    }

    public function test_os_departamentos_padrao_sao_isolados_por_account(): void
    {
        $a = Account::factory()->create();
        $b = Account::factory()->create();

        $this->assertSame(
            4,
            Department::withoutGlobalScopes()->where('account_id', $a->getKey())->count()
        );
        $this->assertSame(
            4,
            Department::withoutGlobalScopes()->where('account_id', $b->getKey())->count()
        );

        // Cada conta tem os seus próprios quatro registros — não compartilham ids.
        $idsA = Department::withoutGlobalScopes()->where('account_id', $a->getKey())->pluck('id');
        $idsB = Department::withoutGlobalScopes()->where('account_id', $b->getKey())->pluck('id');
        $this->assertEmpty($idsA->intersect($idsB));

        $member = $this->memberOf($a, 'operador');
        $this->actingAs($member, 'web')
            ->getJson('/api/v1/departments')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_operador_renomeia_departamento_padrao(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $operador = $this->memberOf($account, 'operador');

        $societario = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Societário')
            ->sole();

        $this->actingAs($operador, 'web')
            ->patchJson("/api/v1/departments/{$societario->getKey()}", ['name' => 'Legalização'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Legalização');
    }

    public function test_admin_exclui_departamento_padrao(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $admin = $this->memberOf($account, 'admin');

        $contabil = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Contábil')
            ->sole();

        $this->actingAs($admin, 'web')
            ->deleteJson("/api/v1/departments/{$contabil->getKey()}")
            ->assertNoContent();

        $this->actingAs($admin, 'web')
            ->getJson('/api/v1/departments')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissing(['name' => 'Contábil']);
    }

    public function test_operador_creates_department_with_members(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $operador = $this->memberOf($account, 'operador');
        $ana = $this->memberOf($account, 'user', ['name' => 'Ana']);

        $response = $this->actingAs($operador, 'web')->postJson('/api/v1/departments', [
            'name' => 'Jurídico',
            'color' => 'success',
            'member_ids' => [$ana->getKey()],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Jurídico');
        $response->assertJsonPath('data.color', 'success');

        $this->assertDatabaseHas('departments', [
            'account_id' => $account->getKey(),
            'name' => 'Jurídico',
        ]);

        $departmentId = $response->json('data.id');

        $this->assertDatabaseHas('department_user', [
            'department_id' => $departmentId,
            'user_id' => $ana->getKey(),
            'account_id' => $account->getKey(),
        ]);

        $this->getJson('/api/v1/account/members/directory')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $ana->getKey(),
                'name' => 'Ana',
            ]);
    }

    public function test_user_is_forbidden_and_foreign_member_rejected(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $other = Account::factory()->create();
        $user = $this->memberOf($account, 'user');
        $outsider = $this->memberOf($other, 'user');

        $this->actingAs($user, 'web')->postJson('/api/v1/departments', [
            'name' => 'Jurídico',
            'color' => 'success',
        ])->assertForbidden();

        // Cada Account nasce com os 4 departamentos padrão; nenhum novo foi criado.
        $this->assertDatabaseCount('departments', 8);

        $admin = $this->memberOf($account, 'admin');

        $this->actingAs($admin, 'web')->postJson('/api/v1/departments', [
            'name' => 'Jurídico',
            'color' => 'success',
            'member_ids' => [$outsider->getKey()],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('departments', 8);
    }

    public function test_duplicate_name_with_different_case_is_rejected(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $admin = $this->memberOf($account, 'admin');

        $this->actingAs($admin, 'web')->postJson('/api/v1/departments', [
            'name' => 'Jurídico',
            'color' => 'success',
        ])->assertCreated();

        $this->actingAs($admin, 'web')->postJson('/api/v1/departments', [
            'name' => '  jURíDiCo  ',
            'color' => 'primary',
        ])->assertUnprocessable();

        // Os 4 padrão do seed + o Jurídico recém-criado.
        $this->assertSame(5, Department::count());
    }

    public function test_departments_are_isolated_between_accounts(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $other = Account::factory()->create();
        $admin = $this->memberOf($account, 'admin');
        $foreign = $this->memberOf($other, 'admin');

        $departmentId = $this->actingAs($admin, 'web')->postJson('/api/v1/departments', [
            'name' => 'Jurídico',
            'color' => 'success',
        ])->assertCreated()->json('data.id');

        $this->actingAs($foreign, 'web')->getJson('/api/v1/departments')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonMissing(['name' => 'Jurídico']);

        $this->actingAs($foreign, 'web')->patchJson("/api/v1/departments/{$departmentId}", [
            'name' => 'Roubado',
        ])->assertNotFound();

        $this->actingAs($foreign, 'web')->deleteJson("/api/v1/departments/{$departmentId}")
            ->assertNotFound();

        $this->assertDatabaseHas('departments', ['id' => $departmentId, 'name' => 'Jurídico']);
    }

    public function test_crud_follows_role_and_update_syncs_members(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $operador = $this->memberOf($account, 'operador');
        $user = $this->memberOf($account, 'user');
        $ana = $this->memberOf($account, 'user', ['name' => 'Ana']);
        $beto = $this->memberOf($account, 'user', ['name' => 'Beto']);

        $departmentId = $this->actingAs($operador, 'web')->postJson('/api/v1/departments', [
            'name' => 'Jurídico',
            'color' => 'success',
            'member_ids' => [$ana->getKey()],
        ])->assertCreated()->json('data.id');

        $this->actingAs($operador, 'web')->getJson('/api/v1/departments')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Contábil')
            ->assertJsonFragment(['name' => 'Jurídico']);

        $this->actingAs($user, 'web')->getJson('/api/v1/departments')->assertOk();

        $this->actingAs($operador, 'web')->patchJson("/api/v1/departments/{$departmentId}", [
            'name' => 'Trabalhista',
            'member_ids' => [$beto->getKey()],
        ])->assertOk()->assertJsonPath('data.name', 'Trabalhista');

        $this->assertDatabaseMissing('department_user', [
            'department_id' => $departmentId,
            'user_id' => $ana->getKey(),
        ]);
        $this->assertDatabaseHas('department_user', [
            'department_id' => $departmentId,
            'user_id' => $beto->getKey(),
        ]);

        $this->actingAs($user, 'web')->patchJson("/api/v1/departments/{$departmentId}", [
            'name' => 'Bloqueado',
        ])->assertForbidden();

        $this->actingAs($user, 'web')->deleteJson("/api/v1/departments/{$departmentId}")
            ->assertForbidden();

        $this->actingAs($operador, 'web')->deleteJson("/api/v1/departments/{$departmentId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('departments', ['id' => $departmentId]);
        $this->assertDatabaseHas('users', ['id' => $ana->getKey()]);
        $this->assertDatabaseHas('users', ['id' => $beto->getKey()]);
    }

    public function test_support_writes_are_audited_requires_real_membership(): void
    {
        $targetAccount = Account::factory()->create();
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->selectBusinessAccount($superAdmin, $targetAccount->id);
        $this->actingAs($superAdmin)->postJson('/api/v1/departments', [])
            ->assertForbidden()->assertJsonPath('code', 'membership_required');
    }

    public function test_destroy_libera_etapas_que_referenciam_o_departamento(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $operador = $this->memberOf($account, 'operador');
        $this->actingAs($operador, 'web');

        $department = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();

        $template = ProcessTemplate::factory()->create(['account_id' => $account->getKey()]);
        $step = $template->steps()->create([
            'account_id' => $account->getKey(),
            'title' => 'Apurar', 'department_id' => $department->getKey(),
            'due_day' => 3, 'priority' => 'medium', 'order' => 1,
        ]);

        // Excluir nunca bloqueia por uso: a etapa sobrevive sem departamento.
        $this->deleteJson("/api/v1/departments/{$department->getKey()}")
            ->assertNoContent();

        $this->assertDatabaseMissing('departments', ['id' => $department->getKey()]);
        $this->assertDatabaseHas('process_template_tasks', [
            'id' => $step->getKey(),
            'title' => 'Apurar',
            'department_id' => null,
        ]);
    }

    public function test_destroy_libera_tasks_abertas_e_fechadas(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $operador = $this->memberOf($account, 'operador');
        $this->actingAs($operador, 'web');

        $department = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Pessoal')
            ->sole();

        $open = Task::factory()->create([
            'account_id' => $account->getKey(),
            'department_id' => $department->getKey(),
            'status' => 'doing',
            'title' => 'Aberta',
            'due_on' => '2026-03-10',
        ]);
        Task::factory()->create([
            'account_id' => $account->getKey(),
            'department_id' => $department->getKey(),
            'status' => 'done',
        ]);

        // Task aberta não segura a exclusão: 204 e as duas ficam sem departamento.
        $this->deleteJson("/api/v1/departments/{$department->getKey()}")
            ->assertNoContent();

        $this->assertDatabaseMissing('departments', ['id' => $department->getKey()]);
        $this->assertDatabaseCount('tasks', 2);
        // due_on persiste como datetime no SQLite.
        $this->assertDatabaseHas('tasks', [
            'id' => $open->getKey(),
            'department_id' => null,
            'status' => 'doing',
            'title' => 'Aberta',
            'due_on' => '2026-03-10 00:00:00',
        ]);
    }

    public function test_payload_da_task_sem_departamento_traz_department_null(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $operador = $this->memberOf($account, 'operador');
        $this->actingAs($operador, 'web');

        $department = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Pessoal')
            ->sole();

        $task = Task::factory()->create([
            'account_id' => $account->getKey(),
            'department_id' => $department->getKey(),
        ]);

        $this->deleteJson("/api/v1/departments/{$department->getKey()}")
            ->assertNoContent();

        $this->getJson("/api/v1/tasks/{$task->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.department_id', null)
            ->assertJsonPath('data.department', null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function memberOf(Account $account, string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
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
