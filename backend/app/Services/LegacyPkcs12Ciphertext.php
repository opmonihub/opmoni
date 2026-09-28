<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * O container PKCS#12 que só abre com o provedor legado — RC2-40-CBC, o que a
 * ICP-Brasil emitiu por anos — e a continuação de `ValidationException` que
 * permite a um chamador dizer de **quem** é o arquivo sem reescrever a frase.
 *
 * Continuação e não exceção nova porque a tela já escuta a chave `certificate` e
 * o HTTP já responde 422 nos dois casos: o que muda aqui é só a possibilidade de
 * o cofre acrescentar o nome do cliente, que a leitura compartilhada não conhece
 * e não pode conhecer — o chamador do escritório não pode acabar com o nome do
 * escritório em uma mensagem de erro.
 */
final class LegacyPkcs12Ciphertext extends ValidationException
{
    /**
     * A frase inteira, e não um fragmento: quem a compõe é quem sabe de quem é o
     * certificado. O cofre troca o sujeito — "O certificado" vira "O certificado do
     * cliente X" — e o resto da frase é o mesmo caractere a caractere.
     */
    public const MESSAGE = 'O certificado usa criptografia legada RC2 e precisa ser exportado novamente sem a opção legacy.';

    public static function becauseRc2(): self
    {
        // `ValidationException` não tem construtor de mensagem — ele recebe um
        // validador e monta a frase a partir dele —, e a fábrica herdada já
        // devolve `static`, ou seja, esta classe.
        return self::withMessages(['certificate' => [self::MESSAGE]]);
    }
}
