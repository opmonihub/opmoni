<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_purchases', function (Blueprint $table): void {
            $table->string('source_access_key', 44)->nullable();
            $table->unsignedBigInteger('source_import_id')->nullable();
            $table->string('source_sha256', 64)->nullable();
            $table->unsignedInteger('source_byte_length')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->unsignedInteger('draft_revision')->nullable();
            $table->json('commercial_snapshot')->nullable();
            $table->unique(['id', 'erp_company_id', 'erp_establishment_id'], 'erp_purchase_scope_identity_unique');
            $table->unique(['erp_company_id', 'source_access_key'], 'erp_purchase_company_source_key_unique');
        });
        Schema::table('erp_purchase_lines', function (Blueprint $table): void {
            $table->unsignedInteger('ordinal')->nullable();
            $table->unsignedInteger('source_ordinal')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('erp_unit_id')->nullable()->constrained('erp_units')->restrictOnDelete();
            $table->decimal('base_quantity', 22, 4)->nullable();
        });
        Schema::create('erp_purchase_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->restrictOnDelete();
            $table->string('token_digest', 64)->unique();
            $table->string('schema_version', 40);
            $table->string('source_sha256', 64);
            $table->unsignedInteger('source_byte_length');
            $table->string('source_access_key', 44)->nullable();
            $table->json('source_snapshot');
            $table->json('errors');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('completed_key_digest', 64)->nullable();
            $table->string('completed_payload_hash', 64)->nullable();
            $table->foreignId('erp_purchase_id')->nullable()->constrained('erp_purchases')->restrictOnDelete();
            $table->json('original_result')->nullable();
            $table->timestamps();
            $table->index(['erp_company_id', 'actor_user_id']);
            $table->unique(['id', 'erp_company_id', 'erp_establishment_id'], 'erp_purchase_import_scope_identity_unique');
            $table->foreign(['erp_establishment_id', 'erp_company_id'], 'erp_purchase_import_establishment_company_fk')->references(['id', 'erp_company_id'])->on('erp_establishments')->restrictOnDelete();
            $table->foreign(['erp_purchase_id', 'erp_company_id', 'erp_establishment_id'], 'erp_purchase_import_purchase_scope_fk')->references(['id', 'erp_company_id', 'erp_establishment_id'])->on('erp_purchases')->restrictOnDelete();
        });
        Schema::table('erp_purchases', function (Blueprint $table): void {
            $table->foreign(['source_import_id', 'erp_company_id', 'erp_establishment_id'], 'erp_purchase_source_import_scope_fk')->references(['id', 'erp_company_id', 'erp_establishment_id'])->on('erp_purchase_imports')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Purchase source history is durable. Restore a compatible application version instead of rolling back this schema.');
    }
};
