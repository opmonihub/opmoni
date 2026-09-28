<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\SerproAuthorizationTermResource;
use App\Models\Account;
use App\Models\SerproAuthorizationTerm;
use App\Tenant\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A leitura do termo de autorização do escritório.
 *
 * **Só existe leitura, e a ausência de escrita é a decisão, não uma
 * omissão.** O termo é assinado pelo e-CNPJ do escritório e emitido pela
 * plataforma; nenhum Membro tem o material para assinar e nenhum tem o que
 * pedir ao provedor em nome do escritório. Emitir é do job que o upload do
 * e-CNPJ despacha e renovar é da agenda — nenhum dos dois passa por aqui.
 *
 * **Uma conta sem termo responde `200` com `ausente`, e não `404`.** A
 * ausência é um estado do produto — é ele que manda a tela pedir o e-CNPJ —,
 * e um `404` seria indistinguível de rota errada para quem está do outro
 * lado da API.
 *
 * O controller não decifra, não decide estado e não formata material. A
 * busca é por `account_id` explícito, o que faz o termo de outra conta não
 * aparecer em nenhuma hipótese.
 */
class SerproAuthorizationTermController extends Controller
{
    public function __invoke(Request $request): SerproAuthorizationTermResource
    {
        Gate::authorize('viewAny', SerproAuthorizationTerm::class);

        $conta = Account::findOrFail(resolve(CurrentTenant::class)->accountId);

        return new SerproAuthorizationTermResource(SerproAuthorizationTerm::currentFor($conta->getKey()));
    }
}
