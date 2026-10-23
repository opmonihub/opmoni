<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_catalog_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('schema_version', 32);
            $table->char('token_digest', 64)->unique();
            $table->char('content_digest', 64);
            $table->json('rows');
            $table->json('errors');
            $table->json('totals');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->char('completed_key_digest', 64)->nullable();
            $table->char('completed_payload_hash', 64)->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
            $table->index(['erp_company_id', 'actor_user_id', 'kind']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('erp_catalog_imports') && DB::table('erp_catalog_imports')->exists()) {
            throw new RuntimeException('Catalog import history must be preserved; deploy a compatible application version.');
        }
        Schema::dropIfExists('erp_catalog_imports');
    }
};
