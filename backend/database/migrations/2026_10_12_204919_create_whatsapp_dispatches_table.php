<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma linha de Rastreio por envio lógico: conta × cliente × obrigação ×
     * artefato × competência. A idempotência do Disparo é a unique de
     * (`account_id`, `idempotency_key`) — a chave é um hash determinístico
     * com janela de 24 h, e o segundo clique dentro dela reencontra a linha
     * existente em vez de criar um segundo envio ao wzap.
     *
     * `failure_reason` é sempre texto seguro para a UI: nunca corpo de
     * resposta, apikey ou dado fiscal. `dispatched_in_support` + `support_access_log_id`
     * ligam o envio à auditoria quando o ator é suporte em conta alheia.
     */
    public function up(): void
    {
        Schema::create('whatsapp_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('obligation', 40);
            $table->string('artifact_type', 20);
            $table->string('competence', 7);
            $table->string('idempotency_key', 64);
            $table->string('message_id')->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('failure_reason', 200)->nullable();
            $table->foreignId('dispatched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('dispatched_in_support')->default(false);
            $table->foreignId('support_access_log_id')->nullable()->constrained('support_access_logs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['account_id', 'idempotency_key']);
            $table->index('message_id');
            $table->index(['account_id', 'client_id', 'obligation', 'competence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_dispatches');
    }
};
