<?php

namespace App\Services\Fiscal\Support;

use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Busca por nome local, compartilhada pelos dois parsers do módulo.
 *
 * Existe para que `local-name()` seja a única forma de encontrar elemento aqui:
 * o prefixo de namespace e o nome do elemento de resposta variam entre
 * ambientes, e um caminho absoluto escrito à mão quebra em um deles. E
 * `DOMXPath::query()` devolve `false` — não uma lista vazia — quando a
 * expressão é inválida, então `->item(0)` sobre esse retorno é `TypeError`; o
 * guard é o que transforma isso em "não encontrou".
 */
final class XmlQuery
{
    /**
     * `emit/CNPJ` vira `//*[local-name()="emit"]/*[local-name()="CNPJ"]`: cada
     * segmento casa pelo nome local dentro do segmento anterior, então um
     * caminho por modelo (`toma` no CT-e, `dest` na NF-e) sobrevive a qualquer
     * namespace. Comparar o nome local com a alternativa inteira
     * (`"emit/CNPJ"`) nunca casaria e mataria todo caminho com mais de um
     * segmento. Os segmentos são literais da chamada, nunca texto do documento.
     */
    public static function expression(string $path): string
    {
        $steps = array_map(
            static fn (string $segment): string => '*[local-name()="'.$segment.'"]',
            explode('/', $path),
        );

        return '//'.implode('/', $steps);
    }

    public static function first(DOMXPath $xpath, string $path, ?DOMNode $scope = null): ?DOMElement
    {
        return self::firstBy($xpath, self::expression($path), $scope);
    }

    /**
     * @return list<DOMElement>
     */
    public static function all(DOMXPath $xpath, string $path, ?DOMNode $scope = null): array
    {
        $found = self::run($xpath, self::expression($path), $scope);

        if ($found === null) {
            return [];
        }

        $elements = [];

        foreach ($found as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * Para o que não é caminho por nome local, como `//*[@Id]`.
     */
    public static function firstBy(DOMXPath $xpath, string $expression, ?DOMNode $scope = null): ?DOMElement
    {
        $node = self::run($xpath, $expression, $scope)?->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    /**
     * @return \DOMNodeList<DOMNode>|null
     */
    private static function run(DOMXPath $xpath, string $expression, ?DOMNode $scope): ?\DOMNodeList
    {
        // `//` é absoluto mesmo com nó de contexto, então a busca dentro de um
        // escopo precisa do `.` na frente — sem ele, "o `cStat` do corpo"
        // viraria "o primeiro `cStat` do envelope".
        if ($scope !== null && str_starts_with($expression, '//')) {
            $expression = '.'.$expression;
        }

        $found = $scope === null
            ? $xpath->query($expression)
            : $xpath->query($expression, $scope);

        return $found === false ? null : $found;
    }
}
