<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O dia do mês em que cada documento da conta sincroniza sozinho. A
     * unique `(account_id, obligation)` é a agenda em si: um documento, um
     * dia — mudou o dia, a linha muda; documento sem linha é documento sem
     * busca automática. O teto de 28 é o que garante que o dia existe em
     * todos os meses do ano.
     */
    public function up(): void
    {
        Schema::create('serpro_obligation_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('obligation');
            $table->unsignedTinyInteger('day');
            $table->timestamps();

            $table->unique(['account_id', 'obligation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_obligation_schedules');
    }
};
