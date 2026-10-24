<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_operation_natures', function (Blueprint $table): void {
            $table->unsignedBigInteger('configuration_revision')->default(1);
            $table->string('cfop_reference_version', 64)->nullable();
            $table->unique(['erp_company_id', 'id'], 'erp_nature_company_identity');
        });
        Schema::table('erp_products', fn (Blueprint $table) => $table->unique(['erp_company_id', 'id'], 'erp_product_rule_company_identity'));
        Schema::create('erp_operation_nature_cfop_choices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('erp_company_id');
            $table->unsignedBigInteger('erp_operation_nature_id');
            $table->string('cfop', 4);
            $table->string('reference_version', 64);
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['erp_company_id', 'erp_operation_nature_id', 'cfop'], 'erp_nature_choice_code_unique');
            $table->unique(['erp_company_id', 'erp_operation_nature_id', 'id'], 'erp_nature_choice_company_identity');
            $table->foreign(['erp_company_id', 'erp_operation_nature_id'], 'erp_nature_choice_owner_fk')->references(['erp_company_id', 'id'])->on('erp_operation_natures')->restrictOnDelete();
            $table->index(['erp_company_id', 'erp_operation_nature_id', 'status'], 'erp_nature_choice_status_index');
        });
        Schema::create('erp_operation_nature_product_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('erp_company_id');
            $table->unsignedBigInteger('erp_operation_nature_id');
            $table->unsignedBigInteger('erp_product_id');
            $table->unsignedBigInteger('erp_operation_nature_cfop_choice_id');
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->foreign(['erp_company_id', 'erp_operation_nature_id'], 'erp_nature_exception_owner_fk')->references(['erp_company_id', 'id'])->on('erp_operation_natures')->restrictOnDelete();
            $table->foreign(['erp_company_id', 'erp_product_id'], 'erp_nature_exception_product_fk')->references(['erp_company_id', 'id'])->on('erp_products')->restrictOnDelete();
            $table->foreign(['erp_company_id', 'erp_operation_nature_id', 'erp_operation_nature_cfop_choice_id'], 'erp_nature_exception_choice_fk')->references(['erp_company_id', 'erp_operation_nature_id', 'id'])->on('erp_operation_nature_cfop_choices')->restrictOnDelete();
            $table->index(['erp_company_id', 'erp_operation_nature_id', 'status'], 'erp_nature_exception_status_index');
        });
        DB::statement("CREATE UNIQUE INDEX erp_nature_active_product_exception ON erp_operation_nature_product_exceptions (erp_company_id, erp_operation_nature_id, erp_product_id) WHERE status = 'active'");
    }

    public function down(): void
    {
        if (DB::table('erp_operation_nature_cfop_choices')->exists()
            || DB::table('erp_operation_nature_product_exceptions')->exists()
            || DB::table('erp_operation_natures')->where('configuration_revision', '>', 1)->orWhereNotNull('cfop_reference_version')->exists()) {
            throw new RuntimeException('Cannot remove accepted operation nature configuration or rule history.');
        }
        Schema::dropIfExists('erp_operation_nature_product_exceptions');
        Schema::dropIfExists('erp_operation_nature_cfop_choices');
        Schema::table('erp_products', fn (Blueprint $table) => $table->dropUnique('erp_product_rule_company_identity'));
        Schema::table('erp_operation_natures', function (Blueprint $table): void {
            $table->dropUnique('erp_nature_company_identity');
            $table->dropColumn(['configuration_revision', 'cfop_reference_version']);
        });
    }
};
