<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Etapas e tasks passam a referenciar o departamento por id: todo nome em
     * texto que não bate (trim, sem diferenciar caixa) com um departamento da
     * mesma Account vira um departamento novo com cor `neutral`, e a FK é
     * preenchida por par. `nullOnDelete` porque excluir o departamento nunca
     * bloqueia: as linhas ficam sem departamento. Roda sem tenant — toda
     * consulta carrega `account_id` explícito e ignora escopos globais.
     * Nomes longos recebem sufixo quando o corte em 40 caracteres colide,
     * sem juntar departamentos de nomes originais diferentes.
     */
    public function up(): void
    {
        foreach (['process_template_tasks', 'tasks'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('department_id')
                    ->nullable()
                    ->after('title')
                    ->constrained('departments')
                    ->nullOnDelete();
            });
        }

        $departmentsByName = [];
        $occupiedNames = [];
        $legacyNames = [];
        $pairsByTable = [];
        $nameKey = static fn (int $accountId, string $name): string => $accountId.'|'.mb_strtolower(trim($name));

        foreach (DB::table('departments')->get(['id', 'account_id', 'name']) as $department) {
            $key = $nameKey((int) $department->account_id, $department->name);
            $departmentsByName[$key] = (int) $department->id;
            $occupiedNames[$key] = true;
        }

        foreach (['process_template_tasks', 'tasks'] as $table) {
            $pairsByTable[$table] = DB::table($table)
                ->select('account_id', 'department')
                ->distinct()
                ->orderBy('account_id')
                ->orderBy('department')
                ->get();

            foreach ($pairsByTable[$table] as $row) {
                $legacyNames[$nameKey((int) $row->account_id, (string) $row->department)] = true;
            }
        }

        $resolveId = function (int $accountId, string $name) use (&$departmentsByName, &$occupiedNames, $legacyNames, $nameKey): int {
            $key = $nameKey($accountId, $name);

            if (array_key_exists($key, $departmentsByName)) {
                return $departmentsByName[$key];
            }

            $departmentName = mb_substr($name, 0, 40);
            $departmentKey = $nameKey($accountId, $departmentName);
            $suffixNumber = 1;

            // Reservar os nomes legados evita que um corte ocupe o nome real
            // de outro departamento que só será migrado na próxima tabela.
            while (isset($occupiedNames[$departmentKey]) || ($departmentKey !== $key && isset($legacyNames[$departmentKey]))) {
                $suffix = ' ('.$suffixNumber++.')';
                $departmentName = mb_substr($name, 0, 40 - mb_strlen($suffix)).$suffix;
                $departmentKey = $nameKey($accountId, $departmentName);
            }

            $id = (int) DB::table('departments')->insertGetId([
                'account_id' => $accountId,
                'name' => $departmentName,
                'color' => 'neutral',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $occupiedNames[$departmentKey] = true;

            return $departmentsByName[$key] = $id;
        };

        foreach ($pairsByTable as $table => $pairs) {
            foreach ($pairs as $row) {
                $name = trim((string) $row->department);

                if ($name === '') {
                    continue;
                }

                $id = $resolveId((int) $row->account_id, $name);

                DB::table($table)
                    ->where('account_id', (int) $row->account_id)
                    ->where('department', $row->department)
                    ->update(['department_id' => $id]);
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('department');
            });
        }
    }

    /**
     * Recria a coluna de texto copiando o nome atual pelo join e remove a FK.
     * Departamentos criados pelo `up` ficam — são dados válidos da Account.
     */
    public function down(): void
    {
        foreach (['process_template_tasks', 'tasks'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('department')->default('Fiscal')->after('title');
            });

            DB::table($table)
                ->join('departments', "{$table}.department_id", '=', 'departments.id')
                ->update(["{$table}.department" => DB::raw('departments.name')]);

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('department_id');
            });
        }
    }
};
