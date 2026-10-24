<?php

namespace App\Erp\Sales;

use App\Erp\ErpCompanyAuthorization;
use App\Erp\ErpOperationNatureRules;
use App\Models\Erp\ErpProductVariant;
use App\Support\AccessSession;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ErpCommercialTaxReview
{
    public function __construct(private ErpCommercialTaxSnapshot $tax, private ErpCommercialDraftService $drafts) {}

    public function assertAuthority(Request $request): void
    {
        AccessSession::assertOwnership($request);
        $context = CurrentCompany::fromRequest($request);
        $company = $context->company->fresh();
        $access = app(ErpCompanyAuthorization::class)->resolveAccess($request->user(), $company, $context->delegatedOfficeId, true);
        abort_unless($access !== null && $access['kind'] === $context->accessKind, 403, 'Permissão insuficiente');
        if ($context->accessKind === 'delegated') {
            abort_unless((int) $access['delegation']->id === (int) $context->delegation?->id, 403, 'Permissão insuficiente');
        }
        $fresh = new CurrentCompany($company, $access['kind'], $access['membership'] ?? null, $access['delegation'] ?? null, $access['office_id'] ?? null, $access['office_role'] ?? null);
        $fresh->assertPermission((string) $request->route()?->getName(), true);
        if ($context->membership !== null && isset($access['membership'])) {
            $context->membership->setRawAttributes($access['membership']->getAttributes(), true);
        }
        if ($context->delegation !== null && isset($access['delegation'])) {
            $context->delegation->setRawAttributes($access['delegation']->getAttributes(), true);
        }
    }

    public function preview(Request $request, string $kind, int $id): array
    {
        return DB::transaction(function () use ($request, $kind, $id): array {
            $this->assertAuthority($request);
            if (array_diff(array_keys($request->all()), ['draft_revision', 'line_ids', 'tax_context', 'lines']) !== []) {
                throw ValidationException::withMessages(['operation' => 'Conteúdo não suportado.']);
            }
            $model = $this->source($request, $kind, $id, $request->all());
            $options = $this->options($request->all(), $model);
            $rows = $this->candidates($request, $model, $options);
            $proof = ['version' => 1, 'binding' => $this->binding($request, $kind, $model), 'options' => $options, 'candidate_hash' => $this->digest($rows)];
            $encoded = base64_encode($this->json($proof));
            $this->assertAuthority($request);

            return ['reviewed_fingerprint' => $encoded.'.'.$this->sign($encoded), 'draft_revision' => $model->draft_revision, 'lines' => $rows];
        });
    }

    public function apply(Request $request, string $kind, int $id, array $input): Model
    {
        $this->assertAuthority($request);
        $unexpected = array_diff(array_keys($input), ['draft_revision', 'tax_refresh', 'expected_total', 'total_amount', 'subtotal_amount', 'discount_amount', 'tax_amount']);
        if ($unexpected !== []) {
            throw ValidationException::withMessages(['tax_refresh' => 'Revise a classificação separadamente das demais alterações do documento.']);
        }
        $data = Validator::make($input, ['tax_refresh' => ['required', 'array:reviewed_fingerprint,line_ids,tax_context,lines'], 'tax_refresh.reviewed_fingerprint' => ['required', 'string', 'max:65536']])->validate();
        $review = $data['tax_refresh'];
        $model = $this->source($request, $kind, $id, $input);
        $options = $this->options($review, $model);
        $token = $review['reviewed_fingerprint'];
        $parts = explode('.', $token);
        $this->conflict(count($parts) === 2 && hash_equals($this->sign($parts[0]), $parts[1]));
        $decoded = base64_decode($parts[0], true);
        $proof = $decoded === false ? null : json_decode($decoded, true);
        $this->conflict(is_array($proof) && ($proof['version'] ?? null) === 1
            && $this->json($proof['binding'] ?? []) === $this->json($this->binding($request, $kind, $model))
            && $this->json($proof['options'] ?? []) === $this->json($options));
        try {
            $rows = $this->candidates($request, $model, $options);
        } catch (ValidationException) {
            $this->conflict(false);
        }
        $this->conflict(hash_equals($proof['candidate_hash'] ?? '', $this->digest($rows)));
        $this->drafts->validateNewReferences($request, $model);
        foreach (['expected_total' => 'total_amount', 'total_amount' => 'total_amount', 'subtotal_amount' => 'subtotal_amount', 'discount_amount' => 'discount_amount', 'tax_amount' => 'tax_amount'] as $key => $field) {
            if (array_key_exists($key, $input) && ErpCommercialDraftService::decimal($input[$key], $key) !== $model->$field) {
                throw ValidationException::withMessages([$key => 'Total difere das linhas aceitas.']);
            }
        }
        foreach ($rows as $row) {
            $this->assertAuthority($request);
            $model->lines()->whereKey($row['id'])->firstOrFail()->update(['product_snapshot' => $row['product_snapshot']]);
        }
        $this->assertAuthority($request);
        $model->draft_revision++;
        $savedInput = $model->draft_input ?? [];
        if (array_key_exists('tax_context', $options)) {
            $savedInput['tax_context'] = $options['tax_context'];
        }
        $model->draft_input = $savedInput;
        $snapshot = $model->commercial_snapshot;
        $snapshot['document']['draft_revision'] = $model->draft_revision;
        $snapshot['lines'] = $model->fresh('lines')->lines->map(fn (Model $line): array => $line->attributesToArray())->all();
        $snapshot['version'] = ErpCommercialSnapshotVersions::documentVersion($snapshot['lines']);
        $model->commercial_snapshot = $snapshot;
        $model->save();
        $this->assertAuthority($request);

        return $model->fresh('lines');
    }

    private function source(Request $request, string $kind, int $id, array $input): Model
    {
        $model = $this->drafts->resource($request, $kind, $id, true);
        abort_unless(in_array($model->status, ['draft', 'confirmed'], true), 409, 'Documento já consumido ou lançado.');
        abort_unless($model->draft_revision !== null, response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
        $this->drafts->assertRevision($model, $input);
        $model->setRelation('lines', $model->lines()->orderBy('ordinal')->lockForUpdate()->get());
        abort_unless($model->lines->isNotEmpty() && is_array($model->commercial_snapshot) && ErpCommercialSnapshotVersions::valid($model->commercial_snapshot), response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));

        return $model;
    }

    private function options(array $input, Model $model): array
    {
        $data = Validator::make($input, [
            'line_ids' => ['sometimes', 'array', 'list', 'min:1', 'max:100'],
            'line_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'tax_context' => ['sometimes', 'array:origin_uf,destination_uf,model,purpose,final_consumer,presence'],
            'lines' => ['sometimes', 'array', 'list', 'max:100'],
            'lines.*' => ['array:id,cfop'], 'lines.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.cfop' => ['sometimes', 'nullable', 'string', 'regex:/\A[123567][0-9]{3}\z/'],
        ])->validate();
        $owned = $model->lines->pluck('id')->all();
        $selected = array_map(intval(...), $data['line_ids'] ?? $owned);
        if (array_diff($selected, $owned) !== []) {
            throw ValidationException::withMessages(['line_ids' => 'Selecione somente linhas deste documento.']);
        }
        sort($selected);
        $data['line_ids'] = $selected;
        if (array_key_exists('tax_context', $data) && count($selected) !== count($owned)) {
            throw ValidationException::withMessages(['tax_context' => 'Revise todas as linhas ao alterar o contexto do documento.']);
        }
        $changes = [];
        foreach ($data['lines'] ?? [] as $index => $line) {
            if (! in_array((int) $line['id'], $selected, true)) {
                throw ValidationException::withMessages(['lines.'.$index.'.id' => 'Linha fora da seleção revisada.']);
            }
            $line['id'] = (int) $line['id'];
            $changes[] = $line;
        }
        usort($changes, fn ($a, $b): int => $a['id'] <=> $b['id']);
        $data['lines'] = $changes;

        return $data;
    }

    private function candidates(Request $request, Model $model, array $options): array
    {
        $context = CurrentCompany::fromRequest($request);
        $selected = $model->lines->whereIn('id', $options['line_ids']);
        $this->tax->lock($context, $selected->pluck('erp_product_variant_id')->filter()->all(), array_filter([$model->erp_operation_nature_id]));
        $nature = $model->erp_operation_nature_id === null ? null : app(ErpOperationNatureRules::class)->nature($context, $model->erp_operation_nature_id, related: true, lock: true);
        $actual = $this->tax->context(array_replace($model->draft_input ?? [], array_intersect_key($options, ['tax_context' => true])), $model->erp_establishment_id);
        $changes = collect($options['lines'])->keyBy('id');
        $rows = [];
        foreach ($selected as $index => $line) {
            $variant = $line->erp_product_variant_id === null ? null : ErpProductVariant::query()->whereKey($line->erp_product_variant_id)->where('status', 'active')->whereHas('product', fn ($query) => $query->where('erp_company_id', $context->company->id)->where('status', 'active'))->first();
            if ($line->erp_product_variant_id !== null && $variant === null) {
                throw ValidationException::withMessages(['lines.'.$index.'.erp_product_variant_id' => 'Produto ativo inválido para esta empresa.']);
            }
            $previous = $line->product_snapshot;
            $explicit = $this->tax->explicit($changes->get($line->id, []), $previous, 'lines.'.$index);
            $snapshot = $this->tax->project($context, $variant?->product, $nature, $previous, $actual, $explicit, 'lines.'.$index);
            $rows[] = ['id' => $line->id, 'previous_product_snapshot' => $previous, 'product_snapshot' => $snapshot];
        }

        return $rows;
    }

    private function binding(Request $request, string $kind, Model $model): array
    {
        $context = CurrentCompany::fromRequest($request);

        return ['company' => $context->company->id, 'actor' => $request->user()->id,
            'access' => $context->accessKind, 'office' => $context->delegatedOfficeId, 'delegation' => $context->delegation?->id,
            'session' => hash('sha256', $request->session()->getId()), 'kind' => $kind, 'source' => $model->id,
            'draft_revision' => $model->draft_revision, 'source_hash' => $this->digest([$model->commercial_snapshot, $model->draft_input, $model->lines->map(fn (Model $line): array => $line->attributesToArray())->all()])];
    }

    private function sign(string $encoded): string
    {
        return hash_hmac('sha256', $encoded, (string) config('app.key'));
    }

    private function conflict(bool $valid): void
    {
        abort_unless($valid, response()->json(['code' => 'commercial_tax_review_changed', 'message' => 'A classificação ou o contexto mudou. Revise a prévia novamente.'], 409));
    }

    private function digest(mixed $value): string
    {
        return hash('sha256', $this->json($value));
    }

    private function json(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (is_array($item)) {
                if (! array_is_list($item)) {
                    ksort($item);
                }

                return array_map($normalize, $item);
            }

            return $item;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR);
    }
}
