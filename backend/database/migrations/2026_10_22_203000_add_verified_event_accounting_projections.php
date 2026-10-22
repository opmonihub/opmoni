<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_fiscal_events', function (Blueprint $table): void {
            $table->longText('accepted_xml')->nullable();
            $table->string('accepted_sha256', 64)->nullable();
            $table->unsignedInteger('accepted_byte_length')->nullable();
            $table->string('accepted_event_identity', 32)->nullable();
            $table->json('accepted_identity')->nullable();
        });
        Schema::table('erp_accounting_deliveries', function (Blueprint $table): void {
            $table->string('projection_identity', 64)->default('document');
            $table->foreignId('erp_fiscal_event_id')->nullable()->constrained('erp_fiscal_events')->restrictOnDelete();
            $table->dropUnique('erp_delivery_approval_unique');
        });
        DB::statement('DROP INDEX erp_delivery_placeholder_unique');
        DB::statement('DROP INDEX erp_delivery_legacy_unique');
        Schema::table('erp_accounting_deliveries', function (Blueprint $table): void {
            $table->unique(['erp_company_id', 'erp_fiscal_document_id', 'account_id', 'delegation_id', 'mapping_id', 'projection_identity'], 'erp_delivery_projection_approval_unique');
        });
        DB::statement('CREATE UNIQUE INDEX erp_delivery_projection_placeholder_unique ON erp_accounting_deliveries (erp_company_id, erp_fiscal_document_id, account_id, delegation_id, projection_identity) WHERE delegation_id IS NOT NULL AND mapping_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX erp_delivery_projection_legacy_unique ON erp_accounting_deliveries (erp_company_id, erp_fiscal_document_id, account_id, projection_identity) WHERE delegation_id IS NULL');
    }

    public function down(): void
    {
        if (DB::table('erp_accounting_deliveries')->where('projection_identity', '!=', 'document')->orWhereNotNull('erp_fiscal_event_id')->exists()
            || DB::table('erp_fiscal_events')->whereNotNull('accepted_xml')->exists()) {
            throw new RuntimeException('Cannot narrow accounting projection identity or discard accepted fiscal event evidence.');
        }
        DB::statement('DROP INDEX erp_delivery_projection_placeholder_unique');
        DB::statement('DROP INDEX erp_delivery_projection_legacy_unique');
        Schema::table('erp_accounting_deliveries', function (Blueprint $table): void {
            $table->dropUnique('erp_delivery_projection_approval_unique');
            $table->dropConstrainedForeignId('erp_fiscal_event_id');
            $table->dropColumn('projection_identity');
            $table->unique(['erp_company_id', 'erp_fiscal_document_id', 'account_id', 'delegation_id', 'mapping_id'], 'erp_delivery_approval_unique');
        });
        DB::statement('CREATE UNIQUE INDEX erp_delivery_placeholder_unique ON erp_accounting_deliveries (erp_company_id, erp_fiscal_document_id, account_id, delegation_id) WHERE delegation_id IS NOT NULL AND mapping_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX erp_delivery_legacy_unique ON erp_accounting_deliveries (erp_company_id, erp_fiscal_document_id, account_id) WHERE delegation_id IS NULL');
        Schema::table('erp_fiscal_events', function (Blueprint $table): void {
            $table->dropColumn(['accepted_xml', 'accepted_sha256', 'accepted_byte_length', 'accepted_event_identity', 'accepted_identity']);
        });
    }
};
