<?php

namespace App\Erp\Fiscal;

use App\Erp\ErpProductTaxFields;
use App\Erp\Sales\ErpCommercialDraftService;
use App\Erp\Sales\ErpCommercialSnapshotVersions;
use App\Models\Erp\Sales\ErpSale;
use Illuminate\Validation\ValidationException;

final class ErpFiscalFrozenSaleSourceProjection
{
    /** @return array<string, mixed> */
    public function project(ErpSale $sale, string $model): array
    {
        $snapshot = app(ErpCommercialDraftService::class)->postedSnapshot($sale);
        $lines = $snapshot['lines'] ?? [];
        $snapshotVersion = $snapshot['version'] ?? null;
        $legacy = $snapshotVersion === ErpCommercialDraftService::VERSION;
        $resolved = $snapshotVersion === ErpCommercialSnapshotVersions::RESOLVED;
        if (! in_array($model, ['55', '65'], true)
            || ! ($legacy || $resolved)
            || ($sale->fiscal_model !== null && (string) $sale->fiscal_model !== $model)
            || ! is_array($snapshot['accepted'] ?? null)
            || $resolved && ((string) ($sale->fiscal_model ?? '') !== $model
                || ($snapshot['fiscal_context']['model'] ?? null) !== $model
                || ($snapshot['fiscal_context']['readiness'] ?? null) !== 'source_prerequisites_checked')
            || $legacy && ((isset($snapshot['document']['fiscal_model']) && $snapshot['document']['fiscal_model'] !== null)
                || isset($snapshot['fiscal_context']['model']) && $snapshot['fiscal_context']['model'] !== null
                || isset($snapshot['fiscal_context']['readiness']) && $snapshot['fiscal_context']['readiness'] !== 'unverified')
            || ! is_array($snapshot['party'] ?? null)
            || ! is_array($snapshot['party']['address'] ?? null)
            || ! is_array($lines)
            || $lines === []) {
            $this->invalidSource('source', 'Venda lançada sem fatos fiscais revisados e aceitos para este modelo.');
        }

        $recipient = $snapshot['party'];
        foreach (['tax_id', 'name'] as $field) {
            if (! is_string($recipient[$field] ?? null) || trim($recipient[$field]) === '') {
                $this->invalidSource('source.recipient', 'Destinatário congelado incompleto.');
            }
        }

        $nature = null;
        $items = [];
        foreach ($lines as $index => $line) {
            $facts = $line['product_snapshot'] ?? [];
            $product = $facts['product'] ?? [];
            $tax = $legacy ? $product : ($facts['product_tax_resolution']['values'] ?? []);
            $operationNature = $facts['operation_nature'] ?? [];
            $cfop = $facts['cfop_resolution'] ?? [];
            $provenance = [
                'product_tax_resolution' => $facts['product_tax_resolution'] ?? null,
                'product_tax_configuration' => $facts['product_tax_configuration'] ?? null,
                'cfop_resolution' => $cfop,
            ];

            if (($facts['version'] ?? null) !== $snapshotVersion
                || ! is_array($product)
                || ! is_array($tax)
                || $legacy && (array_key_exists('product_tax_resolution', $facts)
                    || array_key_exists('product_tax_configuration', $facts)
                    || array_key_exists('cfop_resolution', $facts))
                || ! is_array($operationNature)
                || $resolved && (! is_array($cfop)
                    || ($cfop['effective_cfop'] ?? null) !== ($operationNature['cfop'] ?? null)
                    || ($cfop['nature_id'] ?? null) !== ($operationNature['id'] ?? null)
                    || ($cfop['default_cfop'] ?? null) !== ($operationNature['default_cfop'] ?? null)
                    || ($cfop['configuration_revision'] ?? null) !== ($operationNature['configuration_revision'] ?? null))
                || ! is_array($facts['unit'] ?? null)
                || ! is_string($product['ncm'] ?? null)
                || $product['ncm'] === ''
                || ! is_string($operationNature['cfop'] ?? null)
                || $operationNature['cfop'] === ''
                || ! is_string($operationNature['description'] ?? null)
                || ! is_numeric($operationNature['id'] ?? null)
                || ! is_numeric($line['id'] ?? null)
                || ! array_key_exists('erp_product_variant_id', $line)
                || ($line['erp_product_variant_id'] !== null && ! is_numeric($line['erp_product_variant_id']))) {
                $this->invalidSource('source.items.'.$index, 'Linha sem classificação fiscal efetiva e proveniência revisada.');
            }

            if ($resolved && ! is_array($facts['product_tax_resolution'] ?? null)) {
                $this->invalidSource('source.items.'.$index.'.provenance.product_tax_resolution', 'Resolução tributária congelada inválida.');
            }

            if ($resolved) {
                $this->assertResolvedEvidence($facts, $product, $facts['product_tax_resolution'] ?? [], $operationNature, $cfop, $snapshot, $sale, $index);
            }

            if (! is_string($product['origin_code'] ?? null) || $product['origin_code'] === '') {
                $this->invalidSource('source.items.'.$index.'.tax', 'Origem tributária congelada incompleta.');
            }
            foreach (['icms_cst', 'pis_cst', 'cofins_cst', 'icms_rate'] as $field) {
                if (! array_key_exists($field, $tax) || $tax[$field] === null || $tax[$field] === '') {
                    $this->invalidSource('source.items.'.$index.'.tax', 'Classificação tributária congelada incompleta.');
                }
            }
            if ($nature === null) {
                $nature = $operationNature;
            } elseif ((int) $nature['id'] !== (int) $operationNature['id']) {
                $this->invalidSource('source.items.'.$index, 'Natureza de operação divergente entre linhas da venda.');
            }

            $item = [
                'source_line_id' => (int) $line['id'],
                'erp_product_variant_id' => $line['erp_product_variant_id'] === null ? null : (int) $line['erp_product_variant_id'],
                'code' => $facts['variant']['sku'] ?? $product['sku'] ?? '',
                'description' => $product['name'] ?? '',
                'ncm' => $product['ncm'],
                'cfop' => $operationNature['cfop'],
                'unit' => $facts['unit']['short_name'] ?? '',
                'quantity' => $line['quantity'] ?? null,
                'unit_price' => $line['unit_price'] ?? null,
                'gross_total' => $line['gross_amount'] ?? null,
                'tax' => [
                    'origin' => $product['origin_code'],
                    'icms_cst' => $tax['icms_cst'],
                    'pis_cst' => $tax['pis_cst'],
                    'cofins_cst' => $tax['cofins_cst'],
                    'icms_rate' => $tax['icms_rate'],
                ],
            ];
            if ($resolved) {
                $item['provenance'] = $provenance;
            }
            $items[] = $item;
        }

        if ((int) ($nature['id'] ?? 0) !== (int) ($sale->erp_operation_nature_id ?? 0)) {
            $this->invalidSource('source.identity.operation_nature', 'Natureza congelada da venda inválida.');
        }

        return [
            'snapshot' => $snapshot,
            'recipient' => [
                'cnpj' => $recipient['tax_id'],
                'legal_name' => $recipient['name'],
                'address' => $recipient['address'],
            ],
            'operation_nature' => $nature,
            'items' => $items,
        ];
    }

    /** @param array<string, mixed> $facts
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $tax
     * @param  array<string, mixed>  $operationNature
     * @param  array<string, mixed>  $cfop
     * @param  array<string, mixed>  $snapshot
     */
    private function assertResolvedEvidence(array $facts, array $product, array $tax, array $operationNature, array $cfop, array $snapshot, ErpSale $sale, int|string $index): void
    {
        $path = 'source.items.'.$index.'.provenance';
        $configuration = $facts['product_tax_configuration'] ?? null;
        if (! is_array($configuration)
            || ! array_key_exists('profile_id', $configuration)
            || ! array_key_exists('intents', $configuration)
            || ($configuration['intents'] !== null && ! is_array($configuration['intents']))
            || ! is_array($configuration['values'] ?? null)) {
            $this->invalidSource($path.'.product_tax_configuration', 'Configuração tributária congelada incompleta.');
        }

        $fields = ErpProductTaxFields::names();
        $intents = $configuration['intents'] ?? [];
        $profileId = $configuration['profile_id'];
        if ($profileId !== null && ! self::positiveId($profileId)) {
            $this->invalidSource($path.'.product_tax_configuration', 'Identidade da configuração tributária congelada inválida.');
        }
        foreach ($intents as $field => $intent) {
            if (! in_array($field, $fields, true) || ! in_array($intent, ['value', 'inherit', 'unset'], true)) {
                $this->invalidSource($path.'.product_tax_configuration', 'Intenção tributária congelada inválida.');
            }
        }
        foreach ($fields as $field) {
            if (! array_key_exists($field, $configuration['values'])
                || ($configuration['values'][$field] !== null && ! is_string($configuration['values'][$field]) && ! is_numeric($configuration['values'][$field]))) {
                $this->invalidSource($path.'.product_tax_configuration', 'Valores da configuração tributária congelada incompletos.');
            }
        }

        $taxPath = $path.'.product_tax_resolution';
        if (($tax['version'] ?? null) !== 'product-tax-v1') {
            $this->invalidSource($taxPath, 'Versão da resolução tributária congelada desconhecida.');
        }
        if (! self::positiveId($product['id'] ?? null)
            || ! self::positiveId($tax['product_id'] ?? null)
            || (int) $tax['product_id'] !== (int) $product['id']) {
            $this->invalidSource($taxPath.'.product_id', 'Identidade da resolução tributária não corresponde ao produto congelado.');
        }
        if (! is_array($tax['values'] ?? null) || ! is_array($tax['sources'] ?? null)) {
            $this->invalidSource($taxPath.'.sources', 'Fontes da resolução tributária congelada incompletas.');
        }
        foreach ($fields as $field) {
            if (! array_key_exists($field, $tax['values']) || ($product[$field] ?? null) !== $tax['values'][$field]) {
                $this->invalidSource($taxPath.'.values', 'Valores da resolução tributária divergem do produto congelado.');
            }
            $source = $tax['sources'][$field] ?? null;
            if (! is_array($source)
                || ! in_array($source['source'] ?? null, ['product', 'unset', 'profile', 'company_profile', 'company_setting', 'unresolved'], true)
                || ! in_array($source['intent'] ?? null, ['value', 'inherit', 'unset'], true)) {
                $this->invalidSource($taxPath.'.sources', 'Fonte tributária por campo incompleta ou inválida.');
            }
            $expectedIntent = match ($source['source']) {
                'product' => 'value',
                'unset' => 'unset',
                default => 'inherit',
            };
            if ($source['intent'] !== $expectedIntent
                || array_key_exists($field, $intents) && $intents[$field] !== $source['intent']) {
                $this->invalidSource($taxPath.'.sources', 'Intenção da fonte tributária congelada inconsistente.');
            }
            if ($source['source'] === 'product' && ($configuration['values'][$field] ?? null) !== $tax['values'][$field]
                || $source['source'] === 'unset' && ($tax['values'][$field] !== null || $configuration['values'][$field] !== null)
                || $source['source'] === 'company_setting' && (! is_string($source['setting'] ?? null) || trim($source['setting']) === '')) {
                $this->invalidSource($taxPath.'.sources', 'Identidade da fonte tributária congelada inconsistente.');
            }
        }

        $profile = $tax['profile'] ?? null;
        if ($profile !== null && ! self::validRevisionIdentity($profile)) {
            $this->invalidSource($taxPath.'.profile', 'Identidade do perfil tributário congelado inválida.');
        }
        if (($profile['profile_id'] ?? null) !== null && (int) $profile['profile_id'] !== (int) ($configuration['profile_id'] ?? 0)
            || ($configuration['profile_id'] ?? null) === null && $profile !== null) {
            $this->invalidSource($taxPath.'.profile', 'Perfil tributário congelado diverge da configuração do produto.');
        }
        $companyDefault = $tax['company_default'] ?? null;
        if (! is_array($companyDefault)
            || ! array_key_exists('revision', $companyDefault)
            || ! array_key_exists('profile_id', $companyDefault)
            || ! array_key_exists('profile_revision', $companyDefault)
            || ! array_key_exists('profile_revision_id', $companyDefault)
            || ! is_numeric($companyDefault['revision'])
            || ($companyDefault['profile_id'] !== null && ! self::positiveId($companyDefault['profile_id']))) {
            $this->invalidSource($taxPath.'.company_default', 'Identidade do padrão tributário congelado incompleta.');
        }
        foreach ($tax['sources'] as $field => $source) {
            if (($source['source'] ?? null) === 'profile' && ! self::sameRevisionIdentity($source, $profile)
                || ($source['source'] ?? null) === 'company_profile'
                    && (! self::positiveId($source['profile_id'] ?? null)
                        || ! self::positiveId($source['revision'] ?? null)
                        || ! self::positiveId($source['revision_id'] ?? null)
                        || (int) $source['profile_id'] !== (int) ($companyDefault['profile_id'] ?? 0)
                        || (int) $source['revision'] !== (int) ($companyDefault['profile_revision'] ?? 0)
                        || (int) $source['revision_id'] !== (int) ($companyDefault['profile_revision_id'] ?? 0))) {
                $this->invalidSource($taxPath.'.sources.'.$field, 'Identidade de perfil usada pela classificação tributária diverge da proveniência congelada.');
            }
        }
        if (! is_array($tax['unresolved_fields'] ?? null)
            || ! is_array($tax['context'] ?? null)
            || ! is_array($tax['applicability'] ?? null)
            || ! array_key_exists('profile', $tax['applicability'])
            || ! array_key_exists('company_profile', $tax['applicability'])
            || ! is_string($tax['company_settings_fingerprint'] ?? null)
            || ! is_string($tax['fingerprint'] ?? null)
            || ! is_array($tax['errors'] ?? null)) {
            $this->invalidSource($taxPath, 'Metadados da resolução tributária congelada incompletos.');
        }
        foreach (['profile', 'company_profile'] as $source) {
            if ($tax['applicability'][$source] !== null && ! is_array($tax['applicability'][$source])) {
                $this->invalidSource($taxPath.'.applicability.'.$source, 'Aplicabilidade tributária congelada inválida.');
            }
        }

        $cfopPath = $path.'.cfop_resolution';
        $cfopContext = $cfop['context'] ?? null;
        $applicability = $cfop['applicability'] ?? null;
        if (($cfop['version'] ?? null) !== 'cfop-resolution-v1'
            || ! in_array($cfop['source'] ?? null, ['nature_default', 'explicit', 'product_exception'], true)
            || ! is_array($cfopContext)
            || ! is_array($applicability)
            || ($applicability['status'] ?? null) !== 'supported'
            || ($applicability['rules_version'] ?? null) !== 'goods-sales-cfop-v1'
            || ! is_array($cfop['unresolved_fields'] ?? null)
            || $cfop['unresolved_fields'] !== []
            || ! is_string($cfop['reference_version'] ?? null)
            || trim($cfop['reference_version']) === ''
            || ! is_string($cfop['context_fingerprint'] ?? null)
            || ! is_string($cfop['fingerprint'] ?? null)
            || (string) ($cfopContext['model'] ?? '') !== (string) ($snapshot['fiscal_context']['model'] ?? '')
            || (string) ($cfopContext['model'] ?? '') !== (string) ($sale->fiscal_model ?? '')
            || (int) ($cfopContext['erp_establishment_id'] ?? 0) !== (int) $sale->erp_establishment_id
            || ($cfopContext['movement'] ?? null) !== 'out') {
            $this->invalidSource($cfopPath.'.applicability', 'Aplicabilidade CFOP congelada ausente ou incompatível.');
        }
        if (($cfop['source'] === 'nature_default' && ($cfop['explicit_choice'] ?? null) !== null)
            || ($cfop['source'] === 'explicit' && ($cfop['explicit_choice'] ?? null) !== ($cfop['effective_cfop'] ?? null))
            || ($cfop['source'] === 'product_exception'
                && (! self::positiveId($cfop['choice_id'] ?? null) || ! self::positiveId($cfop['exception_id'] ?? null)))) {
            $this->invalidSource($cfopPath, 'Identidade da regra CFOP congelada inconsistente.');
        }
    }

    private static function positiveId(mixed $value): bool
    {
        return is_numeric($value) && (int) $value > 0;
    }

    private static function validRevisionIdentity(mixed $identity): bool
    {
        return is_array($identity)
            && self::positiveId($identity['profile_id'] ?? null)
            && self::positiveId($identity['revision'] ?? null)
            && self::positiveId($identity['revision_id'] ?? null);
    }

    private static function sameRevisionIdentity(array $source, mixed $identity): bool
    {
        return self::validRevisionIdentity($identity)
            && self::positiveId($source['profile_id'] ?? null)
            && self::positiveId($source['revision'] ?? null)
            && self::positiveId($source['revision_id'] ?? null)
            && (int) $source['profile_id'] === (int) $identity['profile_id']
            && (int) $source['revision'] === (int) $identity['revision']
            && (int) $source['revision_id'] === (int) $identity['revision_id'];
    }

    private function invalidSource(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
