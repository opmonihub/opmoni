<?php

namespace App\Services\Fiscal\Manifestacao;

use App\Enums\FiscalScienceRolloutMode;

/**
 * Production science rollout: global gate plus explicit disabled/pilot/fleet mode.
 */
final class FiscalScienceRolloutGate
{
    /**
     * @return array{allowed: bool, reason: ?string}
     */
    public function evaluate(int $accountId, int $clientId, string $chaveAcesso): array
    {
        if (! config('fiscal.manifestacao_enabled', false)) {
            return ['allowed' => false, 'reason' => 'gate_disabled'];
        }

        $mode = FiscalScienceRolloutMode::tryFrom((string) config('fiscal.manifestacao_rollout_mode', 'disabled'))
            ?? FiscalScienceRolloutMode::Disabled;

        if ($mode === FiscalScienceRolloutMode::Disabled) {
            return ['allowed' => false, 'reason' => 'gate_disabled'];
        }

        if ($mode === FiscalScienceRolloutMode::Fleet) {
            return ['allowed' => true, 'reason' => null];
        }

        $allowlist = config('fiscal.manifestacao_pilot_allowlist', []);

        if (! is_array($allowlist) || $allowlist === []) {
            return ['allowed' => false, 'reason' => 'pilot_scope'];
        }

        foreach ($allowlist as $tuple) {
            if (! is_array($tuple) || count($tuple) !== 3) {
                continue;
            }

            [$tupleAccount, $tupleClient, $tupleChave] = $tuple;

            if ((int) $tupleAccount === $accountId
                && (int) $tupleClient === $clientId
                && (string) $tupleChave === $chaveAcesso) {
                return ['allowed' => true, 'reason' => null];
            }
        }

        return ['allowed' => false, 'reason' => 'pilot_scope'];
    }
}
