<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientDispatchContact;
use App\Services\SupportAudit;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * O CRUD dos Contatos de envio do cliente, aninhado na carteira:
 * `clients/{client}/dispatch-contacts`.
 *
 * O `{client}` resolve pelo binding escopado do tenant — id de cliente
 * de outra conta é 404 antes da policy. O `{contact}` é revalidado contra
 * o cliente da rota: contato de outro cliente (mesma conta ou não) é 404,
 * porque a URL declara o dono e quem não casa não é deste recurso.
 *
 * A feature flag `whatsapp.enabled` desliga as quatro rotas com 404, no
 * mesmo padrão do Canal e do Disparo.
 */
class ClientDispatchContactController extends Controller
{
    public function index(Client $client): JsonResponse
    {
        abort_unless((bool) config('whatsapp.enabled'), 404);

        Gate::authorize('viewAny', ClientDispatchContact::class);

        $contatos = ClientDispatchContact::query()
            ->where('client_id', $client->getKey())
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $contatos->map(fn (ClientDispatchContact $c): array => $this->payload($c))->all()]);
    }

    public function store(Client $client, Request $request): JsonResponse
    {
        abort_unless((bool) config('whatsapp.enabled'), 404);

        Gate::authorize('create', ClientDispatchContact::class);

        $dados = $this->validar($request);

        $contato = new ClientDispatchContact($dados);
        $contato->client()->associate($client);
        $contato->account_id = $client->account_id;

        try {
            $contato->save();
        } catch (QueryException $exception) {
            // O segundo clique reencontra a unique (cliente, canal, valor):
            // é 422 com o campo bloqueante, como na régua do Disparo.
            throw ValidationException::withMessages([
                'value' => ['Este cliente já tem um Contato de envio com este canal e valor.'],
            ]);
        }

        SupportAudit::logWrite($request, 'client_dispatch_contacts', 'create', $contato->getKey(), [
            'client_id' => $client->getKey(),
            'channel' => $contato->channel,
        ]);

        return response()->json(['data' => $this->payload($contato)], 201);
    }

    public function update(Client $client, int $contact, Request $request): JsonResponse
    {
        abort_unless((bool) config('whatsapp.enabled'), 404);

        $contato = $this->contatoDoCliente($client, $contact);

        Gate::authorize('update', $contato);

        $dados = $this->validar($request, updating: true, contact: $contato);

        try {
            $contato->fill($dados)->save();
        } catch (QueryException $exception) {
            throw ValidationException::withMessages([
                'value' => ['Este cliente já tem um Contato de envio com este canal e valor.'],
            ]);
        }

        SupportAudit::logWrite($request, 'client_dispatch_contacts', 'update', $contato->getKey(), [
            'client_id' => $client->getKey(),
        ]);

        return response()->json(['data' => $this->payload($contato)]);
    }

    public function destroy(Client $client, int $contact, Request $request): JsonResponse
    {
        abort_unless((bool) config('whatsapp.enabled'), 404);

        $contato = $this->contatoDoCliente($client, $contact);

        Gate::authorize('delete', $contato);

        $contato->delete();

        SupportAudit::logWrite($request, 'client_dispatch_contacts', 'delete', $contact, [
            'client_id' => $client->getKey(),
        ]);

        return response()->json(null, 204);
    }

    /**
     * O contato só existe dentro do cliente da rota — id alheio (mesma
     * conta ou não) é 404, porque a URL declara o dono.
     */
    private function contatoDoCliente(Client $client, int $contactId): ClientDispatchContact
    {
        $contato = ClientDispatchContact::query()
            ->where('client_id', $client->getKey())
            ->find($contactId);

        abort_unless($contato !== null, 404);

        return $contato;
    }

    /**
     * A régua do cadastro: `whatsapp` exige telefone plausível (10–13
     * dígitos, a mesma que o Disparo aplica), `email` exige endereço
     * válido, e o canal fora do par é 422. Na edição o canal não troca —
     * mudar de canal é um contato novo.
     *
     * @return array{channel: string, label: ?string, value: string}
     */
    private function validar(Request $request, bool $updating = false, ?ClientDispatchContact $contact = null): array
    {
        $dados = $request->validate([
            // Na edição o canal não troca — mudar de canal é um contato
            // novo — então mandar `channel` no update é 422, não ignore.
            'channel' => [$updating ? 'prohibited' : 'required', 'string', Rule::in(ClientDispatchContact::CHANNELS)],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'value' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
        ]);

        $channel = $contact !== null ? $contact->channel : $dados['channel'];
        // A régua do canal corre só sobre o valor que chegou no request:
        // o valor gravado passa intacto num PATCH de rótulo — o backfill
        // semeia telefones legados fora dos 10–13 dígitos, e um PATCH
        // que não toca `value` não pode tornar esse contato ineditável.
        $valueNovo = array_key_exists('value', $dados);
        $value = $dados['value'] ?? $contact?->value ?? '';

        if ($valueNovo
            && $channel === ClientDispatchContact::CHANNEL_WHATSAPP
            && ! ClientDispatchContact::telefonePlausivel($value)) {
            throw ValidationException::withMessages([
                'value' => ['O telefone está fora do padrão para envio por WhatsApp.'],
            ]);
        }

        if ($valueNovo
            && $channel === ClientDispatchContact::CHANNEL_EMAIL
            && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages([
                'value' => ['O e-mail informado não é válido.'],
            ]);
        }

        return [
            // `??` trata `null` como ausência: um `label: null` explícito
            // é remoção do rótulo, e só `array_key_exists` a distingue.
            'label' => array_key_exists('label', $dados) ? $dados['label'] : $contact?->label,
            'channel' => $channel,
            'value' => ClientDispatchContact::normalizarValor($channel, $value),
        ];
    }

    /**
     * O que a folha e o modal leem — nunca mais que id, rótulo, canal e
     * valor. O telefone normalizado é o próprio destino, não um segredo.
     *
     * @return array<string, mixed>
     */
    private function payload(ClientDispatchContact $contact): array
    {
        return [
            'id' => $contact->getKey(),
            'client_id' => $contact->client_id,
            'label' => $contact->label,
            'channel' => $contact->channel,
            'value' => $contact->value,
            'created_at' => $contact->created_at?->toIso8601String(),
            'updated_at' => $contact->updated_at?->toIso8601String(),
        ];
    }
}
