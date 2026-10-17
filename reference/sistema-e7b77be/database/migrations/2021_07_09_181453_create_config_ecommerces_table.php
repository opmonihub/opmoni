<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateConfigEcommercesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('config_ecommerces', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nome', 30);

            $table->string('logo', 80)->default('');
            $table->string('img_contato', 80)->default('');
            $table->string('fav_icon', 80)->default('');
            $table->integer('timer_carrossel')->default(5);

            // alter table config_ecommerces add column fav_icon varchar(80) default '';
            // alter table config_ecommerces add column timer_carrossel integer default 5;

            $table->string('rua', 80);
            $table->string('numero', 10);
            $table->string('bairro', 30);
            $table->string('cidade', 30);
            $table->string('uf', 2);
            $table->string('cep', 10);
            $table->string('telefone', 15);
            $table->string('email', 60);
            $table->string('link_facebook', 120);
            $table->string('link_twiter', 120);
            $table->string('link_instagram', 120);
            $table->decimal('frete_gratis_valor', 10, 2);
            $table->string('mercadopago_public_key', 120);
            $table->string('mercadopago_access_token', 120);
            $table->string('funcionamento', 120);
            $table->string('latitude', 15);
            $table->string('longitude', 15);
            $table->text('politica_privacidade');
            $table->text('src_mapa')->deafult('');

            $table->string('google_api', 40)->default('');

            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')
            ->onDelete('cascade');

            $table->string('token', 25);
            $table->string('cor_fundo', 7)->default('#000');
            $table->string('cor_btn', 7)->default('#000');
            $table->text('mensagem_agradecimento');
            
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
        Schema::dropIfExists('config_ecommerces');
    }
}
