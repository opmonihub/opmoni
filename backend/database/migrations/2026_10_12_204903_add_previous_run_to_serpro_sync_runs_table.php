<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O re-sync é execução nova com histórico próprio, e o elo para a
     * anterior é esta coluna: sem ele, a tela não distingue "primeira
     * execução" de "retentativa da que falhou", e apagar o elo apagaria a
     * linha do tempo. `nullOnDelete` porque remover a execução anterior não
     * deve derrubar a que a sucedeu.
     */
    public function up(): void
    {
        Schema::table('serpro_sync_runs', function (Blueprint $table): void {
            $table->foreignId('previous_run_id')
                ->nullable()
                ->after('requested_by')
                ->constrained('serpro_sync_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('serpro_sync_runs', function (Blueprint $table): void {
            $table->dropForeign(['previous_run_id']);
            $table->dropColumn('previous_run_id');
        });
    }
};
