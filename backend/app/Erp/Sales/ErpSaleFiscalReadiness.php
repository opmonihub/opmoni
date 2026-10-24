<?php

namespace App\Erp\Sales;

use App\Erp\Fiscal\ErpFiscalSaleSourcePrerequisites;
use App\Models\Erp\Fiscal\ErpFiscalConfiguration;
use App\Models\Erp\Fiscal\ErpFiscalConfigurationRevision;
use App\Models\Erp\Sales\ErpSale;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ErpSaleFiscalReadiness
{
    public const VERSION = 'sale-source-prerequisites-v1';

    public function modelInput(array $input, ?string $previous = null): ?string
    {
        $model = array_key_exists('fiscal_model', $input) ? $input['fiscal_model'] : $previous;
        if ($model !== null && (! is_string($model) || ! in_array($model, ['55', '65'], true))) {
            throw ValidationException::withMessages(['fiscal_model' => 'Selecione NF-e (55), NFC-e (65) ou nenhuma intenção fiscal.']);
        }
        if ($model !== null && isset($input['tax_context']['model']) && $input['tax_context']['model'] !== $model) {
            throw ValidationException::withMessages(['tax_context.model' => 'O modelo do contexto deve corresponder à intenção fiscal declarada.']);
        }

        return $model;
    }

    /** Minimal current configuration identity, also bound to explicit tax refresh proofs. */
    public function configurationPreconditions(CurrentCompany $context, ErpSale $sale): array
    {
        $revision = $this->configuration($context, $sale);
        $issuer = $sale->establishment()->where('erp_company_id', $context->company->id)->lockForUpdate()->first();

        return [
            'model' => $sale->fiscal_model, 'issuer_id' => $sale->erp_establishment_id,
            'configuration_revision_id' => $revision?->id, 'configuration_sha256' => $revision?->configuration_sha256,
            'environment' => $revision?->environment,
            'operation_nature_id' => $revision?->configuration['models'][$sale->fiscal_model]['operation_nature_id'] ?? null,
            'issuer_fingerprint' => hash('sha256', json_encode($issuer?->only(['id', 'cnpj', 'name', 'status']), JSON_THROW_ON_ERROR)),
        ];
    }

    public function inspect(Request $request, ErpSale $sale): array
    {
        if ($sale->fiscal_model === null) {
            return ['model' => null, 'schema_version' => null, 'readiness' => 'unverified', 'issuer_resolution' => null];
        }
        abort_unless(DB::transactionLevel() > 0, 500);
        $context = CurrentCompany::fromRequest($request);
        abort_unless((int) $sale->erp_company_id === (int) $context->company->id, 404);
        $sale->loadMissing('lines');
        $current = app(ErpCommercialTaxSnapshot::class)->currentPreconditionsMatch($context, $sale);
        $revision = $this->configuration($context, $sale);
        $preconditions = $this->configurationPreconditions($context, $sale);
        $configuration = $revision?->configuration ?? [];
        $settings = $configuration['models'][$sale->fiscal_model] ?? null;
        $issuer = $sale->establishment()->where('erp_company_id', $context->company->id)->lockForUpdate()->first();
        $issuerSettings = $configuration['issuer'] ?? [];
        $address = $issuerSettings['address'] ?? [];
        $party = $sale->party_snapshot;
        $facts = [
            'issuer' => [
                'cnpj' => $issuer?->cnpj, 'legal_name' => $issuer?->name,
                'state_registration' => $issuerSettings['state_registration'] ?? null,
                'tax_regime' => $issuerSettings['tax_regime'] ?? null,
                'address' => ['street' => $address['street'] ?? null, 'number' => $address['number'] ?? null,
                    'district' => $address['district'] ?? null, 'city_code' => $address['city_code'] ?? null,
                    'city' => $address['city_name'] ?? null, 'postal_code' => $address['zip_code'] ?? null, 'uf' => $issuerSettings['uf'] ?? null],
            ],
            'recipient' => $party === null ? null : ['cnpj' => $party['tax_id'] ?? null, 'legal_name' => $party['name'] ?? null, 'address' => $party['address'] ?? null],
            'operation_nature' => null, 'items' => [],
        ];
        $errors = [];
        if (! is_array($settings)) {
            $errors['configuration'] = ['Configure os dados do emissor para este modelo.'];
        } elseif ((int) ($settings['operation_nature_id'] ?? 0) !== (int) $sale->erp_operation_nature_id) {
            $errors['configuration.operation_nature_id'] = ['A natureza configurada deve corresponder à natureza revisada da venda.'];
        }
        if ($sale->erp_operation_nature_id === null) {
            $errors['erp_operation_nature_id'] = ['Selecione a natureza da operação e revise o CFOP.'];
        }
        $actual = $sale->draft_input['tax_context'] ?? [];
        foreach (['origin_uf', 'destination_uf', 'model', 'purpose', ...($sale->fiscal_model === '65' ? ['final_consumer', 'presence'] : [])] as $field) {
            if (! array_key_exists($field, $actual) || $actual[$field] === null || $actual[$field] === '') {
                $errors['tax_context.'.$field] = ['Informe o contexto da operação para este modelo.'];
            }
        }
        if (($actual['model'] ?? null) !== $sale->fiscal_model) {
            $errors['tax_context.model'] = ['Revise o contexto para o modelo fiscal declarado.'];
        }
        foreach ($sale->lines as $index => $line) {
            $saved = $line->product_snapshot ?? [];
            $product = $saved['product'] ?? [];
            $cfop = $saved['cfop_resolution'] ?? [];
            foreach ($cfop['unresolved_fields'] ?? [] as $field) {
                $path = str_starts_with($field, 'context.') ? 'tax_context.'.substr($field, 8) : $field;
                $errors[$path] = ['Complete o contexto e revise o CFOP da venda.'];
            }
            if (($cfop['effective_cfop'] ?? null) === null || ($cfop['applicability']['status'] ?? null) !== 'supported') {
                $errors['lines.'.$index.'.cfop'] = ['Revise um CFOP aplicável para a linha.'];
            }
            if (($cfop['context']['model'] ?? null) !== $sale->fiscal_model) {
                $errors['tax_context.model'] = ['A classificação congelada deve corresponder ao modelo declarado.'];
            }
            $facts['operation_nature'] ??= $saved['operation_nature'] ?? null;
            $facts['items'][] = [
                'source_line_id' => $line->id, 'code' => $saved['variant']['sku'] ?? $product['sku'] ?? null,
                'description' => $product['name'] ?? $saved['description'] ?? null,
                'ncm' => $product['ncm'] ?? null, 'cfop' => $saved['operation_nature']['cfop'] ?? null,
                'unit' => $saved['unit']['short_name'] ?? null,
                'quantity' => $line->quantity, 'unit_price' => $line->unit_price, 'gross_total' => $line->gross_amount,
                'tax' => ['origin' => $product['origin_code'] ?? null, 'icms_cst' => $product['icms_cst'] ?? null,
                    'pis_cst' => $product['pis_cst'] ?? null, 'cofins_cst' => $product['cofins_cst'] ?? null],
            ];
        }
        foreach (app(ErpFiscalSaleSourcePrerequisites::class)->errors($sale->fiscal_model, $facts) as $field => $messages) {
            $path = $field === 'recipient' ? 'erp_contact_id' : $field;
            $path = str_replace(['recipient.cnpj', 'recipient.'], ['party.tax_id', 'party.'], $path);
            $path = preg_replace('/^items\.(\d+)\.tax\.origin$/', 'lines.$1.origin_code', $path);
            $path = preg_replace('/^items\.(\d+)\.tax\./', 'lines.$1.', $path);
            $path = preg_replace('/^items\.(\d+)\./', 'lines.$1.', $path);
            $errors[$path] = $messages;
        }
        if (! $current) {
            $errors['tax_refresh'] = ['A classificação mudou. Revise a prévia tributária antes de lançar.'];
        }

        return ['version' => self::VERSION, 'model' => $sale->fiscal_model,
            'readiness' => $errors === [] ? 'source_prerequisites_checked' : 'incomplete',
            'errors' => $errors, 'classification_current' => $current, 'review' => $preconditions];
    }

    /** Ordinary edits preserve review; an existing draft changing intent needs an explicit reviewed proof. */
    public function capture(Request $request, ErpSale $sale, array $previous = [], bool $reviewed = false): array
    {
        $result = $this->inspect($request, $sale);
        if (! $reviewed && $sale->fiscal_model !== null && ! $sale->wasRecentlyCreated) {
            $review = ($previous['model'] ?? null) === $sale->fiscal_model ? ($previous['review'] ?? null) : null;
            if ($review !== $result['review']) {
                $result['readiness'] = 'review_required';
            }
            $result['review'] = $review;
        }

        return $result;
    }

    public function assertForPost(Request $request, ErpSale $sale): array
    {
        if ($sale->fiscal_model === null) {
            return $sale->commercial_snapshot['fiscal_context'] ?? [];
        }
        app(ErpCommercialTaxReview::class)->assertAuthority($request);
        $current = $this->inspect($request, $sale);
        $reviewed = $sale->commercial_snapshot['fiscal_context'] ?? [];
        abort_unless(($reviewed['version'] ?? null) === self::VERSION && ($reviewed['model'] ?? null) === $sale->fiscal_model
            && ($current['classification_current'] ?? false) && ($reviewed['review'] ?? null) === $current['review'],
            response()->json(['code' => 'sale_fiscal_review_changed', 'message' => 'A fonte comercial ou a configuração mudou. Revise a classificação e a intenção fiscal antes de lançar.'], 409));
        if ($current['errors'] !== []) {
            throw ValidationException::withMessages($current['errors']);
        }

        return $current;
    }

    private function configuration(CurrentCompany $context, ErpSale $sale): ?ErpFiscalConfigurationRevision
    {
        $owner = ErpFiscalConfiguration::query()->where('erp_company_id', $context->company->id)
            ->where('erp_establishment_id', $sale->erp_establishment_id)->lockForUpdate()->first();

        return $owner === null ? null : ErpFiscalConfigurationRevision::query()->where('erp_fiscal_configuration_id', $owner->id)->whereKey($owner->current_revision_id)->first();
    }
}
