<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Os Contatos de envio do cliente: uma linha por destino (WhatsApp ou
     * e-mail), sempre escopada à conta — a unique lógica é por
     * (cliente, canal, valor), e em sqlite não há índice parcial portátil,
     * então ela é composta normal.
     *
     * O backfill semeia: cada `clients.phone` não nulo vira um registro
     * `whatsapp` sem rótulo, com o telefone normalizado em dígitos e a
     * conta lida da própria linha do cliente — a `account_id` nunca vem
     * de sessão, porque aqui não há sessão.
     */
    public function up(): void
    {
        Schema::create('client_dispatch_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('channel');
            $table->string('value');
            $table->timestamps();

            $table->unique(['client_id', 'channel', 'value']);
            $table->index(['account_id', 'client_id']);
        });

        DB::table('clients')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($clients): void {
                $agora = now();

                $linhas = $clients
                    ->map(fn ($client) => [
                        'account_id' => $client->account_id,
                        'client_id' => $client->id,
                        'label' => null,
                        'channel' => 'whatsapp',
                        'value' => (string) preg_replace('/\D+/', '', (string) $client->phone),
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ])
                    ->filter(fn (array $linha): bool => $linha['value'] !== '')
                    ->values()
                    ->all();

                if ($linhas !== []) {
                    DB::table('client_dispatch_contacts')->insert($linhas);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_dispatch_contacts');
    }
};
