<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O `whatsapp_id` (wamid) que os recibos do wzap carregam — o
     * `message_id` gravado pelo job é o UUID interno da fila do gateway,
     * e o wamid só chega no evento `message.status` (`whatsapp_id`).
     * Sem esta coluna, um recibo nunca casaria com a linha do Rastreio.
     */
    public function up(): void
    {
        Schema::table('whatsapp_dispatches', function (Blueprint $table): void {
            $table->string('whatsapp_id')->nullable()->after('message_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_dispatches', function (Blueprint $table): void {
            $table->dropIndex(['whatsapp_id']);
            $table->dropColumn('whatsapp_id');
        });
    }
};
