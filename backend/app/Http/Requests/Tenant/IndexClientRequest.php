<?php

namespace App\Http\Requests\Tenant;

use App\Enums\ClientStatus;
use App\Enums\DeadlineStatus;
use App\Enums\TaxRegime;
use App\Models\Client;
use App\Models\Tag;
use App\Tenant\CurrentTenant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class IndexClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Client::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', $this->oneOf(fn (mixed $item): bool => is_string($item) && ClientStatus::tryFrom($item) !== null)],
            'tax_regime' => ['sometimes', $this->oneOf(fn (mixed $item): bool => is_string($item) && TaxRegime::tryFrom($item) !== null)],
            'deadline_status' => ['sometimes', $this->oneOf(fn (mixed $item): bool => is_string($item) && DeadlineStatus::tryFrom($item) !== null)],
            'certificate_status' => ['sometimes', $this->oneOf(fn (mixed $item): bool => is_string($item) && DeadlineStatus::tryFrom($item) !== null)],
            'poa_status' => ['sometimes', $this->oneOf(fn (mixed $item): bool => is_string($item) && DeadlineStatus::tryFrom($item) !== null)],
            'tag_id' => ['sometimes', $this->tagIds()],
            'client_id' => [
                'sometimes',
                'integer',
                Rule::exists('clients', 'id')->where(function ($query): void {
                    $query
                        ->where('account_id', resolve(CurrentTenant::class)->accountId)
                        ->whereNull('deleted_at');
                }),
            ],
            'view' => ['sometimes', Rule::in([
                'certificate_missing', 'certificate_valid', 'certificate_expiring', 'certificate_expired',
                'poa_missing', 'poa_valid', 'poa_expiring', 'poa_expired',
            ])],
            'sort' => ['sometimes', Rule::in(['name', 'tax_id', 'status', 'tax_regime', 'created_at', 'certificate', 'poa'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([25, 50, 100])],
            'all' => ['sometimes', 'boolean'],
            'sheet' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A scalar stays a single match. A list is a union, capped so the query stays bounded.
     *
     * @param  Closure(mixed): bool  $valid
     */
    private function oneOf(Closure $valid): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($valid): void {
            $values = is_array($value) ? array_values($value) : [$value];

            if ($values === [] || count($values) > 20 || count($values) !== count(array_unique(array_map(strval(...), $values)))) {
                $fail('O campo selecionado é inválido.');

                return;
            }

            foreach ($values as $item) {
                if (! $valid($item)) {
                    $fail('O campo selecionado é inválido.');

                    return;
                }
            }
        };
    }

    private function tagIds(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $values = is_array($value) ? array_values($value) : [$value];
            $ids = [];

            foreach ($values as $item) {
                if (! is_numeric($item) || (int) $item < 1 || in_array((int) $item, $ids, true)) {
                    $fail('O campo selecionado é inválido.');

                    return;
                }

                $ids[] = (int) $item;
            }

            if ($ids === [] || count($ids) > 20) {
                $fail('O campo selecionado é inválido.');

                return;
            }

            $found = Tag::query()
                ->where('account_id', resolve(CurrentTenant::class)->accountId)
                ->whereKey($ids)
                ->count();

            if ($found !== count($ids)) {
                $fail('O campo selecionado é inválido.');
            }
        };
    }
}
