<?php

namespace App\Erp\Sales;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpCommercialReturnService;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\Stock\ErpStockDecimal;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpCustomerGroup;
use App\Models\Erp\ErpDiscountRule;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpPriceGroup;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpTaxRate;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Sales\ErpOrder;
use App\Models\Erp\Sales\ErpQuote;
use App\Models\Erp\Sales\ErpSale;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ErpCommercialDraftService
{
    public const VERSION = 'commercial-v1';

    public static function modelClass(string $kind): string
    {
        return match ($kind) {
            'quote' => ErpQuote::class, 'order' => ErpOrder::class, 'sale' => ErpSale::class
        };
    }

    public static function numberField(string $kind): string
    {
        return match ($kind) {
            'quote' => 'quote_no', 'order' => 'order_no', 'sale' => 'invoice_no'
        };
    }

    public static function decimal(mixed $value, string $field, int $scale = 4): string
    {
        if (! is_string($value) || ! preg_match('/^\d{1,'.(22 - $scale).'}(?:\.\d{1,'.$scale.'})?$/D', $value)) {
            throw ValidationException::withMessages([$field => 'Informe um decimal em texto com até '.$scale.' casas.']);
        }

        return bcadd($value, '0', $scale);
    }

    public static function bounded(string $value, string $field): string
    {
        return self::decimal($value, $field);
    }

    public static function id(mixed $value, string $field): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9]\d{0,15}$/D', (string) $value) || bccomp((string) $value, '9007199254740991', 0) > 0) {
            throw ValidationException::withMessages([$field => 'Identificador inválido.']);
        }

        return (int) $value;
    }

    /** Ancestors are filtered in SQL before pagination, including company identity. */
    public function scopedQuery(Request $request, string $kind): Builder
    {
        $context = CurrentCompany::fromRequest($request);
        $class = self::modelClass($kind);
        $query = $class::where('erp_company_id', $context->company->id);
        $allowed = app(ErpEstablishmentAccess::class)->allowedIds($context);
        if ($allowed !== null) {
            $query->whereIn('erp_establishment_id', $allowed);
        }
        if ($kind === 'order') {
            $query->where(fn ($q) => $q->whereNull('erp_quote_id')->orWhereHas('quote', fn ($parent) => $parent->whereIn('id', $this->scopedQuery($request, 'quote')->select('id'))));
        } elseif ($kind === 'sale') {
            $query->where(fn ($q) => $q->whereNull('erp_order_id')->orWhereHas('order', fn ($parent) => $parent->whereIn('id', $this->scopedQuery($request, 'order')->select('id'))));
        }

        return $query;
    }

    public function scopeIds(Model $model): array
    {
        $ids = [$model->erp_establishment_id];
        if ($model instanceof ErpOrder && $model->erp_quote_id) {
            $parent = ErpQuote::where('erp_company_id', $model->erp_company_id)->findOrFail($model->erp_quote_id);
            $ids = [...$ids, ...$this->scopeIds($parent)];
        }
        if ($model instanceof ErpSale && $model->erp_order_id) {
            $parent = ErpOrder::where('erp_company_id', $model->erp_company_id)->findOrFail($model->erp_order_id);
            $ids = [...$ids, ...$this->scopeIds($parent)];
        }

        return array_values(array_unique($ids));
    }

    public function resource(Request $request, string $kind, int $id, bool $lock = false): Model
    {
        $context = CurrentCompany::fromRequest($request);
        $class = self::modelClass($kind);
        $query = $this->scopedQuery($request, $kind);
        $model = ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
        app(ErpEstablishmentAccess::class)->assertResource($context, $model);
        if ($model instanceof ErpOrder && $model->erp_quote_id) {
            $this->resource($request, 'quote', $model->erp_quote_id);
        }
        if ($model instanceof ErpSale && $model->erp_order_id) {
            $this->resource($request, 'order', $model->erp_order_id);
        }

        return $model;
    }

    public function save(Request $request, string $kind, array $input, ?int $id): Model
    {
        $context = CurrentCompany::fromRequest($request);
        $model = $id === null ? null : $this->resource($request, $kind, $id, true);
        if ($model) {
            abort_unless(in_array($model->status, ['draft', 'confirmed'], true), 409, 'Documento já consumido ou lançado.');
            if (isset($input['draft_revision'])) {
                abort_unless((int) $input['draft_revision'] === $model->draft_revision, 409, 'Rascunho alterado.');
            }
            if (! array_key_exists('lines', $input)) {
                return $this->saveRetainedLines($request, $kind, $model, $input);
            }
            $savedInput = $model->draft_input ?? [];
            foreach (['expected_total', 'total_amount', 'subtotal_amount', 'discount_amount', 'tax_amount'] as $assertion) {
                unset($savedInput[$assertion]);
            }
            $input = array_replace($savedInput, $input);
        }
        $field = self::numberField($kind);
        if ($kind === 'sale' && array_key_exists('sale_no', $input)) {
            if (array_key_exists('invoice_no', $input) && $input['invoice_no'] !== $input['sale_no']) {
                $this->error('sale_no', 'Números informados divergem.');
            }
            $input['invoice_no'] = $input['sale_no'];
        }
        $data = Validator::make($input, [
            'erp_establishment_id' => ['required'], 'erp_contact_id' => ['sometimes', 'nullable'],
            'currency' => ['sometimes', 'in:BRL'], $field => ['sometimes', 'nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'list', 'min:1', 'max:100'],
        ])->validate();
        $company = $context->company->id;
        $establishment = self::id($data['erp_establishment_id'], 'erp_establishment_id');
        $scope = app(ErpCatalogScope::class);
        $scope->assertEstablishmentBelongsToCompany($company, $establishment);
        app(ErpEstablishmentAccess::class)->assertRelated($context, $establishment);
        $scope->assertActiveEstablishment($company, $establishment);
        $party = null;
        $contact = null;
        if (($input['erp_contact_id'] ?? null) !== null) {
            $contact = $this->active(ErpContact::class, $company, self::id($input['erp_contact_id'], 'erp_contact_id'), 'erp_contact_id');
            if (! $contact->is_customer) {
                $this->error('erp_contact_id', 'Selecione um cliente comercial.');
            }
            $party = $contact->only(['id', 'name', 'trade_name', 'tax_id', 'email', 'phone', 'address', 'is_customer']);
        }
        $customerGroup = $contact?->erp_customer_group_id ? $this->active(ErpCustomerGroup::class, $company, $contact->erp_customer_group_id, 'erp_contact_id') : null;
        if (! array_key_exists('erp_price_group_id', $input) && ErpPriceGroup::where('erp_company_id', $company)->where('status', 'active')->where('is_default', true)->count() > 1) {
            $this->error('erp_price_group_id', 'Selecione o grupo diante de padrões ambíguos.');
        }
        $priceGroup = array_key_exists('erp_price_group_id', $input)
            ? ($input['erp_price_group_id'] === null ? null : $this->active(ErpPriceGroup::class, $company, self::id($input['erp_price_group_id'], 'erp_price_group_id'), 'erp_price_group_id'))
            : ErpPriceGroup::where('erp_company_id', $company)->where('status', 'active')->where('is_default', true)->first();
        $nature = null;
        if (($input['erp_operation_nature_id'] ?? null) !== null) {
            $nature = $this->scopedReference($request, ErpOperationNature::class, self::id($input['erp_operation_nature_id'], 'erp_operation_nature_id'), $establishment, 'erp_operation_nature_id');
        }
        $oldIds = $model?->lines()->pluck('id')->all() ?? [];
        $seen = [];
        $lines = [];
        foreach ($input['lines'] as $index => $line) {
            $path = 'lines.'.$index;
            if (! is_array($line)) {
                $this->error($path, 'Linha inválida.');
            }
            if (isset($line['description']) && (! is_string($line['description']) || trim($line['description']) === '' || mb_strlen($line['description']) > 1000)) {
                $this->error($path.'.description', 'Descrição inválida.');
            }
            if (isset($line['id'])) {
                $lineId = self::id($line['id'], $path.'.id');
                if (! in_array($lineId, $oldIds, true) || in_array($lineId, $seen, true)) {
                    $this->error($path.'.id', 'Linha inválida ou duplicada.');
                }
                $seen[] = $lineId;
            }
            $quantity = self::decimal($line['quantity'] ?? null, $path.'.quantity');
            if (bccomp($quantity, '0', 4) <= 0) {
                $this->error($path.'.quantity', 'Quantidade deve ser positiva.');
            }
            $variant = $product = $unit = null;
            $factor = '1.00000000';
            $catalogPrice = null;
            if (($line['erp_product_variant_id'] ?? null) !== null) {
                $variantId = self::id($line['erp_product_variant_id'], $path.'.erp_product_variant_id');
                $scope->assertVariantBelongsToCompany($company, $variantId, $path.'.erp_product_variant_id');
                $variant = ErpProductVariant::with('product.unit')->findOrFail($variantId);
                $product = $variant->product;
                $unitId = isset($line['erp_unit_id']) ? self::id($line['erp_unit_id'], $path.'.erp_unit_id') : $product->erp_unit_id;
                $unit = $this->active(ErpUnit::class, $company, $unitId, $path.'.erp_unit_id');
                $factor = $this->factor($unit, $product->erp_unit_id, $company, $path.'.erp_unit_id');
                if (! $unit->allow_decimal && bccomp($quantity, bcadd($quantity, '0', 0), 4) !== 0) {
                    $this->error($path.'.quantity', 'Unidade não aceita quantidade fracionária.');
                }
                $basePrice = $variant->sale_price ?? $product->sale_price;
                $catalogPrice = $basePrice === null ? null : self::bounded(bcadd(bcmul($basePrice, $factor, 12), '0', 4), $path.'.unit_price');
            } else {
                if (! is_string($line['description'] ?? null) || trim($line['description']) === '') {
                    $this->error($path.'.description', 'Descreva o serviço avulso.');
                }
                $this->capability($request, 'erp.sales.price.override', $path.'.unit_price');
                if (isset($line['erp_unit_id'])) {
                    $unit = $this->active(ErpUnit::class, $company, self::id($line['erp_unit_id'], $path.'.erp_unit_id'), $path.'.erp_unit_id');
                }
            }
            if (isset($line['unit_conversion_factor']) && self::decimal($line['unit_conversion_factor'], $path.'.unit_conversion_factor', 8) !== $factor) {
                $this->error($path.'.unit_conversion_factor', 'Fator difere da unidade selecionada.');
            }
            $baseQuantity = ErpStockDecimal::base($quantity, $factor, $path.'.quantity');
            $candidate = $catalogPrice;
            $applied = 'catalog';
            if ($candidate !== null && $customerGroup) {
                $candidate = $this->percentagePrice($catalogPrice, $customerGroup->percentage_adjustment, $path.'.unit_price');
                $applied = 'customer_group';
            }
            $customerCandidate = $candidate;
            if ($catalogPrice !== null && $priceGroup) {
                $candidate = $this->percentagePrice($catalogPrice, $priceGroup->percentage_adjustment, $path.'.unit_price');
                $applied = 'price_group';
            }
            $price = isset($line['unit_price']) ? self::decimal($line['unit_price'], $path.'.unit_price') : $candidate;
            if ($price === null) {
                $this->error($path.'.unit_price', 'Informe o preço.');
            }
            if ($candidate === null || $price !== $candidate) {
                $this->capability($request, 'erp.sales.price.override', $path.'.unit_price');
            }
            $price = self::bounded($price, $path.'.unit_price');
            $gross = self::bounded(bcmul($quantity, $price, 4), $path.'.unit_price');
            $discount = self::decimal($line['discount_amount'] ?? '0', $path.'.discount_amount');
            $rule = null;
            if (isset($line['erp_discount_rule_id'])) {
                $rule = $this->active(ErpDiscountRule::class, $company, self::id($line['erp_discount_rule_id'], $path.'.erp_discount_rule_id'), $path.'.erp_discount_rule_id');
                $ruleDiscount = $this->ruleAmount($rule, $gross, $path.'.erp_discount_rule_id');
                if (isset($line['discount_amount']) && $discount !== $ruleDiscount) {
                    $this->capability($request, 'erp.sales.discount.override', $path.'.discount_amount');
                } else {
                    $discount = $ruleDiscount;
                }
            } elseif (bccomp($discount, '0', 4) > 0) {
                $this->capability($request, 'erp.sales.discount.override', $path.'.discount_amount');
            }
            if (bccomp($discount, $gross, 4) > 0) {
                $this->error($path.'.discount_amount', 'Desconto excede o subtotal.');
            }
            $tax = self::decimal($line['tax_amount'] ?? '0', $path.'.tax_amount');
            $rate = null;
            if (isset($line['erp_tax_rate_id'])) {
                $rate = $this->scopedReference($request, ErpTaxRate::class, self::id($line['erp_tax_rate_id'], $path.'.erp_tax_rate_id'), $establishment, $path.'.erp_tax_rate_id');
            }
            $snapshot = [
                'version' => self::VERSION, 'description' => $line['description'] ?? ($product ? $product->name.' / '.$variant->name : ''),
                'product' => $product?->only(['id', 'name', 'sku', 'barcode', 'barcode_type', 'kind', 'track_stock', 'ncm', 'cest', 'origin_code', 'icms_cst', 'icms_csosn', 'pis_cst', 'cofins_cst', 'ipi_cst', 'icms_rate', 'pis_rate', 'cofins_rate', 'ipi_rate', 'simple_national_credit_rate', 'ibs_cbs_cst', 'ibs_cbs_classification_code']),
                'variant' => $variant?->only(['id', 'name', 'sku', 'barcode', 'barcode_type']),
                'unit' => $unit?->only(['id', 'short_name', 'actual_name', 'allow_decimal', 'base_unit_id', 'conversion_factor']),
                'unit_chain' => $this->unitChain($unit, $company, $path.'.erp_unit_id'),
                'pricing' => ['price_group_resolution' => array_key_exists('erp_price_group_id', $input) ? ($input['erp_price_group_id'] === null ? 'explicit_null' : 'explicit') : 'default', 'capability' => 'erp.sales.price.override', 'catalog_base_price' => $variant ? ($variant->sale_price ?? $product->sale_price) : null, 'catalog_price' => $catalogPrice, 'customer_candidate' => $customerCandidate, 'quantization' => 'truncate4', 'customer_group' => $customerGroup?->only(['id', 'name', 'percentage_adjustment']), 'price_group' => $priceGroup?->only(['id', 'name', 'percentage_adjustment']), 'applied_source' => $applied, 'authoritative_price' => $candidate, 'unit_price' => $price, 'manual_override' => $candidate === null || $candidate !== $price, 'actor_id' => $request->user()->id],
                'manual_discount' => bccomp($discount, '0', 4) > 0 && ($rule === null || (isset($line['discount_amount']) && $discount !== $ruleDiscount)), 'discount_actor_id' => $request->user()->id,
                'tax_rate' => $rate?->only(['id', 'name', 'rate', 'tax_kind', 'erp_establishment_id']), 'discount_rule' => $rule?->only(['id', 'name', 'kind', 'value']), 'operation_nature' => $nature?->only(['id', 'cfop', 'description', 'movement', 'erp_establishment_id']),
            ];
            $lines[] = ['erp_company_id' => $company, 'id' => $line['id'] ?? null, 'ordinal' => $index + 1, 'erp_product_variant_id' => $variant?->id, 'erp_unit_id' => $unit?->id, 'quantity' => $quantity, 'base_quantity' => $baseQuantity, 'unit_conversion_factor' => $factor, 'unit_price' => $price, 'gross_amount' => $gross, 'discount_amount' => $discount, 'tax_amount' => $tax, 'product_snapshot' => $snapshot, '_expected_tax' => $rate !== null && array_key_exists('tax_amount', $line) ? $tax : null];
        }
        $totals = $this->totals($request, $lines, $input, $company);
        foreach ($input['lines'] as $index => $submittedLine) {
            if (isset($submittedLine['line_total']) && self::decimal($submittedLine['line_total'], 'lines.'.$index.'.line_total') !== $lines[$index]['line_total']) {
                $this->error('lines.'.$index.'.line_total', 'Total difere do cálculo da linha.');
            }
        }
        foreach (['expected_total' => 'total_amount', 'total_amount' => 'total_amount', 'subtotal_amount' => 'subtotal_amount', 'discount_amount' => 'discount_amount', 'tax_amount' => 'tax_amount'] as $expected => $actual) {
            if (isset($input[$expected]) && self::decimal($input[$expected], $expected) !== $totals[$actual]) {
                $this->error($expected, 'Total difere do cálculo das linhas.');
            }
        }
        $class = self::modelClass($kind);
        if ($model && $class::where('erp_company_id', $company)->where('erp_establishment_id', $establishment)->where($field, $model->$field)->whereKeyNot($model->id)->exists()) {
            $this->error($field, 'Número já utilizado no estabelecimento.');
        }
        $number = $model?->$field ?? app(ErpCommercialNumbers::class)->allocate($kind, $company, $establishment, $input[$field] ?? ($input['sale_no'] ?? null));
        if ($model && isset($input[$field]) && $input[$field] !== $number) {
            $this->error($field, 'Número do rascunho é imutável.');
        }
        $model ??= new $class;
        $model->fill($totals + ['erp_company_id' => $company, 'erp_establishment_id' => $establishment, 'erp_contact_id' => $contact?->id, 'erp_price_group_id' => $priceGroup?->id, 'erp_operation_nature_id' => $nature?->id, $field => $number, 'currency' => $input['currency'] ?? 'BRL', 'status' => $kind === 'order' ? 'confirmed' : 'draft', 'party_snapshot' => $party, 'draft_revision' => ($model->draft_revision ?? 0) + 1, 'draft_input' => $input]);
        if ($kind === 'sale' && ! $model->exists) {
            $model->fiscal_status = 'none';
        }
        $model->save();
        $kept = [];
        foreach ($lines as $line) {
            $lineId = $line['id'];
            unset($line['id']);
            $saved = $lineId ? $model->lines()->findOrFail($lineId) : $model->lines()->make();
            $saved->fill($line)->save();
            $kept[] = $saved->id;
        }
        $model->lines()->whereNotIn('id', $kept)->delete();
        $model->commercial_snapshot = $this->snapshot($model->fresh('lines'));
        $model->commercial_snapshot = $model->commercial_snapshot + ['manual_header_discount' => isset($input['discount']) && ! isset($input['erp_discount_rule_id']) && bccomp($totals['discount_amount'], '0', 4) > 0, 'header_discount_rule_id' => $input['erp_discount_rule_id'] ?? null, 'price_group_resolution' => array_key_exists('erp_price_group_id', $input) ? ($input['erp_price_group_id'] === null ? 'explicit_null' : 'explicit') : 'default'];
        $model->save();

        return $model->fresh('lines');
    }

    /** Omitted lines retain the accepted normalized evidence; refresh requires explicit lines. */
    private function saveRetainedLines(Request $request, string $kind, Model $model, array $input): Model
    {
        abort_unless($model->draft_revision !== null && $model->lines()->exists(), response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
        $model->load('lines');
        $this->validateNewReferences($request, $model);
        $company = $model->erp_company_id;
        $context = CurrentCompany::fromRequest($request);
        $establishment = array_key_exists('erp_establishment_id', $input) ? self::id($input['erp_establishment_id'], 'erp_establishment_id') : $model->erp_establishment_id;
        app(ErpCatalogScope::class)->assertActiveEstablishment($company, $establishment);
        app(ErpEstablishmentAccess::class)->assertRelated($context, $establishment);
        if (isset($input['currency']) && $input['currency'] !== 'BRL') {
            $this->error('currency', 'Moeda comercial não suportada.');
        }
        $numberField = self::numberField($kind);
        foreach ([$numberField, ...($kind === 'sale' ? ['sale_no'] : [])] as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== $model->$numberField) {
                $this->error($field, 'Número do rascunho é imutável.');
            }
        }
        $class = self::modelClass($kind);
        if ($class::where('erp_company_id', $company)->where('erp_establishment_id', $establishment)->where($numberField, $model->$numberField)->whereKeyNot($model->id)->exists()) {
            $this->error($numberField, 'Número já utilizado no estabelecimento.');
        }
        if (array_key_exists('erp_price_group_id', $input) && $input['erp_price_group_id'] !== $model->erp_price_group_id && (string) $input['erp_price_group_id'] !== (string) $model->erp_price_group_id) {
            $this->error('erp_price_group_id', 'Envie as linhas explicitamente para atualizar o preço.');
        }
        if (array_key_exists('erp_contact_id', $input)) {
            $contact = $input['erp_contact_id'] === null ? null : $this->active(ErpContact::class, $company, self::id($input['erp_contact_id'], 'erp_contact_id'), 'erp_contact_id');
            if ($contact && ! $contact->is_customer) {
                $this->error('erp_contact_id', 'Selecione um cliente comercial.');
            }
            $model->erp_contact_id = $contact?->id;
            $model->party_snapshot = $contact?->only(['id', 'name', 'trade_name', 'tax_id', 'email', 'phone', 'address', 'is_customer']);
        }
        if (array_key_exists('erp_operation_nature_id', $input) && (string) $input['erp_operation_nature_id'] !== (string) $model->erp_operation_nature_id) {
            $this->error('erp_operation_nature_id', 'Envie as linhas explicitamente para atualizar a natureza da operação.');
        }
        $natureId = array_key_exists('erp_operation_nature_id', $input) ? $input['erp_operation_nature_id'] : $model->erp_operation_nature_id;
        if ($natureId !== null) {
            $this->scopedReference($request, ErpOperationNature::class, self::id($natureId, 'erp_operation_nature_id'), $establishment, 'erp_operation_nature_id');
        }
        foreach ($model->lines as $index => $line) {
            if ($line->product_snapshot['tax_rate']['id'] ?? null) {
                $this->scopedReference($request, ErpTaxRate::class, $line->product_snapshot['tax_rate']['id'], $establishment, 'lines.'.$index.'.erp_tax_rate_id');
            }
        }
        $adjustsDiscount = array_key_exists('discount', $input) || array_key_exists('erp_discount_rule_id', $input);
        $adjustsTax = array_key_exists('tax_rate_percent', $input);
        $totals = $model->only(['subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount']);
        if ($adjustsDiscount || $adjustsTax) {
            $lines = $model->lines->map(fn (Model $line): array => $line->only(['id', 'ordinal', 'erp_company_id', 'erp_product_variant_id', 'erp_unit_id', 'quantity', 'base_quantity', 'unit_price', 'unit_conversion_factor', 'gross_amount', 'discount_amount', 'tax_amount', 'line_total', 'product_snapshot']))->all();
            foreach ($lines as &$line) {
                if ($adjustsDiscount && isset($line['product_snapshot']['header_discount'])) {
                    $line['discount_amount'] = '0.0000';
                    unset($line['product_snapshot']['header_discount']);
                }
                if (($adjustsTax || $adjustsDiscount) && isset($line['product_snapshot']['header_tax'])) {
                    $line['tax_amount'] = '0.0000';
                }
            }
            unset($line);
            $adjustments = array_intersect_key($input, array_flip(['discount', 'erp_discount_rule_id', 'tax_rate_percent']));
            if ($adjustsDiscount && ! $adjustsTax && isset($model->commercial_snapshot['header_adjustments']['tax']['rate_percent'])) {
                $adjustments['tax_rate_percent'] = $model->commercial_snapshot['header_adjustments']['tax']['rate_percent'];
            }
            $totals = $this->totals($request, $lines, $adjustments, $company);
            foreach ($lines as $line) {
                $id = $line['id'];
                unset($line['id']);
                $model->lines()->findOrFail($id)->update($line);
            }
        }
        foreach (['expected_total' => 'total_amount', 'total_amount' => 'total_amount', 'subtotal_amount' => 'subtotal_amount', 'discount_amount' => 'discount_amount', 'tax_amount' => 'tax_amount'] as $assertion => $actual) {
            if (array_key_exists($assertion, $input) && self::decimal($input[$assertion], $assertion) !== $totals[$actual]) {
                $this->error($assertion, 'Total difere das linhas aceitas.');
            }
        }
        $model->fill($totals);
        $model->erp_establishment_id = $establishment;
        $model->erp_operation_nature_id = $natureId;
        $model->draft_revision++;
        $raw = $model->draft_input ?? [];
        foreach (['expected_total', 'total_amount', 'subtotal_amount', 'discount_amount', 'tax_amount'] as $field) {
            unset($raw[$field]);
        }
        if ($adjustsDiscount) {
            unset($raw['discount'],$raw['erp_discount_rule_id']);
        }
        $model->draft_input = array_replace($raw, $input);
        $model->save();
        $snapshot = $model->commercial_snapshot;
        $snapshot['party'] = $model->party_snapshot;
        $snapshot['document'] = $model->only(['id', 'erp_company_id', 'erp_establishment_id', 'erp_quote_id', 'erp_order_id', 'quote_no', 'order_no', 'invoice_no', 'draft_revision']);
        $snapshot['totals'] = $totals;
        $snapshot['establishment'] = $model->establishment()->first()?->only(['id', 'name', 'cnpj', 'address', 'ie', 'tax_regime']);
        if ($adjustsDiscount || $adjustsTax) {
            $snapshot['lines'] = $model->fresh('lines')->lines->map(fn (Model $line): array => $line->attributesToArray())->all();
            $first = $model->fresh('lines')->lines->first()->product_snapshot;
            $snapshot['header_adjustments'] = ['discount' => $first['header_discount'] ?? null, 'tax' => $first['header_tax'] ?? null];
            if ($adjustsDiscount) {
                $snapshot['manual_header_discount'] = isset($input['discount']) && bccomp($totals['discount_amount'], '0', 4) > 0;
                $snapshot['header_discount_rule_id'] = $input['erp_discount_rule_id'] ?? null;
            }
        }
        $model->commercial_snapshot = $snapshot;
        $model->save();

        return $model->fresh('lines');
    }

    private function totals(Request $request, array &$lines, array $input, int $company): array
    {
        $sum = function (string $field) use (&$lines): string {
            return array_reduce($lines, fn (string $carry, array $line): string => bcadd($carry, $line[$field], 4), '0.0000');
        };
        $gross = $sum('gross_amount');
        $discount = $input['discount'] ?? null;
        if ($discount !== null || isset($input['erp_discount_rule_id'])) {
            if (bccomp($sum('discount_amount'), '0', 4) !== 0 || collect($lines)->contains(fn ($line) => $line['product_snapshot']['discount_rule'] !== null)) {
                $this->error('discount', 'Escolha desconto por linha ou documento.');
            }
            if (isset($input['erp_discount_rule_id'])) {
                $rule = $this->active(ErpDiscountRule::class, $company, self::id($input['erp_discount_rule_id'], 'erp_discount_rule_id'), 'erp_discount_rule_id');
                $amount = $this->ruleAmount($rule, $gross, 'erp_discount_rule_id');
            } else {
                if (! is_array($discount) || array_diff(array_keys($discount), ['discount_type', 'discount_amount']) !== []) {
                    $this->error('discount', 'Desconto inválido.');
                }
                $raw = self::decimal($discount['discount_amount'] ?? null, 'discount.discount_amount');
                if (! in_array($discount['discount_type'] ?? 'fixed', ['fixed', 'percentage'], true)) {
                    $this->error('discount.discount_type', 'Tipo inválido.');
                }
                $amount = ($discount['discount_type'] ?? 'fixed') === 'percentage' ? bcdiv(bcmul($gross, $raw, 8), '100', 4) : $raw;
            }
            if (! isset($input['erp_discount_rule_id']) && bccomp($amount, '0', 4) > 0) {
                $this->capability($request, 'erp.sales.discount.override', 'discount.discount_amount');
            }
            if (bccomp($amount, $gross, 4) > 0) {
                $this->error('discount', 'Desconto excede o subtotal.');
            }
            $this->allocate($lines, $amount, 'gross_amount', 'discount_amount');
            foreach ($lines as &$line) {
                $line['product_snapshot']['header_discount'] = ['mode' => isset($input['erp_discount_rule_id']) ? 'rule' : 'manual', 'rule' => isset($input['erp_discount_rule_id']) ? $rule->only(['id', 'name', 'kind', 'value', 'coupon_code']) : null, 'submitted' => $discount, 'total_amount' => $amount, 'actor_id' => $request->user()->id];
            }
            unset($line);
        }
        foreach ($lines as &$line) {
            $line['net_amount'] = bcsub($line['gross_amount'], $line['discount_amount'], 4);
        }
        unset($line);
        foreach ($lines as $index => &$line) {
            $rate = $line['product_snapshot']['tax_rate']['rate'] ?? null;
            if ($rate !== null) {
                $line['tax_amount'] = self::bounded(bcdiv(bcmul($line['net_amount'], $rate, 8), '100', 4), 'lines.'.$index.'.tax_amount');
                if (($line['_expected_tax'] ?? null) !== null && $line['_expected_tax'] !== $line['tax_amount']) {
                    $this->error('lines.'.$index.'.tax_amount', 'Tributo difere da base final e referência selecionada.');
                }
                $line['product_snapshot']['tax_calculation'] = ['base_amount' => $line['net_amount'], 'rate_percent' => $rate, 'tax_amount' => $line['tax_amount'], 'source' => 'selected_commercial_rate', 'legal_applicability' => null];
            }
            unset($line['_expected_tax']);
        }
        unset($line);
        if (isset($input['tax_rate_percent'])) {
            if (bccomp($sum('tax_amount'), '0', 4) !== 0 || collect($lines)->contains(fn ($line) => $line['product_snapshot']['tax_rate'] !== null)) {
                $this->error('tax_rate_percent', 'Escolha tributo por linha ou documento.');
            }
            $rate = self::decimal($input['tax_rate_percent'], 'tax_rate_percent');
            $this->allocate($lines, bcdiv(bcmul($sum('net_amount'), $rate, 8), '100', 4), 'net_amount', 'tax_amount');
            foreach ($lines as &$line) {
                $line['product_snapshot']['header_tax'] = ['rate_percent' => $rate, 'source' => 'explicit_commercial_rate', 'legal_applicability' => null];
            }
            unset($line);
        }
        foreach ($lines as $index => &$line) {
            $line['line_total'] = self::bounded(bcadd($line['net_amount'], $line['tax_amount'], 4), 'lines.'.$index.'.line_total');
            $line['product_snapshot']['amounts'] = array_intersect_key($line, array_flip(['quantity', 'base_quantity', 'unit_conversion_factor', 'unit_price', 'gross_amount', 'net_amount', 'discount_amount', 'tax_amount', 'line_total']));
            unset($line['net_amount']);
        }
        unset($line);

        return ['subtotal_amount' => self::bounded($gross, 'subtotal_amount'), 'discount_amount' => self::bounded($sum('discount_amount'), 'discount_amount'), 'tax_amount' => self::bounded($sum('tax_amount'), 'tax_amount'), 'total_amount' => self::bounded($sum('line_total'), 'total_amount')];
    }

    private function allocate(array &$lines, string $amount, string $weight, string $target): void
    {
        $total = array_reduce($lines, fn ($sum, $line) => bcadd($sum, $line[$weight], 4), '0.0000');
        if (bccomp($total, '0', 4) === 0) {
            if (bccomp($amount, '0', 4) !== 0) {
                $this->error($target, 'Base vazia.');
            }

            return;
        }
        $cumulative = $allocated = '0.0000';
        foreach ($lines as &$line) {
            $cumulative = bcadd($cumulative, $line[$weight], 4);
            $slice = ErpStockDecimal::slice($amount, $total, $cumulative, $allocated);
            $line[$target] = $slice;
            $allocated = bcadd($allocated, $slice, 4);
        }
    }

    public function snapshot(Model $model): array
    {
        $context = CurrentCompany::fromRequest();
        $establishment = $model->establishment()->first();

        return ['version' => self::VERSION, 'company' => $context->company->only(['id', 'name', 'cnpj', 'razao_social', 'ie']), 'establishment' => $establishment?->only(['id', 'name', 'cnpj', 'address', 'ie', 'tax_regime']), 'party' => $model->party_snapshot, 'currency' => $model->currency, 'document' => $model->only(['id', 'erp_company_id', 'erp_establishment_id', 'erp_quote_id', 'erp_order_id', 'quote_no', 'order_no', 'invoice_no', 'draft_revision']), 'totals' => $model->only(['subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount']), 'lines' => $model->lines->map(fn (Model $line): array => $line->attributesToArray())->all(), 'header_adjustments' => ['discount' => $model->lines->first()?->product_snapshot['header_discount'] ?? null, 'tax' => $model->lines->first()?->product_snapshot['header_tax'] ?? null], 'fiscal_context' => ['model' => null, 'schema_version' => null, 'readiness' => 'unverified', 'issuer_resolution' => null]];
    }

    public function postedSnapshot(ErpSale $sale): array
    {
        abort_unless($sale->status === 'posted' && is_array($sale->commercial_snapshot) && isset($sale->commercial_snapshot['accepted']), response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));

        return $sale->commercial_snapshot;
    }

    public function acceptedSnapshot(Model $model, int $actorId): array
    {
        $snapshot = $model->commercial_snapshot;
        abort_unless(is_array($snapshot) && ($snapshot['version'] ?? null) === self::VERSION, response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
        $snapshot['accepted'] = ['actor_id' => $actorId, 'at' => now()->toISOString(), 'draft_revision' => $model->draft_revision];

        return $snapshot;
    }

    public function convert(Request $request, string $kind, int $id, array $input): array
    {
        $source = $this->resource($request, $kind, $id, true)->load('lines');
        abort_unless(in_array($source->status, ['draft', 'confirmed'], true), 409, 'Documento já convertido.');
        abort_unless($source->draft_revision !== null && $source->lines->isNotEmpty(), response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
        $this->assertRevision($source, $input);
        $targetClass = self::modelClass($kind === 'quote' ? 'order' : 'sale');
        abort_if($targetClass::where($kind === 'quote' ? 'erp_quote_id' : 'erp_order_id', $source->id)->exists(), response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
        $this->validateNewReferences($request, $source);
        $targetKind = $kind === 'quote' ? 'order' : 'sale';
        $class = self::modelClass($targetKind);
        $numberField = self::numberField($targetKind);
        $attributes = $source->only(['erp_company_id', 'erp_establishment_id', 'erp_contact_id', 'erp_price_group_id', 'erp_operation_nature_id', 'currency', 'subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount', 'party_snapshot', 'draft_input']);
        $attributes += [$numberField => app(ErpCommercialNumbers::class)->allocate($targetKind, $source->erp_company_id, $source->erp_establishment_id, $input[$numberField] ?? null), 'draft_revision' => 1, 'status' => $targetKind === 'order' ? 'confirmed' : 'draft', 'conversion_source_id' => $source->id, $kind === 'quote' ? 'erp_quote_id' : 'erp_order_id' => $source->id];
        if ($targetKind === 'sale') {
            $attributes['fiscal_status'] = 'none';
        }
        $child = $class::create($attributes);
        foreach ($source->lines as $line) {
            $values = $line->only(['ordinal', 'erp_product_variant_id', 'erp_unit_id', 'quantity', 'base_quantity', 'unit_price', 'unit_conversion_factor', 'gross_amount', 'discount_amount', 'tax_amount', 'line_total', 'product_snapshot', 'source_quote_line_id', 'source_quote_document_id', 'erp_company_id']);
            $values[$kind === 'quote' ? 'source_quote_line_id' : 'source_order_line_id'] = $line->id;
            $values[$kind === 'quote' ? 'source_quote_document_id' : 'source_order_document_id'] = $source->id;
            $child->lines()->create($values);
        }
        $child->load('lines');
        $snapshot = $source->commercial_snapshot;
        $snapshot['document'] = $child->only(['id', 'erp_company_id', 'erp_establishment_id', 'erp_quote_id', 'erp_order_id', 'quote_no', 'order_no', 'invoice_no', 'draft_revision']);
        $snapshot['lines'] = $child->lines->map(fn (Model $line): array => $line->attributesToArray())->all();
        $child->commercial_snapshot = $snapshot;
        $draftInput = $child->draft_input;
        foreach ($draftInput['lines'] as &$rawLine) {
            unset($rawLine['id']);
        }
        unset($rawLine);
        $child->draft_input = $draftInput;
        $child->save();
        $source->status = 'converted';
        $source->save();

        return [$source, $child];
    }

    public function validateNewReferences(Request $request, Model $model): void
    {
        app(ErpCatalogScope::class)->assertActiveEstablishment($model->erp_company_id, $model->erp_establishment_id);
        if ($model->erp_contact_id) {
            $contact = $this->active(ErpContact::class, $model->erp_company_id, $model->erp_contact_id, 'erp_contact_id');
            if (! $contact->is_customer) {
                $this->error('erp_contact_id', 'Contato não é cliente comercial.');
            }
        }
        if ($model->erp_price_group_id) {
            $this->active(ErpPriceGroup::class, $model->erp_company_id, $model->erp_price_group_id, 'erp_price_group_id');
        }
        if ($model->erp_operation_nature_id) {
            $this->scopedReference($request, ErpOperationNature::class, $model->erp_operation_nature_id, $model->erp_establishment_id, 'erp_operation_nature_id');
        }
        if ($model->commercial_snapshot['header_discount_rule_id'] ?? null) {
            $this->active(ErpDiscountRule::class, $model->erp_company_id, $model->commercial_snapshot['header_discount_rule_id'], 'erp_discount_rule_id');
        }
        if ($model->commercial_snapshot['manual_header_discount'] ?? false) {
            $this->capability($request, 'erp.sales.discount.override', 'discount');
        }
        foreach ($model->lines as $i => $line) {
            $snapshot = $line->product_snapshot ?? [];
            if ($snapshot['pricing']['manual_override'] ?? false) {
                $this->capability($request, 'erp.sales.price.override', 'lines.'.$i.'.unit_price');
            }
            if ($snapshot['manual_discount'] ?? false) {
                $this->capability($request, 'erp.sales.discount.override', 'lines.'.$i.'.discount_amount');
            }
            foreach (['discount_rule' => ErpDiscountRule::class, 'tax_rate' => ErpTaxRate::class] as $ref => $class) {
                if ($snapshot[$ref]['id'] ?? null) {
                    if ($ref === 'tax_rate') {
                        $this->scopedReference($request, $class, $snapshot[$ref]['id'], $model->erp_establishment_id, 'lines.'.$i.'.erp_tax_rate_id');
                    } else {
                        $this->active($class, $model->erp_company_id, $snapshot[$ref]['id'], 'lines.'.$i.'.erp_discount_rule_id');
                    }
                }
            }
            if ($snapshot['pricing']['customer_group']['id'] ?? null) {
                $this->active(ErpCustomerGroup::class, $model->erp_company_id, $snapshot['pricing']['customer_group']['id'], 'erp_contact_id');
            }
            if ($line->erp_product_variant_id) {
                app(ErpCatalogScope::class)->assertVariantBelongsToCompany($model->erp_company_id, $line->erp_product_variant_id, 'lines.'.$i.'.erp_product_variant_id');
            }
            if ($line->erp_unit_id) {
                $this->active(ErpUnit::class, $model->erp_company_id, $line->erp_unit_id, 'lines.'.$i.'.erp_unit_id');
            }
            foreach ($snapshot['unit_chain'] ?? [] as $unitReference) {
                $this->active(ErpUnit::class, $model->erp_company_id, $unitReference['id'], 'lines.'.$i.'.erp_unit_id');
            }
        }
    }

    public function assertRevision(Model $model, array $input): void
    {
        if (! array_key_exists('draft_revision', $input)) {
            $this->error('draft_revision', 'Informe a revisão aceita do rascunho.');
        }
        abort_unless(self::id($input['draft_revision'], 'draft_revision') === $model->draft_revision, 409, 'Rascunho alterado.');
    }

    public function serialize(Model $model, bool $full = true): array
    {
        $data = $model->only(['id', 'erp_establishment_id', 'erp_contact_id', 'erp_price_group_id', 'erp_quote_id', 'erp_order_id', 'quote_no', 'order_no', 'invoice_no', 'status', 'fiscal_status', 'currency', 'draft_revision', 'subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount']);
        if ($full) {
            $data += ['party_snapshot' => $model->party_snapshot, 'commercial_snapshot' => $model->commercial_snapshot, 'lines' => $model->lines()->orderBy('ordinal')->get()->map(fn (Model $line): array => $line->attributesToArray())->all()];
        }
        if ($full && $model instanceof ErpSale && $model->status === 'posted') {
            $data += app(ErpCommercialReturnService::class)->sourceProjection($model, 'sale');
        }
        $data['stock_reserved'] = false;
        $data['needs_reconciliation'] = $model->draft_revision === null || ($data['returnability']['needs_reconciliation'] ?? false) || ($data['finance']['needs_reconciliation'] ?? false);

        return $data;
    }

    public function capability(Request $request, string $capability, string $field): void
    {
        $context = CurrentCompany::fromRequest($request);
        $permissions = $context->accessKind === 'delegated' ? ($context->delegation?->effectivePermissions() ?? []) : $context->permissions();
        if (! in_array($capability, $permissions, true) && ! in_array('*', $permissions, true)) {
            $this->error($field, 'Permissão específica necessária.');
        }
        $context->assertPermission($capability, true);
    }

    private function active(string $class, int $company, int $id, string $field): Model
    {
        $model = $class::where('erp_company_id', $company)->where('status', 'active')->find($id);
        if (! $model) {
            $this->error($field, 'Referência ativa inválida para esta empresa.');
        }

        return $model;
    }

    private function scopedReference(Request $request, string $class, int $id, int $establishment, string $field): Model
    {
        $model = $this->active($class, CurrentCompany::fromRequest($request)->company->id, $id, $field);
        app(ErpEstablishmentAccess::class)->assertRelatedResource(CurrentCompany::fromRequest($request), $model, $field);
        if ($model->erp_establishment_id !== null && $model->erp_establishment_id !== $establishment) {
            $this->error($field, 'Referência de outro estabelecimento.');
        }

        return $model;
    }

    private function factor(Model $unit, int $baseId, int $company, string $field): string
    {
        $factor = '1.00000000';
        $seen = [];
        while ($unit->id !== $baseId) {
            if (in_array($unit->id, $seen, true) || ! $unit->base_unit_id) {
                $this->error($field, 'Unidade incompatível.');
            }
            $seen[] = $unit->id;
            $exact = bcmul($factor, $unit->conversion_factor, 16);
            $factor = bcadd($exact, '0', 8);
            if (bccomp($exact, $factor, 16) !== 0 || bccomp($factor, '0', 8) <= 0) {
                $this->error($field, 'Conversão não representável.');
            }
            $unit = $this->active(ErpUnit::class, $company, $unit->base_unit_id, $field);
        }

        return self::decimal($factor, $field, 8);
    }

    private function unitChain(?Model $unit, int $company, string $field): array
    {
        $chain = [];
        $seen = [];
        while ($unit !== null) {
            if (in_array($unit->id, $seen, true) || count($seen) >= 100) {
                $this->error($field, 'Cadeia de unidades inválida.');
            }
            $seen[] = $unit->id;
            $chain[] = $unit->only(['id', 'short_name', 'actual_name', 'allow_decimal', 'base_unit_id', 'conversion_factor']);
            $unit = $unit->base_unit_id ? $this->active(ErpUnit::class, $company, $unit->base_unit_id, $field) : null;
        }

        return $chain;
    }

    private function percentagePrice(string $base, string $rate, string $field): string
    {
        $price = bcadd($base, bcdiv(bcmul($base, $rate, 8), '100', 8), 4);
        if (bccomp($price, '0', 4) < 0) {
            $this->error($field, 'Ajuste produz preço negativo.');
        }

        return self::bounded($price, $field);
    }

    private function ruleAmount(Model $rule, string $gross, string $field): string
    {
        if (! empty($rule->conditions) || $rule->coupon_code !== null) {
            $this->error($field, 'Condições da regra não suportadas.');
        }

        return match ($rule->kind) {
            'fixed' => $rule->value, 'percentage' => bcdiv(bcmul($gross, $rule->value, 8), '100', 4), default => throw ValidationException::withMessages([$field => 'Tipo não suportado.'])
        };
    }

    private function error(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
