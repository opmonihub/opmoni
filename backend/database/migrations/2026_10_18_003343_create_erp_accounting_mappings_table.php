<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_accounting_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies');
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments');
            $table->foreignId('delegation_id')->constrained('erp_office_delegations');
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('approved_by_user_id')->constrained('users');
            $table->timestamp('approved_at');
            $table->unsignedInteger('revision');
            $table->string('status', 16)->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['delegation_id', 'erp_establishment_id', 'revision'], 'erp_mapping_revision_unique');
        });
        DB::statement("CREATE UNIQUE INDEX erp_mapping_active_unique ON erp_accounting_mappings (delegation_id, erp_establishment_id) WHERE status = 'active'");
        Schema::table('erp_accounting_deliveries', function (Blueprint $table): void {
            $table->foreignId('delegation_id')->nullable()->constrained('erp_office_delegations');
            $table->foreignId('mapping_id')->nullable()->constrained('erp_accounting_mappings');
            $table->unsignedInteger('mapping_revision')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users');
            $table->dropUnique('erp_accounting_deliveries_intent_unique');
            $table->unique(['erp_company_id', 'erp_fiscal_document_id', 'account_id', 'delegation_id', 'mapping_id'], 'erp_delivery_approval_unique');
        });
        DB::statement('CREATE UNIQUE INDEX erp_delivery_placeholder_unique ON erp_accounting_deliveries (erp_company_id, erp_fiscal_document_id, account_id, delegation_id) WHERE delegation_id IS NOT NULL AND mapping_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX erp_delivery_legacy_unique ON erp_accounting_deliveries (erp_company_id, erp_fiscal_document_id, account_id) WHERE delegation_id IS NULL');
    }

    public function down(): void
    {
        if (DB::table('erp_accounting_deliveries')->select('erp_company_id', 'erp_fiscal_document_id', 'account_id')->groupBy('erp_company_id', 'erp_fiscal_document_id', 'account_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot narrow accounting intent identity while distinct grant histories exist.');
        }
        DB::statement('DROP INDEX erp_delivery_legacy_unique');
        DB::statement('DROP INDEX erp_delivery_placeholder_unique');
        Schema::table('erp_accounting_deliveries', function (Blueprint $table): void {
            $table->dropUnique('erp_delivery_approval_unique');
            $table->dropConstrainedForeignId('mapping_id');
            $table->dropConstrainedForeignId('delegation_id');
            $table->dropConstrainedForeignId('requested_by_user_id');
            $table->dropColumn('mapping_revision');
            $table->unique(['erp_company_id', 'erp_fiscal_document_id', 'account_id'], 'erp_accounting_deliveries_intent_unique');
        });
        Schema::dropIfExists('erp_accounting_mappings');
    }
};
