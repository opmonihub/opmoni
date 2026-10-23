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
        Schema::create('erp_accounting_delivery_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('erp_accounting_delivery_id')->constrained('erp_accounting_deliveries')->restrictOnDelete();
            $table->unsignedInteger('generation');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->unsignedInteger('attempt_sequence')->default(1);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error', 80)->nullable();
            $table->timestamps();
            $table->unique(['erp_accounting_delivery_id', 'generation'], 'erp_accounting_delivery_dispatch_generation_unique');
            $table->index(['status', 'available_at', 'lease_expires_at'], 'erp_accounting_delivery_dispatch_due_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('erp_accounting_delivery_dispatches');
    }
};
