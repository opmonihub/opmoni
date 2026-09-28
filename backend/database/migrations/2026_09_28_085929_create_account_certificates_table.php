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

            // Metadados não secretos, todos eles declarados pelo certificado e
            // nunca pelo formulário: é o que sobra da linha depois que o
            // conteúdo é apagado, e portanto o que faz dela histórico.
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
             * mantém os metadados: é histórico do que foi gravado, não lixo. E
             * nunca há duas linhas correntes porque quem grava tranca a conta e
             * marca a anterior no mesmo passo — a garantia é da aplicação, como
             * no cofre de cliente, e não de um índice, porque um índice comum
             * não consegue dizer "no máximo uma corrente por conta" e um índice
             * parcial exigiria SQL cru em uma migração que precisa rodar
             * também no SQLite da suíte.
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
     */
    public function down(): void
    {
        Schema::dropIfExists('account_certificates');
    }
};
