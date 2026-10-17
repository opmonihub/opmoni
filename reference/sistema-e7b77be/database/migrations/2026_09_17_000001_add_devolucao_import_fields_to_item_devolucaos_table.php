<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddDevolucaoImportFieldsToItemDevolucaosTable extends Migration
{
    public function up()
    {
        Schema::table('item_devolucaos', function (Blueprint $table) {
            $table->integer('modBC')->default(3)->after('pRedBC');
            $table->string('cest', 7)->default('')->after('ncm');
            $table->decimal('vUnTrib', 10, 4)->default(0)->after('valor_unit');
            $table->decimal('vIPI', 12, 2)->default(0)->after('vbcIpi');
        });
    }

    public function down()
    {
        Schema::table('item_devolucaos', function (Blueprint $table) {
            $table->dropColumn(['modBC', 'cest', 'vUnTrib', 'vIPI']);
        });
    }
}
