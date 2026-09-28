<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproFailure;
use App\Services\SerproException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * O termo de autorização de **um escritório**, e o token que ele rendeu.
 *
 * Uma conta tem um termo, e o índice único de `account_id` é o que garante
 * isso: dois termos para a mesma conta fariam a renovação diária reenviar o
 * mesmo documento duas vezes, com dois tokens em jogo e nada no produto para
 * dizer qual é o bom.
 *
 * **As duas colunas cifradas e o estado não são `Fillable`, e a ausência é a
 * garantia, não uma omissão.** O documento assinado e o token de autorização
 * são o material mais sensível que este sistema guarda — um termo assinado
 * pelo escritório é um documento jurídico, e o token é o que permite agir em
 * nome dele. Nenhum dos dois atravessa um `fill($request->validated())` em
 * nenhuma hipótese, e `state` entra na mesma lista porque o estado é escrito
 * pelo `SerproTermManager` a partir da resposta do provedor, nunca por um
 * corpo de requisição: um estado vindo do cliente é a forma mais curta de
 * declarar um escritório autorizado sem prova nenhuma.
 *
 * Nenhuma coluna cifrada sai por `toArray()`/`toJson()`: a resource lista o
 * que devolve — `state`, `expires_on`, `signed_at`, `document_present` —, e o
 * `#[Hidden]` abaixo é a segunda rede, caso alguém chegue a serializar a linha
 * inteira, que é o que faria um `return $term` em um controller. A segunda
 * rede tem um caso que a afirma, e ele quebra se o `#[Hidden]` sair.
 *
 * **O documento não tem método público que o devolva.** A spec exige que o
 * XML assinado nunca saia do backend, e a forma de garantir isso é não ter
 * por onde ele saia: a decifra do documento mora no `SerproTermManager`, que
 * é o único que a usa, e o modelo não oferece atalho. O token tem método
 * porque o `SerproTermManager::validToken()` é a porta de entrada do plano 03
 * e o que ele devolve é uma credencial de chamada, não um documento.
 *
 * **Este model não tem factory, e isso é uma decisão.** A única forma de
 * produzir um termo de verdade é `SerproTermManager::issue()`, e ela exige
 * e-CNPJ gerado em tempo de execução, gate aberto e um dublê do provedor. Uma
 * factory aqui teria de fabricar um `<termoDeAutorizacao/>` de descarte — uma
 * segunda e mais fraca maneira de fazer um termo, que nenhum teste exercita e
 * que a próxima pessoa poderia usar achando que é o caminho real. Os testes
 * sobem pelo manager, que é o caminho que o produto executa.
 */
#[Fillable([
    'document_expires_on',
    'signed_at',
    'last_submitted_at',
])]
#[Hidden(['document_encrypted', 'token_encrypted'])]
class SerproAuthorizationTerm extends Model
{
    use BelongsToAccount;

    /**
     * A frase única de "o que está guardado não abre", para as duas causas
     * que exigem a mesma ação do operador.
     *
     * Nenhum byte, nenhum caminho e nenhuma mensagem do OpenSSL: o que este
     * modelo sabe é que não há material utilizável, e o texto de onde a
     * leitura falhou é exatamente o que pode carregar pedaço do documento
     * assinado.
     */
    private const ILEGIVEL = 'O termo de autorização gravado não pôde ser lido: o conteúdo guardado não abre com a chave de aplicação atual.';

    protected function casts(): array
    {
        return [
            // `date` e não `datetime`: a vigência do termo é um dia, `AAAAMMDD`
            // no documento, e o documento vale por ele inteiro. Guardar como
            // instante faria a comparação ser "ainda não passou o meio-dia" —
            // a leitura errada de um dia de validade.
            'document_expires_on' => 'date',
            'token_expires_at' => 'datetime',
            'state' => SerproAuthorizationTermState::class,
            'signed_at' => 'datetime',
            'last_submitted_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * O termo desta conta, pelo `account_id` explícito.
     *
     * A mesma razão de `AccountCertificate::currentFor()`: o escopo global
     * de `BelongsToAccount` só filtra quando há `CurrentTenant`, e no console
     * e na fila não filtra nada — quem renova o termo precisa do termo da
     * conta que está sendo servida, e não do que sobrou no singleton de uma
     * execução anterior. E quando o escopo **está** ativo e é o de outra
     * conta, as duas condições se cancelam e o resultado é `null`: a falha de
     * um tenant é recusar, nunca devolver o termo alheio.
     */
    public static function currentFor(int $accountId): ?self
    {
        return self::query()->where('account_id', $accountId)->first();
    }

    /**
     * O token de autorização, em claro, para quem vai chamar o gateway.
     *
     * `null` quando a linha ainda não tem token — o intervalo entre a
     * assinatura e a resposta do provedor é real, e um `?string` que devolvesse
     * string vazia nesse intervalo colocaria um `Authorization:` vazio no
     * gateway em vez de recusar a chamada.
     *
     * Um cifrado guardado que não abre é uma falha nomeada, e não um
     * `DecryptException` subindo de dentro da chamada e virando `500` no
     * consumidor: a mensagem não repete o erro do OpenSSL nem o valor
     * guardado.
     *
     * @throws SerproException
     */
    public function token(): ?string
    {
        return $this->token_encrypted === null
            ? null
            : $this->decrypted($this->token_encrypted);
    }

    /**
     * O estado é um dos seis da spec, e o nome dele é o que a tela decide
     * mostrar. Este método existe para a checagem de "o token ainda serve"
     * não duplicar a lista: quem pode chamar o gateway é quem tem um termo
     * `validado` ou `autenticado` dentro da vigência.
     */
    public function authorizesGateway(): bool
    {
        return in_array($this->state, [SerproAuthorizationTermState::Validado, SerproAuthorizationTermState::Autenticado], true);
    }

    private function decrypted(string $encrypted): string
    {
        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            throw new SerproException(self::ILEGIVEL, SerproFailure::NotSent, 0);
        }
    }
}
