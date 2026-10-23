<?php

namespace App\Erp;

use App\Models\Erp\ErpCatalogImport;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ErpCatalogImportOperation
{
    public const PRODUCTS = 'erp.catalog.import.products.confirm';

    public const CONTACTS = 'erp.catalog.import.contacts.confirm';

    public function __construct(private ErpCatalogImportService $imports) {}

    public function canonical(Request $request, string $operation): array
    {
        $kind = $this->kind($operation);
        $allowed = $kind === 'products' ? ['token', 'erp_unit_id', 'source_operation_key'] : ['token', 'source_operation_key'];
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['token' => 'Opções de confirmação não suportadas.']);
        }
        $unit = null;
        if ($kind === 'products') {
            $id = $request->input('erp_unit_id');
            if (! is_int($id) && ! is_string($id)) {
                throw ValidationException::withMessages(['erp_unit_id' => 'Informe um identificador inteiro.']);
            }
            $validated = Validator::make(['erp_unit_id' => $id], ['erp_unit_id' => ['required', 'integer', 'min:1', 'max:9007199254740991']])->validate();
            $unit = (int) $validated['erp_unit_id'];
        }
        $preview = $this->imports->find($request, $kind, lock: true);

        return ['preview_id' => $preview->id, 'preview_token_digest' => $preview->token_digest, 'kind' => $kind, 'schema_version' => $preview->schema_version, 'content_digest' => $preview->content_digest, 'erp_unit_id' => $unit];
    }

    public function assertAuthorized(Request $request, string $operation, array $references): ErpCatalogImport
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertPermission($operation, true);

        return ErpCatalogImport::query()->where('erp_company_id', $context->company->id)->where('actor_user_id', $request->user()->id)->where('kind', $this->kind($operation))->findOrFail($references['preview_id']);
    }

    public function post(Request $request, string $operation, array $payload, string $keyDigest, string $hash): array
    {
        $preview = $this->assertAuthorized($request, $operation, $payload);
        $result = $this->imports->complete($request, $preview, $payload, $keyDigest, $hash);

        return ['status' => 200, 'body' => ['data' => $result], 'resource_refs' => ['preview_id' => $preview->id, 'kind' => $preview->kind]];
    }

    private function kind(string $operation): string
    {
        return match ($operation) {
            self::PRODUCTS => 'products', self::CONTACTS => 'contacts'
        };
    }
}
