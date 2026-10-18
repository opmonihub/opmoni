<?php

namespace App\Erp\Fiscal;

use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalInvalidation;

final class ErpFiscalProductionGate
{
    public function allowsLiveDispatch(ErpFiscalDocument|ErpFiscalInvalidation $document, ?int $actorUserId = null): bool
    {
        if ($document->environment !== 'production') {
            return true;
        }

        if (! filter_var(config('erp.fiscal_production_enabled', false), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return $this->canaryAllows($document, $actorUserId);
    }

    public function assertLiveDispatch(ErpFiscalDocument|ErpFiscalInvalidation $document, ?int $actorUserId = null): void
    {
        if ($this->allowsLiveDispatch($document, $actorUserId)) {
            return;
        }

        abort(response()->json([
            'code' => 'fiscal_production_blocked',
            'message' => 'Transmissão em produção bloqueada: gate de provedor/canário não habilitado ou escopo inválido.',
            'live_verification' => 'unverified',
        ], 403));
    }

    private function canaryAllows(ErpFiscalDocument|ErpFiscalInvalidation $document, ?int $actorUserId): bool
    {
        $scope = config('erp.fiscal_canary_scope', []);
        if (! is_array($scope) || $scope === []) {
            return false;
        }

        foreach ($scope as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $companyId = (int) ($entry['company_id'] ?? 0);
            $userId = (int) ($entry['user_id'] ?? 0);
            $model = (string) ($entry['model'] ?? '');
            $environment = (string) ($entry['environment'] ?? 'production');
            if ($companyId !== (int) $document->erp_company_id) {
                continue;
            }
            if ($userId !== 0 && $userId !== $actorUserId) {
                continue;
            }
            if ($model !== '' && $model !== (string) $document->model) {
                continue;
            }
            if ($environment !== (string) $document->environment) {
                continue;
            }

            return true;
        }

        return false;
    }
}
