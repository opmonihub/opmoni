<?php

namespace App\Enums;

/**
 * Onde a entrega caiu na cadeia da distribuição do documento.
 *
 * É um eixo diferente de `FiscalKind`: o resumo e o documento autorizado são os
 * dois `kind = document` — nenhum dos dois é evento — e é a etapa que os separa.
 * São três entregas de distribuição para um documento, e a chave composta sem
 * esta coluna guardava as duas primeiras na mesma linha, com o documento
 * completo sobrescrevendo o XML, a posição e o digest do resumo.
 */
enum FiscalStage: string
{
    case Summary = 'summary';
    case Document = 'document';
    case Event = 'event';

    /**
     * A etapa com que esta se compara por `digVal`, ou `null` quando não tem
     * par.
     *
     * Resumo e documento autorizado são as duas pontas da verificação de
     * integridade da decisão 8: um traz o digest que o resumo calculou, o outro
     * o que o protocolo de autorização carregou. Evento não tem digest, e por
     * isso não tem com que conferir — o que é o terceiro estado do veredito, o
     * "não dá para dizer".
     */
    public function counterpart(): ?self
    {
        return match ($this) {
            self::Summary => self::Document,
            self::Document => self::Summary,
            self::Event => null,
        };
    }

    /**
     * O rótulo do arquivo, para o XML da etapa continuar legível em disco.
     *
     * Um evento nunca chega aqui com o rótulo: o identificador do evento é mais
     * específico e é o que o nomeia.
     */
    public function fileToken(): string
    {
        return match ($this) {
            self::Summary => 'resumo',
            self::Document => 'documento',
            self::Event => 'evento',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Summary => 'Resumo',
            self::Document => 'Documento completo',
            self::Event => 'Evento',
        };
    }
}
