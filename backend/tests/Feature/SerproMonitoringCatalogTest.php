<?php

namespace Tests\Feature;

use App\Services\SerproObligationCatalog;
use Tests\TestCase;

/**
 * O contrato entre os dois registros das dezenove obrigações.
 *
 * `monitoringNav.ts` é a autoridade de apresentação e
 * `integra-contador.obligations` a de leitura: o painel desenha o que o
 * primeiro declara e a API responde pelo que o segundo sabe, e os dois só
 * podem divergir se alguém editar um sem o outro. O teste lê o TypeScript
 * **como texto** — `node --test` não transpila para o PHPUnit, e nenhum dos
 * dois deve importar o outro: é a igualdade das chaves o que o contrato vale.
 */
class SerproMonitoringCatalogTest extends TestCase
{
    public function test_as_dezenove_obrigacoes_coincidem_com_o_registro_do_frontend(): void
    {
        $registro = $this->registroFrontend();

        $this->assertCount(19, $registro, 'O registro do frontend deixou de ter dezenove obrigações.');

        $mapa = config('integra-contador.obligations');

        $this->assertCount(19, $mapa);
        $this->assertSame(
            array_keys($registro),
            array_keys($mapa),
            'Os slugs divergiram: '.implode(', ', array_keys($mapa)).' no backend; '.implode(', ', array_keys($registro)).' no frontend.',
        );

        foreach ($registro as $slug => $esperado) {
            $entrada = $mapa[$slug];

            $this->assertSame($esperado['category'], $entrada['category'], "A categoria de `{$slug}` divergiu.");
            $this->assertSame($esperado['service'], $entrada['service'], "O serviço de `{$slug}` divergiu.");
            $this->assertSame($esperado['procuracao'], $entrada['procuracao'], "A família de `{$slug}` divergiu.");
        }
    }

    public function test_a_revisao_do_catalogo_e_a_mesma_dos_dois_lados(): void
    {
        $this->assertSame(
            config('integra-contador.catalogue_revision'),
            $this->revisaoFrontend(),
        );
    }

    public function test_obrigacao_sem_fonte_nao_declara_servico_nem_familia(): void
    {
        $mapa = config('integra-contador.obligations');

        foreach (['parcelamentos/pgfn', 'declaracoes/fgts'] as $slug) {
            $this->assertSame('unavailable', $mapa[$slug]['category']);
            $this->assertNull($mapa[$slug]['service']);
            $this->assertNull($mapa[$slug]['procuracao']);
            $this->assertFalse($mapa[$slug]['sync_enabled']);
        }

        $dirf = $mapa['declaracoes/dirf'];
        $this->assertSame('extinct', $dirf['category']);
        $this->assertNull($dirf['service']);
        $this->assertNull($dirf['procuracao']);
        $this->assertFalse($dirf['sync_enabled']);
    }

    public function test_todo_servico_sincronizado_tem_caminho_versao_e_cobranca_publicados(): void
    {
        // A entrada só pode prometer `sync_enabled` com par `SISTEMA/IDSERVICO`
        // resolvido em `services`: um `sync_enabled` sem caminho publicado
        // mandaria a chamada para um endpoint chutado, que é o defeito que o
        // marcador existe para impedir.
        $services = config('integra-contador.services');

        foreach (config('integra-contador.obligations') as $slug => $obrigacao) {
            if (! $obrigacao['sync_enabled']) {
                continue;
            }

            $this->assertMatchesRegularExpression('/^[A-Z-]+\/[A-Z0-9-]+$/', (string) $obrigacao['service'], "`{$slug}` sincroniza sem par SISTEMA/IDSERVICO.");

            [, $idServico] = explode('/', $obrigacao['service'], 2);
            $servico = $services[$idServico] ?? null;

            $this->assertNotNull($servico, "`{$slug}` sincroniza por `{$idServico}`, que não está no mapa de serviços.");
            $this->assertArrayHasKey('path', $servico);
            $this->assertNotSame('', $servico['path']);
            $this->assertArrayHasKey('versaoSistema', $servico);
            $this->assertNotSame('', (string) $servico['versaoSistema']);
            $this->assertIsBool($servico['billable']);
        }
    }

    public function test_derivado_nomeia_do_que_projeta_e_direto_nao(): void
    {
        foreach (config('integra-contador.obligations') as $slug => $obrigacao) {
            if ($obrigacao['category'] === 'derived') {
                $this->assertNotNull($obrigacao['derived_from'], "`{$slug}` é derived sem nomear a fonte.");
            } else {
                $this->assertNull($obrigacao['derived_from'], "`{$slug}` não é derived e nomeia fonte.");
            }
        }
    }

    public function test_syncables_devolve_so_o_que_uma_execucao_pode_cobrar(): void
    {
        $catalogo = new SerproObligationCatalog;
        $services = config('integra-contador.services');

        $syncables = $catalogo->syncables();
        $ids = array_column($syncables, 'id_servico');

        $this->assertSame($ids, array_unique($ids), 'Um mesmo serviço aparece duas vezes na execução.');

        foreach ($syncables as $unidade) {
            $this->assertArrayHasKey($unidade['id_servico'], $services);
            $this->assertNotSame('', $unidade['slug']);
            $this->assertSame($unidade['id_sistema'], explode('/', config("integra-contador.obligations.{$unidade['slug']}.service"), 2)[0]);
        }

        // `get` devolve a entrada crua e `oracle` aponta o serviço que mede a
        // autorização — os dois são o contrato que o job lê.
        $this->assertSame('unavailable', $catalogo->get('parcelamentos/pgfn')['category']);
        $this->assertNull($catalogo->get('inexistente'));
        $this->assertSame('OBTERPROCURACAO41', $catalogo->oracle()['id_servico']);
    }

    public function test_as_alternativas_de_familia_quebram_no_separador_do_mapa(): void
    {
        $catalogo = new SerproObligationCatalog;

        $this->assertSame([[]], $catalogo->procuracaoAlternatives(null));
        $this->assertSame([['00060']], $catalogo->procuracaoAlternatives('00060'));
        $this->assertSame([['00076', '00188']], $catalogo->procuracaoAlternatives('00076+00188'));
        $this->assertSame(
            [['00149', '10011'], ['00210', '10036']],
            $catalogo->procuracaoAlternatives('00149+10011, 00210+10036'),
        );
    }

    /**
     * O registro `slug => {service, procuracao, category}`, extraído do
     * TypeScript como texto: os pares aparecem na mesma ordem em todas as
     * dezenove entradas, e a ordem dos campos dentro de cada uma é a que o
     * registro declara.
     *
     * @return array<string, array{service: ?string, procuracao: ?string, category: string}>
     */
    private function registroFrontend(): array
    {
        $arquivo = base_path('../frontend/app/utils/monitoringNav.ts');
        $fonte = (string) file_get_contents($arquivo);

        preg_match_all(
            "/slug: '([^']+)'[\s\S]*?service: (null|'[^']*')[\s\S]*?procuracao: (null|'[^']*')[\s\S]*?category: '([^']+)'/",
            $fonte,
            $pares,
            PREG_SET_ORDER,
        );

        $registro = [];
        foreach ($pares as [, $slug, $service, $procuracao, $category]) {
            $registro[$slug] = [
                'service' => $service === 'null' ? null : trim($service, "'"),
                'procuracao' => $procuracao === 'null' ? null : trim($procuracao, "'"),
                'category' => $category,
            ];
        }

        return $registro;
    }

    private function revisaoFrontend(): ?string
    {
        $fonte = (string) file_get_contents(base_path('../frontend/app/utils/monitoringNav.ts'));

        return preg_match("/monitoringCatalogueRevision = '([^']+)'/", $fonte, $m) === 1 ? $m[1] : null;
    }
}
