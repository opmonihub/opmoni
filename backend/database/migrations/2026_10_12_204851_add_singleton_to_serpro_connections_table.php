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
             * Coluna constante com índice único: toda linha vale 1, então a
             * segunda linha colide. É a forma de o banco — e não uma convenção
             * da aplicação — garantir "uma única credencial da plataforma".
             *
             * Um `lockForUpdate()` não serve aqui: sobre resultado vazio ele não
             * trava nada, e duas primeiras gravações simultâneas leriam ambas
             * "não há credencial" e criariam duas linhas. O índice vale para
             * qualquer processo e qualquer entrada, inclusive uma restauração
             * ou um `insert` manual que nunca passe pelo gerenciador.
             *
             * **Pré-condição de deploy, e ela não é negociável:** rodar esta
             * migração com mais de uma linha em `serpro_connections` falha, e a
             * falha aqui é uma **invariante anulada, não uma indisponibilidade**.
             * A coluna `singleton` não é lida por nenhuma linha do código — a
             * unicidade é do índice, e `SerproConnection::current()` faz
             * `first()` sem ordem justamente porque a segunda linha não
             * existe —, o que significa que a aplicação **funciona
             * perfeitamente com a garantia ausente** e ninguém descobre pela
             * tela. Quem recebe o erro do banco aqui está diante de um ambiente
             * que já tinha duas credenciais, e a correção é resolver qual das
             * duas vale antes de migrar, não repetir a migração.
             *
             * Verificação antes de deployar, em uma linha, e ela é a única
             * forma de saber antes de bater no índice:
             *
             *     SELECT count(*) FROM serpro_connections;
             *
             * O resultado tem de ser `0` ou `1`. Acima de `1`, a migração não
             * roda e a pergunta a responder é qual credencial sobrevive —
             * nenhuma das duas linhas tem o que o índice não possa decidir, e a
             * escolha é de quem responde pela integração, não do banco.
             *
             * O que **não** é uma razão para adiar a migração: a aplicação
             * seguir funcionando sem a garantia. Ela segue funcionando porque
             * ninguém lê a coluna, e é exatamente por isso que o erro precisa
             * ser estrondo e não uma nota de rodapé.
             */
            $table->unsignedTinyInteger('singleton')->default(1);
            $table->unique('singleton', 'serpro_connections_singleton_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('serpro_connections', function (Blueprint $table): void {
            $table->dropUnique('serpro_connections_singleton_unique');
        });

        Schema::table('serpro_connections', function (Blueprint $table): void {
            $table->dropColumn('singleton');
        });
    }
};
