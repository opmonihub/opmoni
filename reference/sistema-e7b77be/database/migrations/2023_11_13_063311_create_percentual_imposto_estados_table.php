<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePercentualImpostoEstadosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('percentual_imposto_estados', function (Blueprint $table) {
            $table->id();

            $table->integer('product_id')->unsigned();
            $table->foreign('product_id')->references('id')->on('products')
            ->onDelete('cascade');

            $table->string('uf', 2);
            $table->decimal('percentual_icms', 5, 2);
            $table->decimal('percentual_pis', 5, 2);
            $table->decimal('percentual_cofins', 5, 2);
            $table->decimal('percentual_ipi', 5, 2);
            $table->decimal('percentual_fcp', 5, 2);
            $table->decimal('percentual_icms_interno', 5, 2);
            $table->decimal('percentual_red_bc', 5, 2)->nullable();

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
        Schema::dropIfExists('percentual_imposto_estados');
    }
}
