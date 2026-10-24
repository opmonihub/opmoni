<?php

namespace App\Erp;

use App\Erp\CfopReference\CfopReferenceCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpOperationNatureCfopChoice;
use App\Models\Erp\ErpProduct;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ErpCfopResolver
{
    /**
     * Reviewed goods-sale subset of the pinned reference, independent of prefix classification.
     * MOC I08-10..50 supplies direction/destination checks; NT 2026.002 v1.11 I08-150
     * supplies the separate NFC-e list. Other tax treatment is never inferred here.
     *
     * @var array<string, string>
     */
    private const GOODS_SALES = ['5101' => 'same_uf', '5102' => 'same_uf', '5103' => 'same_uf', '5104' => 'same_uf', '5115' => 'same_uf', '5405' => 'same_uf', '6101' => 'different_uf', '6102' => 'different_uf'];

    private const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public function __construct(private CfopReferenceCatalog $references, private ErpOperationNatureRules $rules) {}

    /**
     * Omitted updates retain the prior explicit choice. Null deliberately clears it.
     * Draft snapshot preservation/review remains the operation caller's responsibility.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>|null  $previousLine
     */
    public function explicitChoice(array $line, ?array $previousLine = null): ?string
    {
        if (! array_key_exists('cfop', $line)) {
            return $previousLine['cfop'] ?? null;
        }
        $data = Validator::make(['line' => $line], ['line.cfop' => ['nullable', 'string', 'regex:/\A[123567][0-9]{3}\z/']])->validate();

        return $data['line']['cfop'] ?? null;
    }

    /** @param array<string, mixed> $operationContext
     * @return array<string, mixed>
     */
    public function resolve(CurrentCompany $context, ErpProduct $product, ?ErpOperationNature $nature, array $operationContext = [], ?string $explicit = null): array
    {
        return DB::transaction(function () use ($context, $product, $nature, $operationContext, $explicit): array {
            ErpCompany::query()->lockForUpdate()->findOrFail($context->company->id);
            if ((int) $product->erp_company_id !== (int) $context->company->id) {
                throw ValidationException::withMessages(['erp_product_id' => 'Produto inválido para esta empresa.']);
            }
            $actual = $this->operationContext($operationContext);
            $nature = $nature === null ? null : $this->rules->nature($context, $nature->id, related: true, lock: true);
            $result = [
                'version' => 'cfop-resolution-v1', 'effective_cfop' => null, 'source' => null,
                'nature_id' => $nature?->id, 'configuration_revision' => $nature?->configuration_revision,
                'default_cfop' => $nature?->cfop, 'choice_id' => null, 'exception_id' => null,
                'reference_version' => null, 'context' => $actual, 'unresolved_fields' => [],
                'applicability' => ['status' => 'unresolved'],
            ];
            if ($actual['erp_establishment_id'] !== null) {
                $establishment = app(ErpEstablishmentAccess::class)->assertRelated($context, $actual['erp_establishment_id'], 'context.erp_establishment_id');
                if ($establishment->status !== 'active') {
                    throw ValidationException::withMessages(['context.erp_establishment_id' => 'Selecione um estabelecimento ativo.']);
                }
                if ($nature?->erp_establishment_id !== null && (int) $nature->erp_establishment_id !== $establishment->id) {
                    throw ValidationException::withMessages(['erp_operation_nature_id' => 'Natureza incompatível com o estabelecimento da operação.']);
                }
            }
            if ($nature === null) {
                $this->rules->product($context, $product->id);
                if ($explicit !== null) {
                    throw ValidationException::withMessages(['line.cfop' => 'Selecione uma natureza ativa que permita este CFOP.']);
                }
                $result['unresolved_fields'] = ['erp_operation_nature_id'];

                return $this->fingerprint($result);
            }
            $this->rules->assertActive($nature);
            if ($actual['movement'] !== null && ($actual['movement'] !== 'out' || $actual['movement'] !== $nature->movement)) {
                throw ValidationException::withMessages(['context.movement' => 'A direção deve corresponder à natureza e à operação de venda.']);
            }
            $choice = null;
            $exception = null;
            if ($explicit !== null) {
                $choice = $this->codeChoice($nature, $explicit);
                $code = $explicit;
                $source = 'explicit';
            } else {
                $exception = $nature->productExceptions()->where('erp_company_id', $context->company->id)->where('erp_product_id', $product->id)->where('status', 'active')->lockForUpdate()->first();
                if ($exception !== null) {
                    $choice = $nature->cfopChoices()->where('erp_company_id', $context->company->id)->whereKey($exception->erp_operation_nature_cfop_choice_id)->lockForUpdate()->first();
                    if ($choice === null || $choice->status !== 'active') {
                        $this->invalidCandidate();
                    }
                    $code = $choice->cfop;
                    $source = 'product_exception';
                } else {
                    $code = $nature->cfop;
                    $choice = $this->codeChoice($nature, $code);
                    $source = 'nature_default';
                }
            }
            $product = $this->rules->product($context, $product->id);
            $version = $choice?->reference_version ?? ($code === $nature->cfop ? $nature->cfop_reference_version : null);
            if (($choice !== null && $choice->status !== 'active') || ($source !== 'nature_default' && ($version === null || ($choice === null && $code !== $nature->cfop)))) {
                $this->invalidCandidate();
            }
            $result['choice_id'] = $choice?->id;
            $result['exception_id'] = $exception?->id;
            $result['source'] = $source;
            if ($version === null) {
                $result['unresolved_fields'] = ['line.cfop'];
                $result['applicability'] = ['status' => 'unverified', 'reason' => 'legacy_reference_unverified'];

                return $this->fingerprint($result);
            }
            $reference = $this->references->find($code);
            if ($reference === null || $reference['reference_version'] !== $version) {
                $this->invalidCandidate();
            }
            $result['reference_version'] = $version;
            if ($reference['applicability']['direction'] !== 'outbound') {
                $this->invalidCandidate();
            }
            if ($product->kind !== 'goods' || ! isset(self::GOODS_SALES[$code]) || $reference['source_metadata']['indNFe'] !== '1'
                || $reference['source_metadata']['indComb'] !== '0' || $reference['source_metadata']['indTransp'] !== '0'
                || $reference['applicability']['status'] === 'unsupported') {
                if ($source !== 'nature_default') {
                    $this->invalidCandidate();
                }
                $result['unresolved_fields'] = ['line.cfop'];
                $result['applicability'] = ['status' => 'unsupported', 'reason' => 'goods_sale_context_not_supported'];

                return $this->fingerprint($result);
            }
            foreach (['erp_establishment_id', 'movement', 'origin_uf', 'destination_uf', 'model', 'purpose'] as $required) {
                if ($actual[$required] === null) {
                    $result['unresolved_fields'][] = 'context.'.$required;
                }
            }
            if ($actual['purpose'] !== null && $actual['purpose'] !== 'normal') {
                throw ValidationException::withMessages(['context.purpose' => 'Esta prévia suporta apenas venda com finalidade normal.']);
            }
            if ($actual['origin_uf'] !== null && $actual['destination_uf'] !== null
                && (($actual['origin_uf'] === $actual['destination_uf']) !== (self::GOODS_SALES[$code] === 'same_uf'))) {
                $this->invalidCandidate();
            }
            if ($actual['model'] === '65') {
                if (! $reference['applicability']['nfce_reference_listed'] || self::GOODS_SALES[$code] !== 'same_uf') {
                    $this->invalidCandidate();
                }
                foreach (['final_consumer', 'presence'] as $required) {
                    if ($actual[$required] === null) {
                        $result['unresolved_fields'][] = 'context.'.$required;
                    }
                }
                if ($actual['final_consumer'] !== null && ! $actual['final_consumer']) {
                    throw ValidationException::withMessages(['context.final_consumer' => 'A NFC-e exige consumidor final.']);
                }
                if ($actual['presence'] !== null && ! in_array($actual['presence'], ['1', '4', '5'], true)) {
                    throw ValidationException::withMessages(['context.presence' => 'Informe uma presença suportada para NFC-e.']);
                }
            }
            if ($result['unresolved_fields'] === []) {
                $result['effective_cfop'] = $code;
                $result['applicability'] = ['status' => 'supported', 'rules_version' => 'goods-sales-cfop-v1'];
            }

            return $this->fingerprint($result);
        });
    }

    private function codeChoice(ErpOperationNature $nature, string $code): ?ErpOperationNatureCfopChoice
    {
        return $nature->cfopChoices()->where('erp_company_id', $nature->erp_company_id)->where('cfop', $code)->lockForUpdate()->first();
    }

    private function invalidCandidate(): never
    {
        throw ValidationException::withMessages(['line.cfop' => 'O CFOP selecionado ou a exceção ativa é inválido para esta natureza e contexto. Revise a escolha.']);
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function operationContext(array $context): array
    {
        $data = Validator::make(['context' => $context], [
            'context' => ['array:erp_establishment_id,movement,origin_uf,destination_uf,model,purpose,final_consumer,presence'],
            'context.erp_establishment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'context.movement' => ['sometimes', 'nullable', 'string', 'in:in,out'],
            'context.origin_uf' => ['sometimes', 'nullable', 'string', Rule::in(self::UFS)],
            'context.destination_uf' => ['sometimes', 'nullable', 'string', Rule::in(self::UFS)],
            'context.model' => ['sometimes', 'nullable', 'string', 'in:55,65'],
            'context.purpose' => ['sometimes', 'nullable', 'string', 'max:32'],
            'context.final_consumer' => ['sometimes', 'nullable', 'boolean'],
            'context.presence' => ['sometimes', 'nullable', 'string', 'in:0,1,2,3,4,5,9'],
        ])->validate()['context'];

        return array_replace(array_fill_keys(['erp_establishment_id', 'movement', 'origin_uf', 'destination_uf', 'model', 'purpose', 'final_consumer', 'presence'], null), $data);
    }

    private function fingerprint(array $result): array
    {
        $result['context_fingerprint'] = hash('sha256', json_encode($result['context'], JSON_THROW_ON_ERROR));
        $result['fingerprint'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }
}
