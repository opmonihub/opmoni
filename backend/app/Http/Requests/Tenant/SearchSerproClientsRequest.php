<?php

namespace App\Http\Requests\Tenant;

use App\Enums\SerproManualSearchMode;
use App\Models\SerproMonitoring;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A lista de clientes que a busca manual recebe, com o recorte opcional. O
 * lote é validado inteiro — o mesmo desenho da associação —, e o gate é o
 * do papel que associa clientes: pedir busca gasta cota do provedor do mesmo
 * jeito, e quem não pode associar não pode cobrar por dentro.
 */
class SearchSerproClientsRequest extends FormRequest
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
                    ->where('person_type', 'company')),
            ],
            // Modo e data de recálculo são o que o modal manda junto: o modo
            // é metadado do pedido (nenhum serviço documenta filtro de
            // recorte) e a data fica registrada até um serviço documentar
            // período — o job é quem decide o que o provedor aceita.
            'mode' => ['nullable', Rule::enum(SerproManualSearchMode::class)],
            'recalculate_date' => ['nullable', 'date'],
        ];
    }
}
