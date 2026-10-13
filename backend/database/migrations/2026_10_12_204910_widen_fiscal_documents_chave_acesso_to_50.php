<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A chave de acesso da NFS-e nacional tem **50** posições, e a coluna nasceu
     * com 44 — a largura da NF-e e do CT-e. Sem alargar, o primeiro documento
     * real da ADN não entra: o `INSERT` falha com truncamento, e o conector
     * novo "funciona" na API enquanto grava nada.
     *
     * Os índices não mudam: a unique `(client_id, chave_acesso, stage, event_id)`
     * e os dois índices secundários acompanham a coluna — em Postgres a alteração
     * reescreve a coluna e preserva os índices; no SQLite (a suíte) o Laravel
     * recria a tabela e os índices com ela. É por isso que a alteração aqui é da
     * coluna só, e nenhum `dropUnique`/`unique` aparece de propósito.
     */
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->char('chave_acesso', 50)->change();
        });
    }

    /**
     * A reversão é para ambiente de desenvolvimento: com qualquer chave de 50
     * posições já gravada, voltar a 44 truncaria identidade fiscal — e é por
     * isso que ela não faz parte do plano de rollback do change, que é
     * desligar `nfse_enabled`, e não restringir a coluna.
     */
    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->char('chave_acesso', 44)->change();
        });
    }
};
