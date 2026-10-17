<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateItemDevolucaosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('item_devolucaos', function (Blueprint $table) {
            $table->increments('id');

            $table->string('cod', 10);
            $table->string('nome', 150);
            $table->string('ncm', 10);
            $table->string('cfop', 10);
            $table->string('codBarras', 13);
            $table->decimal('valor_unit', 10, 4);
            $table->decimal('quantidade', 10, 4);
            $table->boolean('item_parcial');    
            $table->string('unidade_medida', 8);  

            $table->string('cst_csosn', 3);   
            $table->string('cst_pis', 3);   
            $table->string('cst_cofins', 3);   
            $table->string('cst_ipi', 3);   
            $table->decimal('perc_icms');  
            $table->decimal('vICMS', 10, 2);  
            $table->decimal('perc_pis');   
            $table->decimal('perc_cofins');   
            $table->decimal('perc_ipi'); 
            $table->decimal('pRedBC'); 
            $table->decimal('vBC'); 

            $table->integer('devolucao_id')->unsigned();
            $table->foreign('devolucao_id')->references('id')->on('devolucaos')
            ->onDelete('cascade');

            $table->string('codigo_anp', 10)->default('');
            $table->string('descricao_anp', 95)->default('');
            $table->decimal('perc_glp', 5,2)->default(0);
            $table->decimal('perc_gnn', 5,2)->default(0);
            $table->decimal('perc_gni', 5,2)->default(0);
            $table->string('uf_cons', 2)->default('');
            $table->decimal('valor_partida', 10, 2)->default(0);
            $table->string('unidade_tributavel', 4)->default('');
            $table->decimal('quantidade_tributavel', 10, 2)->default(0);

            $table->decimal('vBCSTRet', 8, 2)->default(0);
            $table->decimal('vFrete', 8, 2)->default(0);
            $table->decimal('modBCST', 8, 2);
            $table->decimal('vBCST', 8, 2);
            $table->decimal('pICMSST', 8, 2);
            $table->decimal('vICMSST', 8, 2);
            $table->decimal('pMVAST', 8, 2);

            $table->integer('orig');
            $table->decimal('pST', 10, 2);
            $table->decimal('vICMSSubstituto', 10, 2);
            $table->decimal('vICMSSTRet', 10, 2);
            
            $table->decimal('vbcPis', 12, 2);
            $table->decimal('vbcCofins', 12, 2);
            $table->decimal('vbcIpi', 12, 2);

            // alter table item_devolucaos add column perc_glp decimal(5,2) default 0;
            // alter table item_devolucaos add column perc_gnn decimal(5,2) default 0;
            // alter table item_devolucaos add column perc_gni decimal(5,2) default 0;
            // alter table item_devolucaos add column codigo_anp varchar(10) default '';
            // alter table item_devolucaos add column descricao_anp varchar(95) default '';
            // alter table item_devolucaos add column uf_cons varchar(2) default '';
            // alter table item_devolucaos add column valor_partida decimal(10, 2) default 0;

            // alter table item_devolucaos add column unidade_tributavel varchar(4) default '';
            // alter table item_devolucaos add column quantidade_tributavel decimal(10, 2) default 0;

            // alter table item_devolucaos add column vBCSTRet decimal(8,2) default 0;
            // alter table item_devolucaos add column vFrete decimal(8,2) default 0;

            // alter table item_devolucaos add column modBCST decimal(8,2) default 0;
            // alter table item_devolucaos add column vBCST decimal(8,2) default 0;
            // alter table item_devolucaos add column pMVAST decimal(8,2) default 0;
            // alter table item_devolucaos add column pICMSST decimal(8,2) default 0;
            // alter table item_devolucaos add column vICMSST decimal(8,2) default 0;

            // alter table item_devolucaos add column orig integer default 0;
            // alter table item_devolucaos add column pST decimal(10,2) default 0;
            // alter table item_devolucaos add column vICMSSubstituto decimal(10,2) default 0;
            // alter table item_devolucaos add column vICMSSTRet decimal(10,2) default 0;


            // alter table item_devolucaos add column vbcPis decimal(12,2) default 0;
            // alter table item_devolucaos add column vbcCofins decimal(12,2) default 0;
            // alter table item_devolucaos add column vbcIpi decimal(12,2) default 0;
            // alter table item_devolucaos MODIFY column valor_unit decimal(10,4);
            // alter table item_devolucaos MODIFY column quantidade decimal(10,4);

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
        Schema::dropIfExists('item_devolucaos');
    }
}
