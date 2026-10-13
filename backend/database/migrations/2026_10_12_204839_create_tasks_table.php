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
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('process_id')->constrained('processes')->cascadeOnDelete();
            $table->string('title');
            $table->string('department')->default('Fiscal');
            $table->text('description')->nullable();
            $table->string('status')->default('todo');
            $table->date('due_on')->nullable();
            $table->string('priority')->default('medium');
            $table->foreignId('assignee_member_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('dismissal_reason')->nullable();
            $table->unsignedInteger('order')->default(1);
            $table->timestamps();
            $table->index(['process_id', 'order']);
            $table->index(['account_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
