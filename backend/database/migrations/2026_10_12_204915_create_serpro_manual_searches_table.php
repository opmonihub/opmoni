<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O pedido de busca sob demanda: um registro por `conta × cliente ×
     * obrigação`, e é ele — e não a execução — que a cota mensal conta. O
     * índice é o da contagem de cota: agrupar por cliente no mês, dentro da
     * conta e da obrigação, é a pergunta que o endpoint responde a cada POST.
     *
     * `mode` e `recalculate_date` são o que o modal pediu e o provedor pode
     * ainda não saber responder: o modo é metadado da leitura e a data de
     * recálculo fica registrada mesmo enquanto nenhum serviço documentado
     * aceitar recorte de período.
     */
    public function up(): void
    {
        Schema::create('serpro_manual_searches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('obligation');
            $table->string('state', 20)->default('queued');
            $table->string('mode', 20)->default('full');
            $table->date('recalculate_date')->nullable();
            $table->string('reason', 200)->nullable();
            $table->timestamps();

            $table->index(['account_id', 'client_id', 'obligation', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_manual_searches');
    }
};
