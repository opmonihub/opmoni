<?php

namespace App\Erp\Sales;

use App\Erp\ErpProductTaxFields;

final class ErpCommercialSnapshotVersions
{
    public const RESOLVED = 'commercial-v2';

    public static function documentVersion(array $lines): string
    {
        foreach ($lines as $line) {
            if (($line['product_snapshot']['version'] ?? null) === self::RESOLVED) {
                return self::RESOLVED;
            }
        }

        return ErpCommercialDraftService::VERSION;
    }

    public static function valid(array $snapshot): bool
    {
        $version = $snapshot['version'] ?? null;
        if (! in_array($version, [ErpCommercialDraftService::VERSION, self::RESOLVED], true)) {
            return false;
        }
        foreach ($snapshot['lines'] ?? [] as $line) {
            $facts = $line['product_snapshot'] ?? [];
            if (($facts['version'] ?? ErpCommercialDraftService::VERSION) !== self::RESOLVED) {
                if (($facts['version'] ?? ErpCommercialDraftService::VERSION) !== ErpCommercialDraftService::VERSION) {
                    return false;
                }

                continue;
            }
            if ($version !== self::RESOLVED || ! is_array($facts['product_tax_resolution'] ?? null) || ! is_array($facts['cfop_resolution'] ?? null)) {
                return false;
            }
            foreach (ErpProductTaxFields::names() as $field) {
                if (! array_key_exists($field, $facts['product_tax_resolution']['values'] ?? [])
                    || ($facts['product'][$field] ?? null) !== $facts['product_tax_resolution']['values'][$field]) {
                    return false;
                }
            }
            if (($facts['operation_nature']['cfop'] ?? null) !== ($facts['cfop_resolution']['effective_cfop'] ?? null)
                || ($facts['operation_nature']['id'] ?? null) !== ($facts['cfop_resolution']['nature_id'] ?? null)
                || ($facts['operation_nature']['default_cfop'] ?? null) !== ($facts['cfop_resolution']['default_cfop'] ?? null)
                || ($facts['operation_nature']['configuration_revision'] ?? null) !== ($facts['cfop_resolution']['configuration_revision'] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
