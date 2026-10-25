<?php

namespace App\Http\Requests\OfficeErp;

use App\Erp\Commercial\OfficeErpMoney;
use App\Erp\ErpCoreModules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveSalesPlanRequest extends FormRequest
{
    private const FIELDS = [
        'name', 'description', 'price', 'currency', 'interval_months', 'due_in_days', 'module_keys', 'published',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'min:1', 'max:255', 'not_regex:/^\s*$/u'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['required', 'string'],
            'currency' => ['sometimes', 'string', Rule::in(['BRL'])],
            'interval_months' => ['required', 'integer:strict', Rule::in([1, 3, 6, 12])],
            'due_in_days' => ['required', 'integer:strict', 'min:0', 'max:65535'],
            'module_keys' => ['required', 'array', 'list'],
            'module_keys.*' => ['required', 'string', Rule::in(ErpCoreModules::BASIC), 'distinct:strict'],
            'published' => ['required', 'boolean:strict'],
        ];

        foreach (array_diff(array_keys($this->all()), self::FIELDS) as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }

    /** @return array{name: string, description: ?string, price: string, currency: 'BRL', interval_months: int, due_in_days: int, module_keys: list<string>, published: bool} */
    public function terms(): array
    {
        $data = $this->validated();
        $price = OfficeErpMoney::canonical($data['price'], 'price');

        $modules = array_values($data['module_keys']);
        sort($modules, SORT_STRING);

        return [
            'name' => trim($data['name']),
            'description' => isset($data['description']) ? trim($data['description']) : null,
            'price' => $price,
            'currency' => 'BRL',
            'interval_months' => $data['interval_months'],
            'due_in_days' => $data['due_in_days'],
            'module_keys' => $modules,
            'published' => $data['published'],
        ];
    }
}
