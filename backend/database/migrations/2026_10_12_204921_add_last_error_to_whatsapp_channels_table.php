<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O motivo do `status = error`, persistido para leituras futuras.
     *
     * A resposta do wzap traz `last_error` ao vivo, mas falhas de
     * transporte/configuração (`ensureInstance` antes da instância existir,
     * timeout) nunca chegam ao gateway — sem a coluna, a tela lia
     * `status = error` com `last_error = null` no próximo GET. O texto que
     * entra aqui é só o motivo sanitizado da `WzapException` (code +
     * message do envelope), nunca corpo bruto nem apikey.
     */
    public function up(): void
    {
        Schema::table('whatsapp_channels', function (Blueprint $table): void {
            $table->text('last_error')->nullable()->after('connected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_channels', function (Blueprint $table): void {
            $table->dropColumn('last_error');
        });
    }
};
