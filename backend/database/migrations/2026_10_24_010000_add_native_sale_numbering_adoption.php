<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_invoice_schemes', function (Blueprint $table): void {
            $table->string('native_key', 100)->nullable();
            $table->string('status', 16)->default('active');
            $table->unique(['erp_company_id', 'native_key'], 'erp_invoice_schemes_company_native_key_unique');
        });
        Schema::table('erp_company_setup_states', function (Blueprint $table): void {
            $table->boolean('sale_numbering_adopted')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('erp_company_setup_states')->where('sale_numbering_adopted', true)->exists()
            || DB::table('erp_invoice_schemes')->whereNotNull('native_key')->exists()
            || DB::table('erp_invoice_schemes')->where('status', '!=', 'active')->exists()) {
            throw new RuntimeException('Cannot remove accepted native sale numbering or scheme status.');
        }
        Schema::table('erp_company_setup_states', function (Blueprint $table): void {
            $table->dropColumn('sale_numbering_adopted');
        });
        Schema::table('erp_invoice_schemes', function (Blueprint $table): void {
            $table->dropUnique('erp_invoice_schemes_company_native_key_unique');
            $table->dropColumn(['native_key', 'status']);
        });
    }
};
