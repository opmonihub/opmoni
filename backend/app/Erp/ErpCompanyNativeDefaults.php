<?php

namespace App\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanySetupState;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpUnit;
use App\Support\AccessSession;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ErpCompanyNativeDefaults
{
    /** @return array{native_defaults: array<string, mixed>} */
    public function toPayload(ErpCompany $company): array
    {
        $state = $this->state($company);

        return ['native_defaults' => [
            'revision' => $state?->revision ?? 0,
            'preset_version' => $state?->preset_version,
            'currency' => $this->currency($company, $state),
            'unit' => $this->unit($company, $state),
            'customer' => $this->customer($company, $state),
        ]];
    }

    /** Unit catalog readers receive only the unit preference, without customer or settings data.
     * @return array{status: string, id: ?int, label: ?string}
     */
    public function unitDefault(ErpCompany $company): array
    {
        return $this->unit($company, $this->state($company));
    }

    /** Called only for new drafts inside the authorized commercial journal transaction.
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function applyToNewCommercialDraft(Request $request, string $kind, array $input): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $context = CurrentCompany::fromRequest($request);
        $context->assertPermission('erp.'.match ($kind) {
            'quote' => 'quotes', 'order' => 'orders', 'sale' => 'sales',
        }.'.store', true);
        AccessSession::assertOwnership($request);

        // ResolveCompany already holds Company before the journal receipt and these setup/reference locks.
        $state = ErpCompanySetupState::query()->forCompany($context->company->id)->lockForUpdate()->first();
        if (! array_key_exists('currency', $input)) {
            $currency = $this->currency($context->company, $state);
            if ($currency['status'] === 'available') {
                $input['currency'] = $currency['code'];
            } elseif ($state === null && $currency['status'] === 'unset') {
                $input['currency'] = 'BRL';
            } else {
                throw ValidationException::withMessages(['currency' => 'A moeda padrão não está resolvida. Informe a moeda BRL explicitamente.']);
            }
        }

        if ($kind === 'sale' && ! array_key_exists('erp_contact_id', $input) && $state?->default_customer_id !== null) {
            $customer = ErpContact::query()->forCompany($context->company->id)
                ->whereKey($state->default_customer_id)->lockForUpdate()->first();
            if ($customer === null || $customer->status !== 'active' || ! $customer->is_customer) {
                throw ValidationException::withMessages(['erp_contact_id' => 'O cliente padrão está indisponível. Selecione um cliente ativo ou escolha uma venda sem cliente vinculado.']);
            }
            $input['erp_contact_id'] = $customer->id;
        }

        return $input;
    }

    private function state(ErpCompany $company): ?ErpCompanySetupState
    {
        return ErpCompanySetupState::query()->forCompany($company->id)->first();
    }

    /** @return array{status: string, code: ?string} */
    private function currency(ErpCompany $company, ?ErpCompanySetupState $state): array
    {
        if ($state === null) {
            return ['status' => $this->legacyStatus($company, 'currency_id'), 'code' => null];
        }

        return ['status' => $state->currency === null ? 'unset' : ($state->currency === 'BRL' ? 'available' : 'unavailable'),
            'code' => $state->currency === 'BRL' ? 'BRL' : null];
    }

    /** @return array{status: string, id: ?int, label: ?string} */
    private function unit(ErpCompany $company, ?ErpCompanySetupState $state): array
    {
        if ($state?->default_unit_id === null) {
            return $this->reference($state === null ? $this->legacyStatus($company, 'default_unit') : 'unset');
        }
        $unit = ErpUnit::query()->forCompany($company->id)->whereKey($state->default_unit_id)->first();

        return $unit !== null && $unit->status === 'active'
            ? $this->reference('available', $unit->id, $unit->short_name.' · '.$unit->actual_name)
            : $this->reference('unavailable');
    }

    /** @return array{status: string, id: ?int, label: ?string} */
    private function customer(ErpCompany $company, ?ErpCompanySetupState $state): array
    {
        if ($state?->default_customer_id === null) {
            return $this->reference('unset');
        }
        $customer = ErpContact::query()->forCompany($company->id)->whereKey($state->default_customer_id)->first();

        return $customer !== null && $customer->status === 'active' && $customer->is_customer
            ? $this->reference('available', $customer->id, $customer->name)
            : $this->reference('unavailable');
    }

    private function legacyStatus(ErpCompany $company, string $field): string
    {
        return array_key_exists($field, $company->operating_settings ?? []) ? 'unresolved' : 'unset';
    }

    /** @return array{status: string, id: ?int, label: ?string} */
    private function reference(string $status, ?int $id = null, ?string $label = null): array
    {
        return ['status' => $status, 'id' => $id, 'label' => $label];
    }
}
