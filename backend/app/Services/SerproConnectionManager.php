<?php

namespace App\Services;

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
    ): SerproConnection {
        $rotated = false;

        try {
            $connection = DB::transaction(function () use ($key, $secret, $certificate, $password, &$rotated): SerproConnection {
                $locked = SerproConnection::query()->lockForUpdate()->first();

                if ($locked === null) {
                    $this->refuseIncompleteFirstWrite($key, $secret, $certificate, $password);

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
                        $this->certificateAttributes($certificate, $password),
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

                if ($certificate !== null) {
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
    ): void {
        $missing = [];

        if ($key === '') {
            $missing['consumer_key'] = 'Informe a chave de integração do Integra Contador.';
        }

        if ($secret === null || $secret === '') {
            $missing['consumer_secret'] = 'Informe o segredo da chave de integração.';
        }

        if ($certificate === null) {
            $missing['certificate'] = 'Envie o certificado do contratante.';
        }

        if ($password === null || $password === '') {
            $missing['password'] = 'Informe a senha do certificado do contratante.';
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
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
    private function certificateAttributes(UploadedFile $certificate, ?string $password): array
    {
        $bytes = $certificate->get();
        $parsed = $this->identity->parse($bytes, $password ?? '');

        $attributes = [
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
