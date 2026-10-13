<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O dedupe dos recibos do wzap: uma linha por `event_id` já processado.
     * Guarda-se só o que o Rastreio precisa — canal, evento e o disparo que
     * ele atualizou — porque o corpo do webhook pode carregar texto de
     * mensagem e não deve ser persistido.
     */
    public function up(): void
    {
        Schema::create('whatsapp_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_channel_id')->constrained()->cascadeOnDelete();
            $table->string('event_id');
            $table->foreignId('whatsapp_dispatch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['whatsapp_channel_id', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');
    }
};
