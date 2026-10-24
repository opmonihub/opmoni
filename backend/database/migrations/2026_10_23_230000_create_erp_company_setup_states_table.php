<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['erp_units', 'erp_contacts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->string('native_key', 100)->nullable();
                $table->unique(['erp_company_id', 'native_key'], $tableName.'_company_native_key_unique');
            });
        }

        foreach (['erp_units', 'erp_invoice_schemes', 'erp_invoice_layouts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->unique(['erp_company_id', 'id'], $tableName.'_setup_company_id_unique');
            });
        }

        Schema::create('erp_company_setup_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->unique()->constrained('erp_companies')->cascadeOnDelete();
            $table->string('preset_version', 32);
            $table->unsignedBigInteger('revision')->default(1);
            $table->string('currency', 3)->nullable();
            $table->foreignId('default_unit_id')->nullable();
            $table->foreignId('default_customer_id')->nullable();
            $table->foreignId('default_invoice_scheme_id')->nullable();
            $table->foreignId('default_invoice_layout_id')->nullable();
            $table->json('preset_owned_keys')->nullable();
            $table->timestamps();

            foreach ([
                'default_unit_id' => 'erp_units',
                'default_customer_id' => 'erp_contacts',
                'default_invoice_scheme_id' => 'erp_invoice_schemes',
                'default_invoice_layout_id' => 'erp_invoice_layouts',
            ] as $column => $referenceTable) {
                $table->foreign(['erp_company_id', $column], 'erp_setup_'.$column.'_company_fk')
                    ->references(['erp_company_id', 'id'])->on($referenceTable)->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (DB::table('erp_company_setup_states')->exists()
            || DB::table('erp_units')->whereNotNull('native_key')->exists()
            || DB::table('erp_contacts')->whereNotNull('native_key')->exists()) {
            throw new RuntimeException('Cannot remove accepted ERP company setup or native record identity.');
        }

        Schema::dropIfExists('erp_company_setup_states');

        foreach (['erp_units', 'erp_invoice_schemes', 'erp_invoice_layouts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropUnique($tableName.'_setup_company_id_unique');
            });
        }

        foreach (['erp_units', 'erp_contacts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropUnique($tableName.'_company_native_key_unique');
                $table->dropColumn('native_key');
            });
        }
    }
};
