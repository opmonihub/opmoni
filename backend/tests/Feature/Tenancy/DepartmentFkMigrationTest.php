<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\Department;
use App\Models\Process;
use App\Models\ProcessTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A migração de dados do departments-fk: etapas e tasks guardavam o
 * departamento como texto livre e passam a apontar para um registro.
 * O teste simula o estado legado (coluna `department` com texto) e roda
 * só a migration nova por cima.
 */
class DepartmentFkMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migracao_resolve_texto_por_nome_cria_faltantes_e_preenche_fks(): void
    {
        $account = Account::factory()->create();
        $other = Account::factory()->create();

        // Volta as duas tabelas ao estado legado: texto livre em `department`,
        // sem a FK que a migration nova ainda vai criar.
        $this->backToTextColumns();

        // A Account já nasce com os 4 padrão; o teste simula o estado legado
        // apenas no que diz respeito às colunas de texto nas etapas e tasks.
        $fiscal = DB::table('departments')
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->value('id');
        DB::table('departments')
            ->where('account_id', $other->getKey())
            ->where('name', 'Fiscal')
            ->update(['color' => 'info']);

        $templateId = DB::table('process_templates')->insertGetId([
            'account_id' => $account->getKey(),
            'name' => 'PGDAS',
            'cascade' => false,
            'generate_day' => 1,
            'due_day' => 20,
            'is_active' => true,
            'regimes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $processId = DB::table('processes')->insertGetId([
            'account_id' => $account->getKey(),
            'name' => 'Março',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Nome existente, mas digitado em outra caixa: casa com o cadastrado.
        $matchStepId = DB::table('process_template_tasks')->insertGetId([
            'account_id' => $account->getKey(),
            'template_id' => $templateId,
            'title' => 'Apurar',
            'department' => '  fIScaL ',
            'due_day' => 3,
            'priority' => 'medium',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Nome inexistente: vira departamento novo na mesma Account.
        $newStepId = DB::table('process_template_tasks')->insertGetId([
            'account_id' => $account->getKey(),
            'template_id' => $templateId,
            'title' => 'Legalizar',
            'department' => 'Legalização',
            'due_day' => 5,
            'priority' => 'medium',
            'order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Texto vazio: department_id null.
        $emptyStepId = DB::table('process_template_tasks')->insertGetId([
            'account_id' => $account->getKey(),
            'template_id' => $templateId,
            'title' => 'Avulsa',
            'department' => '   ',
            'due_day' => 7,
            'priority' => 'medium',
            'order' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Nome com mais de 40 caracteres: cortado em 40 no departamento criado.
        $longName = str_repeat('DepartamentoX', 4); // 52 chars
        $longStepId = DB::table('process_template_tasks')->insertGetId([
            'account_id' => $account->getKey(),
            'template_id' => $templateId,
            'title' => 'Longa',
            'department' => $longName,
            'due_day' => 9,
            'priority' => 'medium',
            'order' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $taskMatchId = DB::table('tasks')->insertGetId([
            'account_id' => $account->getKey(),
            'process_id' => $processId,
            'title' => 'Task fiscal',
            'department' => 'FISCAL',
            'status' => 'todo',
            'priority' => 'medium',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $taskNewId = DB::table('tasks')->insertGetId([
            'account_id' => $account->getKey(),
            'process_id' => $processId,
            'title' => 'Task legalização',
            'department' => 'legalização',
            'status' => 'todo',
            'priority' => 'medium',
            'order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Departamento com o mesmo nome em outra Account não interfere.
        $otherProcessId = DB::table('processes')->insertGetId([
            'account_id' => $other->getKey(),
            'name' => 'Março',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignTaskId = DB::table('tasks')->insertGetId([
            'account_id' => $other->getKey(),
            'process_id' => $otherProcessId,
            'title' => 'Task alheia',
            'department' => 'Fiscal',
            'status' => 'todo',
            'priority' => 'medium',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runNewMigration();

        $this->assertTrue(Schema::hasColumn('process_template_tasks', 'department_id'));
        $this->assertTrue(Schema::hasColumn('tasks', 'department_id'));
        $this->assertFalse(Schema::hasColumn('process_template_tasks', 'department'));
        $this->assertFalse(Schema::hasColumn('tasks', 'department'));

        // "  fIScaL " casou com o Fiscal cadastrado — nenhum departamento novo.
        $this->assertSame($fiscal, DB::table('process_template_tasks')->find($matchStepId)->department_id);
        $this->assertSame($fiscal, DB::table('tasks')->find($taskMatchId)->department_id);

        // "Legalização" e "legalização" viram um departamento só, cor neutral.
        $legalizacao = DB::table('departments')
            ->where('account_id', $account->getKey())
            ->whereRaw('LOWER(name) = ?', ['legalização'])
            ->sole();
        $this->assertSame('neutral', $legalizacao->color);
        $this->assertSame($legalizacao->id, DB::table('process_template_tasks')->find($newStepId)->department_id);
        $this->assertSame($legalizacao->id, DB::table('tasks')->find($taskNewId)->department_id);

        // Texto vazio virou null; nome >40 chars virou departamento cortado.
        $this->assertNull(DB::table('process_template_tasks')->find($emptyStepId)->department_id);

        $truncated = DB::table('departments')
            ->where('account_id', $account->getKey())
            ->where('name', mb_substr($longName, 0, 40))
            ->sole();
        $this->assertSame($truncated->id, DB::table('process_template_tasks')->find($longStepId)->department_id);

        // A task da outra Account aponta para o Fiscal dela, não para o nosso.
        $foreignFiscal = DB::table('departments')
            ->where('account_id', $other->getKey())
            ->where('name', 'Fiscal')
            ->sole();
        $this->assertSame($foreignFiscal->id, DB::table('tasks')->find($foreignTaskId)->department_id);
    }

    public function test_migracao_preserva_departamentos_distintos_com_o_mesmo_prefixo_longo(): void
    {
        $account = Account::factory()->create();
        $template = ProcessTemplate::factory()->create(['account_id' => $account->getKey()]);
        $process = Process::factory()->create(['account_id' => $account->getKey()]);
        $this->backToTextColumns();

        $prefix = str_repeat('á', 40);
        $firstName = $prefix.' Norte';
        $secondName = $prefix.' Sul';
        $firstStepId = $this->insertLegacyStep($account->getKey(), $template->getKey(), $firstName, 1);
        $secondStepId = $this->insertLegacyStep($account->getKey(), $template->getKey(), $secondName, 2);
        $firstTaskId = $this->insertLegacyTask($account->getKey(), $process->getKey(), '  '.mb_strtoupper($firstName).'  ', 1);
        $secondTaskId = $this->insertLegacyTask($account->getKey(), $process->getKey(), $secondName, 2);

        $this->runNewMigration();

        $firstId = DB::table('process_template_tasks')->find($firstStepId)->department_id;
        $secondId = DB::table('process_template_tasks')->find($secondStepId)->department_id;
        $this->assertNotSame($firstId, $secondId);
        $this->assertSame($firstId, DB::table('tasks')->find($firstTaskId)->department_id);
        $this->assertSame($secondId, DB::table('tasks')->find($secondTaskId)->department_id);

        $departments = DB::table('departments')->whereIn('id', [$firstId, $secondId])->get();
        foreach ($departments as $department) {
            $this->assertSame($account->getKey(), $department->account_id);
            $this->assertSame('neutral', $department->color);
            $this->assertLessThanOrEqual(40, mb_strlen($department->name));
        }
    }

    public function test_migracao_preserva_o_departamento_existente_quando_o_nome_longo_colide_com_seu_prefixo(): void
    {
        $account = Account::factory()->create();
        $prefix = str_repeat('á', 40);
        $existing = Department::factory()->create([
            'account_id' => $account->getKey(),
            'name' => $prefix,
            'color' => 'info',
        ]);
        $occupied = Department::factory()->create([
            'account_id' => $account->getKey(),
            'name' => str_repeat('á', 36).' (1)',
            'color' => 'warning',
        ]);
        $template = ProcessTemplate::factory()->create(['account_id' => $account->getKey()]);
        $process = Process::factory()->create(['account_id' => $account->getKey()]);
        $this->backToTextColumns();
        $stepId = $this->insertLegacyStep($account->getKey(), $template->getKey(), $prefix.' Norte', 1);
        $taskId = $this->insertLegacyTask($account->getKey(), $process->getKey(), $prefix, 1);

        $this->runNewMigration();

        $createdId = DB::table('process_template_tasks')->find($stepId)->department_id;
        $created = DB::table('departments')->find($createdId);
        $this->assertNotSame($existing->getKey(), $createdId);
        $this->assertSame($existing->getKey(), DB::table('tasks')->find($taskId)->department_id);
        $this->assertSame($account->getKey(), $created->account_id);
        $this->assertLessThanOrEqual(40, mb_strlen($created->name));
        $this->assertDatabaseHas('departments', [
            'id' => $existing->getKey(),
            'name' => $prefix,
            'color' => 'info',
        ]);
        $this->assertDatabaseHas('departments', [
            'id' => $occupied->getKey(),
            'name' => str_repeat('á', 36).' (1)',
            'color' => 'warning',
        ]);
    }

    public function test_migracao_nao_une_o_nome_longo_ao_prefixo_que_existe_apenas_nas_tarefas_legadas(): void
    {
        $account = Account::factory()->create();
        $template = ProcessTemplate::factory()->create(['account_id' => $account->getKey()]);
        $process = Process::factory()->create(['account_id' => $account->getKey()]);
        $this->backToTextColumns();
        $prefix = str_repeat('á', 40);
        $stepId = $this->insertLegacyStep($account->getKey(), $template->getKey(), $prefix.' Norte', 1);
        $taskId = $this->insertLegacyTask($account->getKey(), $process->getKey(), $prefix, 1);

        $this->runNewMigration();

        $stepDepartmentId = DB::table('process_template_tasks')->find($stepId)->department_id;
        $taskDepartmentId = DB::table('tasks')->find($taskId)->department_id;
        $this->assertNotSame($stepDepartmentId, $taskDepartmentId);
        $this->assertSame($prefix, DB::table('departments')->find($taskDepartmentId)->name);
        $this->assertLessThanOrEqual(40, mb_strlen(DB::table('departments')->find($stepDepartmentId)->name));
    }

    private function insertLegacyStep(int $accountId, int $templateId, string $department, int $order): int
    {
        return (int) DB::table('process_template_tasks')->insertGetId([
            'account_id' => $accountId,
            'template_id' => $templateId,
            'title' => 'Etapa '.$order,
            'department' => $department,
            'due_day' => 5,
            'priority' => 'medium',
            'order' => $order,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLegacyTask(int $accountId, int $processId, string $department, int $order): int
    {
        return (int) DB::table('tasks')->insertGetId([
            'account_id' => $accountId,
            'process_id' => $processId,
            'title' => 'Tarefa '.$order,
            'department' => $department,
            'status' => 'todo',
            'priority' => 'medium',
            'order' => $order,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverte as duas tabelas ao esquema legado para que a migration nova
     * possa ser exercitada como em produção.
     */
    private function backToTextColumns(): void
    {
        foreach (['process_template_tasks', 'tasks'] as $table) {
            if (Schema::hasColumn($table, 'department_id')) {
                Schema::table($table, function ($blueprint): void {
                    $blueprint->dropConstrainedForeignId('department_id');
                });
            }

            if (! Schema::hasColumn($table, 'department')) {
                Schema::table($table, function ($blueprint): void {
                    $blueprint->string('department')->default('Fiscal')->after('title');
                });
            }
        }
    }

    /**
     * Carrega e executa a migration nova, localizada pelo sufixo de arquivo
     * fora das migrations de criação já rodadas pelo RefreshDatabase.
     */
    private function runNewMigration(): void
    {
        $files = glob(database_path('migrations/*department*fk*.php'))
            ?: glob(database_path('migrations/*departments_fk*.php'))
            ?: glob(database_path('migrations/*department_id*.php'));

        $this->assertNotEmpty(
            $files,
            'Migration do departments-fk não encontrada em database/migrations.'
        );

        sort($files);
        $migration = require end($files);
        $migration->up();
    }
}
