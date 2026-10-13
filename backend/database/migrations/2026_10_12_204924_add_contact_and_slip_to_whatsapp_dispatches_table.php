<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O Disparo passa a nascer do Contato de envio, não do telefone solto
     * do cliente: `client_dispatch_contact_id` liga a linha ao destino
     * escolhido no modal Enviar guias. Nullable porque os disparos do
     * fluxo legado (`client.phone`) e os anteriores à coluna não têm
     * contato.
     *
     * A coluna fica **sem** `constrained()`/`nullOnDelete` de propósito:
     * um `SET NULL` apagaria a evidência de que o destino era um contato
     * e o job cairia no telefone da carteira — um número que o membro
     * não marcou. Preservando o id órfão, o job distingue "linha que
     * nasceu com contato" de "linha legada" e falha com motivo legível
     * quando o contato some; o Rastreio também mantém a referência. A
     * integridade é lógica (service/job conferem `account_id`), não uma
     * FK que impediria deletar o contato.
     *
     * `slip_number` é o identificador da guia lógica enviada — o número
     * do DAS quando o período o tem, `null` quando a guia veio de
     * `declared_at` (GERARDAS12) sem número sincronizado. É ele que a
     * regra "sem reenvio da mesma guia" compara: `slip_number` diferente
     * é recálculo e libera o envio de novo.
     */
    public function up(): void
    {
        Schema::table('whatsapp_dispatches', function (Blueprint $table): void {
            // Sem `constrained()`: um SET NULL apagaria a evidência do
            // destino escolhido; o id órfão é o que o job usa para falhar
            // com motivo em vez de enviar ao telefone errado.
            $table->unsignedBigInteger('client_dispatch_contact_id')
                ->nullable()
                ->after('client_id')
                ->index();
            $table->string('slip_number', 30)->nullable()->after('competence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_dispatches', function (Blueprint $table): void {
            $table->dropColumn('client_dispatch_contact_id');
            $table->dropColumn('slip_number');
        });
    }
};
