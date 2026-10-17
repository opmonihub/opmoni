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
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->timestamp('authorized_at')->nullable()->after('emissao_at');
            $table->string('provider_note_status')->nullable()->after('authorized_at');

            $table->string('delivery_origin')->default('unverified')->after('provider_note_status');
            $table->string('delivery_environment')->nullable()->after('delivery_origin');
            $table->string('queried_tax_id', 14)->nullable()->after('delivery_environment');
            $table->timestamp('delivery_received_at')->nullable()->after('queried_tax_id');
            $table->uuid('delivery_correlation_id')->nullable()->after('delivery_received_at');

            $table->string('storage_state')->default('unverified')->after('xml_bytes');
            $table->timestamp('storage_verified_at')->nullable()->after('storage_state');

            $table->string('processing_state')->nullable()->after('storage_verified_at');
            $table->string('processing_reason')->nullable()->after('processing_state');
            $table->timestamp('next_attempt_at')->nullable()->after('processing_reason');
            $table->unsignedSmallInteger('processing_attempts')->default(0)->after('next_attempt_at');
            $table->timestamp('processing_started_at')->nullable()->after('processing_attempts');
            $table->timestamp('processing_completed_at')->nullable()->after('processing_started_at');
            $table->timestamp('processing_claim_expires_at')->nullable()->after('processing_completed_at');

            $table->index(['account_id', 'stage', 'processing_state', 'next_attempt_at'], 'fiscal_documents_processing_due_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropIndex('fiscal_documents_processing_due_idx');
            $table->dropColumn([
                'authorized_at',
                'provider_note_status',
                'delivery_origin',
                'delivery_environment',
                'queried_tax_id',
                'delivery_received_at',
                'delivery_correlation_id',
                'storage_state',
                'storage_verified_at',
                'processing_state',
                'processing_reason',
                'next_attempt_at',
                'processing_attempts',
                'processing_started_at',
                'processing_completed_at',
                'processing_claim_expires_at',
            ]);
        });
    }
};
