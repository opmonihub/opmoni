<?php

namespace App\Erp\Stock;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\Finance\ErpFinanceParticipant;
use App\Erp\Sales\ErpCommercialDraftService as Decimal;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpTaxRate;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpPurchaseLine;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ErpPurchaseDraftService
{
    public const VERSION = 'purchase-draft-v1';

    public function resource(Request $request, int $id, bool $lock = false): ErpPurchase
    {
        $query = ErpPurchase::where('erp_company_id', CurrentCompany::fromRequest($request)->company->id);
        $model = ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
        app(ErpEstablishmentAccess::class)->assertResource(CurrentCompany::fromRequest($request), $model);

        return $model;
    }

    public function active(string $class, int $company, mixed $id, string $field): Model
    {
        $id = Decimal::id($id, $field);
        $model = $class::where('erp_company_id', $company)->where('status', 'active')->find($id);
        if (! $model) {
            $this->error($field, 'Referência ativa inválida para esta empresa.');
        }

        return $model;
    }

    public function supplier(Request $request, mixed $id, ?string $issuer = null): ErpContact
    {
        $supplier = $this->active(ErpContact::class, CurrentCompany::fromRequest($request)->company->id, $id, 'erp_contact_id');
        if (! $supplier->is_supplier || ($issuer !== null && preg_replace('/\D+/', '', (string) $supplier->tax_id) !== preg_replace('/\D+/', '', $issuer))) {
            $this->error('erp_contact_id', 'Selecione fornecedor ativo correspondente ao emitente.');
        }

        return $supplier;
    }

    public function save(Request $request, array $body, ?int $id = null, ?array $import = null): ErpPurchase
    {
        $context = CurrentCompany::fromRequest($request);
        $company = $context->company->id;
        $model = $id === null ? new ErpPurchase : $this->resource($request, $id, true)->load(['lines' => fn ($q) => $q->orderBy('ordinal')->orderBy('id')->lockForUpdate()]);
        abort_unless(! $model->exists || $model->status === 'draft', 409, 'Compra já lançada.');
        $this->revision($model, $body);
        $establishment = Decimal::id($body['erp_establishment_id'] ?? $model->erp_establishment_id, 'erp_establishment_id');
        app(ErpEstablishmentAccess::class)->assertRelated($context, $establishment);
        app(ErpCatalogScope::class)->assertActiveEstablishment($company, $establishment);
        $source = $import['source_snapshot'] ?? $model->source_snapshot;
        $supplierId = $body['erp_contact_id'] ?? $model->erp_contact_id;
        $supplier = $this->supplier($request, $supplierId, $source['emit']['cpf_cnpj'] ?? null);
        if ($model->source_access_key && ($establishment !== $model->erp_establishment_id || $supplier->id !== $model->erp_contact_id)) {
            $this->error('erp_establishment_id', 'Destino e fornecedor do XML são imutáveis.');
        }
        $currency = $body['currency'] ?? $model->currency ?? 'BRL';
        if (! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || ($source !== null && $currency !== 'BRL')) {
            $this->error('currency', 'Moeda inválida para esta compra.');
        }
        $number = $body['document_no'] ?? $model->document_no ?? ($source['numero'] ?? null);
        if ($number !== null && (! is_string($number) || mb_strlen($number) > 64)) {
            $this->error('document_no', 'Número inválido.');
        }
        $hasLines = array_key_exists('lines', $body);
        if (! $hasLines && ! $model->exists) {
            $this->error('lines', 'Informe os itens.');
        }
        $lines = [];
        $total = '0.0000';
        if (! $hasLines) {
            abort_unless($model->draft_revision !== null, response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
            $this->validateReferences($request, $model, $establishment);
            $total = (string) $model->total_amount;
        } else {
            if (! is_array($body['lines']) || ! array_is_list($body['lines']) || count($body['lines']) < 1 || count($body['lines']) > 500) {
                $this->error('lines', 'Informe de 1 a 500 itens.');
            }
            $old = $model->exists ? $model->lines->keyBy('id') : collect();
            $seen = [];
            foreach ($body['lines'] as $index => $line) {
                $path = 'lines.'.$index;
                $lineId = isset($line['id']) ? Decimal::id($line['id'], $path.'.id') : null;
                if ($lineId !== null && (! $old->has($lineId) || in_array($lineId, $seen, true))) {
                    $this->error($path.'.id', 'Linha inválida ou duplicada.');
                }
                $seen[] = $lineId;
                $existingLine = $lineId !== null ? $old[$lineId] : null;
                $sourceItem = $import !== null ? ($source['det'][$index] ?? null) : ($existingLine?->product_snapshot['source_item'] ?? null);
                if ($model->source_access_key && ($sourceItem === null || count($body['lines']) !== $old->count())) {
                    $this->error($path.'.id', 'Preserve todos os itens originais do XML.');
                }
                $normalized = $this->line($request, $line, $index, $establishment, $sourceItem, $import !== null, $existingLine);
                $normalized['id'] = $lineId;
                $lines[] = $normalized;
                $total = Decimal::bounded(bcadd($total, $normalized['line_total'], 4), 'total_amount');
            }
        }
        if (isset($body['expected_total']) && Decimal::decimal($body['expected_total'], 'expected_total') !== $total) {
            $this->error('expected_total', 'Total diverge da compra.');
        }
        $model->fill(['erp_company_id' => $company, 'erp_establishment_id' => $establishment, 'erp_contact_id' => $supplier->id, 'supplier_snapshot' => (! $model->exists || isset($body['erp_contact_id'])) ? $supplier->only(['id', 'name', 'trade_name', 'tax_id', 'email', 'phone', 'address', 'is_supplier']) : $model->supplier_snapshot, 'currency' => $currency, 'document_no' => $number, 'status' => 'draft', 'total_amount' => $total, 'draft_revision' => ($model->draft_revision ?? 0) + 1]);
        if ($import !== null) {
            $model->fill($import);
        }
        $model->save();
        if ($hasLines) {
            $kept = [];
            foreach ($lines as $line) {
                $lineId = $line['id'];
                unset($line['id']);
                $saved = $lineId === null ? $model->lines()->create($line) : tap($model->lines()->findOrFail($lineId), fn ($row) => $row->update($line));
                $kept[] = $saved->id;
            }
            $model->lines()->whereNotIn('id', $kept)->delete();
        }
        $model->load('lines');
        $model->commercial_snapshot = ['version' => self::VERSION, 'draft_revision' => $model->draft_revision, 'actor_id' => $request->user()->id, 'establishment' => ErpEstablishment::findOrFail($establishment)->only(['id', 'cnpj', 'name', 'address']), 'supplier' => $model->supplier_snapshot, 'currency' => $currency, 'total_amount' => $total, 'lines' => $model->lines->map(fn ($line) => $line->attributesToArray())->all()];
        $model->save();

        return $model;
    }

    private function line(Request $request, array $line, int $index, int $establishment, ?array $source, bool $initialImport, ?ErpPurchaseLine $existingLine): array
    {
        $company = CurrentCompany::fromRequest($request)->company->id;
        $path = 'lines.'.$index;
        $quantity = Decimal::decimal($line['quantity'] ?? null, $path.'.quantity');
        if (bccomp($quantity, '0', 4) <= 0) {
            $this->error($path.'.quantity', 'Quantidade deve ser positiva.');
        }
        $cost = Decimal::decimal($line['unit_cost'] ?? null, $path.'.unit_cost');
        $product = $variant = $unit = null;
        $explicitBaseUnitFactor = false;
        $factor = '1.00000000';
        $chain = [];
        if (isset($line['erp_product_variant_id'])) {
            $variantId = Decimal::id($line['erp_product_variant_id'], $path.'.erp_product_variant_id');
            app(ErpCatalogScope::class)->assertVariantBelongsToCompany($company, $variantId, $path.'.erp_product_variant_id');
            $variant = ErpProductVariant::with('product')->findOrFail($variantId);
            $product = $variant->product;
            $unit = $this->active(ErpUnit::class, $company, $line['erp_unit_id'] ?? $product->erp_unit_id, $path.'.erp_unit_id');
            [$factor, $chain] = $this->conversion($unit, $product->erp_unit_id, $company, $path.'.erp_unit_id');
            if ($source !== null && ($product->kind !== 'goods' || ! $product->track_stock)) {
                $this->error($path.'.erp_product_variant_id', 'Item físico do XML exige produto com controle de estoque.');
            }
        } elseif ($source !== null) {
            $this->error($path.'.erp_product_variant_id', 'Mapeie explicitamente o item físico.');
        } elseif (isset($line['erp_unit_id'])) {
            $unit = $this->active(ErpUnit::class, $company, $line['erp_unit_id'], $path.'.erp_unit_id');
            [$factor, $chain] = $this->conversion($unit, $unit->id, $company, $path.'.erp_unit_id');
        }
        if (isset($line['unit_conversion_factor'])) {
            $selectedFactor = Decimal::decimal($line['unit_conversion_factor'], $path.'.unit_conversion_factor', 8);
            if ($variant !== null && ! isset($line['erp_unit_id'])) {
                // Preserve the established API's explicit base-unit conversion when no alternate unit is selected.
                $factor = $selectedFactor;
                $explicitBaseUnitFactor = true;
            } elseif ($selectedFactor !== $factor) {
                $this->error($path.'.unit_conversion_factor', 'Fator difere da unidade selecionada; selecione unidade compatível.');
            }
        }
        if ($unit && ! $unit->allow_decimal && bccomp($quantity, bcadd($quantity, '0', 0), 4) !== 0) {
            $this->error($path.'.quantity', 'Unidade não aceita frações.');
        }
        $description = $line['description'] ?? ($product?->kind === 'goods' ? $product->name.' / '.$variant->name : null);
        if (! is_string($description) || trim($description) === '' || mb_strlen($description) > 1000) {
            $this->error($path.'.description', 'Descreva o item ou serviço.');
        }
        try {
            $base = ErpStockDecimal::base($quantity, $factor, $path.'.quantity');
        } catch (ValidationException $exception) {
            if ($explicitBaseUnitFactor) {
                $this->error($path.'.unit_conversion_factor', 'Fator não produz uma quantidade base exata.');
            }

            throw $exception;
        }
        $gross = Decimal::bounded(bcmul($quantity, $cost, 4), $path.'.unit_cost');
        $existingAmounts = $existingLine?->product_snapshot['amounts'] ?? [];
        $discount = Decimal::decimal($line['discount_amount'] ?? ($source !== null ? ($existingAmounts['discount_amount'] ?? '0') : '0'), $path.'.discount_amount');
        $tax = Decimal::decimal($line['tax_amount'] ?? ($source !== null ? ($existingAmounts['tax_amount'] ?? '0') : '0'), $path.'.tax_amount');
        $rate = $nature = null;
        foreach (['erp_tax_rate_id' => ErpTaxRate::class, 'erp_operation_nature_id' => ErpOperationNature::class] as $field => $class) {
            if (isset($line[$field])) {
                $reference = $this->active($class, $company, $line[$field], $path.'.'.$field);
                app(ErpEstablishmentAccess::class)->assertRelatedResource(CurrentCompany::fromRequest($request), $reference, $path.'.'.$field);
                if ($reference->erp_establishment_id !== null && $reference->erp_establishment_id !== $establishment) {
                    $this->error($path.'.'.$field, 'Referência de outro estabelecimento.');
                }
                if ($field === 'erp_tax_rate_id') {
                    $rate = $reference;
                } else {
                    $nature = $reference;
                }
            }
        }
        if (bccomp($discount, $gross, 4) > 0) {
            $this->error($path.'.discount_amount', 'Desconto excede o valor bruto.');
        }
        $net = bcsub($gross, $discount, 4);
        if ($rate !== null) {
            $computed = Decimal::bounded(bcdiv(bcmul($net, $rate->rate, 8), '100', 4), $path.'.tax_amount');
            if (isset($line['tax_amount']) && $tax !== $computed) {
                $this->error($path.'.tax_amount', 'Imposto difere da taxa comercial selecionada.');
            }
            $tax = $computed;
        }
        $preservesSourceAmounts = $source !== null && isset($existingAmounts['quantity'], $existingAmounts['unit_cost'], $existingAmounts['line_total'])
            && Decimal::decimal($existingAmounts['quantity'], $path.'.quantity') === $quantity
            && Decimal::decimal($existingAmounts['unit_cost'], $path.'.unit_cost') === $cost
            && Decimal::decimal($existingAmounts['discount_amount'] ?? '0', $path.'.discount_amount') === $discount
            && Decimal::decimal($existingAmounts['tax_amount'] ?? '0', $path.'.tax_amount') === $tax;
        $amounts = $initialImport ? $source['amounts'] : ($preservesSourceAmounts
            ? array_intersect_key($existingAmounts, array_flip(['gross_amount', 'discount_amount', 'net_amount', 'tax_amount', 'line_total']))
            : ['gross_amount' => $gross, 'discount_amount' => $discount, 'net_amount' => $net, 'tax_amount' => $tax, 'line_total' => Decimal::bounded(bcadd($net, $tax, 4), $path.'.line_total')]);
        $snapshot = ['version' => self::VERSION, 'description' => $description, 'product' => $product?->only(['id', 'name', 'kind', 'track_stock', 'erp_unit_id', 'ncm', 'cest']), 'variant' => $variant?->only(['id', 'name', 'sku', 'barcode']), 'physical_profile' => $product?->only(['kind', 'track_stock']) ?? ['kind' => 'service', 'track_stock' => false], 'unit' => $unit?->only(['id', 'actual_name', 'short_name', 'allow_decimal', 'conversion_factor', 'base_unit_id']), 'unit_chain' => $chain, 'tax_rate' => $rate?->only(['id', 'name', 'rate', 'erp_establishment_id']), 'operation_nature' => $nature?->only(['id', 'description', 'cfop', 'erp_establishment_id']), 'source_item' => $source, 'amounts' => $amounts + ['quantity' => $quantity, 'base_quantity' => $base, 'unit_cost' => $cost, 'unit_conversion_factor' => $factor], 'valuation_policy' => 'accepted-payable-consideration-v1', 'legal_applicability' => null];

        return ['ordinal' => $index + 1, 'source_ordinal' => $source['line'] ?? null, 'erp_product_variant_id' => $variant?->id, 'erp_unit_id' => $unit?->id, 'description' => $description, 'quantity' => $quantity, 'base_quantity' => $base, 'unit_cost' => $cost, 'unit_conversion_factor' => $factor, 'line_total' => $amounts['line_total'], 'product_snapshot' => $snapshot];
    }

    private function conversion(Model $unit, int $baseId, int $company, string $field): array
    {
        $factor = '1.00000000';
        $chain = $seen = [];
        $reached = false;
        while ($unit !== null) {
            if (in_array($unit->id, $seen, true) || count($seen) >= 100) {
                $this->error($field, 'Cadeia cíclica ou excessiva.');
            }
            $seen[] = $unit->id;
            $chain[] = $unit->only(['id', 'actual_name', 'short_name', 'allow_decimal', 'base_unit_id', 'conversion_factor']);
            if ($unit->id === $baseId) {
                $reached = true;
            }
            if (! $reached) {
                $exact = bcmul($factor, $unit->conversion_factor, 16);
                $factor = Decimal::decimal(bcadd($exact, '0', 8), $field, 8);
                if (bccomp($exact, $factor, 16) !== 0 || bccomp($factor, '0', 8) <= 0) {
                    $this->error($field, 'Conversão não representável.');
                }
            }
            $unit = $unit->base_unit_id ? $this->active(ErpUnit::class, $company, $unit->base_unit_id, $field) : null;
        }
        if (! $reached) {
            $this->error($field, 'Unidade incompatível com a unidade base do produto.');
        }

        return [$factor, $chain];
    }

    public function validateReferences(Request $request, ErpPurchase $model, ?int $establishment = null): void
    {
        $company = $model->erp_company_id;
        $establishment ??= $model->erp_establishment_id;
        $this->supplier($request, $model->erp_contact_id, $model->source_snapshot['emit']['cpf_cnpj'] ?? null);
        foreach ($model->lines as $index => $line) {
            $path = 'lines.'.$index;
            $snapshot = $line->product_snapshot;
            abort_unless(($snapshot['version'] ?? null) === self::VERSION && isset($snapshot['amounts']), response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
            if ($line->erp_product_variant_id !== null) {
                app(ErpCatalogScope::class)->assertVariantBelongsToCompany($company, $line->erp_product_variant_id, $path.'.erp_product_variant_id');
            }
            foreach ($snapshot['unit_chain'] ?? [] as $reference) {
                $this->active(ErpUnit::class, $company, $reference['id'], $path.'.erp_unit_id');
            }
            foreach (['tax_rate' => ErpTaxRate::class, 'operation_nature' => ErpOperationNature::class] as $name => $class) {
                if ($snapshot[$name]['id'] ?? null) {
                    $reference = $this->active($class, $company, $snapshot[$name]['id'], $path.'.'.$name);
                    app(ErpEstablishmentAccess::class)->assertRelatedResource(CurrentCompany::fromRequest($request), $reference, $path.'.'.$name);
                    if ($reference->erp_establishment_id !== null && $reference->erp_establishment_id !== $establishment) {
                        $this->error($path.'.'.$name, 'Referência de outro estabelecimento.');
                    }
                }
            }
        }
    }

    public function revision(ErpPurchase $model, array $body): void
    {
        if (isset($body['draft_revision'])) {
            abort_unless(Decimal::id($body['draft_revision'], 'draft_revision') === $model->draft_revision, 409, 'Rascunho alterado.');
        }
    }

    public function post(Request $request, int $id, array $body, string $key): array
    {
        $model = $this->resource($request, $id, true)->load(['lines' => fn ($q) => $q->orderBy('erp_product_variant_id')->orderBy('id')->lockForUpdate()]);
        abort_unless($model->status === 'draft', 409, 'Compra já lançada.');
        abort_unless($model->draft_revision !== null && $model->lines->isNotEmpty(), response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
        $this->revision($model, $body);
        if (isset($body['expected_total']) && Decimal::decimal($body['expected_total'], 'expected_total') !== $model->total_amount) {
            $this->error('expected_total', 'Total alterado.');
        }
        app(ErpCatalogScope::class)->assertActiveEstablishment($model->erp_company_id, $model->erp_establishment_id);
        $this->validateReferences($request, $model);
        foreach ($model->lines as $line) {
            $profile = $line->product_snapshot['physical_profile'] ?? $line->product_snapshot['product'];
            $physical = ErpStockParticipant::physicalProfile($profile);
            abort_if($physical === null, response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
            if (! $physical) {
                continue;
            }
            $base = ErpStockDecimal::base($line->quantity, $line->unit_conversion_factor, 'quantity');
            abort_unless($base === $line->base_quantity && $line->erp_product_variant_id !== null, 409);
            app(ErpStockLedger::class)->receive($model->erp_company_id, $model->erp_establishment_id, $line->erp_product_variant_id, $base, null, ['source_type' => 'erp_purchase', 'source_id' => $model->id, 'source_line_id' => $line->id, 'posted_by_user_id' => $request->user()->id], $key.'-line-'.$line->id, $line->line_total, $model->currency);
        }
        $obligation = bccomp($model->total_amount, '0', 4) > 0 ? app(ErpFinanceParticipant::class)->postPayableForPurchase($model->erp_company_id, $model->erp_establishment_id, $model->id, $model->total_amount, $model->currency, $key.'-obligation', $request->user()->id) : null;
        $snapshot = $model->commercial_snapshot;
        $snapshot['accepted'] = ['actor_id' => $request->user()->id, 'at' => now()->toISOString(), 'draft_revision' => $model->draft_revision];
        $model->fill(['status' => 'posted', 'source_operation_key' => $key, 'posted_at' => now(), 'posted_by_user_id' => $request->user()->id, 'commercial_snapshot' => $snapshot])->save();

        return [$model, $obligation];
    }

    public function serialize(ErpPurchase $model, bool $full = true): array
    {
        $data = $model->only(['id', 'erp_establishment_id', 'erp_contact_id', 'document_no', 'status', 'total_amount', 'currency', 'draft_revision', 'source_access_key', 'source_sha256', 'source_byte_length', 'source_operation_key']);
        $data['supplier_snapshot'] = $model->supplier_snapshot;
        $data['needs_reconciliation'] = $model->draft_revision === null;
        if ($full) {
            $data['source_snapshot'] = $this->publicSourceSnapshot($model->source_snapshot);
            $data['lines'] = $model->lines()->orderBy('ordinal')->orderBy('id')->get()->map(function (ErpPurchaseLine $line): array {
                $serialized = $line->attributesToArray();
                if (isset($serialized['product_snapshot']['source_item']['source_facts'])) {
                    unset($serialized['product_snapshot']['source_item']['source_facts']);
                }

                return $serialized;
            })->all();
        } else {
            $lines = $model->relationLoaded('lines') ? $model->lines : $model->lines()->orderBy('ordinal')->orderBy('id')->get();
            $data['lines'] = $lines->map(fn (ErpPurchaseLine $line): array => $line->only(['id', 'erp_product_variant_id', 'quantity', 'unit_cost', 'line_total', 'unit_conversion_factor']))->all();
        }

        return $data;
    }

    /** @param array<string, mixed>|null $source
     * @return array<string, mixed>|null
     */
    private function publicSourceSnapshot(?array $source): ?array
    {
        if ($source === null) {
            return null;
        }
        unset($source['source_facts']);
        if (isset($source['det']) && is_array($source['det'])) {
            foreach ($source['det'] as $index => $line) {
                unset($line['source_facts']);
                $source['det'][$index] = $line;
            }
        }

        return $source;
    }

    private function error(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
