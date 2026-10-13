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
        Schema::create('client_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->string('serial_number');
            $table->timestamp('valid_from');
            $table->timestamp('valid_until');
            $table->string('original_filename');
            $table->string('storage_path')->nullable();
            $table->string('sha256', 64);
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'replaced_at', 'removed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_certificates');
    }
};
