<?php

namespace App\Services\Fiscal\Contracts;

/**
 * Uma entrada do lote que não virou documento.
 *
 * O serviço entrega o lote como posições, e uma posição não é um documento: o
 * `docZip` pode não descompactar, a chave pode vir com dígito verificador
 * inválido, e um `resCTe` pode aparecer no lote de NF-e. Nenhum desses casos
 * é motivo para desistir do lote — os outros documentos continuam legíveis —
 * e nenhum pode ser silencioso, porque o que a posição representa é
 * desconhecido enquanto ela não for lida.
 *
 * O que o conector sabe é o suficiente para o consumidor reportar: qual
 * posição falhou, qual `schema` ela declarava e **por qual etapa**. O que ele
 * não carrega é o conteúdo: `reason` é uma frase fixa que nomeia a etapa, e
 * não a mensagem da exceção, que pode repetir o `docZip` ou a chave de acesso
 * — os dois estão fora do que se registra.
 */
final readonly class FailedEntry
{
    /**
     * @param  int  $nsu  a posição desta entrada, para reconciliar depois
     * @param  string  $schema  o `schema` que o serviço declarou para ela
     * @param  string  $reason  frase fixa e segura para log, que nomeia a
     *                          etapa que recusou a entrada
     */
    public function __construct(
        public int $nsu,
        public string $schema,
        public string $reason,
    ) {}
}
