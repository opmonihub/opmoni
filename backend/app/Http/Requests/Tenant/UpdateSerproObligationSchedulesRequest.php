<?php

namespace App\Http\Requests\Tenant;

use App\Services\SerproObligationCatalog;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A agenda por documento que Settings manda. A validação é do lote inteiro e
 * a lista pode ser vazia — "sem agendamento em nenhum documento" é decisão
 * legítima, e o `replace` do manager é quem apaga o que ficou de fora.
 */
class UpdateSerproObligationSchedulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $accountId = resolve(CurrentTenant::class)->accountId;

        // A agenda decide quando o gateway é cobrado pela rotina: é escrita
        // da rotina, e o par é o mesmo que dispara execução e associa
        // cliente — `admin` e `operador`. O `super_admin` em modo suporte
        // escreve por cima como admin, e o modo suporte audita.
        return $user !== null
            && in_array($user->accountRole((int) $accountId), ['admin', 'operador'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Só obrigação com leitura servida entra na agenda: um dia marcado
        // para documento que o catálogo não sincroniza criaria uma execução
        // mensal nascida para falhar.
        $slugs = collect(resolve(SerproObligationCatalog::class)->syncables())
            ->pluck('slug')
            ->all();

        return [
            'schedules' => ['present', 'array'],
            'schedules.*.obligation' => ['required', 'string', 'distinct', Rule::in($slugs)],
            'schedules.*.day' => ['required', 'integer', 'between:1,28'],
        ];
    }
}
