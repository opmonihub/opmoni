<?php

namespace App\Http\Requests\Tenant;

use App\Enums\ClientPersonType;
use App\Models\SerproMonitoring;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A lista de clientes que a associação recebe. A validação é do lote inteiro:
 * um id que não seja PJ da conta corrente reprova o pedido — inserir os
 * válidos antes de falhar deixaria meio lote aplicado sob um `422`.
 */
class AssociateSerproClientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('associate', SerproMonitoring::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $accountId = resolve(CurrentTenant::class)->accountId;

        return [
            'client_ids' => ['required', 'array', 'min:1'],
            'client_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('clients', 'id')->where(fn ($query) => $query
                    ->where('account_id', $accountId)
                    ->where('person_type', ClientPersonType::Company)),
            ],
        ];
    }
}
