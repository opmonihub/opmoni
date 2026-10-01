<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quem fez a chamada, quando houve alguém. A leitura de mensagem é
     * ciência da intimação (D19) e precisa de autor; a execução agendada não
     * tem usuário, por isso a coluna é nullable. `nullOnDelete` pelo mesmo
     * motivo de `run_id`: apagar o usuário não apaga o fato da chamada.
     */
    public function up(): void
    {
        Schema::table('serpro_calls', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('serpro_calls', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
