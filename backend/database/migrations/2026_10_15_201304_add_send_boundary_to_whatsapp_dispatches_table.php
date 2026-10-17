<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_dispatches', function (Blueprint $table): void {
            $table->timestamp('send_attempted_at')->nullable();
            // Sem evidência histórica de pré-envio, linhas legadas são incertas.
            $table->boolean('retry_safe')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_dispatches', function (Blueprint $table): void {
            $table->dropColumn(['send_attempted_at', 'retry_safe']);
        });
    }
};
