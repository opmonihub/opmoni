<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\AccountCertificateFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * O e-CNPJ do escritório, e o histórico do e-CNPJ que ele teve.
 *
 * Uma conta tem um certificado por vez, e as linhas anteriores não são
 * apagadas: a troca marca `replaced_at` e a remoção marca `removed_at`, e as
 * duas apagam as colunas cifradas. O que sobra é o registro do que o
 * escritório autorizou e quando — que é a única coisa que uma linha de
 * histórico pode guardar sem virar segunda cópia do segredo.
 *
 * **O conteúdo cifrado não é `Fillable` e a ausência é a garantia, não uma
 * omissão.** `Fillable` é a camada por onde um `fill($request->validated())`
 * passaria, e o texto cifrado do e-CNPJ, a senha que o abre e o documento
 * contratante não podem atravessar por lá em nenhuma hipótese: os dois
 * primeiros porque são segredo, e o terceiro porque é extraído do certificado no
 * mesmo passo que o decifra, e um `document` vindo do corpo da requisição
 * viraria a fonte da identidade do contratante. Quem os grava é
 * `AccountCertificateVault`, com `forceCreate` e `forceFill` — que ignoram
 * esta lista de propósito, porque o cofre **é** o autor desses valores: ele os
 * cifrou neste mesmo passo.
 *
 * Nenhuma coluna cifrada sai por `toArray()`/`toJson()`: a resource lista o que
 * devolve, e o `#[Hidden]` abaixo é a segunda rede, caso alguém chegue a
 * serializar a linha inteira — que é o que faria um `return $certificate` em um
 * controller.
 */
#[Fillable([
    'subject',
    'serial_number',
    'valid_from',
    'valid_until',
    'original_filename',
    'sha256',
])]
#[Hidden(['certificate_encrypted', 'password_encrypted'])]
class AccountCertificate extends Model
{
    /** @use HasFactory<AccountCertificateFactory> */
    use BelongsToAccount, HasFactory;

    /**
     * A frase única de "não deu para ler o que está gravado", para as três
     * causas que exigem a mesma ação do operador: reenviar o certificado.
     *
     * Nenhum branch, nenhum byte, nenhum caminho e nenhuma mensagem do OpenSSL
     * nesta frase — o que o cofre sabe é que não há material utilizável, e o
     * texto de onde a leitura falhou é exatamente o que pode carregar pedaço
     * do segredo.
     */
    private const ILEGIVEL = 'O certificado do escritório não pôde ser lido: esta linha não guarda mais o arquivo cifrado, ou o que guarda não abre com a chave de aplicação atual.';

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'replaced_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * O e-CNPJ que a conta tem hoje, ou `null` se ela ainda não entregou
     * nenhum, se o que ela entregou foi removido, ou se o que era corrente foi
     * trocado.
     *
     * O `account_id` é **explícito** e não vem do tenant corrente por dois
     * motivos. O escopo global de `BelongsToAccount` só filtra quando há
     * `CurrentTenant` — no console, na fila e no seed ele não filtra nada, e
     * quem assina o termo em um job precisa do certificado da conta que está
     * sendo servida e não do que sobrou no singleton de uma execução anterior.
     * E, quando o escopo **está** ativo e é o de outra conta, as duas condições
     * se cancelam e o resultado é `null`: a falha de um tenant é recusar, nunca
     * devolver o certificado alheio.
     *
     * `latest('id')` é o que torna a escolha determinística se duas linhas
     * correntes existirem por escrita fora do cofre: vence a mais recente, e o
     * índice `(account_id, replaced_at, removed_at)` é o que torna a busca
     * barata.
     */
    public static function currentFor(int $accountId): ?self
    {
        return self::query()
            ->where('account_id', $accountId)
            ->whereNull('replaced_at')
            ->whereNull('removed_at')
            ->latest('id')
            ->first();
    }

    /**
     * Os bytes do PKCS#12, em claro, para quem vai assinar em nome da conta.
     *
     * A coluna guarda `Crypt::encryptString(base64_encode($bytes))`: a base64
     * fica **sob** a cifra, e por isso a leitura é decifrar primeiro e só então
     * decodificar. Invertido, o `base64_decode` estrito devolve `false` e o
     * certificado some sem que nada avise.
     *
     * Só a linha corrente tem bytes: uma linha trocada ou removida tem a coluna
     * vazia **de propósito** — é o que apaga o segredo —, e quem pede os bytes
     * de uma delas recebe uma falha, nunca string vazia. Um `?string` com
     * `null` nesses casos esconderia o descaso dentro de um fluxo que assina
     * documento do SERPRO, que é o lugar mais caro para um `null` calado.
     *
     * @throws RuntimeException quando a linha não guarda bytes legíveis.
     */
    public function certificateBytes(): string
    {
        $bytes = base64_decode($this->decrypted($this->certificate_encrypted), true);

        if ($bytes === false) {
            throw new RuntimeException(self::ILEGIVEL);
        }

        return $bytes;
    }

    /**
     * A senha do PKCS#12 em claro. Só no escopo de assinatura: nem entra em
     * `toArray()`, nem em log, nem em mensagem de erro.
     *
     * A senha **não** passa por base64: ela é gravada como
     * `Crypt::encryptString($password)`, e decodificar de novo aqui trocaria
     * uma senha legível por `false` sempre que ela não tivesse comprimento
     * múltiplo de quatro.
     *
     * @throws RuntimeException quando a linha não guarda senha legível.
     */
    public function certificatePassword(): string
    {
        return $this->decrypted($this->password_encrypted);
    }

    /**
     * A leitura de um segredo gravado por este cofre, com uma falha nomeada e
     * única para tudo que não dá para ler.
     *
     * `DecryptException` cru aqui viraria erro de cifra no meio da assinatura,
     * longe de quem precisa reenviar o certificado, e é exatamente o que o
     * materializador do cofre de cliente faz com o mesmo segredo: material
     * ilegível é condição de nome, não exceção de criptografia.
     *
     * @throws RuntimeException
     */
    private function decrypted(?string $encrypted): string
    {
        if ($encrypted === null || $encrypted === '') {
            throw new RuntimeException(self::ILEGIVEL);
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            throw new RuntimeException(self::ILEGIVEL);
        }
    }
}
