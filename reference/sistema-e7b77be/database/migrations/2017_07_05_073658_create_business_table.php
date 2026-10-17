<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBusinessTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('business', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->increments('id');
            $table->string('name');
            $table->integer('currency_id')->unsigned();
            $table->date('start_date')->nullable();
            $table->string('tax_number_1', 100);
            $table->string('tax_label_1', 10);
            $table->string('tax_number_2', 100)->nullable();
            $table->string('tax_label_2', 10)->nullable();
            $table->float('default_profit_percent', 5, 2)->default(0);
            $table->integer('owner_id')->unsigned();
            $table->foreign('owner_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('time_zone')->default('America/Sao_Paulo');
            $table->tinyInteger('fy_start_month')->default(1);
            $table->enum('accounting_method', ['fifo', 'lifo', 'avco'])->default('fifo');
            $table->decimal('default_sales_discount', 5, 2)->nullable();
            $table->enum('sell_price_tax', ['includes', 'excludes'])->default('includes');
            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->string('logo')->nullable();
            $table->string('sku_prefix')->nullable();
            $table->boolean('enable_tooltip')->default(1);

            $table->string('razao_social', 120)->default('*');
            $table->string('cnpj', 20)->default('00.000.000/0000-00');
            $table->string('ie', 15)->default('00000000000');
            $table->string('senha_certificado', 100)->default('1234');
            $table->binary('certificado');

            $table->integer('cidade_id')->nullable()->unsigned();
            $table->foreign('cidade_id')->references('id')->on('cities')->onDelete('cascade');

            $table->string('rua', 60)->default('*');
            $table->string('numero', 10)->default('*');
            $table->string('bairro', 30)->default('*');
            $table->string('cep', 10)->default('00000-000');
            $table->string('telefone', 14)->default('00 00000-0000');

            $table->integer('ultimo_numero_nfe')->default(0);
            $table->integer('ultimo_numero_nfce')->default(0);
            $table->integer('ultimo_numero_cte')->default(0);
            $table->integer('ultimo_numero_mdfe')->default(0);
            $table->string('inscricao_municipal', 15)->default("");
            
            $table->integer('numero_serie_nfe')->default(1);
            $table->integer('numero_serie_nfce')->default(1);
            $table->string('numero_serie_cte', 3)->default('1');
            $table->string('numero_serie_mdfe', 3)->default('1');
            $table->integer('ambiente')->default(2);
            $table->integer('regime')->default(1);

            $table->integer('cst_csosn_padrao')->default('101');
            $table->integer('cst_cofins_padrao')->default('49');
            $table->integer('cst_pis_padrao')->default('49');
            $table->integer('cst_ipi_padrao')->default('99');
            $table->string('natureza_operacao_pdv', 255)->nullable();

            $table->decimal('perc_icms_padrao', 5, 2)->default(0);
            $table->decimal('perc_pis_padrao', 5, 2)->default(0);
            $table->decimal('perc_cofins_padrao', 5, 2)->default(0);
            $table->decimal('perc_ipi_padrao', 5, 2)->default(0);
            $table->decimal('pCredSN', 5, 2)->default(0);
            $table->boolean('exclusao_icms_pis_cofins')->default(0);

            $table->string('ncm_padrao', 12)->default('');
            $table->string('cfop_saida_estadual_padrao', 4)->default('');
            $table->string('cfop_saida_inter_estadual_padrao', 4)->default('');

            $table->string('csc', 70)->default('');
            $table->string('csc_id', 10)->default('');

            $table->string('aut_xml', 18)->default('');
            $table->string('certificado_urn', 191)->default('');

            $table->integer('casas_decimais_valor')->default(2);

            // $table->string('natureza_operacao_pdv', 255)->nullable();

            // alter table business add column aut_xml varchar(18) default '';
            // alter table business add column casas_decimais_valor integer default 2;

            // alter table business add column numero_serie_cte varchar(3) default '1';
            // alter table business add column numero_serie_mdfe varchar(3) default '1';
            // alter table business add column certificado_urn varchar(191) default '1';
            // alter table business add column pCredSN decimal(5,2) default 0;
            // alter table business add column exclusao_icms_pis_cofins boolean default 0;
            // alter table business add column natureza_operacao_pdv varchar(255) default null;
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('business');
    }
}
