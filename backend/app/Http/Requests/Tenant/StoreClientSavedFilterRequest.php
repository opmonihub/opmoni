<?php

namespace App\Http\Requests\Tenant;

use App\Enums\ClientStatus;
use App\Enums\DeadlineStatus;
use App\Enums\TaxRegime;
use App\Models\ClientSavedFilter;
use App\Models\Tag;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClientSavedFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', ClientSavedFilter::class);
    }

    protected function prepareForValidation(): void
    {
        $name = trim((string) $this->input('name', ''));
        $query = trim((string) $this->input('q', ''));
        $filters = collect($this->input('filters', []))
            ->map(function (mixed $filter): mixed {
                if (! is_array($filter) || ! isset($filter['values']) || ! is_array($filter['values'])) {
                    return $filter;
                }

                $filter['values'] = array_map(
                    fn (mixed $value): mixed => is_scalar($value) ? (string) $value : $value,
                    $filter['values']
                );

                return $filter;
            })
            ->all();

        $this->merge([
            'name' => $name,
            'q' => $query === '' ? null : $query,
            'filters' => $filters,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $accountId = resolve(CurrentTenant::class)->accountId;

        return [
            'name' => [
                'required',
                'string',
                'max:40',
                Rule::unique('client_saved_filters', 'name')->where(
                    fn ($query) => $query
                        ->where('account_id', $accountId)
                        ->where('user_id', $this->user()?->getKey())
                ),
            ],
            'q' => ['nullable', 'string', 'max:120'],
            'filters' => ['array', 'max:5'],
            'filters.*.columnId' => ['required', 'string', 'distinct', Rule::in(['tag', 'regime', 'status', 'certificate', 'poa'])],
            'filters.*.operator' => ['required', 'string', Rule::in(['is', 'is not', 'is any of', 'is none of'])],
            'filters.*.values' => ['required', 'array', 'min:1', 'max:20'],
            'filters.*.values.*' => ['required', 'string', 'max:40'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('q') === null && $this->input('filters') === []) {
                $validator->errors()->add('filters', 'Salve uma busca ou ao menos um filtro.');

                return;
            }

            if (ClientSavedFilter::query()->count() >= 20) {
                $validator->errors()->add('name', 'Você já salvou 20 filtros.');
            }

            foreach ($this->input('filters', []) as $index => $filter) {
                foreach ($filter['values'] as $valueIndex => $value) {
                    if (! $this->valueAllowed($filter['columnId'], $value)) {
                        $validator->errors()->add(
                            "filters.$index.values.$valueIndex",
                            'Valor de filtro inválido.'
                        );
                    }
                }
            }
        });
    }

    private function valueAllowed(string $columnId, string $value): bool
    {
        return match ($columnId) {
            'regime' => TaxRegime::tryFrom($value) !== null,
            'status' => ClientStatus::tryFrom($value) !== null,
            'certificate', 'poa' => DeadlineStatus::tryFrom($value) !== null,
            'tag' => ctype_digit($value) && Tag::query()->whereKey((int) $value)->exists(),
            default => false,
        };
    }
}
