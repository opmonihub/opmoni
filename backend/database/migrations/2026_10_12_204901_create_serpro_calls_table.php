<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O registro auditável de cada chamada ao Integra Contador — o que se
     * reconcilia com o relatório de consumo do provedor.
     *
     * `client_id` é nullable porque existem chamadas da conta como um todo
     * (o envio do termo é o exemplo), e `run_id` é nullable porque uma
     * chamada fora de execução continua sendo uma chamada cobrada. Os dois
     * ficam `nullOnDelete`: apagar o cliente ou a execução não apaga o fato
     * de que a cota foi gasta — que é exatamente o que este registro existe
     * para provar.
     *
     * Sem coluna para o corpo: `dados` carrega o documento do cliente dentro
     * do envelope, e a auditoria precisa da tag, do identificador de resposta
     * e da duração — nunca do payload.
     */
    public function up(): void
    {
        Schema::create('serpro_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('serpro_sync_runs')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('id_sistema', 40);
            $table->string('id_servico', 60);
            $table->string('version', 10);
            $table->string('path', 20);
            $table->boolean('billable')->default(true);
            $table->string('status', 20);
            $table->string('provider_code', 80)->nullable();
            $table->string('response_id', 80)->nullable();
            $table->string('request_tag', 32);
            $table->json('messages')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'id']);
            $table->index(['account_id', 'id_servico']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_calls');
    }
};
