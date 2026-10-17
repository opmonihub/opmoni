<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateDevolucaosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('devolucaos', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('contact_id')->unsigned();
            $table->foreign('contact_id')->references('id')->on('contacts');

            $table->integer('natureza_id')->unsigned();
            $table->foreign('natureza_id')->references('id')->on('natureza_operacaos');

            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');

            $table->integer('location_id')->unsigned()->nullable();
            $table->foreign('location_id')->references('id')
            ->on('business_locations')->onDelete('cascade');

            $table->decimal('valor_integral', 10,2);
            $table->decimal('valor_devolvido', 10,2);

            $table->string('motivo', 100);
            $table->string('observacao', 200);
            $table->integer('estado');
            $table->boolean('devolucao_parcial');

            $table->string('chave_nf_entrada',48);
            $table->integer('nNf');
            $table->decimal('vFrete', 10, 2);
            $table->decimal('vDesc', 10, 2);

            $table->string('chave_gerada', 44);
            $table->integer('numero_gerado');

            $table->decimal('vSeguro', 10, 2);
            $table->decimal('vOutro', 10, 2);

            $table->integer('tipo')->default(1);
            $table->integer('sequencia_cce')->default(0);

            $table->string('transportadora_nome', 100)->default('');
            $table->string('transportadora_cidade', 50)->default('');
            $table->string('transportadora_uf', 2)->default('');
            $table->string('transportadora_cpf_cnpj', 18)->default('');
            $table->string('transportadora_ie', 15)->default('');
            $table->string('transportadora_endereco', 120)->default('');

            $table->decimal('frete_quantidade', 6, 2)->default(0);
            $table->string('frete_especie', 20)->default('');
            $table->string('frete_marca', 20)->default('');
            $table->string('frete_numero', 20)->default('');
            $table->integer('frete_tipo')->default(0);

            $table->string('veiculo_placa', 10)->default('');
            $table->string('veiculo_uf', 2)->default('');

            $table->decimal('frete_peso_bruto', 10, 3)->default(0);
            $table->decimal('frete_peso_liquido', 10, 3)->default(0);

            // alter table devolucaos modify column observacao varchar(200);

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
        Schema::dropIfExists('devolucaos');
    }
}
