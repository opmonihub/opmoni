<?php

namespace App\Services;

/**
 * A leitura do mapa `integra-contador.obligations`.
 *
 * O mapa é configuração e esta classe é a forma dele: quem percorre o
 * trabalho de uma execução não deve conhecer as chaves, e quem testa o
 * catálogo não deve depender do job. `syncables()` devolve só o que o
 * job de cliente pode cobrar — obrigação com par `SISTEMA/IDSERVICO`
 * verificado e `sync_enabled` ligado — porque `unavailable`, `extinct` e
 * `derived` de filtro não produzem chamada nenhuma.
 */
final class SerproObligationCatalog
{
    /**
     * O oráculo de autorização corre para todo cliente antes das obrigações:
     * é ele quem descobre que o cliente nunca teve outorga, e por isso é o
     * primeiro item de trabalho — e não uma obrigação do painel.
     *
     * @return array{slug: string, id_sistema: string, id_servico: string}
     */
    public function oracle(): array
    {
        return [
            'slug' => 'procuracoes',
            'id_sistema' => 'PROCURACOES',
            'id_servico' => 'OBTERPROCURACAO41',
        ];
    }

    /**
     * @return array<string, array{category: string, service: ?string, procuracao: ?string, derived_from: ?string, sync_enabled: bool}>
     */
    public function all(): array
    {
        /** @var array<string, array{category: string, service: ?string, procuracao: ?string, derived_from: ?string, sync_enabled: bool}> $map */
        $map = config('integra-contador.obligations', []);

        return $map;
    }

    /**
     * @return array{category: string, service: ?string, procuracao: ?string, derived_from: ?string, sync_enabled: bool}|null
     */
    public function get(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * As obrigações que uma execução pode cobrar, na ordem do mapa. O par
     * `idSistema/idServico` sai quebrado da string `service` — o formato é
     * o que o frontend e o config acordaram — e uma entrada `derived` com o
     * mesmo serviço de uma `direct` não entra de novo: a chamada é uma só
     * por execução, e quem projeta as duas linhas é o escritor.
     *
     * @return list<array{slug: string, id_sistema: string, id_servico: string, procuracao: ?string}>
     */
    public function syncables(): array
    {
        $work = [];
        $servicosVistos = [];

        foreach ($this->all() as $slug => $obrigacao) {
            if (! ($obrigacao['sync_enabled'] ?? false) || $obrigacao['service'] === null) {
                continue;
            }

            [$sistema, $servico] = $this->par($obrigacao['service']);

            if ($servico === null) {
                // Um `service` sem par — `PARCSN`, `CAIXAPOSTAL` — é o
                // marcador de "serviço existe, path ainda não verificado" e
                // nunca sai como chamada.
                continue;
            }

            if (isset($servicosVistos[$servico])) {
                continue;
            }

            $servicosVistos[$servico] = true;
            $work[] = [
                'slug' => $slug,
                'id_sistema' => $sistema,
                'id_servico' => $servico,
                'procuracao' => $obrigacao['procuracao'] ?? null,
            ];
        }

        return $work;
    }

    /**
     * As famílias que a elegibilidade confere, no formato do mapa:
     * alternativas separadas por `,` e conjunções por `+` — `'00149+10011,
     * 00210+10036'` significa "as duas do primeiro par, ou as duas do
     * segundo". `null` é "este serviço não exige outorga".
     *
     * @return list<list<string>>
     */
    public function procuracaoAlternatives(?string $procuracao): array
    {
        if ($procuracao === null) {
            return [[]];
        }

        return array_map(
            fn (string $alternativa): array => array_values(array_filter(
                array_map('trim', explode('+', $alternativa)),
                fn (string $codigo): bool => $codigo !== '',
            )),
            array_map('trim', explode(',', $procuracao)),
        );
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function par(string $service): array
    {
        $partes = explode('/', $service, 2);

        return [$partes[0], $partes[1] ?? null];
    }
}
