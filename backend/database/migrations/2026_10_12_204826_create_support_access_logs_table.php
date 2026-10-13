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
        Schema::create('support_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('super_admin_user_id')->constrained('users');
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->json('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_access_logs');
    }
};
