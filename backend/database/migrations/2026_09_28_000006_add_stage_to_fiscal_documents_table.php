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
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            // A etapa em que a entrega caiu na cadeia da distribuição: resumo,
            // documento completo ou evento. Sem esta coluna na chave, o resumo e
            // o documento autorizado — que são o mesmo documento, com a mesma
            // chave de acesso e o mesmo `event_id` vazio — ocupavam a mesma
            // linha, e o segundo apagava o XML, a posição e o digest do
            // primeiro. A spec exige as duas linhas.
            //
            // O default é `document` porque a tabela é pré-produção: a linha
            // legada, se houver alguma em banco de desenvolvimento, é o
            // documento completo sendo gravado. Numa tabela com dado real a
            // coluna entraria NOT NULL sem default e a migração pediria o
            // backfill das linhas existentes.
            $table->string('stage')->default('document')->after('kind');

            $table->dropUnique(['client_id', 'chave_acesso', 'event_id']);
            $table->unique(['client_id', 'chave_acesso', 'stage', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            // A unicidade antiga não comporta resumo e documento completo na
            // mesma chave: voltar a ela é voltar a um esquema que apaga uma das
            // duas entregas. A reversão é para ambiente de desenvolvimento.
            $table->dropUnique(['client_id', 'chave_acesso', 'stage', 'event_id']);
            $table->unique(['client_id', 'chave_acesso', 'event_id']);

            $table->dropColumn('stage');
        });
    }
};
