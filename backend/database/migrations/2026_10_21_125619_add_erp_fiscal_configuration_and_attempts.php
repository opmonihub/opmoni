<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('erp_fiscal_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->cascadeOnDelete();
            $table->foreignId('current_revision_id')->nullable();
            $table->timestamps();
            $table->unique(['erp_company_id', 'erp_establishment_id'], 'erp_fiscal_configuration_issuer_unique');
        });

        Schema::create('erp_fiscal_configuration_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_fiscal_configuration_id')->constrained('erp_fiscal_configurations')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('environment', 16);
            $table->json('configuration');
            $table->string('configuration_sha256', 64);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['erp_fiscal_configuration_id', 'revision'], 'erp_fiscal_configuration_revision_unique');
        });

        Schema::table('erp_fiscal_configurations', function (Blueprint $table): void {
            $table->foreign('current_revision_id', 'erp_fiscal_configuration_current_revision_fk')
                ->references('id')->on('erp_fiscal_configuration_revisions')->nullOnDelete();
        });

        Schema::create('erp_fiscal_certificate_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_fiscal_certificate_id')->constrained('erp_fiscal_certificates')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->longText('encrypted_certificate');
            $table->longText('encrypted_password');
            $table->string('certificate_fingerprint', 64);
            $table->string('cnpj', 14);
            $table->string('subject', 512)->nullable();
            $table->timestamp('valid_from');
            $table->timestamp('valid_until');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['erp_fiscal_certificate_id', 'revision'], 'erp_fiscal_certificate_revision_unique');
        });

        Schema::table('erp_fiscal_certificates', function (Blueprint $table): void {
            $table->foreignId('current_revision_id')->nullable()->after('revision')
                ->constrained('erp_fiscal_certificate_revisions')->nullOnDelete();
        });

        Schema::create('erp_fiscal_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->string('model', 8);
            $table->string('environment', 16);
            $table->unsignedInteger('series');
            $table->unsignedInteger('next_number');
            $table->timestamps();
            $table->unique(['erp_company_id', 'model', 'environment', 'series'], 'erp_fiscal_number_sequence_scope_unique');
        });

        Schema::create('erp_fiscal_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->cascadeOnDelete();
            $table->foreignId('erp_fiscal_document_id')->nullable()->constrained('erp_fiscal_documents')->nullOnDelete();
            $table->foreignId('erp_fiscal_event_id')->nullable()->constrained('erp_fiscal_events')->nullOnDelete();
            $table->foreignId('erp_fiscal_invalidation_id')->nullable()->constrained('erp_fiscal_invalidations')->nullOnDelete();
            $table->foreignId('erp_fiscal_configuration_revision_id')->nullable()->constrained('erp_fiscal_configuration_revisions')->nullOnDelete();
            $table->foreignId('erp_fiscal_certificate_revision_id')->nullable()->constrained('erp_fiscal_certificate_revisions')->nullOnDelete();
            $table->string('action', 32);
            $table->string('model', 8);
            $table->string('environment', 16);
            $table->unsignedInteger('series')->nullable();
            $table->unsignedInteger('number')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('request_sha256', 64);
            $table->longText('encrypted_source_snapshot')->nullable();
            $table->string('schema_release', 160)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('outbox_state', 16)->default('new');
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->string('provider_receipt', 128)->nullable();
            $table->string('provider_protocol', 128)->nullable();
            $table->json('safe_result')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('lease_token', 36)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['erp_company_id', 'action', 'idempotency_key'], 'erp_fiscal_attempt_idempotency_unique');
            $table->index(['outbox_state', 'available_at', 'created_at'], 'erp_fiscal_attempt_outbox_scan_index');
            $table->index(['lease_expires_at', 'outbox_state'], 'erp_fiscal_attempt_lease_expiry_index');
            $table->index(['erp_company_id', 'model', 'environment', 'series', 'number'], 'erp_fiscal_attempt_number_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('erp_fiscal_attempts');
        Schema::dropIfExists('erp_fiscal_number_sequences');
        Schema::table('erp_fiscal_certificates', function (Blueprint $table): void {
            $table->dropForeign(['current_revision_id']);
            $table->dropColumn('current_revision_id');
        });
        Schema::dropIfExists('erp_fiscal_certificate_revisions');
        Schema::table('erp_fiscal_configurations', function (Blueprint $table): void {
            $table->dropForeign('erp_fiscal_configuration_current_revision_fk');
        });
        Schema::dropIfExists('erp_fiscal_configuration_revisions');
        Schema::dropIfExists('erp_fiscal_configurations');
    }
};
