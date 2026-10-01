<?php

namespace Tests\Feature\Serpro;

use App\Enums\TaxRegime;
use App\Services\SerproObligationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O mapa regime → obrigações: toda sugestão existe no catálogo e é servida —
 * um slug que ninguém publicou, ou que a integração não serve, não pode ser
 * marcado para o operador.
 */
class SerproObligationCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_toda_sugestao_existe_no_mapa_e_e_servida(): void
    {
        $catalogo = resolve(SerproObligationCatalog::class);

        foreach (TaxRegime::cases() as $regime) {
            foreach ($catalogo->suggestedFor($regime) as $slug) {
                $obrigacao = $catalogo->get($slug);

                $this->assertNotNull($obrigacao, "O slug {$slug} está em regime_suggestions sem existir no mapa.");
                $this->assertContains(
                    $obrigacao['category'],
                    ['direct', 'derived'],
                    "O slug {$slug} sugere uma obrigação que o provedor não serve ({$obrigacao['category']}).",
                );
            }
        }
    }

    public function test_slug_inexistente_ou_nao_servido_nao_sai_da_sugestao(): void
    {
        // Um `regime_suggestions` errado deve falhar aqui, e não no controller:
        // o método é a única defesa contra uma configuração que promete o que
        // o provedor não entrega.
        config()->set('integra-contador.regime_suggestions.simple_national', [
            'declaracoes/pgdas',
            'nao-existe',
            'parcelamentos/pgfn',
            'declaracoes/dirf',
        ]);

        $this->assertSame(
            ['declaracoes/pgdas'],
            resolve(SerproObligationCatalog::class)->suggestedFor(TaxRegime::SimpleNational),
        );
    }

    public function test_regime_sem_sugestao_devolve_lista_vazia(): void
    {
        $catalogo = resolve(SerproObligationCatalog::class);

        // `not_applicable` é o regime de quem não tem obrigação fiscal a
        // monitorar: a sugestão é vazia, e não um punhado de obrigações
        // que a etapa fingiria ter motivo para marcar.
        $this->assertSame([], $catalogo->suggestedFor(TaxRegime::NotApplicable));
    }
}
