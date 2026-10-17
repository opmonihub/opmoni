<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddChavePixToMdvesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mdves', function (Blueprint $table) {
            // Campo para chave PIX (NT 2025.001)
            $table->string('chave_pix', 60)->nullable()->after('cnpj_ipef')->comment('Chave PIX para recebimento do frete (email, CPF/CNPJ, telefone ou chave aleatória)');
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
            $table->dropColumn('chave_pix');
        });
    }
}

