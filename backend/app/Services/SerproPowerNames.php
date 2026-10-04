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
 * Fonte dos dois pares de caixa postal: o exemplo publicado de
 * `OBTERPROCURACAO41` na página "Obter procuração" do apicenter (lida em
 * 2026-09-28; a URL está no `_provenance` de
 * `tests/Fixtures/serpro/procuracao-familias.json`). Fonte dos demais: a
 * página "Serviços x Procurações" do apicenter
 * (…/api-integra-contador/pt/servicos_vs_procuracoes/, lida em 2026-10-03) —
 * os nomes são os da coluna "Nome do Serviço (procuração eCAC)", e cada
 * nome mapeia para a lista de códigos que ele autoriza: PERT-SN e RELP têm
 * **um** nome para os **dois** códigos que a tabela lista, e o valor é a
 * lista dos dois. O nome da caixa de entradas do parcelamento ("Solicitar,
 * acompanhar e emitir DAS de parcelamento") é o do código `00188` — o
 * catálogo exige `00076` **e** `00188`, e são as duas outorgas, cada uma
 * com o seu nome, que as concedem juntas.
 */
final class SerproPowerNames
{
    /** @var array<string, list<string>> */
    private const KNOWN = [
        'Caixa Postal - Mensagens' => ['00006'],
        'Caixa Postal - Termo de Opção pelo Domicílio Tributário Eletrônico' => ['00050'],
        'Simples Nacional - Opção pelo Regime de Apuração de Receitas' => ['00060'],
        'PGDAS-D - a partir de 01/2018' => ['00146'],
        'Acessar o sistema DCTFWeb' => ['00103'],
        'Situação Fiscal do Contribuinte' => ['00002'],
        'Pagamentos - Comprovante de Arrecadação' => ['00004'],
        'Parcelamento de Débitos do Simples Nacional' => ['00076'],
        'Solicitar, acompanhar e emitir DAS de parcelamento' => ['00188'],
        'Parcelamento Especial Simples Nacional' => ['00125'],
        'Programa Especial Regularização Tributária - PERT-SN' => ['00149', '10011'],
        'Parcelar dívidas do SN pela LC 193/2022 (RELP)' => ['00210', '10036'],
        /*
         * Observado em 2026-10-04 na resposta de sucesso de
         * `OBTERPROCURACAO41` (dtexpiracao 20271127): quando a procuração
         * cobre todos os sistemas, o e-CAC não enumera os nomes — devolve
         * este. Ele autoriza cada família que esta tabela já comprova, e
         * nenhuma que ela ainda não viu nomeada.
         */
        'TODOS' => [
            '00006',
            '00050',
            '00060',
            '00146',
            '00103',
            '00002',
            '00004',
            '00076',
            '00188',
            '00125',
            '00149',
            '10011',
            '00210',
            '10036',
        ],
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
