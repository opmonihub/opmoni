<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * O container PKCS#12 que só abre com o provedor legado — RC2-40-CBC, o que a
 * ICP-Brasil emitiu por anos — e a continuação de `ValidationException` que
 * permite a um chamador dizer de **quem** é o certificado sem reescrever a frase.
 *
 * Continuação e não exceção nova porque a tela já escuta a chave `certificate` e
 * o HTTP já responde 422 nos dois casos: o que muda aqui é só a possibilidade de
 * o cofre acrescentar o nome do cliente, que a leitura compartilhada não conhece
 * e não pode conhecer — o chamador do escritório não pode acabar com o nome do
 * escritório em uma mensagem de erro.
 *
 * **Ela mora em `app/Services` e não em `app/Exceptions` porque o diretório não
 * existe** e porque `CnpjLookupException` e `SerproException` já vivem aqui, ao
 * lado do serviço que as produz. Criar uma pasta nova em `app/` para uma classe só
 * seria uma decisão de estrutura de Pastas que ninguém pediu, e a proximidade com
 * `CertificatePkcs12` é o que faz a troca de sujeito parecer óbvia em vez de
 * arbitrária.
 */
final class LegacyPkcs12Ciphertext extends ValidationException
{
    /**
     * A frase inteira, e não um fragmento: quem a compõe é quem sabe de quem é o
     * certificado, e o predicado abaixo amarra as duas coisas.
     */
    public const MESSAGE = 'O certificado usa criptografia legada RC2 e precisa ser exportado novamente sem a opção legacy.';

    /**
     * O sujeito trocável da frase. Privado de propósito: um chamador que solte
     * este prefixo no código está fazendo a troca do sujeito por conta própria, e
     * essa é exatamente a operação que a guarda de `sentenceFor()` existe para
     * tornar impossível de fazer em silêncio.
     */
    private const SUBJECT = 'O certificado';

    public static function becauseRc2(): self
    {
        // `ValidationException` não tem construtor de mensagem — ele recebe um
        // validador e monta a frase a partir dele —, e a fábrica herdada já
        // devolve `static`, ou seja, esta classe.
        return self::withMessages(['certificate' => [self::MESSAGE]]);
    }

    /**
     * A mesma recusa, com o sujeito da frase trocado por quem é o certificado.
     *
     * Devolve uma exceção nova em vez de mexer nesta: a que chegou aqui já foi
     * capturada por alguém, e uma exceção capturada que muda de texto por baixo
     * é um objeto que mente sobre si mesmo.
     */
    public function withSubject(string $subject): self
    {
        return self::withMessages([
            'certificate' => [self::sentenceFor(self::MESSAGE, $subject)],
        ]);
    }

    /**
     * A frase `$sentence` com o sujeito trocado por `$subject`.
     *
     * A frase entra por parâmetro, e não é a constante, porque o invariante que
     * esta função protege é "a frase começa com o sujeito que se troca" — e um
     * invariante sobre uma constante não se testa sem poder passar outra
     * constante por baixo dela. Em produção o único valor que entra é `MESSAGE`.
     *
     * A guarda **falha** em vez de cortar o resto pela medida do prefixo. Cortar
     * é o que transforma uma reescrita de texto em lixo entregue ao cliente — e
     * lixo que a suíte não pegaria, porque nenhum teste do cofre confere a frase
     * inteira. Quem reescrever `MESSAGE` para começar com outra coisa recebe uma
     * `LogicException` no lugar onde a frase é montada, que é a única hora em que
     * dá para consertar antes de ter mandado o lixo.
     *
     * @throws LogicException quando `$sentence` não começa com o sujeito trocável.
     */
    public static function sentenceFor(string $sentence, string $subject): string
    {
        if (! str_starts_with($sentence, self::SUBJECT)) {
            throw new LogicException(sprintf(
                'A frase de RC2 não começa com o sujeito "%s" e não pode ter o sujeito trocado. Frase recebida: "%s". Ou a frase volta a começar por "%s", ou a troca é reescrita por inteiro — cortar a frase no tamanho do prefixo entregaria lixo ao cliente.',
                self::SUBJECT,
                $sentence,
                self::SUBJECT,
            ));
        }

        // O corte é em `strlen()` porque o prefixo são bytes ASCII e `substr`
        // conta bytes: um corte no meio de um caractere multibyte produziria lixo
        // — que é o que a guarda acima existe para tornar impossível.
        return $subject.substr($sentence, strlen(self::SUBJECT));
    }
}
