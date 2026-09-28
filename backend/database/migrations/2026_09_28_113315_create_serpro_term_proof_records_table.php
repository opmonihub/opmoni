<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Onde a auditoria da prova de contrato vive, e por que é uma tabela.
     *
     * A spec exige que o comando `serpro:record-term-proof` "registre uma
     * entrada de auditoria com o digest que gravou", e ela **não diz onde**.
     * As duas casas que o repositório já tem não servem:
     *
     * - `SupportAudit::logWrite()` exige um `Request` e um Membro autenticado
     *   da conta corrente, e faz nada sem os dois. Um comando artisan não
     *   tem `Request` nem Membro, então a entrada sairia vazia — e um
     *   registro vazio parece cobertura e não é.
     * - `support_access_logs` é a trilha de acesso de suporte: super_admin
     *   operando numa conta em que não é membro, com `account_id` e `ip`.
     *   Uma prova de contrato não é acesso de suporte, não tem conta e não
     *   vem de um IP de pessoa.
     *
     * A tabela é o que falta, e ela é **acrescida por construção**: não tem
     * `updated_at`, o que faz um `update` que mudasse um valor deixar a linha
     * com um único carimbo dizendo que aquilo foi gravado naquele momento —
     * mentira. Some-se a isso que o modelo `SerproTermProofRecord` recusa
     * `save()` de linha existente e `delete()`. Uma prova substituída não
     * apaga a anterior: ela vira uma linha nova que carrega o digest e a
     * hora que substituiu, e é por isso que as duas colunas `superseded_*`
     * existem.
     */
    public function up(): void
    {
        Schema::create('serpro_term_proof_records', function (Blueprint $table): void {
            $table->id();

            /*
             * O digest **medido** no momento da gravação, e nunca um valor
             * que o operador digitou: o comando escreve o que
             * `SerproTermSigner::formatDigest()` calcula naquele instante. Uma
             * prova que gravasse a afirmação do operador em vez da medida
             * não provaria nada — e leria como evidência, que é pior do que
             * não ter gate.
             */
            $table->string('term_format_sha256', 64);

            /*
             * Quem rodou o comando, e é uma pessoa nomeada num argumento.
             *
             * Um comando artisan não tem usuário autenticado, e sem este
             * argumento a autoria da prova seria `www-data` — o usuário do
             * processo —, que não é alguém. A spec promete que a prova é
             * "evidência com autor", e é este campo que entrega isso.
             */
            $table->string('recorded_by');

            /*
             * O motivo da gravação, e ele é obrigatório quando esta linha
             * substitui uma prova anterior. Registrar que o formato mudou
             * depois que a prova foi feita é informação que o digest sozinho
             * não dá: o digest novo é o que a linha carrega, e a pergunta
             * que só o motivo responde é por que o antigo deixou de valer.
             */
            $table->text('reason')->nullable();

            /*
             * A prova que esta linha substitui, nas duas metades que
             * importam: o digest anterior e o instante em que ele foi
             * gravado. Carregá-las é o que impede a segunda gravação de
             * apagar de onde veio — e sem isso, uma troca de prova seria
             * indistinguível da primeira gravação.
             */
            $table->string('superseded_sha256', 64)->nullable();
            $table->timestamp('superseded_at')->nullable();

            /*
             * Só `created_at`, e é a ausência de `updated_at` que é a
             * garantia — está escrito na docblock da migração. A coluna usa
             * `useCurrent()` pelo mesmo motivo da `support_access_logs`:
             * o valor é o momento do `insert` e ninguém o reescreve.
             */
            $table->timestamp('created_at')->useCurrent();

            $table->index('term_format_sha256');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_term_proof_records');
    }
};
