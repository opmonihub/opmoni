<?php

namespace App\Models;

use App\Enums\SerproFailure;
use App\Enums\SerproTermProof;
use App\Services\SerproCertificateIdentity;
use App\Services\SerproException;
use App\Services\SerproTermSigner;
use Database\Factories\SerproConnectionFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/*
 * As três colunas cifradas — `consumer_secret_encrypted`,
 * `certificate_encrypted` e `certificate_password_encrypted` — e o documento
 * contratante **não** são `Fillable`, e a ausência é a garantia, não uma
 * omissão: `Fillable` é a camada por onde um
 * `$model->fill($request->validated())` passaria, e o segredo, a senha do
 * certificado e os bytes do PFX não podem atravessar por lá em nenhuma
 * hipótese. Quem os grava é o `SerproConnectionManager`, com `forceCreate` na
 * primeira escrita e `forceFill` na rotação — que ignoram esta lista de
 * propósito, porque ele **é** o autor dos valores e não uma request.
 *
 * O documento contratante entra na mesma lista por uma razão que não é de
 * segredo: ele é extraído do certificado e gravado no mesmo passo, e a coluna
 * não pode ser preenchida por um corpo de requisição — a request marca
 * `contratante_numero` como `prohibited`, e `contracting_document` no recurso
 * é sempre lido do que o certificado realmente diz.
 *
 * **`term_format_sha256` e `term_format_proven_at` também não são `Fillable`,
 * e por um motivo diferente das colunas cifradas.** Elas não são segredo:
 * são a prova de que um teste de contrato rodou. A spec declara que nenhuma
 * requisição, nenhum job e nenhuma agenda escreve estas colunas, e o único
 * escritor sancionado é o comando `serpro:record-term-proof` — que as grava
 * com `forceFill`, por ser ele o autor do valor medido. A ausência na lista
 * abaixo é a garantia que faz essa regra valer no código e não só no texto
 * da spec, e é a mesma camada que protege o segredo: o `Fillable` é por onde
 * um `fill($request->validated())` passaria.
 */
#[Fillable([
    'consumer_key',
    'certificate_subject',
    'certificate_serial_number',
    'certificate_valid_from',
    'certificate_valid_until',
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
            'term_format_proven_at' => 'datetime',
        ];
    }

    /**
     * O gate de emissão do termo de autorização, lido nas duas colunas que a
     * spec nomeia e em mais nenhuma parte.
     *
     * **Este é o predicado, e ele é escrito uma única vez.** A spec o dá em
     * duas condições — `term_format_proven_at` não nulo **e**
     * `term_format_sha256` igual a `SerproTermSigner::formatDigest()` —, e
     * cada ponto do código que o precisasse reimplementasse o par deixaria
     * lugar para um deles ler só a primeira condição, que é o estado que
     * nasce sozinho quando o formato do documento muda depois da prova. Por
     * isso o método devolve um `SerproTermProof` e não um booleano: quem
     * recusa a emissão diz **qual** das duas metades faltou, e os dois casos
     * têm consertos diferentes.
     *
     * **A ordem das condições é a da spec e importa.** A primeira é a do
     * instante; a segunda, a do digest. Sem a prova não há nada a comparar, e
     * comparar o digest antes diria "divergente" para uma linha que
     * simplesmente nunca foi gravada — que é o estado de uma instalação nova
     * e a mensagem errada para o operador que acabou de instalar.
     *
     * `formatDigest()` é estático e não toca o banco, o que faz o gate
     * responder mesmo sem credencial de plataforma configurada: a comparação
     * com uma linha que não existe é feita pelo `SerproTermManager` antes de
     * qualquer outra coisa.
     */
    public function termProof(): SerproTermProof
    {
        if ($this->term_format_proven_at === null) {
            return SerproTermProof::Ausente;
        }

        return $this->term_format_sha256 === SerproTermSigner::formatDigest()
            ? SerproTermProof::Provado
            : SerproTermProof::Divergente;
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

    /**
     * A conta cujo e-CNPJ esta credencial usa como certificado contratante.
     *
     * `null` quando a credencial carrega o próprio PFX em
     * `certificate_encrypted`: as duas formas são alternativas, e a coluna
     * `contracting_account_id` só tem valor quando a decisão foi "usar o que a
     * conta já gravou" — que é o que a tela de `/admin/serpro` chama de reusar
     * o e-CNPJ do escritório.
     */
    public function contractingAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'contracting_account_id');
    }

    /**
     * O certificado de escritório que esta credencial reusa, resolvido pela
     * leitura da linha corrente da conta apontada.
     *
     * A resolução é por leitura e não por cópia porque é isso que "sem segunda
     * cópia" quer dizer: se a conta trocar o e-CNPJ, esta credencial passa a
     * assinar com o novo sem que nada aqui seja reescrito. `null` quando a
     * credencial guarda o próprio arquivo — que é a forma alternativa — ou
     * quando a conta apontada não tem certificado corrente, e é nesse segundo
     * caso que a credencial fica sem material de assinatura.
     *
     * Os predicados de "corrente" são os de `AccountCertificate::currentFor()`:
     * `replaced_at` e `removed_at` nulos, a linha de maior `id`. O escopo
     * global de `BelongsToAccount` fica de fora. O job da fila deixa
     * `CurrentTenant` na conta operadora; com ele ativo, `currentFor()` soma
     * `account_id = tenant` a `account_id = contratante` e devolve `null`
     * mesmo com e-CNPJ vigente na conta apontada. Aqui a conta contratante é
     * a referência explícita da credencial, não o tenant da execução.
     */
    public function contractingCertificate(): ?AccountCertificate
    {
        if ($this->contracting_account_id === null) {
            return null;
        }

        return AccountCertificate::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $this->contracting_account_id)
            ->whereNull('replaced_at')
            ->whereNull('removed_at')
            ->latest('id')
            ->first();
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
        // Quando a credencial reusa o e-CNPJ de uma conta, os bytes moram na
        // linha corrente de `account_certificates` daquela conta — aqui não há
        // cópia deles, e ler `certificate_encrypted` seria ler a coluna vazia.
        // O `null` de um certificado ausente é a resposta honesta nos dois
        // formatos, e é o que o materializador transforma em falha nomeada.
        $reused = $this->contractingCertificate();
        if ($reused !== null) {
            return $reused->certificateBytes();
        }

        return $this->certificate_encrypted === null
            ? null
            : Crypt::decryptString($this->certificate_encrypted);
    }

    public function certificatePassword(): ?string
    {
        $reused = $this->contractingCertificate();
        if ($reused !== null) {
            return $reused->certificatePassword();
        }

        return $this->certificate_password_encrypted === null
            ? null
            : Crypt::decryptString($this->certificate_password_encrypted);
    }

    /**
     * Se a credencial tem material de assinatura para usar — próprio ou em
     *prestado da conta apontada.
     *
     * O materializador e o teste de conectividade perguntam isto antes de abrir
     * o PFX: a coluna `certificate_encrypted` sozinha não responde, porque ela é
     * vazia por definição quando a credencial aponta para o e-CNPJ de uma conta.
     */
    public function hasCertificate(): bool
    {
        return $this->certificate_encrypted !== null
            || $this->contractingCertificate() !== null;
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
        $linked = $this->contractingCertificate();

        if ($this->certificate_encrypted === null && $this->contracting_account_id !== null && $linked === null) {
            // A credencial aponta para a conta, mas ela não tem e-CNPJ corrente:
            // a falha é de configuração do escritório, não do PFX desta linha.
            throw new SerproException(
                'O certificado do contratante não está configurado: a conta apontada não tem e-CNPJ corrente.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

        if (! $this->hasCertificate()) {
            // Sem certificado não há identidade a conferir; quem recusa a
            // credencial sem certificado é o materializador, logo em seguida.
            return;
        }

        $validUntil = $linked?->valid_until ?? $this->certificate_valid_until;

        if ($validUntil !== null && $validUntil->isPast()) {
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
        // Quando o certificado é o e-CNPJ emprestado de uma conta, o documento
        // já é o `document` da linha de `account_certificates` — extraído do
        // PFX no upload, e publicado pela API. Relê-lo do arquivo seria uma
        // segunda decifração para saber o que a coluna já afirma. Uma
        // credencial vinculada cuja conta ficou sem certificado não tem
        // documento a conferir: a ausência já foi nomeada antes desta linha.
        $reused = $this->contractingCertificate();
        if ($reused !== null) {
            return (string) $reused->document;
        }
        if ($this->contracting_account_id !== null) {
            return (string) $this->contratante_numero;
        }

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
