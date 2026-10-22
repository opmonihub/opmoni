<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Erp\ErpCatalogWriteService;
use App\Erp\ErpCompanyOperatingSettings;
use App\Erp\LegacyBusinessReference;
use App\Http\Controllers\Controller;
use App\Models\Erp\ErpCompany;
use App\Services\BrazilianTaxId;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ErpCompanySettingsController extends Controller
{
    public function __construct(
        private ErpCompanyOperatingSettings $settings,
        private BrazilianTaxId $taxId,
        private ErpCatalogWriteService $writes,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $company = $context->company;

        return response()->json([
            'data' => $this->settings->toPayload($company),
            'meta' => $this->formMeta(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();

        $request->validate([
            'accounting_method' => ['sometimes', 'in:fifo,lifo'],
            'stock_overselling_enabled' => ['sometimes', 'boolean'],
            'name' => ['sometimes', 'string', 'max:255'],
            'cnpj' => ['sometimes', 'string', 'max:20'],
            'razao_social' => ['sometimes', 'nullable', 'string', 'min:10', 'max:120'],
            'ie' => ['sometimes', 'nullable', 'string', 'max:20'],
        ], [
            'razao_social.min' => 'digite no minimo 10 caractes.',
        ]);

        $persist = $this->settings->extractPersistable($request->all());
        if ($request->exists('stock_overselling_enabled')) {
            $persist['operating_settings']['stock_overselling_enabled'] = $request->boolean('stock_overselling_enabled');
        }

        if (isset($persist['cnpj'])) {
            $cnpj = $this->taxId->normalize((string) $persist['cnpj']);
            if (! $this->taxId->isValidCnpj($cnpj)) {
                throw ValidationException::withMessages(['cnpj' => 'CNPJ inválido']);
            }
            $persist['cnpj'] = $cnpj;
        }

        return $this->writes->run($request, function (CurrentCompany $context) use ($request, $persist): JsonResponse {
            /** @var ErpCompany $company */
            $company = ErpCompany::query()->lockForUpdate()->findOrFail($context->company->id);

            if (isset($persist['cnpj']) && ErpCompany::query()->where('cnpj', $persist['cnpj'])->whereKeyNot($company->id)->exists()) {
                throw ValidationException::withMessages(['cnpj' => 'CNPJ já cadastrado']);
            }

            foreach (['name', 'cnpj', 'razao_social', 'ie'] as $column) {
                if (array_key_exists($column, $persist)) {
                    $company->{$column} = $persist[$column];
                }
            }

            if ($persist['operating_settings'] !== []) {
                $this->settings->mergeOperating($company, $persist['operating_settings']);
            }

            $company->save();
            $this->writes->record($request, $context, $company, 'company_settings_updated');
            $company = $company->fresh();

            return response()->json([
                'data' => $this->settings->toPayload($company),
                'meta' => $this->formMeta(),
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function formMeta(): array
    {
        return [
            'date_formats' => LegacyBusinessReference::dateFormats(),
            'accounting_methods' => LegacyBusinessReference::accountingMethods(),
            'months' => LegacyBusinessReference::fiscalYearMonths(),
            'time_formats' => [12 => '12 horas', 24 => '24 horas'],
            'currency_symbol_placements' => ['before' => 'Antes do valor', 'after' => 'Depois do valor'],
            'regimes' => ['1' => 'Simples', '3' => 'Normal'],
            'ambientes' => ['1' => 'Produção', '2' => 'Homologação'],
            'decimal_places' => ['2' => '2 casas decimais', '3' => '3 casas decimais', '4' => '4 casas decimais'],
        ];
    }
}
