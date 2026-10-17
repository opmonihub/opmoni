<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateItemPedidoEcommercesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('item_pedido_ecommerces', function (Blueprint $table) {
            $table->increments('id');
            
            $table->integer('pedido_id')->unsigned();
            $table->foreign('pedido_id')->references('id')
            ->on('pedido_ecommerces')->onDelete('cascade');

            $table->integer('produto_id')->unsigned();
            $table->foreign('produto_id')->references('id')
            ->on('products')->onDelete('cascade');

            $table->integer('quantidade');
            $table->integer('variacao_id')->default(0);
            $table->timestamps();

            // alter table item_pedido_ecommerces add column variacao_id integer default 0;

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('item_pedido_ecommerces');
    }
}
