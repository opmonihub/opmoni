<?php

namespace App\Services\Fiscal\Capture;

use App\Enums\FiscalSource;

/**
 * A pergunta única que a instalação responde sobre cada fonte com porta —
 * CT-e e NFS-e: **a volta atrás desta fonte está pausada?**
 *
 * Três decisões distintas existem sobre cada serviço, e confundi-las é como
 * se ganha tráfego não verificado:
 *
 * 1. `fiscal.cte_scheduled` — alguém agenda a captura (`routes/console.php`).
 *    NFS-e ainda não tem entrada de agenda, então não tem chave irmã.
 * 2. `fiscal.cte_enabled` / `fiscal.nfse_enabled` — a porta do botão da tela
 *    e do despacho (`FiscalCaptureDispatcher`), que é a fronteira.
 * 3. **Esta**: a volta atrás roda, e uma lacuna que ninguém vai buscar segura a
 *    posição ou não.
 *
 * A terceira é uma pergunta diferente da segunda, e a diferença é o que este
 * arquivo documenta para quem vier conferir a chave no código: o
 * **despachante** recusa **despachar** a captura, o serviço de captura decide
 * se uma lacuna já existente **segura o cursor**, e a reconciliação decide se
 * **consulta**. As três leem a mesma chave porque são três decisões sobre a
 * mesma pergunta de instalação — "esta instalação fala com este serviço ou
 * não" —, e não porque uma decorra da outra. Um conserto na fronteira de
 * despacho não desliga a volta atrás, e vice-versa.
 *
 * Por que a lacuna não pode segurar a posição com a volta atrás parada: `attempts`
 * não é incrementado, então a lacuna fica `pending` para sempre, e `mayAdopt()`
 * não adota a posição enquanto houver `pending`. A captura continua puxando o mesmo
 * lote a cada hora, e **todo documento atrás da posição fica sem ser capturado** —
 * uma perda que cresce com a pausa, e que a volta atrás congelada não pode
 * recuperar. Por isso a lacuna pausada é contada à parte: permitir o avanço limita
 * a perda ao documento daquela posição, que é exatamente o que o esgotamento de
 * tentativas perderia, e a linha da lacuna sobrevive para ser consultada na
 * primeira noite em que a chave voltar a estar ligada.
 *
 * ⚠️ A simetria é deliberada e é a mesma da reconciliação: com a volta atrás
 * parada, a lacuna **não** é consultada, **não** gasta tentativa e **não** segura
 * a posição. Uma lacuna que ninguém busca não pode ser contada como posição que
 * trava um cliente, e o critério de quem a segura tem de ser o mesmo para as duas.
 */
final class FiscalCteGate
{
    /**
     * A reconciliação desta fonte está pausada por decisão de instalação?
     *
     * CT-e e NFS-e têm porta — `fiscal.cte_enabled` e `fiscal.nfse_enabled`,
     * as mesmas do botão e do despacho —, e a NF-e não tem nenhuma, porque o
     * serviço dela é o que este produto sempre serviu. A agenda
     * (`fiscal.cte_scheduled`) **não** entra: ela decide se alguém agenda a
     * captura, e é exatamente a agenda ligada com a chave desligada que produz
     * o estado que este método descreve.
     */
    public function isPaused(FiscalSource $source): bool
    {
        return match ($source) {
            FiscalSource::CteDistribuicao => ! config('fiscal.cte_enabled', false),
            FiscalSource::NfseAdn => ! config('fiscal.nfse_enabled', false),
            default => false,
        };
    }
}
