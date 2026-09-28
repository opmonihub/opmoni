<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A execução de sincronização é histórico consultável, e não estado de
     * fila: uma limpeza de cache ou o restart do worker não podem apagar o
     * que o escritório já viu acontecer.
     *
     * Os seis contadores são colunas porque a pergunta da tela é "quantos de
     * cada", e respondê-la por `groupBy` a cada leitura paginada mediria a
     * carteira inteira para mostrar um número. `total` é a soma dos outros
     * cinco — `indeterminate` e `not_processed` contam como considerados, o
     * que muda quando se conta e não se conta mais nada.
     */
    public function up(): void
    {
        Schema::create('serpro_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state', 20)->default('queued');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('synchronized')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('indeterminate')->default(0);
            $table->unsignedInteger('not_processed')->default(0);
            $table->string('reason', 200)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_sync_runs');
    }
};
