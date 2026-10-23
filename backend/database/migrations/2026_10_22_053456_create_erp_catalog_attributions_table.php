<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('erp_catalog_attributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('access_kind', 16);
            // Historical authority identities must survive membership/delegation removal.
            $table->unsignedBigInteger('erp_company_membership_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('erp_office_delegation_id')->nullable();
            $table->string('action', 32);
            $table->string('resource_type', 32);
            $table->unsignedBigInteger('resource_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->timestampTz('recorded_at');
            $table->index(['erp_company_id', 'resource_type', 'resource_id'], 'erp_catalog_attributions_resource_index');
            $table->index(['erp_company_id', 'batch_id'], 'erp_catalog_attributions_batch_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('erp_catalog_attributions') && DB::table('erp_catalog_attributions')->exists()) {
            throw new RuntimeException('Catalog attribution history must be preserved; deploy a compatible application version.');
        }
        Schema::dropIfExists('erp_catalog_attributions');
    }
};
