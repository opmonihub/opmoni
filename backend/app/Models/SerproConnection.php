<?php

namespace App\Models;

use App\Enums\SerproFailure;
use App\Services\SerproCertificateIdentity;
use App\Services\SerproException;
use Database\Factories\SerproConnectionFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'consumer_key',
    'consumer_secret_encrypted',
    'certificate_encrypted',
    'certificate_password_encrypted',
    'certificate_subject',
    'certificate_serial_number',
    'certificate_valid_from',
    'certificate_valid_until',
    'contratante_numero',
    'contratante_tipo',
])]
// Nenhuma coluna cifrada pode sair por `toArray()`/`toJson()`: a resource lista
// o que devolve, e isto é o que garante que uma lista nova não vaze o segredo.
#[Hidden([
    'consumer_secret_encrypted',
    'certificate_encrypted',
    'certificate_password_encrypted',
])]
class SerproConnection extends Model
{
    /** @use HasFactory<SerproConnectionFactory> */
    use HasFactory;

    /**
     * A chave da identidade extraída, derivada do texto cifrado do certificado:
     * trocar o certificado gera outra chave, então o cache não sobrevive à
     * rotação, e o `v1` invalida o que uma regra de extração anterior tenha
     * gravado. O que é cacheado é o documento — que a API já publica — e nunca
     * o segredo, a senha ou os bytes do PFX.
     */
    private const IDENTITY_CACHE_PREFIX = 'serpro:identity:v1:';

    protected function casts(): array
    {
        return [
            'certificate_valid_from' => 'datetime',
            'certificate_valid_until' => 'datetime',
        ];
    }

    /**
     * A credencial é da plataforma, não de uma conta: exatamente uma linha.
     *
     * "Exatamente uma" é garantido pelo índice único da coluna `singleton`, e
     * não por esta consulta nem por qualquer trava do gerenciador: o índice
     * vale para qualquer processo e qualquer entrada. Por isso o `first()` sem
     * ordem é seguro aqui — a segunda linha não existe.
     */
    public static function current(): ?self
    {
        return self::query()->first();
    }

    public function isConfigured(): bool
    {
        return (string) $this->consumer_key !== ''
            && $this->consumer_secret_encrypted !== null;
    }

    public function consumerSecret(): string
    {
        return Crypt::decryptString($this->consumer_secret_encrypted);
    }

    public function certificateBytes(): ?string
    {
        return $this->certificate_encrypted === null
            ? null
            : Crypt::decryptString($this->certificate_encrypted);
    }

    public function certificatePassword(): ?string
    {
        return $this->certificate_password_encrypted === null
            ? null
            : Crypt::decryptString($this->certificate_password_encrypted);
    }

    /**
     * Pré-condição da primeira chamada: o CNPJ dentro do certificado guardado
     * precisa ser o mesmo que a coluna de contratante manda para o envelope.
     *
     * Falhar aqui troca o `403` do provedor — que, para uma credencial
     * compartilhada, não distingue documento errado de senha errada — por uma
     * falha de configuração nomeada, sem nenhuma ida à rede. A comparação usa
     * o certificado gravado, não o que o formulário mandou.
     *
     * @throws SerproException
     */
    public function assertIdentity(): void
    {
        if ($this->certificate_encrypted === null) {
            // Sem certificado não há identidade a conferir; quem recusa a
            // credencial sem certificado é o materializador, logo em seguida.
            return;
        }

        if ($this->certificate_valid_until !== null && $this->certificate_valid_until->isPast()) {
            throw new SerproException(
                'O certificado do contratante está vencido.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

        $document = $this->cachedDocument();
        $gravado = (string) $this->contratante_numero;

        // Nenhum dos dois é segredo — o documento contratante é publicado pela
        // API — então a mensagem nomeia os dois: um `403` do provedor sem dizer
        // qual CNPJ discordou é o defeito que esta guarda existe para evitar. E
        // uma coluna sem documento não é divergência: a coluna é `NOT NULL` mas
        // aceita a string vazia, e "é do CNPJ X, e não ." não diria nada.
        if ($document !== $gravado) {
            throw new SerproException(
                $gravado === ''
                    ? "O certificado do contratante é do CNPJ {$document}, e a credencial não tem documento contratante gravado."
                    : "O certificado do contratante é do CNPJ {$document}, e não {$gravado}.",
                SerproFailure::DoNotRetry,
                0,
            );
        }
    }

    /**
     * O documento contratante é lido uma vez por versão do certificado cifrado e
     * cacheado pelo resto do dia.
     *
     * A leitura do cifrado guardado é parte desta responsabilidade, e não um
     * detalhe dela: `APP_KEY` girada, coluna truncada ou linha restaurada de
     * outro ambiente deixam o conteúdo ilegível, e o `DecryptException` disso
     * subia cru para os três consumidores deste método — teste de conectividade,
     * `SerproClient` e `SerproTokenProvider` — como `500`. Vira aqui uma falha
     * nomeada, sem o texto do OpenSSL e sem o caminho do certificado, que é a
     * mesma forma que a identidade ilegível já tinha.
     *
     * @throws SerproException
     */
    private function cachedDocument(): string
    {
        $cacheKey = self::IDENTITY_CACHE_PREFIX.hash('sha256', (string) $this->certificate_encrypted);

        $document = Cache::remember($cacheKey, now()->addDay(), function (): string {
            try {
                $bytes = (string) $this->certificateBytes();
                $password = (string) $this->certificatePassword();
            } catch (DecryptException) {
                throw new SerproException(
                    'O certificado do contratante não pôde ser lido: o conteúdo guardado não abre com a chave de aplicação atual.',
                    SerproFailure::NotSent,
                    0,
                );
            }

            try {
                return resolve(SerproCertificateIdentity::class)->document($bytes, $password);
            } catch (ValidationException $exception) {
                throw new SerproException(
                    'O certificado do contratante não pôde ser lido: '.($exception->validator->errors()->first() ?: 'identidade ausente.'),
                    SerproFailure::DoNotRetry,
                    0,
                );
            }
        });

        return (string) $document;
    }
}
