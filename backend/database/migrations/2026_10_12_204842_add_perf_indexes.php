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
        Schema::table('tasks', function (Blueprint $table): void {
            $table->index(['account_id', 'due_on', 'status'], 'tasks_account_due_status_idx');
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->index(['account_id', 'city'], 'clients_account_city_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex('clients_account_city_idx');
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('tasks_account_due_status_idx');
        });
    }
};
