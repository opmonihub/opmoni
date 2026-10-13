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
        Schema::create('fiscal_manifestations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            // Mesma largura de `fiscal_documents` após a migration de NFS-e.
            $table->string('chave_acesso', 50);
            $table->string('event_type', 6);
            $table->unsignedTinyInteger('event_seq')->default(1);
            $table->string('requested_by');
            $table->string('outcome');
            $table->timestamp('requested_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('resulted_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'client_id', 'chave_acesso', 'event_type', 'event_seq']);
            $table->index(['account_id', 'client_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_manifestations');
    }
};
