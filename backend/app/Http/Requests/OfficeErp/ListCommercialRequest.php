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
        'clients' => ['search', 'page', 'per_page'],
        'plans' => ['search', 'status', 'page', 'per_page'],
        'contracts' => ['search', 'status', 'page', 'per_page'],
        'invoices' => ['search', 'status', 'from_on', 'to_on', 'due_from', 'due_to', 'page', 'per_page'],
        'receipts' => ['search', 'status', 'from_on', 'to_on', 'page', 'per_page'],
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
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', Rule::in([25, 50, 100])],
                default => ['sometimes', 'date_format:Y-m-d'],
            };
        }

        return $rules;
    }

    /** @return list<string> */
    private static function statusValues(string $resource): array
    {
        $values = match ($resource) {
            'plans' => ['draft', 'active', 'archived'],
            'contracts' => ['draft', 'awaiting_owner', 'accepted', 'effective', 'canceled'],
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
}
