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
                || $resolved && (! is_array($cfop)
                    || ($cfop['effective_cfop'] ?? null) !== ($operationNature['cfop'] ?? null)
                    || ($cfop['nature_id'] ?? null) !== ($operationNature['id'] ?? null)
                    || ($cfop['default_cfop'] ?? null) !== ($operationNature['default_cfop'] ?? null)
                    || ($cfop['configuration_revision'] ?? null) !== ($operationNature['configuration_revision'] ?? null))
                || ! is_array($operationNature)
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

            if (! is_string($product['origin_code'] ?? null) || $product['origin_code'] === '') {
                $this->invalidSource('source.items.'.$index.'.tax', 'Origem tributária congelada incompleta.');
            }
            foreach (['icms_cst', 'pis_cst', 'cofins_cst', 'icms_rate'] as $field) {
                if (! array_key_exists($field, $tax) || $tax[$field] === null || $tax[$field] === '') {
                    $this->invalidSource('source.items.'.$index.'.tax', 'Classificação tributária congelada incompleta.');
                }
            }
            if ($resolved) {
                foreach (ErpProductTaxFields::names() as $field) {
                    if (! array_key_exists($field, $tax) || ($product[$field] ?? null) !== $tax[$field]) {
                        $this->invalidSource('source.items.'.$index.'.tax', 'Proveniência tributária congelada inconsistente.');
                    }
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

    private function invalidSource(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
