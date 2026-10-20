<?php

namespace App\Erp;

use App\Models\Erp\ErpCatalogImport;
use App\Models\Erp\ErpContact;
use App\Services\BrazilianTaxId;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ErpCatalogImportService
{
    public const PRODUCT_HEADERS = ['name', 'sku', 'kind', 'track_stock', 'sale_price', 'erp_brand_id', 'erp_category_id', 'barcode', 'barcode_type', 'warranty_months', 'warranty_description', 'ncm', 'cest', 'origin_code', 'icms_cst', 'icms_csosn', 'pis_cst', 'cofins_cst', 'ipi_cst', 'ibs_cbs_cst', 'ibs_cbs_classification_code', 'icms_rate', 'pis_rate', 'cofins_rate', 'ipi_rate', 'simple_national_credit_rate'];

    public const CONTACT_HEADERS = ['name', 'trade_name', 'tax_id', 'email', 'phone', 'is_customer', 'is_supplier', 'erp_customer_group_id', 'address_street_type', 'address_street', 'address_number', 'address_complement', 'address_district', 'address_postal_code', 'address_city', 'address_state'];

    public function __construct(private ErpCatalogCsvParser $parser, private ErpCatalogWriteService $writes, private BrazilianTaxId $taxId) {}

    public function preview(Request $request, string $kind): array
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $request->validate(['file' => ['required', 'file', 'max:512']]);
        $content = file_get_contents($request->file('file')->getRealPath());
        if (! is_string($content)) {
            throw ValidationException::withMessages(['file' => 'Arquivo indisponível.']);
        }
        $parsed = $this->parser->parse($content, $kind === 'products' ? self::PRODUCT_HEADERS : self::CONTACT_HEADERS);
        $rows = $errors = [];
        foreach ($parsed as $record) {
            $payload = [];
            try {
                $payload = $this->map($record['values'], $kind);
                $payload = $kind === 'products' ? $this->writes->validateImportProduct($context->company->id, $payload) : $this->writes->validateImportContact($context->company->id, $payload);
            } catch (ValidationException $failure) {
                foreach ($failure->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $errors[] = $this->error($record, $field, $message);
                    }
                }
            }
            $rows[] = ['record' => $record['record'], 'line' => $record['line'], ...($payload === [] ? $record['values'] : $payload)];
        }
        $errors = [...$errors, ...$this->duplicateErrors($rows, $kind, $context->company->id)];
        $invalid = count(array_unique(array_column($errors, 'record')));
        $token = (string) Str::uuid();
        $preview = ErpCatalogImport::query()->create([
            'erp_company_id' => $context->company->id, 'actor_user_id' => $request->user()->id,
            'kind' => $kind, 'schema_version' => $kind.'-v1', 'token_digest' => hash('sha256', $token),
            'content_digest' => hash('sha256', $content), 'rows' => $rows, 'errors' => $errors,
            'totals' => ['records' => count($rows), 'valid' => count($rows) - $invalid, 'invalid' => $invalid], 'expires_at' => now()->addHour(),
        ]);

        return $this->view($preview, $token);
    }

    public function find(Request $request, string $kind, bool $lock = false): ErpCatalogImport
    {
        $data = $request->validate(['token' => ['required', 'string', 'uuid']]);
        $query = ErpCatalogImport::query()->where('erp_company_id', CurrentCompany::fromRequest($request)->company->id)->where('actor_user_id', $request->user()->id)->where('kind', $kind)->where('token_digest', hash('sha256', $data['token']));
        if ($lock) {
            $query->lockForUpdate();
        }
        $preview = $query->first();
        if (! $preview) {
            throw ValidationException::withMessages(['token' => 'Pré-visualização indisponível.']);
        }

        return $preview;
    }

    public function read(Request $request, string $kind): array
    {
        $preview = $this->find($request, $kind);
        $this->assertPendingAvailable($preview);

        return $this->view($preview, $request->input('token'));
    }

    public function assertPendingAvailable(ErpCatalogImport $preview): void
    {
        if ($preview->completed_at === null && $preview->expires_at->isPast()) {
            throw ValidationException::withMessages(['token' => 'Pré-visualização expirada.']);
        }
    }

    /** @param array<string,mixed> $payload */
    public function complete(Request $request, ErpCatalogImport $preview, array $payload, string $keyDigest, string $hash): array
    {
        if ($preview->completed_at !== null) {
            abort_unless(hash_equals($preview->completed_key_digest, $keyDigest) && hash_equals($preview->completed_payload_hash, $hash), 409, 'Pré-visualização já confirmada com outra operação.');

            return $preview->result;
        }
        $this->assertPendingAvailable($preview);
        if ($preview->errors !== []) {
            throw ValidationException::withMessages(['token' => 'Corrija os erros no CSV e gere outra pré-visualização.']);
        }
        $duplicates = $this->duplicateErrors($preview->rows, $preview->kind, $preview->erp_company_id);
        if ($duplicates !== []) {
            throw ValidationException::withMessages(['rows' => array_column($duplicates, 'message')]);
        }
        foreach ($preview->rows as $row) {
            unset($row['line'], $row['record']);
            if ($preview->kind === 'products') {
                $this->writes->createProduct($preview->erp_company_id, [...$row, 'erp_unit_id' => $payload['erp_unit_id']]);
            } else {
                $this->writes->createContact($preview->erp_company_id, $row);
            }
        }
        $result = ['created' => count($preview->rows)];
        $preview->forceFill(['completed_at' => now(), 'completed_key_digest' => $keyDigest, 'completed_payload_hash' => $hash, 'result' => $result])->save();

        return $result;
    }

    private function view(ErpCatalogImport $preview, string $token): array
    {
        return ['token' => $token, 'kind' => $preview->kind, 'schema' => $preview->schema_version, 'expires_at' => $preview->expires_at->toISOString(), 'state' => $preview->completed_at === null ? 'pending' : 'completed', 'rows' => $preview->completed_at === null ? $preview->rows : [], 'errors' => $preview->completed_at === null ? $preview->errors : [], 'totals' => $preview->totals, 'result' => $preview->result];
    }

    /** @param array<string,string> $row
     * @return array<string,mixed>
     */
    private function map(array $row, string $kind): array
    {
        $data = [];
        foreach ($row as $field => $value) {
            $data[$field] = $value === '' && $field !== 'name' ? null : $value;
        }
        foreach ($kind === 'products' ? ['track_stock' => true] : ['is_customer' => true, 'is_supplier' => false] as $field => $default) {
            $value = $data[$field] ?? null;
            if ($value !== null && ! in_array($value, ['0', '1'], true)) {
                throw ValidationException::withMessages([$field => 'Informe 0 ou 1.']);
            }
            $data[$field] = $value === null ? $default : $value === '1';
        }
        foreach (['erp_brand_id', 'erp_category_id', 'erp_customer_group_id', 'warranty_months'] as $field) {
            if (isset($data[$field])) {
                if (! preg_match('/\A\d+\z/', $data[$field]) || strlen(ltrim($data[$field], '0')) > 15) {
                    throw ValidationException::withMessages([$field => 'Informe um inteiro válido.']);
                }
                $data[$field] = (int) $data[$field];
            }
        }
        if ($kind === 'products') {
            $data['kind'] ??= 'goods';
            $data['sale_price'] ??= '0';
            $data['barcode_type'] ??= 'C128';
            if ($data['kind'] === 'service') {
                $data['track_stock'] = false;
            }
            $data['variants'] = [['name' => 'Padrão', 'sku' => $data['sku'] ?? null, 'sale_price' => $data['sale_price'], 'barcode' => $data['barcode'] ?? null, 'barcode_type' => $data['barcode_type']]];
        } else {
            $address = [];
            foreach (self::CONTACT_HEADERS as $field) {
                if (str_starts_with($field, 'address_')) {
                    if (isset($data[$field])) {
                        $address[substr($field, 8)] = $data[$field];
                    }
                    unset($data[$field]);
                }
            }
            $data['address'] = $address === [] ? null : $address;
        }

        return $data;
    }

    /** @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function duplicateErrors(array $rows, string $kind, int $companyId): array
    {
        $field = $kind === 'products' ? 'sku' : 'tax_id';
        $seen = $errors = $existing = [];
        if ($kind === 'contacts') {
            foreach (ErpContact::query()->where('erp_company_id', $companyId)->whereNotNull('tax_id')->cursor() as $contact) {
                $existing[$this->taxId->normalize($contact->tax_id)] = true;
            }
        }
        foreach ($rows as $row) {
            $value = $row[$field] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }
            if ($kind === 'contacts') {
                $value = $this->taxId->normalize($value);
            }
            $seen[$value][] = $row;
        }
        foreach ($seen as $value => $duplicates) {
            if (count($duplicates) > 1 || isset($existing[$value])) {
                foreach ($duplicates as $row) {
                    $errors[] = $this->error($row, $field, 'Identificador duplicado nesta importação ou empresa.');
                }
            }
        }

        return $errors;
    }

    private function error(array $record, string $field, string $message): array
    {
        return ['record' => $record['record'], 'line' => $record['line'], 'field' => $field, 'code' => 'invalid_field', 'message' => $message];
    }
}
