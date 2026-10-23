<?php

namespace App\Erp\Sales;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ErpCommercialNumbers
{
    public function allocate(string $kind, int $company, int $establishment, ?string $provided): string
    {
        $class = ErpCommercialDraftService::modelClass($kind);
        $field = ErpCommercialDraftService::numberField($kind);
        $query = fn () => $class::where('erp_company_id', $company)->where('erp_establishment_id', $establishment);
        if ($provided !== null) {
            if ($provided === '' || trim($provided) !== $provided || mb_strlen($provided) > 64 || preg_match('/[\x00-\x1f\x7f]/', $provided)) {
                throw ValidationException::withMessages([$field => 'Número inválido.']);
            }
            if ($query()->where($field, $provided)->exists()) {
                throw ValidationException::withMessages([$field => 'Número já utilizado neste estabelecimento.']);
            }

            return $provided;
        }
        $scope = ['erp_company_id' => $company, 'erp_establishment_id' => $establishment, 'document_type' => $kind];
        if (! DB::table('erp_commercial_number_sequences')->where($scope)->exists()) {
            DB::table('erp_commercial_number_sequences')->insert($scope + ['next_number' => 1]);
        }
        $row = DB::table('erp_commercial_number_sequences')->where($scope)->lockForUpdate()->first();
        $next = (string) $row->next_number;
        do {
            abort_if(bccomp($next, '9007199254740991', 0) >= 0, 422, 'Numeração esgotada.');
            $number = ['quote' => 'Q-', 'order' => 'O-', 'sale' => 'S-'][$kind].str_pad($next, 6, '0', STR_PAD_LEFT);
            $next = bcadd($next, '1', 0);
        } while ($query()->where($field, $number)->exists());
        DB::table('erp_commercial_number_sequences')->where('id', $row->id)->update(['next_number' => $next]);

        return $number;
    }
}
