<?php

namespace App\Erp;

use App\Models\Erp\ErpCompanyProductTaxDefault;
use App\Models\Erp\ErpProductTaxProfile;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ErpProductTaxProfiles
{
    /** @param array<string, mixed> $payload */
    public function save(Request $request, CurrentCompany $context, array $payload, ?ErpProductTaxProfile $profile = null): ErpProductTaxProfile
    {
        app(ErpCatalogWriteService::class)->assertProtected($request, $context);
        $data = Validator::make($payload, [
            'name' => [$profile === null ? 'required' : 'sometimes', 'string', 'max:255'],
            'fields' => ['sometimes', 'array'],
            'applicability' => ['sometimes', 'array:tax_regime,kind,movement'],
            'applicability.tax_regime' => ['sometimes', 'string', 'in:1,2,3,4'],
            'applicability.kind' => ['sometimes', 'string', 'in:goods,service'],
            'applicability.movement' => ['sometimes', 'string', 'in:in,out'],
            'expected_revision' => ['sometimes', 'integer', 'min:1'],
        ])->validate();
        if (isset($data['fields'])) {
            $data['fields'] = ErpProductTaxFields::validate($data['fields']);
        }
        ErpCompanyProductTaxDefault::query()->where('erp_company_id', $context->company->id)->lockForUpdate()->first();
        if ($profile !== null) {
            $profile = ErpProductTaxProfile::query()->where('erp_company_id', $context->company->id)->lockForUpdate()->findOrFail($profile->id);
            $this->assertExpectedRevision($data, $profile);
            if ($profile->status !== 'active') {
                throw ValidationException::withMessages(['status' => 'O perfil tributário está arquivado.']);
            }
        }
        $previous = $profile?->revisions()->where('revision', $profile->current_revision)->first();
        $fields = array_replace($previous?->fields ?? [], $data['fields'] ?? []);
        $applicability = $data['applicability'] ?? $previous?->applicability ?? [];
        $name = $data['name'] ?? $profile?->name;
        if ($previous !== null && $name === $profile->name && $fields === $previous->fields && $applicability === $previous->applicability) {
            return $profile->load('currentRevision');
        }
        $created = $profile === null;
        $profile ??= ErpProductTaxProfile::query()->create(['erp_company_id' => $context->company->id, 'name' => $name, 'status' => 'active']);
        $this->revise($request, $context, $profile, $name, 'active', $fields, $applicability);
        app(ErpCatalogWriteService::class)->record($request, $context, $profile, $created ? 'created' : 'updated');

        return $profile->load('currentRevision');
    }

    public function archive(Request $request, CurrentCompany $context, int $id): ErpProductTaxProfile
    {
        app(ErpCatalogWriteService::class)->assertProtected($request, $context);
        ErpCompanyProductTaxDefault::query()->where('erp_company_id', $context->company->id)->lockForUpdate()->first();
        $profile = ErpProductTaxProfile::query()->where('erp_company_id', $context->company->id)->lockForUpdate()->findOrFail($id);
        $data = $request->validate(['expected_revision' => ['sometimes', 'integer', 'min:1']]);
        $this->assertExpectedRevision($data, $profile);
        if ($profile->status !== 'archived') {
            $previous = $profile->revisions()->where('revision', $profile->current_revision)->firstOrFail();
            $this->revise($request, $context, $profile, $profile->name, 'archived', $previous->fields, $previous->applicability);
            app(ErpCatalogWriteService::class)->record($request, $context, $profile, 'archived');
        }

        return $profile->load('currentRevision');
    }

    private function assertExpectedRevision(array $data, ErpProductTaxProfile $profile): void
    {
        if (isset($data['expected_revision']) && (int) $data['expected_revision'] !== $profile->current_revision) {
            abort(response()->json(['code' => 'product_tax_profile_changed', 'message' => 'O perfil tributário mudou. Revise a configuração novamente.'], 409));
        }
    }

    private function revise(Request $request, CurrentCompany $context, ErpProductTaxProfile $profile, string $name, string $status, array $fields, array $applicability): void
    {
        $revision = ($profile->current_revision ?? 0) + 1;
        $profile->revisions()->create([
            'erp_company_id' => $context->company->id, 'revision' => $revision, 'name' => $name, 'status' => $status,
            'fields' => $fields, 'applicability' => $applicability, 'actor_user_id' => $request->user()->id,
            'access_kind' => $context->accessKind,
            'erp_company_membership_id' => $context->accessKind === 'direct' ? $context->membership?->id : null,
            'account_id' => $context->accessKind === 'delegated' ? $context->delegatedOfficeId : null,
            'erp_office_delegation_id' => $context->accessKind === 'delegated' ? $context->delegation?->id : null,
        ]);
        $profile->fill(['name' => $name, 'status' => $status, 'current_revision' => $revision])->save();
    }
}
