<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_accounting_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_fiscal_document_id')->constrained('erp_fiscal_documents')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('fiscal_document_id')->nullable()->constrained('fiscal_documents')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('reason_code', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['erp_company_id', 'erp_fiscal_document_id', 'account_id'],
                'erp_accounting_deliveries_intent_unique',
            );
            $table->index(['erp_company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_accounting_deliveries');
    }
};
