<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O espelho local do número de WhatsApp do escritório: uma linha por
     * conta (unique em `account_id`), porque a instância wzap é 1:1 com a
     * `Account` via `external_ref`.
     *
     * `instance_api_key_encrypted` guarda a apikey da instância cifrada com
     * `Crypt` — a chave em claro nunca toca o banco nem sai do backend. O
     * `status` repete o que o wzap informa; quem autoriza Disparo é o valor
     * gravado aqui.
     */
    public function up(): void
    {
        Schema::create('whatsapp_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('wzap_instance_id')->nullable();
            $table->text('instance_api_key_encrypted')->nullable();
            $table->string('status', 20)->default('disconnected');
            $table->timestamp('pairing_started_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_channels');
    }
};
