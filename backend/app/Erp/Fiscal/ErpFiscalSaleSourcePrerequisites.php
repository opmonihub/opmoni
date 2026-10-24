<?php

namespace App\Erp\Fiscal;

final class ErpFiscalSaleSourcePrerequisites
{
    /** @return array<string, list<string>> */
    public function errors(string $model, array $facts): array
    {
        $errors = [];
        foreach (app(ErpFiscalSourceSnapshotFactory::class)->saleSourcePrerequisiteErrors($model, $facts) as $path) {
            $errors[$path] = ['Revise '.$path.' na fonte comercial do modelo '.$model.'.'];
        }

        return $errors;
    }
}
