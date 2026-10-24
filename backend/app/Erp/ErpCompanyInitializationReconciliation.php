<?php

namespace App\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanySetupState;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Sales\ErpInvoiceLayout;
use App\Models\Erp\Sales\ErpInvoiceScheme;
use App\Support\AccessSession;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ErpCompanyInitializationReconciliation
{
    public const OPERATION = 'erp.company.initialization.apply';

    public const VERSION = 'setup-v1';

    private const RECORDS = [
        'unit' => [ErpUnit::class, 'default_unit_id'],
        'customer' => [ErpContact::class, 'default_customer_id'],
        'sale_scheme' => [ErpInvoiceScheme::class, 'default_invoice_scheme_id'],
        'sale_layout' => [ErpInvoiceLayout::class, 'default_invoice_layout_id'],
    ];

    public function __construct(
        private ErpCompanySetupPreset $preset,
        private ErpCompanyOperatingSettings $settings,
        private ErpCompanyAdministration $administration,
        private ErpCatalogWriteService $writes,
        private ErpOperationJournal $journal,
    ) {}

    public function assertAuthorized(Request $request): void
    {
        $this->administration->assertOwner($request);
        AccessSession::assertOwnership($request);
    }

    /** ResolveCompany holds the outer company lock for the complete preview/apply/lookup request. */
    public function preview(Request $request): JsonResponse
    {
        $this->assertAuthorized($request);
        [$company, $state, $records] = $this->lockedInputs($request);
        $plan = $this->plan($company, $state, $records);
        $this->assertAuthorized($request);

        return response()->json(['data' => [
            ...$plan,
            'fingerprint' => $this->fingerprint($request, $company, $state, $records),
        ]]);
    }

    public function apply(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $this->writes->assertProtected($request, $context);
        $this->assertAuthorized($request);
        $data = $request->validate([
            'preview_version' => ['required', 'in:'.self::VERSION],
            'fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ]);
        $key = $this->journal->key($request, true);
        [$company, $state, $records] = $this->lockedInputs($request);
        $hash = hash('sha256', self::VERSION.':'.$data['fingerprint']);
        $receipt = ErpOperationReceipt::query()->where('erp_company_id', $company->id)
            ->where('actor_user_id', $request->user()->id)->where('operation', self::OPERATION)
            ->where('key_digest', hash('sha256', $key))->lockForUpdate()->first();
        $this->assertAuthorized($request);
        if ($receipt !== null) {
            abort_unless($receipt->canonical_version === self::VERSION && hash_equals($receipt->payload_hash, $hash), 409, 'Chave de operação reutilizada com conteúdo diferente');

            return response()->json($receipt->response_body, $receipt->response_status);
        }
        if (! hash_equals($this->fingerprint($request, $company, $state, $records), $data['fingerprint'])) {
            abort(response()->json(['code' => 'initialization_preview_stale', 'message' => 'A configuração mudou. Gere uma nova prévia antes de aplicar.'], 409));
        }
        $plan = $this->plan($company, $state, $records);
        $stored = is_array($company->operating_settings) ? $company->operating_settings : [];
        $missing = $this->preset->missingOperatingSettings($stored);
        $this->assertAuthorized($request);
        if ($missing !== []) {
            $this->settings->mergeOperating($company, $missing);
            $company->save();
            $this->writes->record($request, $context, $company, 'company_settings_updated');
        }
        $manifest = $this->preset->manifest();
        $createdState = $state === null;
        if ($createdState) {
            $state = new ErpCompanySetupState([
                'erp_company_id' => $company->id, 'preset_version' => $manifest['version'], 'revision' => 1,
                'currency' => $this->item($plan, 'currency')['status'] === 'create' ? $manifest['currency'] : null,
                'sale_numbering_adopted' => false, 'preset_owned_keys' => [],
            ]);
        }
        $owned = $state->preset_owned_keys ?? [];
        foreach (self::RECORDS as $name => [$class, $pointer]) {
            $this->assertAuthorized($request);
            $item = $this->item($plan, $name);
            if (! in_array($item['status'], ['create', 'reuse'], true)) {
                continue;
            }
            $record = $records[$name]['native'];
            if ($item['status'] === 'create') {
                $record = new $class(['erp_company_id' => $company->id, ...$manifest['records'][$name]['attributes']]);
                $record->forceFill(['native_key' => $manifest['records'][$name]['key']])->save();
                if (in_array($name, ['unit', 'customer'], true)) {
                    $this->writes->record($request, $context, $record, 'created');
                }
            }
            if ($createdState) {
                $state->{$pointer} = $record->id;
                $owned[$name] = $manifest['records'][$name]['key'];
            }
        }
        $this->assertAuthorized($request);
        if ($createdState) {
            $state->preset_owned_keys = $owned;
            $state->save();
        } elseif ($missing !== []) {
            $state->revision++;
            $state->save();
        }
        $result = ['data' => [...$plan, 'revision' => $state->revision, 'completed' => ! $plan['has_conflicts']]];
        abort_if(strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 16384, 422, 'Resultado de operação inválido');
        $this->assertAuthorized($request);
        ErpOperationReceipt::query()->create([
            'erp_company_id' => $company->id, 'actor_user_id' => $request->user()->id, 'operation' => self::OPERATION,
            'key_digest' => hash('sha256', $key), 'canonical_version' => self::VERSION, 'payload_hash' => $hash,
            'response_status' => 200, 'response_body' => $result, 'resource_refs' => ['setup_state_id' => $state->id], 'recorded_at' => now(),
        ]);
        $this->assertAuthorized($request);

        return response()->json($result);
    }

    /** @return array{ErpCompany, ?ErpCompanySetupState, array<string, array<string, ?Model>>} */
    private function lockedInputs(Request $request): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $company = ErpCompany::query()->whereKey(CurrentCompany::fromRequest($request)->company->id)->lockForUpdate()->firstOrFail();
        $this->assertAuthorized($request);
        $state = ErpCompanySetupState::query()->forCompany($company->id)->lockForUpdate()->first();
        $records = [];
        foreach (self::RECORDS as $name => [$class, $pointer]) {
            $key = $this->preset->manifest()['records'][$name]['key'];
            $ids = $state?->{$pointer} === null ? [] : [$state->{$pointer}];
            $rows = $class::query()->where('erp_company_id', $company->id)->where(function ($query) use ($key, $ids, $name): void {
                $query->where('native_key', $key)->orWhereIn('id', $ids);
                if ($name === 'unit') {
                    $query->orWhere('short_name', 'UN');
                }
            })->orderBy('id')->lockForUpdate()->get();
            $records[$name] = [
                'native' => $rows->firstWhere('native_key', $key),
                'selected' => $state?->{$pointer} === null ? null : $rows->firstWhere('id', $state->{$pointer}),
                'collision' => $name === 'unit' ? $rows->firstWhere('short_name', 'UN') : null,
            ];
        }

        return [$company, $state, $records];
    }

    /** @param array<string, array<string, ?Model>> $records */
    private function plan(ErpCompany $company, ?ErpCompanySetupState $state, array $records): array
    {
        $manifest = $this->preset->manifest();
        $stored = is_array($company->operating_settings) ? $company->operating_settings : [];
        $items = [];
        foreach ($manifest['operating_settings'] as $key => $value) {
            $exists = array_key_exists($key, $stored);
            $before = $exists ? $stored[$key] : ($this->settings->defaults()[$key] ?? null);
            $items[] = $this->result('operating_settings.'.$key, $exists ? 'skip' : 'create', $exists ? 'explicit_value_preserved' : 'missing_preference', $before, $exists ? $before : $value);
        }
        $opaqueCurrency = array_key_exists('currency_id', $stored) || array_key_exists('purchase_currency_id', $stored);
        $items[] = $this->result('currency', $opaqueCurrency ? 'conflict' : ($state !== null ? 'skip' : 'create'), $opaqueCurrency ? 'unresolved_legacy_namespace' : ($state !== null ? 'explicit_value_preserved' : 'missing_native_currency'), $state?->currency, $state !== null ? $state->currency : ($opaqueCurrency ? null : $manifest['currency']));
        foreach (self::RECORDS as $name => [$class, $pointer]) {
            $native = $records[$name]['native'];
            $selected = $records[$name]['selected'];
            if ($state !== null) {
                if ($state->{$pointer} === null) {
                    $conflict = ($native !== null && ! $this->compatible($name, $native))
                        || ($name === 'unit' && $native === null && $records[$name]['collision'] !== null);
                    $items[] = $this->result($name, $conflict ? 'conflict' : 'skip', $conflict ? 'unresolved_reference_requires_review' : 'explicit_value_preserved', null, null);
                } elseif ($selected === null || $selected->status !== 'active' || ($selected->native_key === $manifest['records'][$name]['key'] && ! $this->compatible($name, $selected))) {
                    $items[] = $this->result($name, 'conflict', 'selected_reference_unavailable_or_customized', $state->{$pointer}, $state->{$pointer});
                } else {
                    $items[] = $this->result($name, 'reuse', 'existing_native_selection', $selected->id, $selected->id);
                }
            } elseif ($native !== null) {
                $items[] = $this->result($name, $this->compatible($name, $native) ? 'reuse' : 'conflict', $this->compatible($name, $native) ? 'compatible_native_identity' : 'native_record_customized_or_archived', null, $this->compatible($name, $native) ? $native->id : null);
            } elseif ($name === 'unit' && $records[$name]['collision'] !== null) {
                $items[] = $this->result($name, 'conflict', 'unowned_unit_requires_review', null, null);
            } else {
                $items[] = $this->result($name, 'create', 'missing_native_record', null, ['native_key' => $manifest['records'][$name]['key'], ...$manifest['records'][$name]['attributes']]);
            }
        }

        return ['preview_version' => self::VERSION, 'preset_version' => $manifest['version'], 'revision' => $state?->revision ?? 0, 'sale_numbering_adopted' => $state?->sale_numbering_adopted ?? false, 'items' => $items, 'has_conflicts' => collect($items)->contains('status', 'conflict')];
    }

    private function compatible(string $name, Model $record): bool
    {
        if ($record->status !== 'active') {
            return false;
        }
        if ($name === 'unit') {
            return $record->short_name === 'UN' && $record->actual_name === 'Unidade' && ! $record->allow_decimal && $record->base_unit_id === null && $record->conversion_factor === null;
        }

        return $name !== 'customer' || $record->is_customer;
    }

    private function result(string $key, string $status, string $reason, mixed $before, mixed $after): array
    {
        if (str_starts_with($key, 'operating_settings.')) {
            $before = $this->safeValue($before);
            $after = $this->safeValue($after);
        }

        return ['key' => $key, 'status' => $status, 'reason' => $reason, 'before' => $before, 'after' => $after, 'effective_changed' => $before !== $after];
    }

    /** Values with unexpected legacy types are preserved, but their content is not exposed. */
    private function safeValue(mixed $value): mixed
    {
        return is_array($value) || is_object($value) ? ['preserved' => true, 'type' => get_debug_type($value)] : $value;
    }

    private function item(array $plan, string $name): array
    {
        return collect($plan['items'])->firstWhere('key', $name);
    }

    private function fingerprint(Request $request, ErpCompany $company, ?ErpCompanySetupState $state, array $records): string
    {
        $snapshots = [];
        foreach ($records as $name => $candidates) {
            foreach ($candidates as $kind => $record) {
                $snapshots[$name][$kind] = $record?->getRawOriginal();
            }
        }
        $payload = [self::VERSION, $request->user()->id, $company->id, $this->preset->manifest(), $company->getRawOriginal('operating_settings'), $state?->getRawOriginal(), $snapshots];

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
