<?php

namespace App\Services;

/**
 * Trava de ambiente para probes SERPRO: homologação fiscal e flag explícita.
 */
final class SerproProbeGate
{
    /**
     * @return null quando o probe pode seguir; string legível quando deve parar antes de HTTP
     */
    public function refusalReason(bool $force = false): ?string
    {
        if (config('fiscal.environment') !== 'homologacao') {
            return 'O ambiente fiscal não é homologação (fiscal.environment='.config('fiscal.environment').').';
        }

        if (! config('serpro_probes.enabled', false) && ! $force) {
            return 'Probes desligados (defina SERPRO_PROBE_ENABLED=true ou use --force).';
        }

        return null;
    }
}
