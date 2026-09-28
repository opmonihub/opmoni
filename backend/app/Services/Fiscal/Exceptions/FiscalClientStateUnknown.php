<?php

namespace App\Services\Fiscal\Exceptions;

/**
 * O cadastro do cliente não diz em que UF ele está.
 *
 * A requisição não sai — `cUFAutor` é o interessado e o serviço aceita qualquer
 * código válido da tabela, então um valor inventado seria uma afirmação falsa
 * sobre quem pergunta que nada voltaria para denunciar —, mas a causa é um
 * defeito de cadastro e não de credencial ou de disco.
 *
 * Classe própria por causa do log: `class_basename` é o diagnóstico, e um
 * `FiscalRequestNotSent` genérico não distingue "o certificado não abre" de
 * "a UF não existe na tabela". A mensagem traz o `tax_id` e a sigla que
 * entraram, e nenhuma das duas sai daqui para o log.
 */
final class FiscalClientStateUnknown extends FiscalRequestNotSent
{
    public function __construct(string $message = 'O cliente não tem UF mapeável.')
    {
        parent::__construct($message);
    }
}
