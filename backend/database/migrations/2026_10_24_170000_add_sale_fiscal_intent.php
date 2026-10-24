<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_sales', function (Blueprint $table): void {
            $table->string('fiscal_model', 2)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('erp_sales')->whereNotNull('fiscal_model')->exists()) {
            throw new RuntimeException('Cannot remove explicit sale fiscal intent.');
        }
        Schema::table('erp_sales', function (Blueprint $table): void {
            $table->dropColumn('fiscal_model');
        });
    }
};
