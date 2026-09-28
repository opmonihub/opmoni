<?php

namespace App\Services;

/**
 * O vocabulário nome-do-sistema-e-CAC → família de procuração.
 *
 * A consulta `OBTERPROCURACAO41` responde com **nomes** em texto livre — o
 * que o e-CAC exibe para o usuário —, e não com os códigos que o catálogo do
 * SERPRO usa para cobrar. Esta tabela é a tradução, e ela é código e não
 * configuração porque cada par aqui é uma decisão com evidência: uma família
 * só entra quando o nome foi visto no payload do provedor ou na documentação
 * publicada. Nome que ninguém viu não tem família — e a ausência é a
 * garantia, porque uma entrada `[]` explícita significaria "documentado e
 * autoriza nada", que é uma afirmação que ninguém fez.
 *
 * Fonte dos dois pares abaixo: o exemplo publicado de `OBTERPROCURACAO41` na
 * página "Obter procuração" do apicenter (lida em 2026-09-28; a URL está no
 * `_provenance` de `tests/Fixtures/serpro/procuracao-familias.json`). Os
 * nomes que vierem a ser observados com outros sistemas — REGIMEAPURACAO
 * `00060`, SITFIS `00002`, PGDASD/DEFIS `00146`, DCTFWEB `00103`, PAGTOWEB
 * `00004` — entram aqui só depois de comprovados; antes disso, `familiesFor`
 * os recusa por omissão.
 */
final class SerproPowerNames
{
    /** @var array<string, list<string>> */
    private const KNOWN = [
        'Caixa Postal - Mensagens' => ['00006'],
        'Caixa Postal - Termo de Opção pelo Domicílio Tributário Eletrônico' => ['00050'],
    ];

    /**
     * As famílias de procuração que o nome de sistema autoriza, ou `[]`
     * quando o nome não é dos comprovados. O match é de nome inteiro —
     * normalizado em espaços e bordas, porque o provedor manda texto livre —
     * e nunca por prefixo nem aproximação: uma substring que resolvesse
     * transformaria um nome desconhecido em autorização.
     *
     * @return list<string>
     */
    public function familiesFor(string $systemName): array
    {
        $name = preg_replace('/\s+/u', ' ', trim($systemName)) ?? '';

        return self::KNOWN[$name] ?? [];
    }
}
