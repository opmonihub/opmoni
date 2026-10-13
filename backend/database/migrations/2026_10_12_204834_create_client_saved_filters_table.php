<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_saved_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('q')->nullable();
            $table->json('filters');
            $table->timestamps();

            $table->unique(['account_id', 'user_id', 'name']);
            $table->index(['account_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_saved_filters');
    }
};
