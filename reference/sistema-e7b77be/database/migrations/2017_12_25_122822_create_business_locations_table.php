<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBusinessLocationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('business_locations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->string('name', 256);
            $table->text('landmark')->nullable();
            $table->string('country', 100);
            $table->string('state', 100);
            $table->string('city', 100);
            $table->char('zip_code', 10);
            $table->string('mobile')->nullable();
            $table->string('alternate_number')->nullable();
            $table->string('email')->nullable();

            $table->string('razao_social', 120)->default('*');
            $table->string('cnpj', 20)->default('00.000.000/0000-00');
            $table->string('ie', 15)->default('00000000000');
            $table->string('senha_certificado', 100)->default('1234');
            $table->binary('certificado');

            $table->integer('cidade_id')->nullable()->unsigned()->default(NULL);
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

            $table->decimal('perc_icms_padrao', 5, 2)->default(0);
            $table->decimal('perc_pis_padrao', 5, 2)->default(0);
            $table->decimal('perc_cofins_padrao', 5, 2)->default(0);
            $table->decimal('perc_ipi_padrao', 5, 2)->default(0);

            $table->string('ncm_padrao', 12)->default('');
            $table->string('cfop_saida_estadual_padrao', 4)->default('');
            $table->string('cfop_saida_inter_estadual_padrao', 4)->default('');

            $table->string('csc', 70)->default('');
            $table->string('aut_xml', 18)->default('');
            $table->string('csc_id', 10)->default('');
            $table->string('info_complementar', 255)->default('');
            
            $table->softDeletes();
            $table->timestamps();

            // alter table business_locations add column aut_xml varchar(18) default '';

            // alter table business_locations add column numero_serie_cte varchar(3) default '1';
            // alter table business_locations add column numero_serie_mdfe varchar(3) default '1';
            // Indexing
            $table->index('business_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('business_locations');
    }
}
