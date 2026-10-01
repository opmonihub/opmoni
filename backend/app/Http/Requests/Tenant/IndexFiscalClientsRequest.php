<?php

namespace App\Http\Requests\Tenant;

use App\Enums\FiscalModel;
use App\Models\FiscalDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Os filtros da visão fiscal por cliente.
 *
 * As três chaves são as mesmas da tabela de documentos (`model`,
 * `issued_from`, `issued_to`), com as mesmas regras: filtro inválido é 422
 * com a chave que está errada, e nunca um agregado vazio.
 */
class IndexFiscalClientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A mesma leitura de carteira da tabela (`FiscalDocumentPolicy::viewAny`):
        // o agregado conta o que a conta capturou, e é visível para qualquer
        // membro.
        return Gate::allows('viewAny', FiscalDocument::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'model' => ['sometimes', 'array', 'max:20'],
            'model.*' => ['required', Rule::in(array_column(FiscalModel::cases(), 'value'))],
            'issued_from' => ['sometimes', 'date_format:Y-m-d'],
            'issued_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:issued_from'],
        ];
    }

    /**
     * @return array{model?: list<string>, issued_from?: string, issued_to?: string}
     */
    public function filters(): array
    {
        return $this->validated();
    }
}
