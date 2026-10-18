<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_operation_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 64);
            $table->string('key_digest', 64);
            $table->string('canonical_version', 16);
            $table->string('payload_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->json('resource_refs');
            $table->timestampTz('recorded_at');
            $table->timestamps();
            $table->unique(['erp_company_id', 'actor_user_id', 'operation', 'key_digest'], 'erp_operation_receipts_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_operation_receipts');
    }
};
