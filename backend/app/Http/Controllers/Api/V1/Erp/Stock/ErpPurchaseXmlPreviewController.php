<?php

namespace App\Http\Controllers\Api\V1\Erp\Stock;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\LegacyPort\LegacyNfeXmlParser;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ErpPurchaseXmlPreviewController extends ErpOperationsController
{
    public function __construct(private LegacyNfeXmlParser $parser) {}

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();

        $data = $request->validate([
            'erp_establishment_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'xml' => ['required', 'string'],
        ]);
        app(ErpEstablishmentAccess::class)->assertRelated($context, (int) $data['erp_establishment_id']);
        app(ErpCatalogScope::class)->assertActiveEstablishment($context->company->id, (int) $data['erp_establishment_id']);

        try {
            $parsed = $this->parser->parse($data['xml']);
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        $companyCnpj = $this->digitsOnly($context->company->cnpj ?? '');
        $destCnpj = $this->digitsOnly($parsed['dest']['cpf_cnpj'] ?? '');
        if ($companyCnpj === '' || $destCnpj === '' || $destCnpj !== $companyCnpj) {
            abort(response()->json([
                'code' => 'validation_error',
                'message' => 'Destinatário do XML não corresponde ao CNPJ da empresa.',
            ], 422));
        }

        return response()->json([
            'data' => [
                'chave' => $parsed['chave'],
                'emit' => $parsed['emit'],
                'dest' => $parsed['dest'],
                'det' => $parsed['itens'],
                'total' => $parsed['totais'],
                'duplicatas' => $parsed['fatura'],
            ],
        ]);
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
