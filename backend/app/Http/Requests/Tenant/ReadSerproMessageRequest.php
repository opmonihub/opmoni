<?php

namespace App\Http\Requests\Tenant;

use App\Models\SerproMonitoring;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A confirmação da ciência vai no corpo e é obrigatória: sem `ciencia: true`
 * o pedido reprova com `422` antes de qualquer chamada ao provedor. Abrir o
 * diálogo não é consentimento, e a rota não pode aceitar uma leitura que o
 * Membro não confirmou.
 */
class ReadSerproMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('readMessage', SerproMonitoring::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ciencia' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ciencia.required' => 'Confirme que abrir a mensagem registra a ciência da intimação.',
            'ciencia.accepted' => 'Confirme que abrir a mensagem registra a ciência da intimação.',
        ];
    }
}
