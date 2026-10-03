<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O certificado contratante da credencial pode ser o e-CNPJ que a conta 1 já
     * gravou, sem uma segunda cópia do arquivo.
     *
     * `contracting_account_id` aponta para a Account dona do certificado de
     * escritório que a credencial deve usar, e a resolução é por leitura:
     * `SerproConnection::contractingCertificate()` devolve a linha corrente de
     * `account_certificates` dessa conta, de modo que uma troca do e-CNPJ pela
     * conta não exige nenhuma ação aqui — o link sobrevive à rotação.
     *
     * Anulável e sem valor padrão, pelo mesmo motivo das duas colunas do gate de
     * emissão que esta tabela já tem: um padrão inventaria um vínculo para a
     * credencial gravada antes desta migração, e o estado dela é "certificado
     * próprio gravado em `certificate_encrypted`" — que é o que `null` declara.
     *
     * A integridade é referencial e deliberada: se a conta apontada sumir, a
     * credencial não pode continuar fingindo que tem um certificado para usar —
     * a linha morre junto, que é o destino certo de uma credencial cuja metade
     * de assinatura acabou de deixar de existir.
     */
    public function up(): void
    {
        Schema::table('serpro_connections', function (Blueprint $table): void {
            $table->foreignId('contracting_account_id')
                ->nullable()
                ->constrained('accounts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('serpro_connections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contracting_account_id');
        });
    }
};
