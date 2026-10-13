<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color')->default('neutral');
            $table->timestamps();

            $table->unique(['account_id', 'name']);
        });

        Schema::create('client_tag', function (Blueprint $table): void {
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->unique(['client_id', 'tag_id']);
            $table->index(['account_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_tag');
        Schema::dropIfExists('tags');
    }
};
