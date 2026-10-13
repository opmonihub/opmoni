<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * As linhas `name`-only são placeholder de um CRUD que nunca teve
     * consumidor: elas não têm cliente nem obrigação para migrar para, e
     * "preservar" seria carregar lixo estrutural para a tabela nova. A perda
     * é planejada e o `down()` a registra de propósito: a coluna volta, as
     * linhas não.
     *
     * O `unique (account_id, client_id, obligation)` é a barreira do
     * re-sync: duas execuções que escrevam a mesma projeção ao mesmo tempo
     * produzem uma linha e uma violação tratável, e nunca duas linhas que a
     * tela teria de escolher entre elas.
     */
    public function up(): void
    {
        DB::table('serpro_monitorings')->delete();

        Schema::table('serpro_monitorings', function (Blueprint $table): void {
            $table->dropColumn('name');
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('obligation', 80);
            $table->string('state', 30)->default('sem_dados');
            $table->string('cause', 60)->nullable();
            $table->date('due_on')->nullable();
            $table->json('fields')->nullable();
            $table->json('periods')->nullable();
            $table->json('messages')->nullable();
            $table->timestamp('source_at')->nullable();
            $table->unique(['account_id', 'client_id', 'obligation']);
        });
    }

    /**
     * Reverte o formato, não o conteúdo: as linhas apagadas pelo `up()` são
     * placeholder sem dono, e o `down()` não inventa cliente para elas.
     */
    public function down(): void
    {
        Schema::table('serpro_monitorings', function (Blueprint $table): void {
            $table->dropUnique(['account_id', 'client_id', 'obligation']);
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn([
                'obligation',
                'state',
                'cause',
                'due_on',
                'fields',
                'periods',
                'messages',
                'source_at',
            ]);
            $table->string('name')->nullable();
        });
    }
};
