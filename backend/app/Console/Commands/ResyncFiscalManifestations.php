<?php

namespace App\Console\Commands;

use App\Services\Fiscal\Manifestacao\ManifestacaoResync;
use Illuminate\Console\Command;

final class ResyncFiscalManifestations extends Command
{
    protected $signature = 'fiscal:resync-manifestacoes';

    protected $description = 'Recupera, pela consulta pontual por chave, o XML completo dos resumos com ciência já registrada';

    /**
     * O gate é o da feature, e é lido aqui — na borda do comando — pelo mesmo
     * motivo de `cte_scheduled` existir separada de `cte_enabled`: a agenda
     * registra a entrada, mas o serviço decide se a varredura existe. Com o
     * gate desligado, o que sai daqui é zero consulta e zero documento — o
     * estado gravado continua lá para quando a chave ligar.
     */
    public function handle(ManifestacaoResync $resync): int
    {
        if (! config('fiscal.manifestacao_enabled', false)) {
            $this->info('Manifestação do destinatário desligada (fiscal.manifestacao_enabled): nada a ressincronizar.');

            return self::SUCCESS;
        }

        $recuperados = $resync->run();

        $this->info("XML completos recuperados: {$recuperados}");

        return self::SUCCESS;
    }
}
