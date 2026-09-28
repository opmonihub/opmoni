<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O termo de autorização do escritório: um por conta, o documento
     * assinado **verbatim** e cifrado, e o token separado dele.
     *
     * A unicidade por conta é do **banco**, e não da aplicação, e a diferença
     * importa aqui mais do que em `account_certificates`. Lá duas linhas
     * correntesproduzem um estado silencioso; aqui duas linhas produziriam
     * duas renovações diárias do mesmo documento com dois tokens em jogo, e
     * nada no produto teria como dizer qual dos dois é o bom — a chamada
     * seguinte levaria o token errado e a recusa apareceria como falha do
     * escritório. Um índice único troca essa corrida improvável por erro de
     * banco no ponto em que ela acontece, que é o lugar certo.
     */
    public function up(): void
    {
        Schema::create('serpro_authorization_terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * O documento que o **escritório** assinou, e o e-CNPJ que
             * assinou, e a renovação precisa dos dois. Sem esta coluna a
             * renovação buscaria o certificado **atual** da conta, e ele pode
             * ter sido trocado desde a assinatura: o termo voltaria ao
             * provedor endereçado a uma empresa sob o qual ele nunca foi
             * assinado. Guardar o autor é o que faz a renovação ser função
             * do termo e não da agenda de certificados — e é o que permite
             * renovar com o e-CNPJ removido da base, que é a prova de que o
             * caminho do `304` não assina nada.
             */
            $table->string('author_document', 14);

            /*
             * O XML assinado, **verbatim**, cifrado com `APP_KEY`.
             *
             * "Verbatim" é o ponto inteiro da tabela: a renovação reenvia
             * exatamente estes bytes, e é o `304` do provedor que devolve o
             * token sem re-assinar. Um documento que mudasse de byte entre a
             * gravação e o reenvio seria outro documento, e a renovação seria
             * uma emissão disfarçada — por isso nada reserializa o resultado
             * depois de assinado, e a renovação decifra e reenvia sem passar
             * pelo assinante.
             *
             * `longText` e não `text`, e a razão é a mesma que fez o
             * certificado do escritório escolher o seu: o `TEXT` do MySQL tem
             * teto de 64 KiB, e o valor aqui é base64 de um X.509 **mais** a
             * assinatura — o que cresce com a cadeia de certificação, e a
             * cadeia é do SERPRO e não nossa. No Postgres os dois são `text`,
             * de modo que a suíte não veria a diferença; o vínculo é com
             * `account_certificates.certificate_encrypted`, que é a coluna
             * irmã e usa a mesma.
             *
             * `Crypt::encryptString()` direto, e **não** o
             * `encrypt-then-base64` do certificado do escritório: o XML é
             * texto, o `base64_encode` do certificado existe porque o PFX é
             * binário e o `decryptString` do Laravel não aceitaria o
             * cifrado de bytes crus. Aplicar a convenção do certificado aqui
             * seria trocar uma instrução que funciona por outra que também
             * funciona, e o ganho seria zero.
             */
            $table->longText('document_encrypted');

            /*
             * O token de autorização do escritório, cifrado pelo mesmo
             * motivo e com a mesma chave. É anulável porque entre a
             * assinatura e a resposta do provedor não há token nenhum, e
             * porque um `304` cujo `etag` não serve **não** pode apagar o
             * token que ainda valia.
             */
            $table->text('token_encrypted')->nullable();

            /*
             * A vigência do documento é um **dia**, e não um instante: o
             * `vigencia/@data` que o termo traz é `AAAAMMDD`, e o documento
             * vale por ele inteiro. Guardar como `date` é o que faz a
             * comparação ser "ainda é hoje" em vez de "ainda não passou o
             * meio-dia", que seria a leitura errada de um dia inteiro de
             * validade.
             */
            $table->date('document_expires_on');

            /*
             * A validade do token é um **instante**, e é outra coisa: o
             * provedor documenta que o token fica válido "até a meia-noite do
             * dia seguinte, horário de Brasília". As duas colunas existem
             * porque as duas coisas vencem em momentos diferentes, e
             * confund-las faria o sistema avisar o escritório sobre o termo
             * quando o que venceu foi o token — ou, pior, devolver um token
             * morto e chamar a sincronização de falha do escritório.
             */
            $table->timestamp('token_expires_at')->nullable();

            /*
             * O estado é o que a tela lê, e o enum que o nomeia é
             * `SerproAuthorizationTermState` — o mesmo conjunto que a spec
             * descreve. A largura é a do **valor mais longo** do enum, e
             * `autenticado` tem onze posições. A versão anterior dizia
             * "treze" e não era verdade: um número errado no comentário de uma
             * coluna é a forma mais barata de a próxima pessoa não alargar a
             * coluna quando o enum ganhar um estado, porque ela lê o
             * comentário, acredita nele e não confere. Há um teste que amarra a
             * largura desta linha ao enum, e é ele que avisa.
             */
            $table->string('state', 11);

            /*
             * O motivo do estado, e ele leva o **código** do provedor ou uma
             * frase fixa nossa — nunca o texto que o provedor escreveu sobre
             * a requisição. A requisição do termo carrega o documento
             * assinado do escritório, e o texto de recusa do provedor descreve
             * esse documento. É a mesma regra de `serpro_connections` e pelo
             * mesmo motivo, e sem esta coluna um `recusado` não teria causa
             * para o operador investigate.
             */
            $table->string('state_reason')->nullable();

            /*
             * Quando o documento foi assinado, e quando o provedor aceitou o
             * envio pela última vez. São coisas diferentes: um termo pode ter
             * sido assinado há trinta dias e aceito ontem, e a segunda data é
             * a que diz se a integração está viva.
             */
            $table->timestamp('signed_at');

            /*
             * Quando o provedor **aceitou** o envio pela última vez, e não
             * quando ele foi tentado.
             *
             * A diferença importa porque a coluna é o que responde "a integração
             * está viva": uma linha `recusado` com `last_submitted_at` de
             * agora diria que o envio foi bem-sucedido há um minuto, e é
             * exatamente o inverso do que o estado ao lado afirma. A coluna é
             * anulável por isso — um termo recém-assinado ainda não tem envio
             * aceito, e `null` é a afirmação honesta disso, ao contrário de um
             * carimbo que mentiria sobre a tentativa que acabou de falhar.
             *
             * Ela é carimbada **depois** da resposta do provedor, e não antes:
             * carimbá-la antes faria a coluna registrar uma intenção.
             */
            $table->timestamp('last_submitted_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_authorization_terms');
    }
};
