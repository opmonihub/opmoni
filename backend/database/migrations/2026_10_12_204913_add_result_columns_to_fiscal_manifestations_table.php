<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O veredito do evento no registro de manifestação: o cStat que o
     * `retEvento` devolveu e o `xMotivo` já condensado. São `nullable` porque o
     * evento só tem veredito depois que o fisco responde — um registro
     * `pending` ainda não tem o que mostrar.
     */
    public function up(): void
    {
        Schema::table('fiscal_manifestations', function (Blueprint $table): void {
            $table->string('result_code', 3)->nullable()->after('outcome');
            $table->string('result_message')->nullable()->after('result_code');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_manifestations', function (Blueprint $table): void {
            $table->dropColumn(['result_code', 'result_message']);
        });
    }
};
