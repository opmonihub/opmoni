<?php

namespace App\Http\Requests\Tenant;

use App\Enums\TaskPriority;
use App\Enums\TaxRegime;
use App\Models\AccountUser;
use App\Models\ProcessTemplate;
use App\Services\DepartmentMembership;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProcessTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', ProcessTemplate::class);
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
        $accountId = resolve(CurrentTenant::class)->accountId;

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'cascade' => ['sometimes', 'boolean'],
            'generate_day' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'due_day' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'is_active' => ['sometimes', 'boolean'],
            'regimes' => ['sometimes', 'array'],
            'regimes.*' => [Rule::enum(TaxRegime::class)],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where(fn ($query) => $query->where('account_id', $accountId))],
            'exceptions' => ['sometimes', 'array'],
            'exceptions.*.client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where(fn ($query) => $query->where('account_id', $accountId))],
            'exceptions.*.kind' => ['required', Rule::in(['added', 'removed'])],
            'steps' => ['sometimes', 'array'],
            'steps.*.id' => ['sometimes', 'integer'],
            'steps.*.title' => ['required', 'string', 'max:255'],
            'steps.*.department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where(fn ($query) => $query->where('account_id', $accountId))],
            'steps.*.due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'steps.*.priority' => ['required', Rule::in(TaskPriority::values())],
            'steps.*.order' => ['required', 'integer', 'min:1'],
            'steps.*.description' => ['nullable', 'string'],
            'steps.*.default_assignee_member_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $steps = $this->input('steps', []);

            if (! is_array($steps)) {
                return;
            }

            $accountId = resolve(CurrentTenant::class)->accountId;

            foreach ($steps as $index => $step) {
                $departmentId = is_array($step) && isset($step['department_id'])
                    ? (int) $step['department_id']
                    : null;

                $assignee = is_array($step) ? ($step['default_assignee_member_id'] ?? null) : null;

                if ($assignee === null) {
                    continue;
                }

                $belongs = AccountUser::query()
                    ->where('account_id', $accountId)
                    ->where('user_id', $assignee)
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add(
                        "steps.$index.default_assignee_member_id",
                        'Responsável deve ser membro da conta.'
                    );

                    continue;
                }

                if ($departmentId !== null && ! DepartmentMembership::memberBelongs($accountId, $departmentId, (int) $assignee)) {
                    $validator->errors()->add(
                        "steps.$index.default_assignee_member_id",
                        'O responsável precisa pertencer ao departamento da etapa.'
                    );
                }
            }
        });
    }
}
