<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fiscal_gaps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->unsignedBigInteger('nsu');
            $table->unsignedTinyInteger('attempts')->default(0);
            // Nulo é "devida agora": a linha nasce devida, e só uma consulta
            // que saiu de fato adia a próxima. Uma data no criação diria que a
            // posição já esperou antes de existir.
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            // A identidade da lacuna é a posição, e ela é única por cliente e
            // fonte: reencontrar o mesmo buraco em outra captura atualiza a
            // linha em vez de criar uma segunda, que é o que faria a contagem
            // de tentativas virar duas histórias para a mesma posição.
            $table->unique(['client_id', 'source', 'nsu']);

            // A varredura que pergunta "quem tem buraco" é por conta e por
            // fonte, e o que ordena o estudo é a data da próxima tentativa.
            $table->index(['account_id', 'source', 'next_attempt_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_gaps');
    }
};
