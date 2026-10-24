<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_invoice_layouts', function (Blueprint $table): void {
            $table->string('native_key', 100)->nullable();
            $table->string('status', 16)->default('active');
            $table->unique(['erp_company_id', 'native_key'], 'erp_invoice_layouts_company_native_key_unique');
        });
        Schema::table('erp_sales', function (Blueprint $table): void {
            $table->json('commercial_layout_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('erp_sales')->whereNotNull('commercial_layout_snapshot')->exists()
            || DB::table('erp_invoice_layouts')->whereNotNull('native_key')->exists()
            || DB::table('erp_invoice_layouts')->where('status', '!=', 'active')->exists()) {
            throw new RuntimeException('Cannot remove preserved commercial layouts or native layout identity.');
        }
        Schema::table('erp_sales', function (Blueprint $table): void {
            $table->dropColumn('commercial_layout_snapshot');
        });
        Schema::table('erp_invoice_layouts', function (Blueprint $table): void {
            $table->dropUnique('erp_invoice_layouts_company_native_key_unique');
            $table->dropColumn(['native_key', 'status']);
        });
    }
};
