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
        Schema::table('processes', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('process_templates')->nullOnDelete();
            $table->date('reference_month')->nullable();
            $table->string('status')->default('open');
            $table->date('due_on')->nullable();
            $table->unique(['account_id', 'template_id', 'client_id', 'reference_month'], 'processes_template_client_month_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processes', function (Blueprint $table): void {
            $table->dropUnique('processes_template_client_month_unique');
            $table->dropConstrainedForeignId('template_id');
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['reference_month', 'status', 'due_on']);
        });
    }
};
