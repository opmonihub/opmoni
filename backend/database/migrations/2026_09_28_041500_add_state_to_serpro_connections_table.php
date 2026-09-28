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
        Schema::table('serpro_connections', function (Blueprint $table): void {
            /*
             * O eixo do estado da **credencial**, e não o do dado: um mesmo par
             * configurado pode estar `invalid` porque o provedor recusou o
             * segredo ontem e voltar a `configured` assim que o operador
             * recadastrar, sem que nada tenha sido sincronizado no meio. É o que
             * `SerproConnectionState` nomeia, e o comprimento da coluna é o do
             * valor mais longo dele (`not_configured`, 14).
             *
             * `state_reason` é o motivo que produziu o estado, e é a outra
             * metade do cenário "recusa na emissão do token" da spec: "marks the
             * connection as invalid, **records the rejection reason** and does
             * not start a synchronization".
             *
             * As duas são anuláveis e sem padrão de propósito, e o motivo não é
             * omissão: um `default('not_configured')` inventaria um estado para
             * a linha já gravada antes desta migração — que está configurada e
             * funcional, e dizer que não está é pior do que não dizer. Sem
             * valor, `null` é a afirmação honesta de "ainda ninguém registrou o
             * que este estado é". Quem escreve o estado passa a definir o que ele
             * significa, e não uma constante inventada aqui.
             *
             * O `state_reason` leva texto **nosso** — o rótulo da falha, o
             * código do provedor — e nunca o texto livre da resposta: o provedor
             * escreve ali o que ele entendeu da requisição, e a requisição
             * carrega o token e o documento do cliente. O mesmo motivo que
             * `SerproClient` não deixa subir para a exceção.
             *
             * Nenhuma das duas é escrita aqui nem por esta mudança: quem marca a
             * conexão como `invalid` quando a emissão do token é recusada é a
             * sincronização, que é a tarefa que ainda vai conferir a
             * autenticação por conta própria. As colunas entram agora porque é
             * esta planagem que já mexe na tabela, e uma migração de estado
             * depois de dado gravado seria uma migração com `default` — que é
             * o que este comentário acima recusa.
             */
            $table->string('state', 14)->nullable();
            $table->string('state_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Uma chamada de `Schema::table` por coluna: é o padrão que o SQLite exige
     * para `dropColumn`, e as duas colunas não têm índice — quem as lê é a linha
     * única da plataforma —, o que resolve o problema que motivaria a
     * `dropIndex` separada.
     */
    public function down(): void
    {
        Schema::table('serpro_connections', function (Blueprint $table): void {
            $table->dropColumn('state');
        });

        Schema::table('serpro_connections', function (Blueprint $table): void {
            $table->dropColumn('state_reason');
        });
    }
};
