<?php

namespace App\Http\Requests\Tenant;

use App\Models\AccountUser;
use App\Models\Department;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Department::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:40',
                Rule::unique('departments', 'name')->where(
                    fn ($query) => $query->where('account_id', resolve(CurrentTenant::class)->accountId)
                ),
            ],
            'color' => ['required', Rule::in(['neutral', 'primary', 'success', 'info', 'warning', 'error'])],
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $this->validateNameCaseInsensitive($validator);
                $this->validateMemberIds($validator);
            },
        ];
    }

    private function validateNameCaseInsensitive($validator): void
    {
        $name = $this->input('name');

        if (! is_string($name) || $name === '') {
            return;
        }

        $tenantId = resolve(CurrentTenant::class)->accountId;

        $exists = Department::withoutGlobalScopes()
            ->where('account_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            $validator->errors()->add('name', 'Já existe um departamento com este nome.');
        }
    }

    private function validateMemberIds($validator): void
    {
        $memberIds = $this->input('member_ids', []);

        if (! is_array($memberIds) || $memberIds === []) {
            return;
        }

        $tenantId = resolve(CurrentTenant::class)->accountId;

        $validCount = AccountUser::query()
            ->where('account_id', $tenantId)
            ->whereIn('user_id', $memberIds)
            ->distinct()
            ->count('user_id');

        if ($validCount !== count(array_unique($memberIds))) {
            $validator->errors()->add('member_ids', 'Todos os membros precisam pertencer ao account atual.');
        }
    }
}
