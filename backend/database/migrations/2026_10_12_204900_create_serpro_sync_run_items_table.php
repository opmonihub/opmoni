<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Um item por cliente por execução. O `unique (run_id, client_id)` é a
     * idempotência do job filho: a reentrega da fila atualiza a linha em vez
     * de criar uma segunda, e é o índice — e não o `firstOrCreate` — que o
     * garante quando duas entregas correm ao mesmo tempo.
     *
     * `current_obligation` e `attempted_at` são o marcador de fronteira: o
     * par preenchido significa "uma requisição pode ter chegado ao gateway",
     * e é o que distingue reentrega segura de reenvio que duplicaria efeito.
     */
    public function up(): void
    {
        Schema::create('serpro_sync_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('serpro_sync_runs')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('state', 20)->default('nao_processado');
            $table->string('current_obligation', 80)->nullable();
            $table->string('reason', 200)->nullable();
            $table->string('provider_code', 80)->nullable();
            $table->string('response_id', 80)->nullable();
            $table->string('request_tag', 32)->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'client_id']);
            $table->index(['account_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_sync_run_items');
    }
};
