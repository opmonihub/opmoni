<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_fiscal_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->nullable()->constrained('erp_establishments')->nullOnDelete();
            $table->string('label');
            $table->string('cnpj', 14)->nullable();
            $table->binary('encrypted_certificate');
            $table->binary('encrypted_password');
            $table->string('certificate_fingerprint', 64)->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 16)->default('active');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
        });

        Schema::create('erp_fiscal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->cascadeOnDelete();
            $table->foreignId('erp_fiscal_certificate_id')->nullable()->constrained('erp_fiscal_certificates')->nullOnDelete();
            $table->unsignedBigInteger('erp_sale_id')->nullable();
            $table->string('model', 8);
            $table->string('environment', 16)->default('homologation');
            $table->unsignedInteger('series')->default(1);
            $table->unsignedInteger('number');
            $table->string('access_key', 44)->nullable();
            $table->string('status', 24)->default('draft');
            $table->string('idempotency_key', 128);
            $table->unsignedInteger('certificate_revision')->default(1);
            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('artifact_byte_length')->nullable();
            $table->string('artifact_sha256', 64)->nullable();
            $table->json('commercial_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamps();

            $table->unique(['erp_company_id', 'model', 'series', 'number', 'environment'], 'erp_fiscal_documents_number_unique');
            $table->unique(['erp_company_id', 'idempotency_key'], 'erp_fiscal_documents_idempotency_unique');
            $table->index(['erp_company_id', 'status']);
        });

        Schema::create('erp_fiscal_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_fiscal_document_id')->constrained('erp_fiscal_documents')->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->string('status', 24)->default('queued');
            $table->string('idempotency_key', 128);
            $table->string('protocol', 64)->nullable();
            $table->text('justification')->nullable();
            $table->json('payload_snapshot')->nullable();
            $table->json('provider_response')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['erp_fiscal_document_id', 'idempotency_key'], 'erp_fiscal_events_idempotency_unique');
        });

        Schema::create('erp_fiscal_invalidations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->cascadeOnDelete();
            $table->string('model', 8);
            $table->string('environment', 16)->default('homologation');
            $table->unsignedInteger('series');
            $table->unsignedInteger('number_from');
            $table->unsignedInteger('number_to');
            $table->string('status', 24)->default('queued');
            $table->string('idempotency_key', 128);
            $table->text('justification');
            $table->string('protocol', 64)->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['erp_company_id', 'idempotency_key'], 'erp_fiscal_invalidations_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_fiscal_invalidations');
        Schema::dropIfExists('erp_fiscal_events');
        Schema::dropIfExists('erp_fiscal_documents');
        Schema::dropIfExists('erp_fiscal_certificates');
    }
};
