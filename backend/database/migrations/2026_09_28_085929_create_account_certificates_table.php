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
        Schema::create('account_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            /*
             * O documento contratante vem do certificado — é o CNPJ dentro do
             * e-CNPJ — e a `Account` **não** tem coluna de CNPJ: não há contra o
             * que comparar, e comparar inventando uma coluna seria inventar a
             * fonte. Ele é gravado no mesmo passo que decifra o arquivo, e o
             * `SerproCertificateIdentity` é quem valida: quatorze posições que
             * podem ter letra nos doze primeiros dígitos.
             */
            $table->string('document', 14);

            // Metadados não secretos, todos eles declarados pelo certificado:
            // é o que sobra da linha depois que o conteúdo é apagado, e
            // portanto o que faz dela histórico.
            //
            // "Nunca pelo formulário" é o que o **cofre** decide, e não o que a
            // coluna impede: `subject` e `serial_number` estão no `#[Fillable]`
            // do model, e um teste afirma isso de propósito — a garantia é de que
            // o `fill()` de uma requisição não os alcance, e o que a impede é a
            // request de upload marcar `document` como `prohibited` e o cofre ser
            // o autor dos valores. A coluna aceita o que a AC emitiu; quem decide
            // o que entra é `AccountCertificateVault`, e ele apara `subject` e
            // `original_filename` no limite das duas.
            $table->string('subject');
            $table->string('serial_number');
            $table->timestamp('valid_from');
            $table->timestamp('valid_until');
            $table->string('original_filename');
            $table->string('sha256', 64);

            /*
             * O e-CNPJ fica **no banco**, cifrado, e não em disco: o sistema de
             * arquivos do container do Laravel é efêmero em produção, e um
             * certificado em arquivo sumiria a cada recriação — o escritório
             * voltaria a ter que autorizar depois de todo deploy.
             *
             * A ordem é cifrar a base64, e não o contrário: `base64_decode` de
             * texto que ainda está cifrado devolve `false` e o certificado
             * some em silêncio. Por isso **não existe coluna de caminho** aqui,
             * ao contrário de `client_certificates`, que continua no disco
             * porque os certificados de cliente que já vieram com o produto não
             * estão sendo movidos.
             *
             * Quem decifra é `AccountCertificate::certificateBytes()`, e
             * `APP_KEY` é o que cifra estas colunas — o que significa que
             * rotacionar a chave da aplicação apaga todo e-CNPJ de todos os
             * escritórios, sem recuperação possível.
             */
            $table->longText('certificate_encrypted')->nullable();
            $table->text('password_encrypted')->nullable();

            /*
             * Os dois marcadores que separam "o certificado que o escritório tem
             * hoje" de "o certificado que o escritório já teve".
             *
             * Uma linha trocada ou removida perde as duas colunas cifradas e
             * mantém os metadados: é histórico do que foi gravado, não lixo.
             *
             * **"No máximo uma linha corrente por conta" é garantido pela
             * aplicação, não pelo banco** — `AccountCertificateVault::replace()`
             * tranca a conta com `lockForUpdate()` e marca a anterior no mesmo
             * passo, dentro de uma transação.
             *
             * Um índice parcial daria a garantia ao banco, e a sintaxe é
             * `CREATE UNIQUE INDEX … ON account_certificates (account_id) WHERE
             * replaced_at IS NULL AND removed_at IS NULL`. Ela **não** foi usada,
             * e o motivo que a segura é o da portabilidade entre dialectos, não
             * o da falta de ferramenta:
             *
             * 1. **A seção condicional do índice não existe no MySQL.** Um
             *    índice único parcial é de PostgreSQL e SQLite; o MySQL não tem a
             *    noção de um índice com `WHERE`. Como a migração roda em todas as
             *    bases que o produto suporta, ela teria de ser reescrita à mão por
             *    dialecto — que é o custo que o schema builder existe para
             *    evitar, e aqui ele não evita. `Blueprint::rawIndex()` existe e
             *    escreveria o `CREATE UNIQUE INDEX` no Postgres sem problema
             *    nenhum: o que impede é que a mesma linha de migração não pode
             *    dizer a mesma coisa nos três dialetos.
             * 2. Um índice único troca uma corrida perdida por **erro de banco**.
             *    Hoje a corrida perdida é resolvida pelo `lockForUpdate()`, que
             *    serializa as duas gravações e faz a segunda ver a linha que a
             *    primeira criou. Com o índice, as duas chegam ao mesmo ponto e uma
             *    delas morre de violação — o que obriga o chamador a tratar uma
             *    exceção de banco numa operação que antes não tinha por que
             *    falhar. O índice não impede o estado ruim; ele transforma um
             *    conflito raro em erro, e é esse o argumento que decide mesmo no
             *    Postgres, onde o índice seria possível.
             *
             * E a consequência de uma duplicata **existe**, e ela é medida, não
             * suposta. `supersede()`, `remove()` e `currentFor()` são os três
             * `latest('id')`, e é essa combinação — não "marcar a primeira" — que
             * produz o resultado. Com duas linhas correntes, A (id 1) e B (id 2):
             *
             * - Um **DELETE** marca **B** (a de maior `id`) e devolve `204`. A
             *   linha A continua corrente **e com `certificate_encrypted`
             *   preenchido**, e `currentFor()` passa a devolver A. O escritório
             *   recebeu a confirmação de que o certificado foi removido e
             *   continua podendo assinar o termo com ele.
             * - Um **upload** marca **B** e cria C, que passa a ser a corrente.
             *   A continua corrente, com o conteúdo dela, e nenhuma troca
             *   futura a marca: `latest('id')` devolve sempre a de maior `id`
             *   entre as que faltam marcar, e A é a de menor `id`.
             *
             * O sintoma é um termo assinado com um e-CNPJ que o operador já
             * removeu, e **não** um stack trace — o que é o que torna isto um
             * estado silencioso. O conserto de uma duplicata é, portanto, de
             * inspeção: a linha que sobrou corrente — a de **menor** `id` — é a
             * que tem de ser marcada à mão, e é por `id`, não por posição, que
             * se procura.
             */
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            // A consulta que toda leitura faz: a linha corrente desta conta.
            $table->index(['account_id', 'replaced_at', 'removed_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * A tabela cai inteira e não há coluna a desfazer uma a uma, o que é o que
     * faz este `down()` funcionar igual nos três engines. O `dropColumn` de
     * outra migração deste diretório tem a outra forma — e a diferença importa
     * onde ela aparece: o SQLite abaixo de 3.35 **não** apaga coluna e a
     * instrução vira um no-op silencioso, de modo que um `down()` feito de
     * `dropColumn` deixa a coluna no lugar e o `up()` seguinte falha.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_certificates');
    }
};
