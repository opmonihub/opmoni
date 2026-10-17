<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_effect_journal', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('actor_id');
            $table->uuid('operation_id');
            $table->char('resource_hash', 64);
            $table->char('effect_key', 64)->unique();
            $table->string('step', 64);
            $table->string('state', 16);
            $table->string('receipt', 16)->nullable();
            $table->uuid('owner_token');
            $table->unsignedInteger('version')->default(1);
            $table->text('payload_encrypted')->nullable();
            $table->timestampTz('armed_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();
            $table->index(['account_id', 'resource_hash', 'operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_effect_journal');
    }
};
