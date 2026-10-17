<?php

namespace App\Erp;

class ErpReleaseGate
{
    public function mode(): string
    {
        $mode = strtolower(trim((string) config('erp.release_mode', 'disabled')));
        if (! in_array($mode, ['disabled', 'preview', 'production'], true)) {
            return 'disabled';
        }

        if ($mode === 'production' && filter_var(config('erp.release_enabled', false), FILTER_VALIDATE_BOOL)) {
            return 'production';
        }

        if ($mode === 'preview') {
            return 'preview';
        }

        return filter_var(config('erp.release_enabled', false), FILTER_VALIDATE_BOOL) ? 'production' : 'disabled';
    }

    public function isReleased(): bool
    {
        return in_array($this->mode(), ['preview', 'production'], true);
    }

    public function assertReleased(?int $companyId = null, ?int $userId = null): void
    {
        $mode = $this->mode();
        if ($mode === 'production') {
            return;
        }
        if ($mode === 'preview' && $this->previewAllows($companyId, $userId)) {
            return;
        }

        abort(response()->json([
            'code' => 'erp_release_disabled',
            'message' => 'ERP indisponível: liberação do núcleo comercial ainda não habilitada.',
        ], 403));
    }

    private function previewAllows(?int $companyId, ?int $userId): bool
    {
        $allowlist = config('erp.preview_allowlist', []);
        if (! is_array($allowlist) || $allowlist === []) {
            return false;
        }

        foreach ($allowlist as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $allowedCompany = (int) ($entry['company_id'] ?? 0);
            $allowedUser = (int) ($entry['user_id'] ?? 0);
            if ($allowedCompany !== 0 && $companyId !== null && $allowedCompany !== $companyId) {
                continue;
            }
            if ($allowedUser !== 0 && $userId !== null && $allowedUser !== $userId) {
                continue;
            }

            return true;
        }

        return false;
    }
}
