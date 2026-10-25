<?php

namespace App\Http\Requests\Admin;

use App\Erp\ErpCoreModules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $rules = [
            'slug' => ['missing'],
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'limits' => ['sometimes', 'array:users,clients,monitorings'],
            'limits.users' => ['sometimes', 'nullable', 'integer:strict', 'min:0'],
            'limits.clients' => ['sometimes', 'nullable', 'integer:strict', 'min:0'],
            'limits.monitorings' => ['sometimes', 'nullable', 'integer:strict', 'min:0'],
            ...self::erpCommercialRules(),
        ];
        foreach (array_diff(array_keys($this->all()), ['name', 'limits', 'slug', 'erp_commercial']) as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }

    /** @return array{name?: string, limits?: array{users: ?int, clients: ?int, monitorings: ?int}, erp_commercial?: array{enabled: bool, licence_limit: ?int, module_keys: list<string>}} */
    public function planAttributes(): array
    {
        $data = $this->validated();
        if (array_key_exists('limits', $data)) {
            $data['limits'] = array_replace(['users' => null, 'clients' => null, 'monitorings' => null], $data['limits']);
        }

        return $data;
    }

    /** @return array<string, array<mixed>> */
    public static function erpCommercialRules(): array
    {
        return [
            'erp_commercial' => ['sometimes', 'array:enabled,licence_limit,module_keys', 'required_array_keys:enabled,licence_limit,module_keys'],
            'erp_commercial.enabled' => ['sometimes', 'boolean:strict'],
            'erp_commercial.licence_limit' => ['sometimes', 'nullable', 'integer:strict', 'min:0'],
            'erp_commercial.module_keys' => ['sometimes', 'array', 'list'],
            'erp_commercial.module_keys.*' => ['string', Rule::in(ErpCoreModules::BASIC), 'distinct:strict'],
        ];
    }
}
