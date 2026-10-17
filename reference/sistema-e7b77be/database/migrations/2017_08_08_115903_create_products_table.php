<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->enum('type', ['single', 'variable']);
            $table->integer('unit_id')->unsigned();
            $table->foreign('unit_id')->references('id')->on('units')->onDelete('cascade');
            $table->integer('brand_id')->unsigned()->nullable();
            $table->foreign('brand_id')->references('id')->on('brands')->onDelete('cascade');
            $table->integer('category_id')->unsigned()->nullable();
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->integer('sub_category_id')->unsigned()->nullable();
            $table->foreign('sub_category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->integer('tax')->unsigned()->nullable();
            $table->foreign('tax')->references('id')->on('tax_rates');
            $table->enum('tax_type', ['inclusive', 'exclusive']);
            $table->boolean('enable_stock')->default(0);
            $table->decimal('alert_quantity', 22, 4)->default(0);
            $table->string('sku');
            $table->enum('barcode_type', ['C39', 'C128', 'EAN-13', 'EAN-8', 'UPC-A', 'UPC-E', 'ITF-14']);
            $table->integer('created_by')->unsigned();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->timestamps();

            //Indexing
            $table->index('name');
            $table->index('business_id');
            $table->index('unit_id');
            $table->index('created_by');

            $table->decimal('perc_icms', 4, 2)->default(0);
            $table->decimal('perc_pis', 4, 2)->default(0);
            $table->decimal('perc_cofins', 4, 2)->default(0);
            $table->decimal('perc_ipi', 4, 2)->default(0);
            $table->decimal('pCredSN', 5, 2)->default(0);

            $table->string('cfop_interno', 4)->default('5101');
            $table->string('cfop_externo', 4)->default('6101');

            $table->string('cst_csosn', 4)->default('101');
            $table->string('cst_pis', 4)->default('49');
            $table->string('cst_cofins', 4)->default('49');
            $table->string('cst_ipi', 4)->default('99');

            $table->string('ncm', 10)->default('0');
            $table->string('cest', 10)->nullable();
            $table->string('codigo_barras', 20)->default('');

            $table->string('codigo_anp', 10)->default('');
            $table->decimal('perc_glp', 5,2)->default(0);
            $table->decimal('perc_gnn', 5,2)->default(0);
            $table->decimal('perc_gni', 5,2)->default(0);
            $table->decimal('valor_partida', 10,4)->default(0);

            $table->string('unidade_tributavel', 4)->default('');
            $table->decimal('quantidade_tributavel', 10, 4)->default(0);

            $table->string('tipo', 10)->default('normal');

            //campos para venda veiculo NFe
            $table->string('veicProd', 190)->default('');
            $table->integer('tpOp')->default(0);
            $table->string('chassi', 17)->default('');
            $table->string('cCor', 4)->default('');
            $table->string('xCor', 40)->default('');
            $table->integer('pot')->default(0);
            $table->integer('cilin')->default(0);
            $table->decimal('pesoL', 12, 4)->default(0);
            $table->decimal('pesoB', 12, 4)->default(0);
            $table->string('nSerie', 9)->default('');
            $table->string('tpComb', 2)->default('');
            $table->string('nMotor', 21)->default('');
            $table->decimal('CMT', 12, 4)->default(0);
            $table->decimal('dist', 12, 4)->default(0);
            $table->integer('anoMod')->default(0);
            $table->integer('anoFab')->default(0);
            $table->string('tpPint', 2)->default('');
            $table->string('tpVeic', 2)->default('');
            $table->integer('espVeic')->default(0);
            $table->string('VIN', 2)->default('');
            $table->integer('condVeic')->default(0);
            $table->integer('cMod')->default(0);
            $table->integer('cCorDENATRAN')->default(0);
            $table->integer('lota')->default(0);
            $table->integer('tpRest')->default(0);
            $table->integer('origem')->default(0);
            $table->string('cenq_ipi', 3)->default('999');
            $table->string('ref_balanca', 10)->nullable();

            $table->decimal('qBCMonoRet', 10, 4)->default(0);
            $table->decimal('adRemICMSRet', 10, 4)->default(0);
            $table->decimal('pBio', 10, 4)->default(0);

            $table->integer('indImport')->default(0);
            $table->string('cUFOrig', 2)->nullable();
            $table->decimal('pOrig', 5, 2)->default(0);

            // alter table products add column cUFOrig varchar(2) default null;
            // alter table products add column pOrig decimal(5,2) default 0;
            // alter table products add column indImport integer default 0;

            // alter table products add column tipo varchar(10) default 'normal';
            // alter table products add column veicProd varchar(190) default '';
            // alter table products add column tpOp integer default 0;
            // alter table products add column chassi varchar(17) default '';
            // alter table products add column cCor varchar(4) default '';
            // alter table products add column xCor varchar(40) default '';
            // alter table products add column pot integer default 0;
            // alter table products add column cilin integer default 0;
            // alter table products add column pesoL decimal(12, 4) default 0;
            // alter table products add column pesoB decimal(12, 4) default 0;
            // alter table products add column nSerie varchar(9) default '';
            // alter table products add column tpComb varchar(9) default '';
            // alter table products add column nMotor varchar(9) default '';
            // alter table products add column CMT decimal(12, 4) default 0;
            // alter table products add column dist decimal(12, 4) default 0;
            // alter table products add column anoMod integer default 0;
            // alter table products add column anoFab integer default 0;
            // alter table products add column tpPint varchar(2) default '';
            // alter table products add column tpVeic varchar(2) default '';
            // alter table products add column espVeic integer default 0;
            // alter table products add column VIN varchar(2) default '';
            // alter table products add column condVeic integer default 0;
            // alter table products add column cMod integer default 0;
            // alter table products add column cCorDENATRAN integer default 0;
            // alter table products add column lota integer default 0;
            // alter table products add column tpRest integer default 0;
            // alter table products add column origem integer default 0;


            // alter table products add column pCredSN decimal(5,2) default 0;
            // alter table products add column cenq_ipi varchar(4) default '999';


            //ecommerce

            $table->boolean('ecommerce')->default(0);
            $table->decimal('valor_ecommerce', 12, 2)->default(0);

            $table->decimal('altura', 8, 2)->default(0);
            $table->decimal('largura', 8, 2)->default(0);
            $table->decimal('comprimento', 8, 2)->default(0);

            $table->boolean('destaque')->default(0);
            $table->boolean('novo')->default(0);

            $table->decimal('perc_icms_interestadual', 10,2)->default(0);
            $table->decimal('perc_icms_interno', 10,2)->default(0);
            $table->decimal('perc_fcp_interestadual', 10,2)->default(0);
            $table->integer('modBCST')->default(0);
            $table->integer('modBC')->default(0);
            $table->decimal('pICMSST', 5, 2)->default(0);
            
            // alter table products add column ecommerce boolean default 0;
            // alter table products add column valor_ecommerce decimal(12, 2) default 0;

            // alter table products add column destaque boolean default 0;
            // alter table products add column novo boolean default 0;
            // alter table products add column altura decimal(8, 2) default 0;
            // alter table products add column largura decimal(8, 2) default 0;
            // alter table products add column comprimento decimal(8, 2) default 0;

            // alter table products add column perc_icms_interestadual decimal(10, 2) default 0;
            // alter table products add column perc_icms_interno decimal(10, 2) default 0;
            // alter table products add column perc_fcp_interestadual decimal(10, 2) default 0;

            // alter table products add column modBCST integer default 0;
            // alter table products add column modBC integer default 0;
            // alter table products add column pICMSST decimal(5,2) default 0;
            // alter table products add column ref_balanca varchar(10) default null;

            // alter table products add column qBCMonoRet decimal(10,4) default 0;
            // alter table products add column adRemICMSRet decimal(10,4) default 0;
            // alter table products add column pBio decimal(10,4) default 0;
        });
}

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('products');
    }
}
