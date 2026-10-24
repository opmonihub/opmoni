<?php

namespace App\Erp\Sales;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanySetupState;
use App\Models\Erp\Sales\ErpInvoiceScheme;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ErpCommercialNumbers
{
    public function allocate(string $kind, int $company, int $establishment, ?string $provided): string
    {
        return $this->allocateDocument($kind, $company, $establishment, $provided)['number'];
    }

    /** @return array{number: string, scheme_id: ?int} */
    public function allocateDocument(string $kind, int $company, int $establishment, ?string $provided): array
    {
        return DB::transaction(function () use ($kind, $company, $establishment, $provided): array {
            ErpCompany::query()->whereKey($company)->lockForUpdate()->firstOrFail();
            $scheme = $kind === 'sale' ? $this->saleScheme($company) : null;

            return ['number' => $this->number($kind, $company, $establishment, $provided, $scheme), 'scheme_id' => $scheme?->id];
        });
    }

    private function number(string $kind, int $company, int $establishment, ?string $provided, ?ErpInvoiceScheme $scheme): string
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
            DB::table('erp_commercial_number_sequences')->insert($scope + ['next_number' => $scheme?->start_number ?? 1]);
        }
        $row = DB::table('erp_commercial_number_sequences')->where($scope)->lockForUpdate()->first();
        $next = (string) $row->next_number;
        do {
            abort_if(bccomp($next, '9007199254740991', 0) >= 0, 422, 'Numeração esgotada.');
            $prefix = $scheme === null ? ['quote' => 'Q-', 'order' => 'O-', 'sale' => 'S-'][$kind] : ($scheme->prefix ?? '');
            $number = $prefix.str_pad($next, $scheme === null ? 6 : (int) $scheme->total_digits, '0', STR_PAD_LEFT);
            $next = bcadd($next, '1', 0);
        } while ($query()->where($field, $number)->exists());
        DB::table('erp_commercial_number_sequences')->where('id', $row->id)->update(['next_number' => $next]);

        return $number;
    }

    private function saleScheme(int $company): ?ErpInvoiceScheme
    {
        $state = ErpCompanySetupState::query()->forCompany($company)->lockForUpdate()->first();
        if ($state === null || ! $state->sale_numbering_adopted) {
            return null;
        }
        $scheme = ErpInvoiceScheme::query()->where('erp_company_id', $company)->whereKey($state->default_invoice_scheme_id)->lockForUpdate()->first();
        if ($scheme === null || $scheme->status !== 'active') {
            throw ValidationException::withMessages(['default_invoice_scheme_id' => 'Selecione uma numeração de vendas ativa desta empresa.']);
        }
        $prefix = $scheme->prefix ?? '';
        $digits = (string) $scheme->total_digits;
        $start = (string) $scheme->start_number;
        if (! preg_match('/^[1-9]\d{0,1}$/D', $digits) || (int) $digits > 64 - mb_strlen($prefix)
            || ! preg_match('/^[1-9]\d{0,9}$/D', $start) || bccomp($start, '4294967295', 0) > 0
            || mb_strlen($prefix) > 16 || preg_match('/[\x00-\x1f\x7f]/', $prefix)) {
            throw ValidationException::withMessages(['default_invoice_scheme_id' => 'Revise o prefixo, início e quantidade de dígitos da numeração de vendas.']);
        }

        return $scheme;
    }
}
