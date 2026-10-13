<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * As duas colunas do gate de emissão do termo de autorização, e a
     * prova que elas guardam.
     *
     * O predicado é da spec, palavra por palavra: a emissão acontece se, e
     * somente se, `term_format_proven_at` não for nulo **e**
     * `term_format_sha256` for igual a `SerproTermSigner::formatDigest()`. As
     * duas metades são necessárias e nenhuma basta — por isso o método
     * `SerproConnection::termProof()` existe e é o único lugar onde a
     * comparação é escrita, e é ele que diz qual das duas falhou.
     *
     * **Por que a prova mora na credencial de plataforma.** É a linha única
     * da plataforma, e o fato que ela guarda é sobre o provedor — não sobre
     * nenhuma conta. Uma prova por conta seria a mesma verdade copiada `N`
     * vezes, e as cópias divergem: uma delas esquecida continuaria autorizando
     * um formato que as outras já não reconhecem. Uma prova por deploy
     * seria o oposto do que a spec pede, que é que ela sobreviva ao redeploy.
     *
     * **As duas colunas são anuláveis e sem padrão, e o motivo é o mesmo da
     * coluna `state` desta tabela:** um `default` inventaria uma prova para a
     * linha gravada antes desta migração, e uma prova inventada é exatamente
     * a falha que o gate existe para impedir. `null` é a afirmação honesta de
     * "ninguém registrou o que este formato é", e é a condição que fecha o
     * gate numa instalação nova — que é o estado correto dela.
     *
     * As duas **não** são `Fillable` no modelo, e a ausência é a garantia, na
     * mesma forma que vale para as colunas cifradas daquela tabela: a
     * spec declara que nenhuma requisição, nenhum job e nenhuma agenda
     * escreve estas colunas, e o único escritor sancionado é o comando
     * `serpro:record-term-proof`.
     */
    public function up(): void
    {
        Schema::table('serpro_connections', function (Blueprint $table): void {
            /*
             * O SHA-256 do **formato** do termo, e não o de um termo nem o de
             * uma instância: é o que `SerproTermSigner::formatDigest()`
             * calcula, e por isso muda sozinho quando o modelo do documento
             * ou qualquer uma das quatro constantes de formato mudam. É isso
             * que torna a prova auto-invalidadora — mudar o documento reabre
             * o gate sem ninguém decidir reabrir.
             *
             * Sessenta e quatro posições são as do SHA-256 em hexadecimal, e
             * o comprimento é explícito para que um digest de outro tamanho
             * seja recusado pelo banco e não pela comparação de string.
             */
            $table->string('term_format_sha256', 64)->nullable();

            /*
             * O instante em que o teste de contrato foi feito, e não em que a
             * coluna foi preenchida — que é o mesmo, e é por isso que a
             * coluna existe: a data que responde "quando foi provado" é a
             * informação que o operador precisa quando a prova está valendo
             * e alguém pergunta há quanto tempo.
             */
            $table->timestamp('term_format_proven_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Uma chamada de `Schema::table` por coluna: é o padrão que o SQLite
     * exige para `dropColumn`, e as duas colunas não têm índice — quem as lê
     * é a linha única da plataforma —, o que resolve o problema que
     * motivaria a `dropIndex` separada.
     */
    public function down(): void
    {
        Schema::table('serpro_connections', function (Blueprint $table): void {
            $table->dropColumn('term_format_sha256');
        });

        Schema::table('serpro_connections', function (Blueprint $table): void {
            $table->dropColumn('term_format_proven_at');
        });
    }
};
