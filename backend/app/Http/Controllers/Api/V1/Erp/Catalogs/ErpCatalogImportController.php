<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Http\Controllers\Controller;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpUnit;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ErpCatalogImportController extends Controller
{
    private const MAX_ROWS = 500;

    public function previewProducts(Request $request): JsonResponse
    {
        CurrentCompany::fromRequest($request)->assertCanMutate();
        $rows = $this->parseCsv($request);
        $companyId = CurrentCompany::fromRequest($request)->company->id;
        $preview = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $errors[] = ['line' => $line, 'message' => 'Nome obrigatório'];

                continue;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku !== '' && ErpProduct::query()->where('erp_company_id', $companyId)->where('sku', $sku)->exists()) {
                $errors[] = ['line' => $line, 'message' => 'SKU duplicado'];
            }
            $preview[] = ['line' => $line, 'name' => $name, 'sku' => $sku ?: null, 'sale_price' => $row['sale_price'] ?? '0'];
        }
        $token = (string) Str::uuid();
        Cache::put($this->cacheKey($token), ['kind' => 'products', 'company_id' => $companyId, 'rows' => $preview], now()->addHour());

        return response()->json(['data' => ['token' => $token, 'rows' => $preview, 'errors' => $errors]]);
    }

    public function confirmProducts(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $payload = $request->validate([
            'token' => ['required', 'uuid'],
            'erp_unit_id' => ['required', 'integer'],
        ]);
        $cached = Cache::pull($this->cacheKey($payload['token']));
        if (! is_array($cached) || ($cached['kind'] ?? '') !== 'products' || (int) ($cached['company_id'] ?? 0) !== $context->company->id) {
            throw ValidationException::withMessages(['token' => 'Pré-visualização expirada ou inválida']);
        }
        $unit = ErpUnit::query()->where('erp_company_id', $context->company->id)->whereKey($payload['erp_unit_id'])->first();
        if (! $unit) {
            throw ValidationException::withMessages(['erp_unit_id' => 'Unidade inválida']);
        }
        $created = 0;
        foreach ($cached['rows'] as $row) {
            ErpProduct::query()->create([
                'erp_company_id' => $context->company->id,
                'name' => $row['name'],
                'sku' => $row['sku'],
                'kind' => 'goods',
                'track_stock' => true,
                'erp_unit_id' => $unit->id,
                'sale_price' => $row['sale_price'] ?? '0',
                'status' => 'active',
            ]);
            $created++;
        }

        return response()->json(['data' => ['created' => $created]]);
    }

    public function previewContacts(Request $request): JsonResponse
    {
        CurrentCompany::fromRequest($request)->assertCanMutate();
        $rows = $this->parseCsv($request);
        $companyId = CurrentCompany::fromRequest($request)->company->id;
        $preview = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $errors[] = ['line' => $line, 'message' => 'Nome obrigatório'];

                continue;
            }
            $taxId = trim((string) ($row['tax_id'] ?? ''));
            if ($taxId !== '' && ErpContact::query()->where('erp_company_id', $companyId)->where('tax_id', $taxId)->exists()) {
                $errors[] = ['line' => $line, 'message' => 'Documento duplicado'];
            }
            $preview[] = ['line' => $line, 'name' => $name, 'tax_id' => $taxId ?: null, 'is_customer' => true];
        }
        $token = (string) Str::uuid();
        Cache::put($this->cacheKey($token), ['kind' => 'contacts', 'company_id' => $companyId, 'rows' => $preview], now()->addHour());

        return response()->json(['data' => ['token' => $token, 'rows' => $preview, 'errors' => $errors]]);
    }

    public function confirmContacts(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $payload = $request->validate(['token' => ['required', 'uuid']]);
        $cached = Cache::pull($this->cacheKey($payload['token']));
        if (! is_array($cached) || ($cached['kind'] ?? '') !== 'contacts' || (int) ($cached['company_id'] ?? 0) !== $context->company->id) {
            throw ValidationException::withMessages(['token' => 'Pré-visualização expirada ou inválida']);
        }
        $created = 0;
        foreach ($cached['rows'] as $row) {
            ErpContact::query()->create([
                'erp_company_id' => $context->company->id,
                'name' => $row['name'],
                'tax_id' => $row['tax_id'],
                'is_customer' => true,
                'is_supplier' => false,
                'status' => 'active',
            ]);
            $created++;
        }

        return response()->json(['data' => ['created' => $created]]);
    }

    /** @return list<array<string, string>> */
    private function parseCsv(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'max:512']]);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Arquivo inválido']);
        }
        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'Cabeçalho ausente']);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === false) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($data[$i]) ? trim((string) $data[$i]) : '';
            }
            $rows[] = $row;
            if (count($rows) > self::MAX_ROWS) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => 'Limite de '.self::MAX_ROWS.' linhas excedido']);
            }
        }
        fclose($handle);

        return $rows;
    }

    private function cacheKey(string $token): string
    {
        return 'erp_catalog_import:'.$token;
    }
}
