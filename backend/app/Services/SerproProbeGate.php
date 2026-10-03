<?php

namespace App\Services;

/**
 * Porta dupla antes de qualquer probe SERPRO: ambiente fiscal e flag explícita.
 *
 * O default de `config/fiscal.php` é produção; confiar só na URL do gateway
 * seria perigoso — por isso a checagem de `homologacao` mora aqui.
 */
final class SerproProbeGate
{
    public function blockReason(bool $force = false): ?string
    {
        if (config('fiscal.environment') !== 'homologacao') {
            return 'ambiente_nao_homologacao';
        }

        if (! $force && ! config('serpro_probes.enabled')) {
            return 'probe_desligado';
        }

        return null;
    }

    public function humanMessage(string $reason): string
    {
        return match ($reason) {
            'ambiente_nao_homologacao' => 'Probes SERPRO só rodam com FISCAL_ENVIRONMENT=homologacao.',
            'probe_desligado' => 'Probe desligado: defina SERPRO_PROBE_ENABLED=1 ou use --force.',
            default => $reason,
        };
    }
}
