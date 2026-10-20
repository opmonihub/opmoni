<?php

namespace App\Erp\Stock;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\Sales\ErpCommercialDraftService as Decimal;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\Stock\ErpPurchaseImport;
use App\Support\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ErpPurchaseImportService
{
    public function preview(Request $request): array
    {
        $context = CurrentCompany::fromRequest($request);
        $id = Decimal::id($request->input('erp_establishment_id'), 'erp_establishment_id');
        app(ErpEstablishmentAccess::class)->assertRelated($context, $id);
        app(ErpCatalogScope::class)->assertActiveEstablishment($context->company->id, $id);
        $xml = $request->validate(['xml' => ['required', 'string']])['xml'];
        $establishment = ErpEstablishment::where('erp_company_id', $context->company->id)->findOrFail($id);
        try {
            $source = app(ErpPurchaseXmlPreviewService::class)->parse($xml, preg_replace('/\D+/', '', (string) $establishment->cnpj));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['xml' => $exception->getMessage()]);
        }
        $identityErrors = array_filter($source['errors'], fn (array $error): bool => in_array($error['field'], ['chave', 'emit.CNPJ', 'dest.CNPJ'], true) || str_starts_with($error['field'], 'ide.'));
        if ($identityErrors !== []) {
            $messages = [];
            foreach ($identityErrors as $error) {
                $messages[$error['field']][] = $error['message'];
            }
            throw ValidationException::withMessages($messages);
        }
        $duplicate = app(ErpPurchaseXmlPreviewService::class)->duplicate($context->company->id, $source['chave']);
        if ($duplicate) {
            $source['errors'][] = ['field' => 'chave', 'code' => 'duplicate_source', 'message' => 'XML já importado para a empresa (chave duplicada).'];
        }
        $token = (string) Str::uuid();
        $preview = ErpPurchaseImport::create(['erp_company_id' => $context->company->id, 'actor_user_id' => $request->user()->id, 'erp_establishment_id' => $id, 'token_digest' => hash('sha256', $token), 'schema_version' => ErpPurchaseXmlPreviewService::VERSION, 'source_sha256' => hash('sha256', $xml), 'source_byte_length' => strlen($xml), 'source_access_key' => $source['chave'], 'source_snapshot' => $source, 'errors' => $source['errors'], 'expires_at' => now()->addHour()]);

        return $this->serialize($request, $preview, $token);
    }

    public function find(Request $request, bool $lock = false): ErpPurchaseImport
    {
        $token = $request->validate(['token' => ['required', 'uuid']])['token'];
        $context = CurrentCompany::fromRequest($request);
        $query = ErpPurchaseImport::where('erp_company_id', $context->company->id)->where('actor_user_id', $request->user()->id)->where('token_digest', hash('sha256', $token));
        $preview = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        $establishment = ErpEstablishment::where('erp_company_id', $context->company->id)->findOrFail($preview->erp_establishment_id);
        app(ErpEstablishmentAccess::class)->assertResource($context, $establishment);
        if ($preview->erp_purchase_id) {
            app(ErpPurchaseDraftService::class)->resource($request, $preview->erp_purchase_id);
        }

        return $preview;
    }

    public function serialize(Request $request, ErpPurchaseImport $preview, string $token): array
    {
        $source = $preview->source_snapshot;
        $duplicate = app(ErpPurchaseXmlPreviewService::class)->duplicate($preview->erp_company_id, $preview->source_access_key);
        $allowed = app(ErpEstablishmentAccess::class)->allowedIds(CurrentCompany::fromRequest($request));
        $duplicateId = $duplicate && ($allowed === null || in_array($duplicate->erp_establishment_id, $allowed, true)) ? $duplicate->id : null;

        return array_intersect_key($source, array_flip(['chave', 'emit', 'dest', 'det', 'total', 'duplicatas'])) + ['token' => $token, 'errors' => $preview->errors, 'duplicate_purchase_id' => $duplicateId, 'duplicate' => $duplicate !== null, 'source_sha256' => $preview->source_sha256, 'source_byte_length' => $preview->source_byte_length, 'expires_at' => $preview->expires_at->toISOString(), 'state' => $preview->completed_at ? 'completed' : ($preview->errors !== [] ? 'blocked' : ($preview->expires_at->isPast() ? 'expired' : 'pending')), 'result' => $preview->original_result];
    }

    public function complete(Request $request, array $body, string $keyDigest, string $payloadHash): array
    {
        $preview = $this->find($request, true);
        if ($preview->completed_at) {
            abort_unless(hash_equals($preview->completed_key_digest, $keyDigest) && hash_equals($preview->completed_payload_hash, $payloadHash), 409, 'Prévia já confirmada com outra identidade.');

            return $preview->original_result;
        }
        if ($preview->expires_at->isPast() || $preview->errors !== []) {
            throw ValidationException::withMessages(['token' => $preview->errors !== [] ? 'Corrija os erros da origem com nova prévia.' : 'Prévia expirada; envie novamente o XML.']);
        }
        if ($body['erp_establishment_id'] !== $preview->erp_establishment_id) {
            throw ValidationException::withMessages(['erp_establishment_id' => 'Use o estabelecimento selecionado na prévia.']);
        }
        app(ErpCatalogScope::class)->assertActiveEstablishment($preview->erp_company_id, $preview->erp_establishment_id);
        $establishment = ErpEstablishment::findOrFail($preview->erp_establishment_id);
        $source = $preview->source_snapshot;
        if (preg_replace('/\D+/', '', (string) $establishment->cnpj) !== preg_replace('/\D+/', '', (string) $source['dest']['cpf_cnpj'])) {
            throw ValidationException::withMessages(['erp_establishment_id' => 'CNPJ do estabelecimento diverge do destino original.']);
        }
        abort_if(app(ErpPurchaseXmlPreviewService::class)->duplicate($preview->erp_company_id, $preview->source_access_key), response()->json(['code' => 'duplicate_purchase_source'], 409));
        $items = $body['items'];
        if (count($items) !== count($source['det'])) {
            throw ValidationException::withMessages(['items' => 'Mapeie exatamente todos os itens da origem.']);
        }
        $mapping = [];
        foreach ($items as $index => $item) {
            if (isset($mapping[$item['line']]) || ! isset($source['det'][$item['line'] - 1])) {
                throw ValidationException::withMessages(['items.'.$index.'.line' => 'Item inválido ou duplicado.']);
            }
            $mapping[$item['line']] = $item;
        }
        $lines = [];
        foreach ($source['det'] as $item) {
            if (! isset($mapping[$item['line']])) {
                throw ValidationException::withMessages(['items' => 'Mapeamento incompleto.']);
            }
            $choice = $mapping[$item['line']];
            unset($choice['line']);
            $lines[] = $choice + ['description' => $item['xProd'], 'quantity' => $item['quantity'], 'unit_cost' => $item['unit_cost']];
        }
        try {
            $purchase = app(ErpPurchaseDraftService::class)->save($request, ['erp_establishment_id' => $preview->erp_establishment_id, 'erp_contact_id' => $body['erp_contact_id'], 'lines' => $lines], import: ['source_import_id' => $preview->id, 'source_access_key' => $preview->source_access_key, 'source_sha256' => $preview->source_sha256, 'source_byte_length' => $preview->source_byte_length, 'source_snapshot' => $source]);
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'erp_purchase_company_source_key_unique')) {
                abort(409, 'Chave de NF-e já confirmada para esta empresa.');
            }

            throw $exception;
        }
        $result = app(ErpPurchaseDraftService::class)->serialize($purchase, false);
        $preview->fill(['completed_at' => now(), 'completed_key_digest' => $keyDigest, 'completed_payload_hash' => $payloadHash, 'erp_purchase_id' => $purchase->id, 'original_result' => $result])->save();

        return $result;
    }
}
