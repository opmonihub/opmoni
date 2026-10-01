<?php

namespace App\Http\Requests\Tenant;

use App\Enums\TaskStatus;
use App\Models\AccountUser;
use App\Models\Task;
use App\Services\DepartmentMembership;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task && Gate::allows('update', $task);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', 'string', Rule::in(TaskStatus::values())],
            'dismissal_reason' => ['nullable', 'string', 'max:2000', 'required_if:status,dismissed'],
            'assignee_member_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'due_on' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $assignee = $this->input('assignee_member_id');

            if ($assignee === null) {
                return;
            }

            $accountId = resolve(CurrentTenant::class)->accountId;

            $belongs = AccountUser::query()
                ->where('account_id', $accountId)
                ->where('user_id', $assignee)
                ->exists();

            if (! $belongs) {
                $validator->errors()->add('assignee_member_id', 'Responsável deve ser membro da conta.');

                return;
            }

            $task = $this->route('task');

            if (! $task instanceof Task) {
                return;
            }

            // Departamento excluído libera a task (nullOnDelete): sem vínculo,
            // não há pertencimento a verificar.
            if ($task->department_id !== null && ! DepartmentMembership::memberBelongs($accountId, (int) $task->department_id, (int) $assignee)) {
                $validator->errors()->add('assignee_member_id', 'O responsável precisa pertencer ao departamento da tarefa.');
            }
        });
    }
}
