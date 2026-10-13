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
        Schema::create('fiscal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('model');
            $table->string('kind');
            $table->char('chave_acesso', 44);
            // NOT NULL com default vazio: no Postgres NULL != NULL, então uma
            // coluna nullable anularia a unicidade para documentos sem evento.
            $table->string('event_id')->default('');
            $table->unsignedBigInteger('nsu');
            $table->string('emitente_cnpj', 14)->nullable();
            $table->string('destinatario_cnpj', 14)->nullable();
            $table->decimal('valor_total', 14, 2)->nullable();
            $table->timestamp('emissao_at')->nullable();
            $table->timestamp('evento_ocorrido_em_at')->nullable();
            $table->string('schema')->nullable();
            $table->string('storage_path');
            $table->char('sha256', 64);
            $table->unsignedInteger('xml_bytes');
            $table->boolean('mascarado')->default(false);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['client_id', 'chave_acesso', 'event_id']);
            $table->index(['client_id', 'model', 'nsu']);
            $table->index(['account_id', 'captured_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
    }
};
