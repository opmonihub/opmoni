<?php

namespace App\Erp;

use App\Erp\Finance\ErpFinanceParticipant;
use App\Erp\Finance\ErpFinanceReadService;
use App\Erp\Sales\ErpCommercialDraftService;
use App\Erp\Stock\ErpStockDecimal;
use App\Erp\Stock\ErpStockLedger;
use App\Erp\Stock\ErpStockParticipant;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleReturn;
use App\Models\Erp\Sales\ErpSaleReturnLine;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpPurchaseReturn;
use App\Models\Erp\Stock\ErpPurchaseReturnLine;
use App\Models\Erp\Stock\ErpStockMovement;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ErpCommercialReturnService
{
    public static function modelClass(string $kind): string
    {
        return $kind === 'sale_return' ? ErpSaleReturn::class : ErpPurchaseReturn::class;
    }

    public function scopeIds(Model $model, string $kind): array
    {
        $source = $kind === 'sale_return' ? ErpSale::where('erp_company_id', $model->erp_company_id)->findOrFail($model->erp_sale_id) : ErpPurchase::where('erp_company_id', $model->erp_company_id)->findOrFail($model->erp_purchase_id);

        return array_values(array_unique([$model->erp_establishment_id, ...($kind === 'sale_return' ? app(ErpCommercialDraftService::class)->scopeIds($source) : [$source->erp_establishment_id])]));
    }

    public function scopedQuery(Request $request, string $kind): Builder
    {
        $context = CurrentCompany::fromRequest($request);
        $class = self::modelClass($kind);
        $query = $class::where('erp_company_id', $context->company->id);
        $allowed = app(ErpEstablishmentAccess::class)->allowedIds($context);
        if ($allowed !== null) {
            $query->whereIn('erp_establishment_id', $allowed);
        }
        if ($kind === 'sale_return') {
            $query->whereHas('sale', fn ($source) => $source->whereIn('id', app(ErpCommercialDraftService::class)->scopedQuery($request, 'sale')->select('id')));
        } else {
            $query->whereHas('purchase', function ($source) use ($context, $allowed): void {
                $source->where('erp_company_id', $context->company->id);
                if ($allowed !== null) {
                    $source->whereIn('erp_establishment_id', $allowed);
                }
            });
        }

        return $query;
    }

    public function resource(Request $request, string $kind, int $id, bool $lock = false): Model
    {
        $class = self::modelClass($kind);
        $context = CurrentCompany::fromRequest($request);
        $query = $class::where('erp_company_id', $context->company->id);
        $model = ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
        app(ErpEstablishmentAccess::class)->assertResource($context, $model);
        $this->source($request, $kind, $model[$this->sourceField($kind)], false);

        return $model;
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \InvalidArgumentException('Commercial return must join the caller transaction.');
        }
    }

    private function sourceField(string $kind): string
    {
        return $kind === 'sale_return' ? 'erp_sale_id' : 'erp_purchase_id';
    }

    private function source(Request $request, string $kind, int $id, bool $related): Model
    {
        $class = $kind === 'sale_return' ? ErpSale::class : ErpPurchase::class;
        $context = CurrentCompany::fromRequest($request);
        $source = $class::where('erp_company_id', $context->company->id)->lockForUpdate()->find($id);
        if (! $source && $related) {
            throw ValidationException::withMessages([$this->sourceField($kind) => 'Origem inválida para esta empresa.']);
        }
        abort_unless($source, 404);
        if ($related) {
            app(ErpEstablishmentAccess::class)->assertRelatedResource($context, $source, $this->sourceField($kind));
        } else {
            app(ErpEstablishmentAccess::class)->assertResource($context, $source);
        }
        $source->setRelation('lines', $source->lines()->orderBy('id')->lockForUpdate()->get());

        if ($kind === 'sale_return') {
            app(ErpCommercialDraftService::class)->resource($request, 'sale', $source->id);
        }

        return $source;
    }

    public function store(Request $request, string $kind, array $body): Model
    {
        $this->assertTransaction();
        $sourceField = $this->sourceField($kind);
        $data = Validator::make($body, [$sourceField => ['required'], 'reason' => ['required', 'string', 'max:255'], 'lines' => ['required', 'array', 'list', 'min:1', 'max:100']])->validate();
        $source = $this->source($request, $kind, ErpCommercialDraftService::id($body[$sourceField], $sourceField), true);
        abort_unless($source->status === 'posted', 422, 'Origem precisa estar lançada.');
        app(ErpCatalogScope::class)->assertActiveEstablishment($source->erp_company_id, $source->erp_establishment_id);
        [$lines, $totals] = $this->calculate($kind, $source, $body['lines']);
        foreach (['expected_total', 'total_amount'] as $assertion) {
            if (isset($body[$assertion]) && ErpCommercialDraftService::decimal($body[$assertion], $assertion) !== $totals['total_amount']) {
                throw ValidationException::withMessages([$assertion => 'Total difere das linhas restantes.']);
            }
        }
        $class = self::modelClass($kind);
        $partySnapshot = $source instanceof ErpPurchase ? $source->supplier_snapshot : $source->party_snapshot;
        $model = $class::create($totals + [$sourceField => $source->id, 'erp_company_id' => $source->erp_company_id, 'erp_establishment_id' => $source->erp_establishment_id, 'currency' => $source->currency, 'status' => 'draft', 'reason' => $data['reason'], 'commercial_snapshot' => ['version' => ErpCommercialDraftService::VERSION, 'party' => $partySnapshot, 'source_total' => $source->total_amount]]);
        foreach ($lines as $line) {
            $model->lines()->create($line);
        }

        return $model->load('lines');
    }

    private function calculate(string $kind, Model $source, array $requested, ?int $ownReturnId = null): array
    {
        $returnClass = self::modelClass($kind);
        $returnLineClass = $kind === 'sale_return' ? ErpSaleReturnLine::class : ErpPurchaseReturnLine::class;
        $returnParent = $kind === 'sale_return' ? 'erp_sale_return_id' : 'erp_purchase_return_id';
        $sourceField = $this->sourceField($kind);
        $posted = $returnClass::where($sourceField, $source->id)->where('erp_company_id', $source->erp_company_id)->where('status', 'posted')->get();
        $reason = $this->historyReason($kind, $source, false);
        abort_if($reason !== null, response()->json(['code' => 'legacy_operation_requires_reconciliation', 'reason' => $reason], 409));
        $seen = [];
        $lines = [];
        $totals = ['subtotal_amount' => '0.0000', 'discount_amount' => '0.0000', 'tax_amount' => '0.0000', 'total_amount' => '0.0000'];
        foreach ($requested as $index => $input) {
            if (! is_array($input)) {
                throw ValidationException::withMessages(['lines.'.$index => 'Linha inválida.']);
            }
            $field = 'lines.'.$index;
            $id = ErpCommercialDraftService::id($input['source_line_id'] ?? null, $field.'.source_line_id');
            $line = $source->lines->firstWhere('id', $id);
            if (! $line || in_array($id, $seen, true)) {
                throw ValidationException::withMessages([$field.'.source_line_id' => 'Linha de origem inválida ou duplicada.']);
            }
            $seen[] = $id;
            $quantity = ErpCommercialDraftService::decimal($input['quantity'] ?? null, $field.'.quantity');
            $accepted = $returnLineClass::where('source_line_id', $id)->whereIn($returnParent, $posted->pluck('id'))->orderBy('id')->get();
            $already = ['quantity' => '0.0000', 'discount_amount' => '0.0000', 'tax_amount' => '0.0000', 'total_amount' => '0.0000'];
            foreach ($accepted as $prior) {
                foreach ($already as $key => $value) {
                    $already[$key] = bcadd($value, $prior[$key], 4);
                }
            }
            $cumulative = bcadd($already['quantity'], $quantity, 4);
            if (bccomp($quantity, '0', 4) <= 0 || bccomp($cumulative, $line->quantity, 4) > 0) {
                throw ValidationException::withMessages([$field.'.quantity' => 'Quantidade excede o saldo devolvível.']);
            }
            $components = $line->product_snapshot['amounts'] ?? [];
            $discount = $line->discount_amount ?? $components['discount_amount'] ?? '0.0000';
            $tax = $line->tax_amount ?? $components['tax_amount'] ?? '0.0000';
            abort_if(isset($components['line_total']) && bccomp($components['line_total'], $line->line_total, 4) !== 0, response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
            $net = bcsub($line->line_total, $tax, 4);
            abort_if(bccomp($net, '0', 4) < 0 || bccomp($discount, '0', 4) < 0 || bccomp($tax, '0', 4) < 0 || bccomp($line->quantity, '0', 4) <= 0, response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
            $pieceNet = ErpStockDecimal::slice($net, $line->quantity, $cumulative, bcsub($already['total_amount'], $already['tax_amount'], 4));
            $pieceDiscount = ErpStockDecimal::slice($discount, $line->quantity, $cumulative, $already['discount_amount']);
            $pieceTax = ErpStockDecimal::slice($tax, $line->quantity, $cumulative, $already['tax_amount']);
            $components = ['subtotal_amount' => bcadd($pieceNet, $pieceDiscount, 4), 'discount_amount' => $pieceDiscount, 'tax_amount' => $pieceTax, 'total_amount' => bcadd($pieceNet, $pieceTax, 4)];
            foreach ($components as $key => $value) {
                $totals[$key] = ErpCommercialDraftService::bounded(bcadd($totals[$key], $value, 4), $key);
            }
            $factor = $line->unit_conversion_factor ?? '1.00000000';
            $lines[] = $components + ['erp_company_id' => $source->erp_company_id, 'source_document_id' => $source->id, 'source_line_id' => $id, 'quantity' => $quantity, 'base_quantity' => ErpStockDecimal::base($quantity, $factor, $field.'.quantity'), 'unit_conversion_factor' => $factor, 'source_snapshot' => ['version' => ErpCommercialDraftService::VERSION, 'line' => $line->attributesToArray(), 'cumulative_quantity' => $cumulative, 'source_quantity' => $line->quantity]];
        }

        return [$lines, $totals];
    }

    public function post(Request $request, string $kind, int $id, array $body, string $key): Model
    {
        $this->assertTransaction();
        $reference = $this->resource($request, $kind, $id);
        $source = $this->source($request, $kind, $reference[$this->sourceField($kind)], false);
        $model = $this->resource($request, $kind, $id, true)->load('lines');
        abort_unless($model->status === 'draft', 409, 'Devolução já lançada.');
        abort_unless($source->status === 'posted' && $model->lines->isNotEmpty(), response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
        app(ErpCatalogScope::class)->assertActiveEstablishment($source->erp_company_id, $source->erp_establishment_id);
        [$lines, $totals] = $this->calculate($kind, $source, $model->lines->map(fn (Model $line): array => $line->only(['source_line_id', 'quantity']))->all(), $model->id);
        if (isset($body['expected_total']) && ErpCommercialDraftService::decimal($body['expected_total'], 'expected_total') !== $totals['total_amount']) {
            throw ValidationException::withMessages(['expected_total' => 'Saldo residual alterado.']);
        }
        foreach ($lines as $line) {
            $model->lines()->where('source_line_id', $line['source_line_id'])->firstOrFail()->update($line);
        }
        $model->fill($totals)->save();
        $sourceType = $kind === 'sale_return' ? 'erp_sale' : 'erp_purchase';
        foreach ($lines as $line) {
            $originalLine = $source->lines->firstWhere('id', $line['source_line_id']);
            if (! $originalLine->erp_product_variant_id) {
                continue;
            }
            $movements = ErpStockMovement::where('erp_company_id', $source->erp_company_id)->where('erp_establishment_id', $source->erp_establishment_id)->where('source_type', $sourceType)->where('source_id', $source->id)->where('source_line_id', $originalLine->id)->whereNull('reversal_of_id')->orderBy('id')->get();
            $wasStock = ErpStockParticipant::physicalProfile($originalLine->product_snapshot['product'] ?? null);
            if ($movements->isEmpty()) {
                if ($wasStock !== false) {
                    abort(response()->json(['code' => 'legacy_operation_requires_reconciliation', 'message' => 'Movimento original sem proveniência.'], 409));
                }

                continue;
            }
            abort_unless($movements->count() === 1, response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
            app(ErpStockLedger::class)->reverse($movements->sole(), $line['base_quantity'], $model->reason, $key.'-line-'.$originalLine->id, ['source_type' => 'erp_'.$kind, 'source_id' => $model->id, 'source_line_id' => $originalLine->id, 'posted_by_user_id' => $request->user()->id]);
        }
        $credit = null;
        if (bccomp($totals['total_amount'], '0', 4) > 0) {
            $obligations = ErpObligation::where('erp_company_id', $source->erp_company_id)->where('source_type', $sourceType)->where('source_id', $source->id)->lockForUpdate()->get();
            abort_unless($obligations->count() === 1, response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
            $this->assertFinanceHistory($kind, $source, $obligations->sole());
            $credit = app(ErpFinanceParticipant::class)->postReturnCredit($source->erp_company_id, $obligations->sole()->id, 'erp_'.$kind, $model->id, $totals['total_amount'], $key.'-credit', $request->user()->id);
        }
        $model->load('lines');
        foreach ($model->lines as $acceptedLine) {
            $acceptedLine->source_snapshot = $acceptedLine->source_snapshot + ['accepted' => ['actor_id' => $request->user()->id, 'at' => now()->toISOString(), 'operation_key' => $key]];
            $acceptedLine->save();
        }
        $model->commercial_snapshot = ($model->commercial_snapshot ?? []) + ['credit_id' => $credit?->id, 'financial_disposition' => $credit ? 'opposite_credit' : 'zero_consideration'];
        $model->status = 'posted';
        $model->posted_by_user_id = $request->user()->id;
        $model->posted_at = now();
        $model->source_operation_key = $key;
        $model->save();

        return $model->fresh('lines');
    }

    private function assertFinanceHistory(string $kind, Model $source, ErpObligation $original): void
    {
        $reason = $this->historyReason($kind, $source, true);
        abort_if($reason !== null, response()->json(['code' => 'legacy_operation_requires_reconciliation', 'reason' => $reason], 409));
    }

    /** A single proof is shared by accepted mutations and truthful read projections. */
    private function historyReason(string $kind, Model $source, bool $finance): ?string
    {
        $source->loadMissing('lines');
        if ($source->lines->isEmpty()) {
            return 'source_lines_missing';
        }
        $sums = ['subtotal_amount' => '0.0000', 'discount_amount' => '0.0000', 'tax_amount' => '0.0000', 'total_amount' => '0.0000'];
        foreach ($source->lines as $line) {
            $saved = $line->product_snapshot['amounts'] ?? [];
            $discount = $line->discount_amount ?? $saved['discount_amount'] ?? '0.0000';
            $tax = $line->tax_amount ?? $saved['tax_amount'] ?? '0.0000';
            $net = bcsub($line->line_total, $tax, 4);
            $gross = bcadd($net, $discount, 4);
            if (bccomp($line->quantity, '0', 4) <= 0 || bccomp($net, '0', 4) < 0 || bccomp($discount, '0', 4) < 0 || bccomp($tax, '0', 4) < 0 || ($line->gross_amount !== null && bccomp($line->gross_amount, $gross, 4) !== 0) || (isset($saved['line_total']) && bccomp($saved['line_total'], $line->line_total, 4) !== 0)) {
                return 'source_line_components_unproved';
            }
            foreach (['subtotal_amount' => $gross, 'discount_amount' => $discount, 'tax_amount' => $tax, 'total_amount' => $line->line_total] as $field => $value) {
                $sums[$field] = bcadd($sums[$field], $value, 4);
            }
        }
        if (bccomp($sums['total_amount'], $source->total_amount, 4) !== 0) {
            return 'source_total_unproved';
        }
        if ($kind === 'sale_return') {
            foreach (['subtotal_amount', 'discount_amount', 'tax_amount'] as $field) {
                if ($source[$field] === null || bccomp($sums[$field], $source[$field], 4) !== 0) {
                    return 'source_header_components_unallocated';
                }
            }
        }
        $returnClass = self::modelClass($kind);
        $returns = $returnClass::where('erp_company_id', $source->erp_company_id)->where($this->sourceField($kind), $source->id)->where('status', 'posted')->with('lines')->orderBy('id')->get();
        $returned = [];
        foreach ($returns as $return) {
            if ($return->lines->isEmpty()) {
                return 'legacy_return_lines_missing';
            }
            $total = $discountTotal = $taxTotal = $grossTotal = '0.0000';
            foreach ($return->lines->sortBy('id') as $piece) {
                $line = $source->lines->firstWhere('id', $piece->source_line_id);
                if (! $line || bccomp($piece->quantity, '0', 4) <= 0) {
                    return 'return_source_line_unproved';
                }
                $cursor = $returned[$line->id] ?? ['quantity' => '0.0000', 'discount_amount' => '0.0000', 'tax_amount' => '0.0000', 'net_amount' => '0.0000'];
                $cumulative = bcadd($cursor['quantity'], $piece->quantity, 4);
                if (bccomp($cumulative, $line->quantity, 4) > 0) {
                    return 'return_quantity_unproved';
                }
                $netPiece = bcsub($piece->total_amount, $piece->tax_amount, 4);
                if (bccomp($netPiece, '0', 4) < 0 || bccomp($piece->discount_amount, '0', 4) < 0 || bccomp($piece->tax_amount, '0', 4) < 0 || bccomp($piece->subtotal_amount, bcadd($netPiece, $piece->discount_amount, 4), 4) !== 0) {
                    return 'return_line_components_unproved';
                }
                $returned[$line->id] = ['quantity' => $cumulative, 'discount_amount' => bcadd($cursor['discount_amount'], $piece->discount_amount, 4), 'tax_amount' => bcadd($cursor['tax_amount'], $piece->tax_amount, 4), 'net_amount' => bcadd($cursor['net_amount'], $netPiece, 4)];
                $total = bcadd($total, $piece->total_amount, 4);
                $discountTotal = bcadd($discountTotal, $piece->discount_amount, 4);
                $taxTotal = bcadd($taxTotal, $piece->tax_amount, 4);
                $grossTotal = bcadd($grossTotal, $piece->subtotal_amount, 4);
            }
            foreach (['total_amount' => $total, 'discount_amount' => $discountTotal, 'tax_amount' => $taxTotal, 'subtotal_amount' => $grossTotal] as $field => $sum) {
                if ($return[$field] === null || bccomp($sum, $return[$field], 4) !== 0) {
                    return 'return_total_unproved';
                }
            }
        }
        // Draft IDs do not define posting order; prove aggregate cumulative components.
        foreach ($returned as $sourceLineId => $cursor) {
            $line = $source->lines->firstWhere('id', $sourceLineId);
            $saved = $line->product_snapshot['amounts'] ?? [];
            $tax = $line->tax_amount ?? $saved['tax_amount'] ?? '0.0000';
            foreach (['net_amount' => bcsub($line->line_total, $tax, 4), 'discount_amount' => $line->discount_amount ?? $saved['discount_amount'] ?? '0.0000', 'tax_amount' => $tax] as $component => $total) {
                if (bccomp($cursor[$component], ErpStockDecimal::slice($total, $line->quantity, $cursor['quantity'], '0.0000'), 4) !== 0) {
                    return 'return_line_components_unproved';
                }
            }
        }
        if (! $finance) {
            return null;
        }
        $sourceType = $kind === 'sale_return' ? 'erp_sale' : 'erp_purchase';
        $obligations = ErpObligation::where('erp_company_id', $source->erp_company_id)->where('source_type', $sourceType)->where('source_id', $source->id)->get();
        if (bccomp($source->total_amount, '0', 4) === 0 && $obligations->isEmpty()) {
            return null;
        }
        if ($obligations->count() !== 1) {
            return 'source_obligation_ambiguous';
        }
        $original = $obligations->sole();
        if ($original->direction !== ($kind === 'sale_return' ? 'receivable' : 'payable') || $original->erp_establishment_id !== $source->erp_establishment_id || bccomp($original->amount, $source->total_amount, 4) !== 0 || $original->currency !== $source->currency || bccomp($original->open_amount, '0', 4) < 0 || bccomp($original->open_amount, $original->amount, 4) > 0) {
            return 'source_obligation_incoherent';
        }
        $credits = ErpObligation::where('erp_company_id', $source->erp_company_id)->where('return_of_id', $original->id)->get();
        foreach ($credits as $credit) {
            $return = $returns->firstWhere('id', $credit->source_id);
            if ($credit->source_type !== 'erp_'.$kind || ! $return || $credit->currency !== $original->currency || $credit->direction !== ($original->direction === 'receivable' ? 'payable' : 'receivable') || $credit->erp_establishment_id !== $original->erp_establishment_id || bccomp($credit->amount, $return->total_amount, 4) !== 0 || bccomp($credit->amount, '0', 4) <= 0 || bccomp($credit->open_amount, '0', 4) < 0 || bccomp($credit->open_amount, $credit->amount, 4) > 0) {
                return 'return_credit_unproved';
            }
        }
        foreach ($returns as $return) {
            $matches = $credits->where('source_type', 'erp_'.$kind)->where('source_id', $return->id);
            if (bccomp($return->total_amount, '0', 4) === 0 ? $matches->isNotEmpty() : $matches->count() !== 1) {
                return 'return_credit_missing_or_ambiguous';
            }
        }

        return null;
    }

    /** Current projections never change the accepted source or its paid history. */
    public function sourceProjection(Model $source, string $kind): array
    {
        $reason = $this->historyReason($kind.'_return', $source, true);
        $quantityReason = $this->historyReason($kind.'_return', $source, false);
        $returnClass = self::modelClass($kind.'_return');
        $lineClass = $kind === 'sale' ? ErpSaleReturnLine::class : ErpPurchaseReturnLine::class;
        $postedIds = $returnClass::where('erp_company_id', $source->erp_company_id)->where('erp_'.$kind.'_id', $source->id)->where('status', 'posted')->pluck('id');
        $lines = [];
        foreach ($source->lines()->orderBy('id')->get() as $line) {
            $returned = '0.0000';
            foreach ($lineClass::where('erp_company_id', $source->erp_company_id)->where('source_line_id', $line->id)->whereIn('erp_'.$kind.'_return_id', $postedIds)->get() as $piece) {
                $returned = bcadd($returned, $piece->quantity, 4);
            }
            $lines[] = ['source_line_id' => $line->id, 'quantity' => $line->quantity, 'returned_quantity' => $quantityReason === null ? $returned : null, 'remaining_quantity' => $quantityReason === null ? bcsub($line->quantity, $returned, 4) : null, 'unit_conversion_factor' => $line->unit_conversion_factor, 'line_total' => $line->line_total, 'physical_eligibility' => 'validated_at_post'];
        }
        $obligations = ErpObligation::where('erp_company_id', $source->erp_company_id)->where('source_type', 'erp_'.$kind)->where('source_id', $source->id)->get();
        $finance = ['currency' => $source->currency, 'original_obligation_id' => null, 'gross_original_due' => null, 'opposite_return_credit' => null, 'open_return_credit' => null, 'net_exposure' => null, 'needs_reconciliation' => $reason !== null, 'reconciliation_reason' => $reason, 'automatic_setoff' => false, 'automatic_refund' => false];
        if ($reason === null && $obligations->count() === 1) {
            $original = $obligations->sole();
            app(ErpFinanceReadService::class)->resource(request(), ErpObligation::class, $original->id);
            $principal = $open = '0.0000';
            $links = [];
            foreach (ErpObligation::where('erp_company_id', $source->erp_company_id)->where('return_of_id', $original->id)->orderBy('id')->get() as $credit) {
                app(ErpFinanceReadService::class)->resource(request(), ErpObligation::class, $credit->id);
                abort_unless($credit->currency === $original->currency, 409, 'Moedas vinculadas exigem conciliação.');
                $principal = bcadd($principal, $credit->amount, 4);
                $open = bcadd($open, $credit->open_amount, 4);
                $links[] = ['id' => $credit->id, 'source_type' => $credit->source_type, 'source_id' => $credit->source_id, 'amount' => $credit->amount, 'open_amount' => $credit->open_amount, 'due_on' => $credit->due_on];
            }
            $net = bcsub($original->open_amount, $open, 4);
            $finance = array_replace($finance, ['original_obligation_id' => $original->id, 'gross_original_due' => $original->open_amount, 'original_principal' => $original->amount, 'opposite_return_credit' => $principal, 'open_return_credit' => $open, 'net_exposure' => $original->direction === 'receivable' ? $net : bcsub('0', $net, 4), 'return_credits' => $links, 'original_due_on' => $original->due_on, 'needs_reconciliation' => false]);
        } elseif ($reason === null && bccomp($source->total_amount, '0', 4) === 0 && $obligations->isEmpty()) {
            $finance = array_replace($finance, ['gross_original_due' => '0.0000', 'opposite_return_credit' => '0.0000', 'open_return_credit' => '0.0000', 'net_exposure' => '0.0000', 'needs_reconciliation' => false]);
        }

        return ['needs_reconciliation' => $reason !== null, 'reconciliation_reason' => $reason, 'returnability' => ['needs_reconciliation' => $quantityReason !== null, 'reconciliation_reason' => $quantityReason], 'returnable_lines' => $lines, 'finance' => $finance];
    }

    public function serialize(Model $model, bool $full = true): array
    {
        $data = $model->only(['id', 'erp_sale_id', 'erp_purchase_id', 'erp_establishment_id', 'status', 'currency', 'reason', 'subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount']);
        $data += ['credit_id' => $model->commercial_snapshot['credit_id'] ?? null, 'financial_disposition' => $model->commercial_snapshot['financial_disposition'] ?? null];
        if ($full) {
            $data += ['commercial_snapshot' => $model->commercial_snapshot, 'lines' => $model->lines()->orderBy('id')->get()->map(fn (Model $line): array => $line->attributesToArray())->all()];
        }
        if ($full) {
            $sourceClass = $model instanceof ErpSaleReturn ? ErpSale::class : ErpPurchase::class;
            $sourceKind = $model instanceof ErpSaleReturn ? 'sale' : 'purchase';
            $source = $sourceClass::where('erp_company_id', $model->erp_company_id)->findOrFail($model['erp_'.$sourceKind.'_id']);
            $data += $this->sourceProjection($source, $sourceKind);
        }

        return $data;
    }
}
