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
        Schema::create('process_template_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('process_templates')->cascadeOnDelete();
            $table->string('title');
            $table->string('department')->default('Fiscal');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('due_day')->default(20);
            $table->string('priority')->default('medium');
            $table->unsignedInteger('order')->default(1);
            $table->foreignId('default_assignee_member_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['template_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_template_tasks');
    }
};
