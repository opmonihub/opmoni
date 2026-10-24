<?php

namespace App\Erp;

use App\Enums\Erp\ErpEstablishmentResource;
use App\Erp\CfopReference\CfopReferenceCatalog;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpOperationNatureCfopChoice;
use App\Models\Erp\ErpOperationNatureProductException;
use App\Models\Erp\ErpProduct;
use App\Support\AccessSession;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ErpOperationNatureRules
{
    public const SOURCE_ACTIONS = ['erp.sales.store', 'erp.sales.update', 'erp.quotes.store', 'erp.quotes.update', 'erp.orders.store', 'erp.orders.update'];

    public function __construct(private CfopReferenceCatalog $references) {}

    public static function permission(string $action, Request $request): string
    {
        return match ($action) {
            'erp.operation-natures.choices.index', 'erp.operation-natures.choices.show',
            'erp.operation-natures.product-exceptions.index', 'erp.operation-natures.product-exceptions.show' => 'erp.operation-natures.index',
            'erp.operation-natures.choices.store', 'erp.operation-natures.choices.archive',
            'erp.operation-natures.product-exceptions.store', 'erp.operation-natures.product-exceptions.update',
            'erp.operation-natures.product-exceptions.archive' => 'erp.operation-natures.update',
            'erp.operation-natures.operational-preview' => $request->validate(['source_action' => ['required', 'string', Rule::in(self::SOURCE_ACTIONS)]])['source_action'],
            default => $action,
        };
    }

    /** @param Closure(CurrentCompany): Response $write */
    public function run(Request $request, Closure $write): Response
    {
        return app(ErpCatalogWriteService::class)->run($request, function (CurrentCompany $context) use ($request, $write): Response {
            $this->assertAuthority($request, $context);
            $response = $write($context);
            $this->assertAuthority($request, $context);

            return $response;
        });
    }

    public function assertAuthority(Request $request, CurrentCompany $context): void
    {
        AccessSession::assertOwnership($request);
        $access = app(ErpCompanyAuthorization::class)->resolveAccess($request->user(), $context->company->fresh(), $context->delegatedOfficeId, true);
        abort_unless($access !== null && $access['kind'] === $context->accessKind, 403, 'Permissão insuficiente');
        if ($context->accessKind === 'delegated') {
            abort_unless((int) $access['delegation']->id === (int) $context->delegation?->id, 403, 'Permissão insuficiente');
        }
        $fresh = new CurrentCompany($context->company, $access['kind'], $access['membership'] ?? null, $access['delegation'] ?? null, $access['office_id'] ?? null, $access['office_role'] ?? null);
        $fresh->assertPermission((string) $request->route()?->getName(), true);
        if ($context->membership !== null && isset($access['membership'])) {
            $context->membership->setRawAttributes($access['membership']->getAttributes(), true);
        }
        if ($context->delegation !== null && isset($access['delegation'])) {
            $context->delegation->setRawAttributes($access['delegation']->getAttributes(), true);
        }
    }

    public function nature(CurrentCompany $context, int $id, bool $related = false, bool $lock = false): ErpOperationNature
    {
        $query = ErpOperationNature::query()->where('erp_company_id', $context->company->id)->whereKey($id);
        app(ErpEstablishmentAccess::class)->apply($query, $context, ErpEstablishmentResource::OperationNature);
        $nature = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($nature === null && $related) {
            throw ValidationException::withMessages(['erp_operation_nature_id' => 'Natureza de operação inválida para o contexto autorizado.']);
        }
        abort_unless($nature !== null, 404);

        return $nature;
    }

    /** @param array<string, mixed> $payload */
    public function expectedRevision(ErpOperationNature $nature, array $payload, bool $required = true): void
    {
        $data = Validator::make($payload, ['expected_revision' => [$required ? 'required' : 'sometimes', 'integer', 'min:1']])->validate();
        if (isset($data['expected_revision']) && (int) $data['expected_revision'] !== $nature->configuration_revision) {
            abort(response()->json(['code' => 'operation_nature_changed', 'message' => 'A natureza de operação mudou. Revise a configuração novamente.'], 409));
        }
    }

    /** @return array<string, mixed> */
    public function reference(string $code, string $movement, string $field = 'cfop'): array
    {
        $reference = $this->references->find($code);
        if ($reference === null || $reference['applicability']['direction'] !== ($movement === 'in' ? 'inbound' : 'outbound')) {
            throw ValidationException::withMessages([$field => 'Informe um CFOP canônico válido e compatível com a direção da natureza.']);
        }

        return $reference;
    }

    public function assertActive(ErpOperationNature $nature): void
    {
        if ($nature->status !== 'active') {
            throw ValidationException::withMessages(['erp_operation_nature_id' => 'A natureza de operação está arquivada.']);
        }
    }

    /** @param array<string, mixed> $data */
    public function save(Request $request, CurrentCompany $context, array $data, ?ErpOperationNature $nature = null): ErpOperationNature
    {
        app(ErpCatalogWriteService::class)->assertProtected($request, $context);
        $created = $nature === null;
        if (! $created) {
            $nature = $this->nature($context, $nature->id, lock: true);
            $this->expectedRevision($nature, $request->all(), array_key_exists('allowed_cfops', $data));
        } elseif (array_key_exists('allowed_cfops', $data)) {
            Validator::make($request->all(), ['expected_revision' => ['required', 'integer', 'in:0']])->validate();
        }
        $allowed = $data['allowed_cfops'] ?? null;
        unset($data['allowed_cfops']);
        $movement = $data['movement'] ?? $nature?->movement ?? 'out';
        $default = $data['cfop'] ?? $nature?->cfop;
        $replacedSingleChoice = null;
        if ($nature !== null && $allowed === null && array_key_exists('cfop', $data)
            && $default !== $nature->cfop && $movement !== $nature->movement) {
            $previousChoices = $nature->cfopChoices()->where('erp_company_id', $context->company->id)->where('status', 'active')->orderBy('id')->lockForUpdate()->get();
            if ($previousChoices->count() === 1 && $previousChoices->first()->cfop === $nature->cfop) {
                $replacedSingleChoice = $previousChoices->first();
                $this->assertChoiceUnused($nature, $replacedSingleChoice);
            }
        }
        $verify = array_key_exists('cfop', $data);
        if ($nature !== null && $nature->cfop === $default && $nature->cfop_reference_version === null) {
            $verify = $allowed !== null || ($verify && array_diff(array_keys($data), ['cfop']) === [] && $request->exists('expected_revision'));
        }
        $reference = $verify ? $this->reference($default, $movement) : null;
        if ($reference !== null) {
            $data['cfop_reference_version'] = $reference['reference_version'];
        }
        if (is_array($allowed)) {
            if (! in_array($default, $allowed, true)) {
                throw ValidationException::withMessages(['cfop' => 'O CFOP padrão deve permanecer no conjunto permitido.']);
            }
            foreach ($allowed as $index => $code) {
                $this->reference($code, $movement, 'allowed_cfops.'.$index);
            }
        }
        if ($created) {
            $nature = ErpOperationNature::query()->create([...$data, 'movement' => $movement, 'erp_company_id' => $context->company->id, 'configuration_revision' => 1, 'status' => 'active']);
        } else {
            $nature->fill($data);
        }
        if (($verify || $allowed !== null) && $nature->status !== 'active') {
            $this->assertActive($nature);
        }
        $childrenChanged = false;
        if ($replacedSingleChoice !== null) {
            $replacedSingleChoice->status = 'archived';
            $replacedSingleChoice->save();
            app(ErpCatalogWriteService::class)->record($request, $context, $replacedSingleChoice, 'archived');
            $childrenChanged = true;
        }
        $codes = $allowed ?? ($reference !== null ? [$default] : []);
        foreach ($codes as $code) {
            $childrenChanged = $this->ensureChoice($request, $context, $nature, $code, ! $created) || $childrenChanged;
        }
        if ($allowed !== null) {
            $removed = $nature->cfopChoices()->where('erp_company_id', $context->company->id)->where('status', 'active')->whereNotIn('cfop', $allowed)->orderBy('id')->lockForUpdate()->get();
            foreach ($removed as $choice) {
                $this->assertChoiceUnused($nature, $choice);
                $choice->status = 'archived';
                $choice->save();
                app(ErpCatalogWriteService::class)->record($request, $context, $choice, 'archived');
                $childrenChanged = true;
            }
        }
        if (array_key_exists('movement', $data)) {
            foreach ($nature->cfopChoices()->where('status', 'active')->get() as $choice) {
                $this->reference($choice->cfop, $movement, 'movement');
            }
        }
        if (! $created && ($childrenChanged || $nature->isDirty())) {
            $this->advance($request, $context, $nature);
        } elseif ($created) {
            app(ErpCatalogWriteService::class)->record($request, $context, $nature, 'created');
        }

        return $nature->fresh();
    }

    public function advance(Request $request, CurrentCompany $context, ErpOperationNature $nature, string $action = 'updated'): void
    {
        app(ErpCatalogWriteService::class)->assertProtected($request, $context);
        $this->nature($context, $nature->id, lock: true);
        $this->assertAuthority($request, $context);
        app(ErpEstablishmentAccess::class)->assertResource($context, $nature);
        $nature->configuration_revision++;
        $nature->save();
        app(ErpCatalogWriteService::class)->record($request, $context, $nature, $action);
    }

    public function ensureChoice(Request $request, CurrentCompany $context, ErpOperationNature $nature, string $code, bool $audit = true): bool
    {
        app(ErpCatalogWriteService::class)->assertProtected($request, $context);
        $this->nature($context, $nature->id, lock: true);
        $reference = $this->reference($code, $nature->movement);
        $choice = $nature->cfopChoices()->where('erp_company_id', $context->company->id)->where('cfop', $code)->lockForUpdate()->first();
        if ($choice === null) {
            $choice = $nature->cfopChoices()->create(['erp_company_id' => $context->company->id, 'cfop' => $code, 'reference_version' => $reference['reference_version'], 'status' => 'active']);
            if ($audit) {
                app(ErpCatalogWriteService::class)->record($request, $context, $choice, 'created');
            }

            return true;
        }
        $choice->fill(['status' => 'active', 'reference_version' => $reference['reference_version']]);
        if (! $choice->isDirty()) {
            return false;
        }
        $choice->save();
        if ($audit) {
            app(ErpCatalogWriteService::class)->record($request, $context, $choice, 'updated');
        }

        return true;
    }

    public function assertChoiceUnused(ErpOperationNature $nature, ErpOperationNatureCfopChoice $choice): void
    {
        if ($nature->productExceptions()->where('erp_company_id', $nature->erp_company_id)->where('erp_operation_nature_cfop_choice_id', $choice->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['cfop' => 'Arquive ou revise as exceções ativas antes de remover esta opção.']);
        }
    }

    public function choice(ErpOperationNature $nature, int $id, bool $related = false): ErpOperationNatureCfopChoice
    {
        $choice = $nature->cfopChoices()->where('erp_company_id', $nature->erp_company_id)->whereKey($id)->lockForUpdate()->first();
        if ($related && ($choice === null || $choice->status !== 'active')) {
            throw ValidationException::withMessages(['erp_operation_nature_cfop_choice_id' => 'Opção ativa inválida para esta natureza.']);
        }
        abort_unless($choice !== null, 404);

        return $choice;
    }

    public function exception(ErpOperationNature $nature, int $id): ErpOperationNatureProductException
    {
        return $nature->productExceptions()->where('erp_company_id', $nature->erp_company_id)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function product(CurrentCompany $context, int $id): ErpProduct
    {
        $product = ErpProduct::query()->where('erp_company_id', $context->company->id)->where('status', 'active')->whereKey($id)->lockForUpdate()->first();
        if ($product === null) {
            throw ValidationException::withMessages(['erp_product_id' => 'Produto ativo inválido para esta empresa.']);
        }

        return $product;
    }
}
