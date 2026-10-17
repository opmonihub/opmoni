<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPaymentFieldsToMdvesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mdves', function (Blueprint $table) {
            // Campos obrigatórios para carga lotação (NT 2025.001)
            $table->string('responsavel_pag_nome', 100)->nullable()->comment('Nome do responsável pelo pagamento');
            $table->string('responsavel_pag_cpf_cnpj', 18)->nullable()->comment('CPF/CNPJ do responsável pelo pagamento');
            $table->decimal('valor_frete', 10, 2)->nullable()->comment('Valor do componente frete');
            $table->string('tipo_componente', 2)->default('04')->comment('Tipo do componente (04=Frete)');
            $table->decimal('valor_contrato', 10, 2)->nullable()->comment('Valor total do contrato');
            $table->tinyInteger('indicador_pagamento')->nullable()->comment('0=À vista, 1=A prazo');

            // Campos condicionais para pagamento a prazo
            $table->string('numero_parcela', 3)->nullable()->comment('Número da parcela (001, 002...)');
            $table->date('data_vencimento')->nullable()->comment('Data de vencimento da parcela');
            $table->decimal('valor_parcela', 10, 2)->nullable()->comment('Valor da parcela');

            // Campos condicionais para transportador autônomo
            $table->string('codigo_banco', 3)->nullable()->comment('Código do banco (001, 237...)');
            $table->string('codigo_agencia', 10)->nullable()->comment('Código da agência bancária');
            $table->string('cnpj_ipef', 14)->nullable()->comment('CNPJ da Instituição de Pagamento Eletrônico do Frete (PIX)');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('mdves', function (Blueprint $table) {
            $table->dropColumn([
                'responsavel_pag_nome',
                'responsavel_pag_cpf_cnpj',
                'valor_frete',
                'tipo_componente',
                'valor_contrato',
                'indicador_pagamento',
                'numero_parcela',
                'data_vencimento',
                'valor_parcela',
                'codigo_banco',
                'codigo_agencia',
            ]);
        });
    }
}
