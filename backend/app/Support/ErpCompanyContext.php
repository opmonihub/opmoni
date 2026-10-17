<?php

namespace App\Support;

use App\Models\Erp\ErpCompany;

final class ErpCompanyContext
{
    public ?int $companyId = null;

    public function run(int $companyId, callable $callback): mixed
    {
        abort_unless($companyId > 0, 403, 'Empresa inválida');
        $context = request()->attributes->get(CurrentCompany::class);
        if ($context instanceof CurrentCompany) {
            abort_unless((int) $context->company->id === $companyId, 403, 'Empresa inválida');
        }
        $previous = $this->companyId;
        $this->companyId = $companyId;
        try {
            return $callback();
        } finally {
            $this->companyId = $previous;
        }
    }

    public function runForCompany(ErpCompany $company, callable $callback): mixed
    {
        return $this->run((int) $company->id, $callback);
    }
}
