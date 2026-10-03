<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\SerproConnection;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * A credencial do Integra Contador é uma só, da plataforma, e não pertence a
 * nenhuma conta: existe no máximo uma linha, e quem a escreve é o super_admin.
 *
 * Gravar é rotacionar. O que a request não traz continua como está — omitir o
 * segredo mantém o guardado, que é o que a tela de rotação precisa — e o que
 * ela traz substitui. O documento contratante nunca vem da request: é extraído
 * do PFX no mesmo passo em que o PFX é gravado, para que a coluna nunca possa
 * divergir do certificado que a justificou.
 */
final class SerproConnectionManager
{
    public function __construct(
        private SerproCertificateIdentity $identity,
        private SerproTokenProvider $tokens,
    ) {}

    /**
     * @throws ValidationException
     */
    public function save(
        string $key,
        ?string $secret,
        ?UploadedFile $certificate,
        ?string $password,
        bool $useAccountCertificate = false,
    ): SerproConnection {
        $rotated = false;

        // Um arquivo e o vínculo com o e-CNPJ do escritório são as duas formas
        // de o contratante ter um certificado, e as duas são excludentes: uma
        // request que traz as duas não diz qual o operador quis, e aceitar uma
        // delas em silêncio gravaria uma credencial que não é a que ele pediu.
        if ($useAccountCertificate && $certificate !== null) {
            throw ValidationException::withMessages([
                'certificate' => 'Escolha uma das duas fontes do certificado contratante: o e-CNPJ já gravado em Configurações, ou um arquivo novo — não as duas.',
            ]);
        }

        try {
            $connection = DB::transaction(function () use ($key, $secret, $certificate, $password, $useAccountCertificate, &$rotated): SerproConnection {
                $locked = SerproConnection::query()->lockForUpdate()->first();

                if ($locked === null) {
                    $this->refuseIncompleteFirstWrite($key, $secret, $certificate, $password, $useAccountCertificate);

                    // Cadastrar a credencial também invalida o token: um par em cache
                    // de uma linha que foi apagada continua sendo um token válido.
                    $rotated = true;

                    // `forceCreate`, e não `create`: o segredo gravado e o
                    // certificado extraído **não** são `Fillable` do modelo, e é
                    // essa a garantia de que nenhum `fill()` de request os
                    // alcance. Esta classe é o autor desses valores — ela os
                    // cifrou neste mesmo passo —, e é por isso que ela, e só
                    // ela, atravessa a lista.
                    return SerproConnection::forceCreate(array_merge(
                        ['consumer_key' => $key, 'consumer_secret_encrypted' => Crypt::encryptString((string) $secret)],
                        $this->certificateAttributes($certificate, $password, $useAccountCertificate),
                    ));
                }

                $attributes = [];

                if ($key !== '') {
                    $attributes['consumer_key'] = $key;
                    $rotated = true;
                }

                if ($secret !== null && $secret !== '') {
                    $attributes['consumer_secret_encrypted'] = Crypt::encryptString($secret);
                    $rotated = true;
                }

                if ($useAccountCertificate) {
                    // O vínculo troca a fonte do certificado: a linha passa a
                    // ler o e-CNPJ corrente da conta apontada, e as colunas
                    // próprias — arquivo e senha — são apagadas para que não
                    // fiquem duas verdades sobre o mesmo certificado.
                    $attributes = array_merge($attributes, $this->linkedCertificateAttributes());
                    $rotated = true;
                } elseif ($certificate !== null) {
                    $attributes = array_merge($attributes, $this->certificateAttributes($certificate, $password));
                    $rotated = true;
                } elseif ($password !== null && $password !== '') {
                    $this->confirmPassword($locked, $password);
                    $attributes['certificate_password_encrypted'] = Crypt::encryptString($password);
                    $rotated = true;
                }

                $locked->forceFill($attributes)->save();

                return $locked;
            });
        } catch (UniqueConstraintViolationException $collision) {
            /*
             * O índice de `singleton` recusou a segunda linha: outra gravação
             * chegou primeiro e criou a credencial enquanto esta ainda não a
             * via. O `insert` desta tabela só pode colidir nele — a chave
             * primária é gerada pelo banco e não colide em inserção —, então a
             * recusa tem nome e não é um 500 para o operador. Nada do que veio
             * nesta requisição foi gravado: a transação inteira caiu.
             *
             * O log leva a frase e a classe da exceção, nunca a mensagem dela: a
             * mensagem do banco traz o `insert` com os valores — segredo, senha
             * e PFX cifrado — e um log que os reproduz seria o vazamento que a
             * cifra da coluna existe para evitar.
             */
            Log::warning('Outra gravação da credencial do Integra Contador foi recusada pelo índice único `serpro_connections.singleton`.', [
                'exception' => $collision::class,
            ]);

            throw ValidationException::withMessages([
                'consumer_key' => 'Outra gravação da credencial do Integra Contador chegou primeiro e criou a linha única. Nada desta foi gravada: recarregue a tela e rotacione a credencial que ficou.',
            ]);
        }

        // Depois do commit: um rollback não pode derrubar um token válido, e a
        // rotação é justamente o momento em que ele deixa de valer.
        if ($rotated) {
            $this->tokens->forget();
        }

        return $connection;
    }

    /**
     * A primeira gravação não tem nada para preservar: sem chave, segredo,
     * certificado e senha não existe credencial, e gravar parcial deixaria uma
     * linha que o provedor rejeitaria por um motivo que o operador não vê.
     *
     * @throws ValidationException
     */
    private function refuseIncompleteFirstWrite(
        string $key,
        ?string $secret,
        ?UploadedFile $certificate,
        ?string $password,
        bool $useAccountCertificate = false,
    ): void {
        $missing = [];

        if ($key === '') {
            $missing['consumer_key'] = 'Informe a chave de integração do Integra Contador.';
        }

        if ($secret === null || $secret === '') {
            $missing['consumer_secret'] = 'Informe o segredo da chave de integração.';
        }

        // O certificado pode ser um arquivo novo ou o e-CNPJ que a conta 1 já
        // gravou: a primeira gravação precisa de um dos dois, e a recusa nomeia
        // as duas saídas para que o operador saiba qual caminho tomar.
        if ($certificate === null && ! $useAccountCertificate) {
            $missing['certificate'] = 'Envie o certificado do contratante, ou use o e-CNPJ já gravado em Configurações da conta 1.';
        }

        // Com o e-CNPJ emprestado a senha vem dele, não do formulário.
        if ($certificate !== null && ($password === null || $password === '')) {
            $missing['password'] = 'Informe a senha do certificado do contratante.';
        }

        if ($useAccountCertificate) {
            $this->officeCertificateOrFail();
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    /**
     * O e-CNPJ que a conta 1 já gravou em Configurações, e a recusa quando ele
     * não existe.
     *
     * "A conta 1" é a primeira `Account` do banco, que é a definição que o
     * seed local usa e a que o produto chama de "o escritório da plataforma":
     * `contracting_account_id` aponta para ela, e a leitura é da linha
     * corrente — uma troca do e-CNPJ pela conta segue valendo para a
     * credencial sem nenhum reenvio aqui.
     *
     * @throws ValidationException
     */
    private function officeCertificateOrFail(): AccountCertificate
    {
        $account = Account::query()->orderBy('id')->first();

        $certificate = $account === null
            ? null
            : AccountCertificate::currentFor($account->getKey());

        if ($certificate === null) {
            throw ValidationException::withMessages([
                'certificate' => 'A conta 1 ainda não tem e-CNPJ gravado em Configurações: entregue o certificado lá, ou envie um arquivo nesta tela.',
            ]);
        }

        return $certificate;
    }

    /**
     * Os atributos da credencial que aponta para o e-CNPJ do escritório.
     *
     * As colunas de certificado próprio ficam vazias de propósito: é o que diz
     * que a fonte é outra, e é o que impede que uma cópia cifrada do mesmo
     * arquivo fique presa nesta linha. O documento contratante é o da linha de
     * `account_certificates` — extraído do PFX no upload — e é gravado aqui no
     * mesmo passo, pela mesma razão de sempre: a coluna não pode divergir do
     * certificado que a justificou.
     *
     * @return array<string, mixed>
     */
    private function linkedCertificateAttributes(): array
    {
        $certificate = $this->officeCertificateOrFail();

        return [
            'contracting_account_id' => $certificate->account_id,
            'contratante_numero' => $certificate->document,
            'contratante_tipo' => SerproEnvelope::tipo($certificate->document),
            'certificate_encrypted' => null,
            'certificate_password_encrypted' => null,
            'certificate_subject' => null,
            'certificate_serial_number' => null,
            'certificate_valid_from' => null,
            'certificate_valid_until' => null,
        ];
    }

    /**
     * O certificado é a autoridade: o documento, o titular, a série e a validade
     * gravados são os que ele declara, e não os que a request poderia afirmar.
     *
     * O tipo do contratante sai do mesmo documento, pela mesma regra do envelope,
     * e não do padrão da coluna: são o mesmo dado em duas colunas, e as duas são
     * escritas aqui no mesmo passo para que não possam divergir.
     *
     * Nada é apagado dos bytes ao fim deste método. Uma cópia local zerada não
     * apaga o PFX de lugar nenhum — nem da memória, nem do arquivo temporário do
     * upload, nem do disco do container —, e fingir que apaga é pior do que não
     * dizer nada: o material continua onde está e o código deixa de dizer a
     * verdade sobre ele.
     *
     * @return array<string, mixed>
     */
    private function certificateAttributes(?UploadedFile $certificate, ?string $password, bool $useAccountCertificate = false): array
    {
        // O vínculo com o e-CNPJ do escritório substitui as colunas de arquivo:
        // os bytes e a senha moram na linha corrente de `account_certificates`,
        // e esta linha guarda só a referência — que é o que "sem segunda
        // cópia" significa no contrato.
        if ($useAccountCertificate) {
            return $this->linkedCertificateAttributes();
        }

        if ($certificate === null) {
            return [];
        }

        $bytes = $certificate->get();
        $parsed = $this->identity->parse($bytes, $password ?? '');

        $attributes = [
            // Um arquivo novo encerra o vínculo: a partir daqui a fonte do
            // certificado contratante volta a ser esta linha, e o campo fica
            // `null` para que nenhuma leitura confunda as duas fontes.
            'contracting_account_id' => null,
            'contratante_numero' => $parsed['document'],
            'contratante_tipo' => SerproEnvelope::tipo($parsed['document']),
            'certificate_encrypted' => Crypt::encryptString($bytes),
            'certificate_subject' => $parsed['subject'],
            'certificate_serial_number' => $parsed['serial'],
            'certificate_valid_from' => $parsed['valid_from'],
            'certificate_valid_until' => $parsed['valid_until'],
        ];

        if ($password !== null && $password !== '') {
            $attributes['certificate_password_encrypted'] = Crypt::encryptString($password);
        }

        return $attributes;
    }

    /**
     * Trocar a senha sem reenviar o PFX é legítimo, mas a senha nova precisa
     * abrir o certificado guardado: gravá-la sem conferir deixaria a credencial
     * com um segredo que não abre nada, e a falha só apareceria na primeira
     * chamada, como recusa do provedor.
     *
     * Sem certificado guardado não há o que conferir, e a senha é recusada em
     * vez de gravada — uma linha sem PFX só existe por factory, migração ou
     * escrita fora daqui, e guardar a senha nela produziria uma credencial que
     * nada consegue ler.
     *
     * Certificado guardado que não abre é o mesmo caso com outra causa: chave de
     * aplicação trocada, coluna truncada ou linha restaurada de outro ambiente.
     * Subir o `DecryptException` viraria `500` no operador que está tentando
     * recuperar a credencial, e o conserto — reenviar o certificado — é outro
     * campo do formulário. Por isso a recusa é nomeada e recai no `password`.
     */
    private function confirmPassword(SerproConnection $connection, string $password): void
    {
        if ($connection->certificate_encrypted === null) {
            throw ValidationException::withMessages([
                'password' => 'Não há certificado do contratante gravado para conferir esta senha.',
            ]);
        }

        try {
            $bytes = (string) $connection->certificateBytes();
        } catch (DecryptException) {
            throw ValidationException::withMessages([
                'password' => 'Não foi possível conferir a senha: o certificado do contratante gravado não pôde ser lido. Envie o certificado de novo.',
            ]);
        }

        $this->identity->document($bytes, $password);
    }
}
