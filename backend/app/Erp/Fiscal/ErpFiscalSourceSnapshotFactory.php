<?php

namespace App\Erp\Fiscal;

use App\Erp\BrazilianStateCode;
use InvalidArgumentException;

final class ErpFiscalSourceSnapshotFactory
{
    /** @param array<string, mixed> $source @return array<string, mixed> */
    public function create(string $model, array $source): array
    {
        $this->validateSource($model, $source);

        return $this->sortKeys($source);
    }

    /** @param array<string, mixed> $source */
    public function validateSource(string $model, array $source): void
    {
        $errors = [];
        foreach (['identity', 'issuer'] as $field) {
            if (! is_array($source[$field] ?? null)) {
                throw new InvalidArgumentException('Grupo fiscal obrigatório inválido: '.$field.'.');
            }
        }
        $sections = match ($model) {
            '55', '65' => ['recipient', 'totals', 'freight', 'items', 'payment'], '57' => ['transport_service'], '58' => ['transport_manifest'], default => []
        };
        foreach ($sections as $field) {
            if (! is_array($source[$field] ?? null)) {
                throw new InvalidArgumentException('Grupo fiscal obrigatório inválido: '.$field.'.');
            }
        }
        foreach (['items', 'payment', 'references'] as $field) {
            if (isset($source[$field]) && (! is_array($source[$field]) || ! array_is_list($source[$field]) || count($source[$field]) > 990)) {
                throw new InvalidArgumentException('Lista fiscal inválida: '.$field.'.');
            }
            foreach ($source[$field] ?? [] as $row) {
                if (! is_array($row) || isset($row['tax']) && ! is_array($row['tax'])) {
                    throw new InvalidArgumentException('Item fiscal inválido: '.$field.'.');
                }
            }
        }

        $identity = $source['identity'] ?? [];
        $issuer = $source['issuer'] ?? [];
        $this->assertScalars($identity, array_keys($identity));
        $this->assertScalars($issuer, array_diff(array_keys($issuer), ['address']));
        $requiredIdentity = ['model', 'environment', 'uf', 'issued_at', 'series', 'number', 'access_key'];
        foreach ($requiredIdentity as $field) {
            if (! isset($identity[$field]) || $identity[$field] === '') {
                $errors[] = 'identity.'.$field;
            }
        }
        if ((string) ($identity['model'] ?? '') !== $model) {
            $errors[] = 'identity.model';
        }
        if (! in_array($identity['environment'] ?? null, ['homologation', 'production'], true)) {
            $errors[] = 'identity.environment';
        }
        if (BrazilianStateCode::ibgeFromUf((string) ($identity['uf'] ?? '')) === null) {
            $errors[] = 'identity.uf';
        }
        if (strtotime((string) ($identity['issued_at'] ?? '')) === false || (int) ($identity['series'] ?? -1) < 0 || (int) ($identity['number'] ?? 0) <= 0) {
            $errors[] = 'identity.numbering';
        }
        $rawCnpj = (string) ($issuer['cnpj'] ?? '');
        $cnpj = $rawCnpj;
        if (! preg_match('/^\d{14}$/', $rawCnpj) || ! $this->validCnpj($cnpj)) {
            $errors[] = 'issuer.cnpj';
        }
        foreach (['legal_name', 'state_registration', 'tax_regime'] as $field) {
            if (! isset($issuer[$field]) || $issuer[$field] === '') {
                $errors[] = 'issuer.'.$field;
            }
        }
        if ((int) ($identity['series'] ?? -1) > 999 || (int) ($identity['number'] ?? 0) > 999999999) {
            $errors[] = 'identity.numbering_bounds';
        }
        $this->requireAddress($issuer['address'] ?? null, 'issuer.address', $errors);
        $this->requireText($issuer, 'legal_name', 2, 60, 'issuer', $errors);
        $this->requirePattern($issuer, 'state_registration', '/^[0-9]{2,14}$/', 'issuer', $errors);
        $this->requireEnum($issuer, 'tax_regime', [1, 2, 3, 4], 'issuer', $errors);
        $key = (string) ($identity['access_key'] ?? '');
        if (! app(ErpFiscalPayloadBuilder::class)->validatesAccessKey($key)
            || substr($key, 6, 14) !== $cnpj || substr($key, 20, 2) !== $model
            || (int) substr($key, 22, 3) !== (int) ($identity['series'] ?? 0)
            || (int) substr($key, 25, 9) !== (int) ($identity['number'] ?? 0)) {
            $errors[] = 'identity.access_key';
        }

        if (in_array($model, ['55', '65'], true)) {
            $recipient = $source['recipient'] ?? [];
            $this->assertScalars($recipient, array_diff(array_keys($recipient), ['address']));
            $this->assertScalars($source['freight'], array_keys($source['freight']));
            $this->assertScalars($source['totals'], array_keys($source['totals']));
            if (empty($source['items']) || ! is_array($source['items'])) {
                $errors[] = 'items';
            }
            foreach (['cnpj', 'legal_name', 'state_registration_indicator'] as $field) {
                if (! array_key_exists($field, $recipient) || $recipient[$field] === '') {
                    $errors[] = 'recipient.'.$field;
                }
            }
            $recipientCnpj = (string) ($recipient['cnpj'] ?? '');
            if (! preg_match('/^\d{14}$/', $recipientCnpj) && ! preg_match('/^\d{11}$/', $recipientCnpj)) {
                $errors[] = 'recipient.cnpj';
            }
            $this->requireAddress($recipient['address'] ?? null, 'recipient.address', $errors);
            $this->requireText($recipient, 'legal_name', 2, 60, 'recipient', $errors);
            $this->requireEnum($recipient, 'state_registration_indicator', [1, 2, 9], 'recipient', $errors);
            $this->requireText($identity, 'operation_nature', 1, 60, 'identity', $errors);
            foreach (['operation_type' => [0, 1], 'destination_type' => [1, 2, 3], 'purpose' => [1, 2, 4], 'final_consumer' => [0, 1], 'presence_indicator' => [0, 1, 2, 3, 4, 5, 9], 'print_type' => [0, 1, 2, 3, 4, 5]] as $field => $allowed) {
                $this->requireEnum($identity, $field, $allowed, 'identity', $errors);
            }
            $this->requireEnum($source['freight'], 'modality', [0, 1, 2, 3, 4, 9], 'freight', $errors);
            foreach ($source['items'] ?? [] as $index => $item) {
                $this->assertScalars($item, array_diff(array_keys($item), ['tax']));
                $this->assertScalars($item['tax'] ?? [], array_keys($item['tax'] ?? []));
                foreach (['code' => 60, 'description' => 120, 'unit' => 6] as $field => $maximum) {
                    $this->requireText($item, $field, 1, $maximum, 'items.'.$index, $errors);
                }
                $this->requireEnum($item['tax'] ?? [], 'origin', [0, 1, 2, 3, 4, 5, 6, 7, 8, '0', '1', '2', '3', '4', '5', '6', '7', '8'], 'items.'.$index.'.tax', $errors);
                foreach (['icms_base', 'icms_value', 'pis_value', 'cofins_value'] as $field) {
                    $this->requireDecimal($item['tax'] ?? [], $field, 13, 2, 'items.'.$index.'.tax', $errors);
                }
                $this->requireDecimal($item['tax'] ?? [], 'icms_rate', 3, 4, 'items.'.$index.'.tax', $errors);
                if (! preg_match('/^(?:\d{2}|\d{8})$/', (string) ($item['ncm'] ?? '')) || ! preg_match('/^[123567]\d{3}$/', (string) ($item['cfop'] ?? ''))
                    || ! preg_match('/^\d{1,11}(?:\.\d{1,4})?$/', (string) ($item['quantity'] ?? '')) || bccomp((string) ($item['quantity'] ?? '0'), '0', 4) <= 0
                    || ! preg_match('/^\d{1,11}(?:\.\d{1,10})?$/', (string) ($item['unit_price'] ?? '')) || ! in_array($item['ind_total'] ?? null, [0, 1], true)
                    || $this->cents((string) ($item['gross_total'] ?? '')) < 0) {
                    $errors[] = 'items.'.$index.'.typed_fields';
                }
                foreach (['code', 'description', 'ncm', 'cfop', 'unit', 'quantity', 'unit_price', 'gross_total', 'ind_total'] as $field) {
                    if (! array_key_exists($field, $item) || $item[$field] === '') {
                        $errors[] = 'items.'.$index.'.'.$field;
                    }
                }
                foreach (['origin', 'icms_cst', 'icms_base', 'icms_rate', 'icms_value', 'pis_cst', 'pis_value', 'cofins_cst', 'cofins_value'] as $field) {
                    if (! array_key_exists($field, $item['tax'] ?? [])) {
                        $errors[] = 'items.'.$index.'.tax.'.$field;
                    }
                }
            }
            foreach (['products', 'invoice', 'icms_base', 'icms', 'icms_desonerated', 'fcp', 'st_base', 'st', 'fcp_st', 'fcp_st_retained', 'freight', 'insurance', 'discount', 'ii', 'ipi', 'ipi_returned', 'pis', 'cofins', 'other', 'taxes_total'] as $field) {
                if (! array_key_exists($field, $source['totals'] ?? [])) {
                    $errors[] = 'totals.'.$field;
                }
                $this->requireDecimal($source['totals'], $field, 13, 2, 'totals', $errors);
            }
            if (empty($source['payment']) || ! is_array($source['payment'])) {
                $errors[] = 'payment';
            }
            foreach (['purpose', 'final_consumer'] as $field) {
                if (! array_key_exists($field, $identity)) {
                    $errors[] = 'identity.'.$field;
                }
            }
            if (! isset($source['freight']['modality'])) {
                $errors[] = 'freight.modality';
            }
            $productsCents = 0;
            foreach ($source['items'] ?? [] as $item) {
                $productsCents += $this->cents((string) ($item['gross_total'] ?? ''));
                if (($item['tax']['icms_cst'] ?? null) !== '00' || ! in_array((string) ($item['tax']['pis_cst'] ?? ''), ['04', '05', '06', '07', '08', '09'], true)
                    || ! in_array((string) ($item['tax']['cofins_cst'] ?? ''), ['04', '05', '06', '07', '08', '09'], true)) {
                    $errors[] = 'items.tax.unsupported_group';
                }
            }
            $declaredProducts = $this->cents((string) ($source['totals']['products'] ?? ''));
            $invoiceTotal = $this->cents((string) ($source['totals']['invoice'] ?? ''));
            if ($productsCents < 0 || $declaredProducts < 0 || $productsCents !== $declaredProducts) {
                $errors[] = 'totals.products_mismatch';
            }
            $paidCents = 0;
            foreach ($source['payment'] ?? [] as $payment) {
                $this->assertScalars($payment, array_keys($payment));
                $this->requireEnum($payment, 'method', ['01', '02', '03', '04', '05', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '90'], 'payment', $errors);
                $amount = $this->cents((string) ($payment['amount'] ?? ''));
                if ($amount < 0) {
                    $errors[] = 'payment.amount';
                } else {
                    $paidCents += $amount;
                }
            }
            $expectedPayment = (int) ($identity['purpose'] ?? 0) === 4 ? 0 : $invoiceTotal;
            if ($invoiceTotal < 0 || $paidCents !== $expectedPayment || (int) ($identity['purpose'] ?? 0) === 4 && array_filter($source['payment'], static fn (array $payment): bool => ($payment['method'] ?? null) !== '90') !== []) {
                $errors[] = 'payment.total_mismatch';
            }
            foreach (['freight', 'insurance', 'discount', 'other', 'st_base', 'st', 'fcp_st', 'fcp_st_retained', 'ii', 'ipi', 'ipi_returned'] as $amountField) {
                if ($this->cents((string) ($source['totals'][$amountField] ?? '')) !== 0) {
                    $errors[] = 'totals.'.$amountField;
                }
            }
            if (in_array((int) ($identity['purpose'] ?? 0), [2, 4], true)) {
                $references = $source['references'] ?? [];
                if (! is_array($references) || $references === []) {
                    $errors[] = 'references';
                }
                if ((int) ($identity['purpose'] ?? 0) === 2 && count($references) !== 1) {
                    $errors[] = 'references.complement_requires_exactly_one';
                }
                foreach ($references as $index => $reference) {
                    $this->assertScalars($reference, array_keys($reference));
                    $referenceKey = (string) ($reference['access_key'] ?? '');
                    if (! app(ErpFiscalPayloadBuilder::class)->validatesAccessKey($referenceKey)
                        || ! in_array(substr($referenceKey, 20, 2), ['55', '65'], true)
                        || substr($referenceKey, 6, 14) === $cnpj && $referenceKey === $key) {
                        $errors[] = 'references.'.$index.'.access_key';
                    }
                }
            }
            if ($model === '65' && ((int) ($identity['purpose'] ?? 0) !== 1 || (int) ($identity['final_consumer'] ?? 0) !== 1)) {
                $errors[] = 'identity.consumer_sale_only';
            }
            if ($model === '65') {
                $qr = $source['nfce_qr'] ?? [];
                if (! is_array($qr)) {
                    throw new InvalidArgumentException('Configuração QR NFC-e inválida.');
                }
                $this->assertScalars($qr, array_keys($qr));
                if (($qr['version'] ?? null) !== 3 || ! in_array($qr['mode'] ?? null, ['online', 'offline'], true) || empty($qr['consultation_url'])) {
                    $errors[] = 'nfce_qr.version3_configuration';
                }
                if (! is_array($qr) || ! filter_var($qr['consultation_url'] ?? null, FILTER_VALIDATE_URL) || ! in_array(parse_url($qr['consultation_url'] ?? '', PHP_URL_SCHEME), ['http', 'https'], true)) {
                    $errors[] = 'nfce_qr.url';
                }
                if (($qr['mode'] ?? null) === 'offline') {
                    if (substr($key, 34, 1) !== '9' || strtotime((string) ($identity['contingency_started_at'] ?? '')) === false || mb_strlen((string) ($identity['contingency_justification'] ?? '')) < 15 || mb_strlen((string) ($identity['contingency_justification'] ?? '')) > 256) {
                        $errors[] = 'nfce_qr.offline_contingency_facts';
                    }
                    foreach (['contingency_started_at', 'contingency_justification'] as $field) {
                        if (! isset($identity[$field]) || $identity[$field] === '') {
                            $errors[] = 'identity.'.$field;
                        }
                    }
                    if ($this->cents((string) ($source['totals']['invoice'] ?? '')) < 0) {
                        $errors[] = 'nfce_qr.invoice_total';
                    }
                }
            }
        } elseif ($model === '57') {
            $service = $source['transport_service'] ?? [];
            foreach (['sender', 'receiver', 'taker', 'route', 'tax', 'cargo'] as $field) {
                if (! is_array($service[$field] ?? null)) {
                    throw new InvalidArgumentException('Grupo de transporte inválido: '.$field.'.');
                }
            }
            foreach (['sender', 'receiver'] as $field) {
                $this->requireAddress($service[$field]['address'] ?? null, 'transport_service.'.$field.'.address', $errors);
            }
            $this->assertScalars($service, ['service_type', 'cte_type', 'emission_type', 'operation_nature', 'cfop', 'service_value', 'received_value', 'modal', 'rntrc']);
            $this->assertScalars($service['tax'], array_keys($service['tax']));
            foreach (['sender', 'receiver', 'taker', 'route', 'cargo'] as $field) {
                $this->assertScalars($service[$field], array_diff(array_keys($service[$field]), ['address']));
            }
            foreach (['sender', 'receiver'] as $role) {
                $this->requireParty($service[$role], 'transport_service.'.$role, $errors);
            }
            $this->requireEnum($service['taker'], 'role', [0, 1, 2, 3, 4], 'transport_service.taker', $errors);
            if ((int) ($service['taker']['role'] ?? -1) === 4) {
                $this->requireParty($service['taker'], 'transport_service.taker', $errors);
            }
            if (array_key_exists('state_registration_indicator', $service['taker'])) {
                $this->requireEnum($service['taker'], 'state_registration_indicator', [1, 2, 9], 'transport_service.taker', $errors);
            }
            $this->requireEnum($service, 'service_type', [0, 1, 2, 3, 4], 'transport_service', $errors);
            $this->requireText($service, 'operation_nature', 1, 60, 'transport_service', $errors);
            $this->requirePattern($service, 'cfop', '/^[567]\d{3}$/', 'transport_service', $errors);
            foreach (['origin_city_code', 'destination_city_code'] as $field) {
                $this->requirePattern($service['route'], $field, '/^\d{7}$/', 'transport_service.route', $errors);
            }
            foreach (['origin_city', 'destination_city'] as $field) {
                $this->requireText($service['route'], $field, 2, 60, 'transport_service.route', $errors);
            }
            foreach (['origin_uf', 'destination_uf'] as $field) {
                if (BrazilianStateCode::ibgeFromUf((string) ($service['route'][$field] ?? '')) === null) {
                    $errors[] = 'transport_service.route.'.$field;
                }
            }
            $this->requireEnum($service['route'], 'pickup', [0, 1], 'transport_service.route', $errors);
            $this->requireText($service['cargo'], 'description', 1, 60, 'transport_service.cargo', $errors);
            if (isset($service['cargo']['measure_type'])) {
                $this->requireText($service['cargo'], 'measure_type', 1, 20, 'transport_service.cargo', $errors);
            } elseif (mb_strlen((string) ($service['cargo']['description'] ?? '')) > 20) {
                $errors[] = 'transport_service.cargo.measure_type';
            }
            $this->requireEnum($service['cargo'], 'unit', ['00', '01', '02', '03', '04', '05'], 'transport_service.cargo', $errors);
            $this->requireDecimal($service['cargo'], 'quantity', 11, 4, 'transport_service.cargo', $errors);
            $this->requireDecimal($service['cargo'], 'value', 13, 2, 'transport_service.cargo', $errors);
            foreach (['service_value', 'received_value'] as $field) {
                $this->requireDecimal($service, $field, 13, 2, 'transport_service', $errors);
            }
            $this->requireDecimal($service['tax'], 'rate', 3, 2, 'transport_service.tax', $errors);
            if (is_scalar($service['tax']['rate'] ?? null) && is_numeric($service['tax']['rate']) && (float) $service['tax']['rate'] > 100) {
                $errors[] = 'transport_service.tax.rate';
            }
            foreach (['base', 'rate', 'value'] as $field) {
                if ($this->cents((string) ($service['tax'][$field] ?? '')) < 0) {
                    $errors[] = 'transport_service.tax.'.$field;
                }
            }
            if (($service['tax']['cst'] ?? null) !== '00' || (int) ($service['emission_type'] ?? 0) !== 1 || $this->cents((string) ($service['service_value'] ?? '')) <= 0 || $this->cents((string) ($service['received_value'] ?? '')) < 0) {
                $errors[] = 'transport_service.unsupported_tax_or_values';
            }
            foreach (['components', 'documents'] as $field) {
                if (isset($service[$field]) && (! is_array($service[$field]) || ! array_is_list($service[$field]))) {
                    throw new InvalidArgumentException('Lista de transporte inválida: '.$field.'.');
                }
                foreach ($service[$field] ?? [] as $row) {
                    if (! is_array($row)) {
                        throw new InvalidArgumentException('Item de transporte inválido.');
                    }
                    $this->assertScalars($row, array_keys($row));
                    if ($field === 'components') {
                        $this->requireText($row, 'name', 1, 15, 'transport_service.components', $errors);
                        $this->requireDecimal($row, 'value', 13, 2, 'transport_service.components', $errors);
                    } elseif (($row['type'] ?? null) !== 'nfe' || ! app(ErpFiscalPayloadBuilder::class)->validatesAccessKey((string) ($row['access_key'] ?? '')) || substr((string) ($row['access_key'] ?? ''), 20, 2) !== '55') {
                        $errors[] = 'transport_service.documents';
                    }
                }
            }
            foreach (['service_type', 'cte_type', 'emission_type', 'operation_nature', 'cfop', 'service_value', 'received_value'] as $field) {
                if (! array_key_exists($field, $service) || $service[$field] === '') {
                    $errors[] = 'transport_service.'.$field;
                }
            }
            if (! in_array((int) ($service['cte_type'] ?? -1), [0, 1, 3], true)) {
                $errors[] = 'transport_service.cte_type';
            }
            if ((string) ($service['modal'] ?? '') !== '01') {
                $errors[] = 'transport_service.modal.unsupported';
            }
            if (! preg_match('/^\d{8}$/', (string) ($service['rntrc'] ?? ''))) {
                $errors[] = 'transport_service.rntrc';
            }
            if (in_array((int) ($service['cte_type'] ?? 0), [1, 3], true)) {
                $originalKey = (string) ($service['original_cte_access_key'] ?? '');
                if (! app(ErpFiscalPayloadBuilder::class)->validatesAccessKey($originalKey) || substr($originalKey, 20, 2) !== '57') {
                    $errors[] = 'transport_service.original_cte_access_key';
                }
            }
            foreach (['sender', 'receiver', 'taker', 'route', 'tax', 'cargo'] as $field) {
                if (empty($service[$field]) || ! is_array($service[$field])) {
                    $errors[] = 'transport_service.'.$field;
                }
            }
        } elseif ($model === '58') {
            $manifest = $source['transport_manifest'] ?? [];
            foreach (['vehicle', 'driver', 'cargo', 'route_ufs', 'municipalities', 'documents'] as $field) {
                if (! is_array($manifest[$field] ?? null)) {
                    throw new InvalidArgumentException('Grupo MDF-e inválido: '.$field.'.');
                }
            }
            foreach (['municipalities', 'documents'] as $field) {
                foreach ($manifest[$field] as $row) {
                    if (! is_array($row)) {
                        throw new InvalidArgumentException('Item MDF-e inválido: '.$field.'.');
                    }
                    $this->assertScalars($row, array_keys($row));
                }
            }
            $this->assertScalars($manifest, ['emission_type', 'modal', 'issuer_type', 'rntrc', 'total_cte', 'total_nfe', 'total_mdfe']);
            foreach (['vehicle', 'driver', 'cargo'] as $field) {
                $this->assertScalars($manifest[$field], array_diff(array_keys($manifest[$field]), ['owner']));
            }
            $this->requirePattern($manifest, 'rntrc', '/^\d{8}$/', 'transport_manifest', $errors);
            $this->requirePattern($manifest['vehicle'], 'plate', '/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', 'transport_manifest.vehicle', $errors);
            $this->requirePattern($manifest['vehicle'], 'renavam', '/^\d{9,11}$/', 'transport_manifest.vehicle', $errors);
            $this->requireText($manifest['vehicle'], 'registry', 1, 10, 'transport_manifest.vehicle', $errors);
            foreach (['tare', 'capacity_kg'] as $field) {
                $this->requirePattern($manifest['vehicle'], $field, '/^(?:0|[1-9]\d{0,5})$/', 'transport_manifest.vehicle', $errors);
            }
            $this->requireEnum($manifest['vehicle'], 'road_type', ['01', '02', '03', '04', '05', '06'], 'transport_manifest.vehicle', $errors);
            $this->requireEnum($manifest['vehicle'], 'trailer_type', ['00', '01', '02', '03', '04', '05'], 'transport_manifest.vehicle', $errors);
            $this->requireText($manifest['driver'], 'name', 2, 60, 'transport_manifest.driver', $errors);
            $this->requirePattern($manifest['driver'], 'cpf', '/^\d{11}$/', 'transport_manifest.driver', $errors);
            $this->requireText($manifest['cargo'], 'product_description', 1, 120, 'transport_manifest.cargo', $errors);
            $this->requireEnum($manifest['cargo'], 'product_type', ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'], 'transport_manifest.cargo', $errors);
            $this->requirePattern($manifest['cargo'], 'ncm', '/^(?:\d{2}|\d{8})$/', 'transport_manifest.cargo', $errors);
            $this->requireDecimal($manifest['cargo'], 'total_value', 13, 2, 'transport_manifest.cargo', $errors);
            $this->requireDecimal($manifest['cargo'], 'quantity', 11, 4, 'transport_manifest.cargo', $errors);
            foreach ([...$manifest['route_ufs'], $manifest['vehicle']['uf'] ?? null] as $uf) {
                if (! is_string($uf) || BrazilianStateCode::ibgeFromUf($uf) === null) {
                    $errors[] = 'transport_manifest.uf';
                }
            }
            foreach ($manifest['municipalities'] as $municipality) {
                $this->requireText($municipality, 'name', 2, 60, 'transport_manifest.municipalities', $errors);
                $this->requirePattern($municipality, 'code', '/^\d{7}$/', 'transport_manifest.municipalities', $errors);
            }
            if (isset($manifest['trip_starts_at']) && (! is_string($manifest['trip_starts_at']) || strtotime($manifest['trip_starts_at']) === false)) {
                $errors[] = 'transport_manifest.trip_starts_at';
            }
            foreach ($manifest['documents'] as $document) {
                $this->requirePattern($document, 'municipality_code', '/^\d{7}$/', 'transport_manifest.documents', $errors);
                $this->requireEnum($document, 'model', ['55', '65', '57'], 'transport_manifest.documents', $errors);
                if (! in_array($document['municipality_code'] ?? null, array_column($manifest['municipalities'], 'code'), true) || substr((string) ($document['access_key'] ?? ''), 20, 2) !== ($document['model'] ?? null)) {
                    $errors[] = 'transport_manifest.documents.identity';
                }
            }
            foreach (['total_cte', 'total_nfe', 'total_mdfe'] as $field) {
                if (! isset($manifest[$field]) || ! is_int($manifest[$field]) || $manifest[$field] < 0) {
                    $errors[] = 'transport_manifest.'.$field;
                }
            }
            if ((int) ($manifest['issuer_type'] ?? 0) !== 2 || (int) ($manifest['emission_type'] ?? 0) !== 1 || isset($manifest['vehicle']['owner']) || ! empty($manifest['insurance']) || ! empty($manifest['ciot'])) {
                $errors[] = 'transport_manifest.unsupported_owner_insurance_or_issuer';
            }
            foreach (['emission_type', 'modal', 'route_ufs', 'vehicle', 'driver', 'documents', 'cargo'] as $field) {
                if (empty($manifest[$field])) {
                    $errors[] = 'transport_manifest.'.$field;
                }
            }
            if (count($manifest['route_ufs'] ?? []) < 2 || count($manifest['documents'] ?? []) < 1) {
                $errors[] = 'transport_manifest.route_and_documents';
            }
            if ((string) ($manifest['modal'] ?? '') !== '1') {
                $errors[] = 'transport_manifest.modal.unsupported';
            }
            if (! in_array((string) ($manifest['cargo']['unit'] ?? ''), ['01', '02'], true)) {
                $errors[] = 'transport_manifest.cargo.unit';
            }
            foreach ($manifest['documents'] ?? [] as $index => $document) {
                $this->assertScalars($document, array_keys($document));
                $docKey = (string) ($document['access_key'] ?? '');
                if (! app(ErpFiscalPayloadBuilder::class)->validatesAccessKey($docKey)
                    || ! in_array(substr($docKey, 20, 2), ['55', '65', '57'], true)
                    || substr($docKey, 6, 14) === $cnpj && $docKey === $key) {
                    $errors[] = 'transport_manifest.documents.'.$index.'.access_key';
                }
            }
        } else {
            $errors[] = 'identity.model';
        }

        if ($errors !== []) {
            throw new InvalidArgumentException('Dados fiscais obrigatórios ausentes ou inválidos: '.implode(', ', array_values(array_unique($errors))).'.');
        }
    }

    /** @param array<string, mixed>|null $address @param list<string> $errors */
    private function requireAddress(mixed $address, string $prefix, array &$errors): void
    {
        if (! is_array($address)) {
            $errors[] = $prefix;

            return;
        }
        foreach (['street', 'number', 'district', 'city_code', 'city', 'uf', 'postal_code'] as $field) {
            if (! isset($address[$field]) || ! is_scalar($address[$field]) || $address[$field] === '') {
                $errors[] = $prefix.'.'.$field;
            }
        }
        foreach (['street' => 60, 'number' => 60, 'district' => 60, 'city' => 60] as $field => $maximum) {
            $this->requireText($address, $field, 1, $maximum, $prefix, $errors);
        }
        $this->requirePattern($address, 'city_code', '/^\d{7}$/', $prefix, $errors);
        $this->requirePattern($address, 'postal_code', '/^\d{8}$/', $prefix, $errors);
        if (isset($address['uf']) && is_scalar($address['uf']) && BrazilianStateCode::ibgeFromUf((string) $address['uf']) === null) {
            $errors[] = $prefix.'.uf';
        }
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function sortKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortKeys($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function requireParty(array $party, string $prefix, array &$errors): void
    {
        $this->requirePattern($party, 'cnpj', '/^(?:\d{11}|\d{14})$/', $prefix, $errors);
        $this->requireText($party, 'legal_name', 2, 60, $prefix, $errors);
        $this->requireAddress($party['address'] ?? null, $prefix.'.address', $errors);
    }

    private function requireText(array $values, string $field, int $minimum, int $maximum, string $prefix, array &$errors): void
    {
        $value = $values[$field] ?? null;
        if (! is_string($value) || mb_strlen(trim($value)) < $minimum || mb_strlen($value) > $maximum) {
            $errors[] = $prefix.'.'.$field;
        }
    }

    private function requirePattern(array $values, string $field, string $pattern, string $prefix, array &$errors): void
    {
        $value = $values[$field] ?? null;
        if (! is_string($value) && ! is_int($value) || ! preg_match($pattern, (string) $value)) {
            $errors[] = $prefix.'.'.$field;
        }
    }

    private function requireEnum(array $values, string $field, array $allowed, string $prefix, array &$errors): void
    {
        if (! in_array($values[$field] ?? null, $allowed, true)) {
            $errors[] = $prefix.'.'.$field;
        }
    }

    private function requireDecimal(array $values, string $field, int $wholeDigits, int $fractionDigits, string $prefix, array &$errors): void
    {
        $this->requirePattern($values, $field, '/^\d{1,'.$wholeDigits.'}(?:\.\d{1,'.$fractionDigits.'})?$/', $prefix, $errors);
    }

    private function assertScalars(array $values, array $fields): void
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $values) && (! is_scalar($values[$field]) || is_string($values[$field]) && trim($values[$field]) === '')) {
                throw new InvalidArgumentException('Campo fiscal escalar inválido: '.$field.'.');
            }
        }
    }

    private function validCnpj(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }
        $digits = array_map('intval', str_split($cnpj));
        foreach ([12, 13] as $position) {
            $weights = $position === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $sum = 0;
            foreach ($weights as $index => $weight) {
                $sum += $digits[$index] * $weight;
            }
            $remainder = $sum % 11;
            if ($digits[$position] !== ($remainder < 2 ? 0 : 11 - $remainder)) {
                return false;
            }
        }

        return true;
    }

    private function cents(string $value): int
    {
        if (! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $value)) {
            return -1;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
