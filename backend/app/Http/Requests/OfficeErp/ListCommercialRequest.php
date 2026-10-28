<?php

namespace App\Http\Requests\OfficeErp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ListCommercialRequest extends FormRequest
{
    private const FILTERS = [
        'summary' => ['from_on', 'to_on', 'due_from', 'due_to'],
        'clients' => ['search', 'page', 'per_page', 'sort', 'direction'],
        'plans' => ['search', 'status', 'page', 'per_page', 'sort', 'direction'],
        'contracts' => ['search', 'status', 'page', 'per_page', 'sort', 'direction'],
        'invoices' => ['search', 'status', 'contract_id', 'from_on', 'to_on', 'due_from', 'due_to', 'page', 'per_page', 'sort', 'direction'],
        'receipts' => ['search', 'status', 'from_on', 'to_on', 'page', 'per_page', 'sort', 'direction'],
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $resource = (string) $this->route('resource');
        abort_unless(isset(self::FILTERS[$resource]), 404);

        return self::rulesFor($resource);
    }

    /** @return array<string, mixed> */
    public static function rulesFor(string $resource): array
    {
        abort_unless(isset(self::FILTERS[$resource]), 404);
        $filters = self::FILTERS[$resource];
        $rules = [];

        foreach ($filters as $filter) {
            $rules[$filter] = match ($filter) {
                'search' => ['sometimes', 'nullable', 'string', 'max:255'],
                'status' => ['sometimes', 'nullable', 'string', 'max:32', ...self::statusValues($resource)],
                'contract_id' => ['sometimes', 'integer', 'min:1', 'max:9007199254740991'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', Rule::in([25, 50, 100])],
                'sort' => ['sometimes', 'string', Rule::in(self::sortFields($resource))],
                'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
                default => ['sometimes', 'date_format:Y-m-d'],
            };
        }

        return $rules;
    }

    /** @return list<string> */
    private static function statusValues(string $resource): array
    {
        $values = match ($resource) {
            'plans' => ['active', 'archived'],
            'contracts' => ['accepted', 'effective', 'canceled'],
            'invoices' => ['draft', 'issued', 'canceled'],
            'receipts' => ['effective', 'reversed'],
            default => [],
        };

        return $values === [] ? [] : [Rule::in($values)];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function validateFilters(string $resource, array $input): array
    {
        abort_unless(isset(self::FILTERS[$resource]), 404);
        $input = self::normalizeAliases($resource, $input);
        if (array_key_exists('direction', $input) && ! array_key_exists('sort', $input)) {
            throw ValidationException::withMessages(['direction' => ['A direção exige um campo de ordenação.']]);
        }
        $unknown = array_diff(array_keys($input), self::FILTERS[$resource]);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Este filtro não se aplica a esta lista.'));
        }

        $validator = Validator::make($input, self::rulesFor($resource));
        $validator->after(static function ($validator) use ($input): void {
            foreach ([['from_on', 'to_on'], ['due_from', 'due_to']] as [$start, $end]) {
                if (isset($input[$start], $input[$end]) && $input[$start] > $input[$end]) {
                    $validator->errors()->add($end, 'A data final deve ser igual ou posterior à data inicial.');
                }
            }
        });

        return $validator->validate();
    }

    /** @return list<string> */
    private static function sortFields(string $resource): array
    {
        return match ($resource) {
            'clients' => ['id', 'name'],
            'plans' => ['id', 'name', 'status'],
            'contracts' => ['id', 'client', 'agreement_status'],
            'invoices' => ['id', 'number', 'due_on', 'document_status'],
            'receipts' => ['id', 'received_on', 'reference'],
            default => [],
        };
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeAliases(string $resource, array $input): array
    {
        $aliases = match ($resource) {
            'clients', 'plans' => ['q' => 'search'],
            'contracts' => ['q' => 'search', 'agreement_status' => 'status'],
            'invoices' => ['q' => 'search', 'document_status' => 'status'],
            'receipts' => ['received_from' => 'from_on', 'received_to' => 'to_on'],
            default => [],
        };
        if ($resource === 'summary') {
            $aliases = [];
        }

        foreach ($aliases as $alias => $canonical) {
            if (! array_key_exists($alias, $input)) {
                continue;
            }
            if (array_key_exists($canonical, $input) && $input[$canonical] !== $input[$alias]) {
                throw ValidationException::withMessages([$alias => ['O filtro legado conflita com o filtro canônico.']]);
            }
            $input[$canonical] = $input[$canonical] ?? $input[$alias];
            unset($input[$alias]);
        }

        return $input;
    }
}
