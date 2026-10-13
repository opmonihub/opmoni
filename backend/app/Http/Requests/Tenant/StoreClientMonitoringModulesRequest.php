<?php

namespace App\Http\Requests\Tenant;

use App\Enums\ClientPersonType;
use App\Models\Client;
use App\Services\SerproObligationCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * A lista de slugs da etapa de módulos: validada no lote inteiro — um slug
 * que o catálogo não conhece, ou que o catálogo não serve, reprova o pedido
 * sem gravar metade dele. A pessoa física reprova na mesma porta: a
 * integração só age por PJ, e a regra fica aqui e não no controller.
 */
class StoreClientMonitoringModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $client = $this->route('client');

        return $client instanceof Client && Gate::allows('update', $client);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'obligations' => ['required', 'array', 'min:1'],
            'obligations.*' => ['string', 'distinct'],
        ];
    }

    /**
     * A segunda metade da validação, depois que o lote passou no formato:
     * pessoa jurídica e todo slug servido pelo catálogo. O `404` de slug
     * desconhecido mora no `associate`, mas ele chegaria já dentro da
     * transação — reprovar aqui é o que garante que nada foi gravado antes.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $client = $this->route('client');

            if (! $client instanceof Client) {
                return;
            }

            if ($client->person_type !== ClientPersonType::Company) {
                $validator->errors()->add('obligations', 'Módulos de monitoramento só se aplicam a pessoa jurídica.');

                return;
            }

            $catalogo = resolve(SerproObligationCatalog::class);

            foreach ((array) $this->input('obligations', []) as $indice => $slug) {
                $obrigacao = $catalogo->get((string) $slug);

                if ($obrigacao === null || ! in_array($obrigacao['category'], ['direct', 'derived'], true)) {
                    $validator->errors()->add("obligations.{$indice}", 'Obrigação desconhecida ou sem fonte de leitura.');
                }
            }
        });
    }
}
