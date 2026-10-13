<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Models\SerproMonitoring;
use App\Models\WhatsAppChannel;
use App\Models\WhatsAppDispatch;
use App\Services\WhatsApp\WhatsAppDispatchService;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * O Disparo e o Rastreio da folha PGDAS: o POST `send-guides` enfileira
 * a guia vigente para os Contatos de envio marcados, o POST legado por
 * artefato segue atendendo o modal antigo do detalhe da declaração, e o
 * GET devolve o histórico do cliente (a célula filtra por competência,
 * o painel lê tudo).
 *
 * O `{obligation}` carrega a barra do slug (`declaracoes/pgdas`) e o
 * `where('.+')` da rota a preserva. O feature flag `whatsapp.enabled`
 * desliga o Disparo inteiro — rota e serviço respondem 404 quando a
 * conta não tem a funcionalidade ligada.
 */
class WhatsAppDispatchController extends Controller
{
    public function __construct(private WhatsAppDispatchService $service) {}

    /**
     * O Enviar guias do modal: um POST, um Disparo `guia` por Contato de
     * envio WhatsApp marcado. `resend_acknowledgements` carrega os ids
     * com Ciência de reenvio — a exceção que deixa a mesma guia sair de
     * novo para o mesmo contato.
     */
    public function sendGuides(string $obligation, int $client, Request $request): JsonResponse
    {
        $this->garantirSlug($obligation);
        abort_unless((bool) config('whatsapp.enabled'), 404);

        Gate::authorize('create', WhatsAppDispatch::class);

        $dados = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer', 'min:1'],
            'resend_acknowledgements' => ['sometimes', 'array'],
            'resend_acknowledgements.*' => ['integer', 'min:1'],
        ]);

        $accountId = (int) resolve(CurrentTenant::class)->accountId;

        try {
            $resultado = $this->service->sendGuides(
                $accountId,
                $client,
                array_map('intval', $dados['contact_ids']),
                array_map('intval', $dados['resend_acknowledgements'] ?? []),
                $request->user(),
                $request,
            );
        } catch (ValidationException $exception) {
            return $this->resposta422($exception);
        }

        return response()->json([
            'data' => [
                'competence' => $resultado['competence'],
                'slip_number' => $resultado['slip_number'],
                'dispatches' => array_map(
                    fn (WhatsAppDispatch $d): array => $this->payload($d),
                    $resultado['dispatches'],
                ),
                'skipped' => $resultado['skipped'],
            ],
        ], 202);
    }

    public function store(string $obligation, int $client, Request $request): JsonResponse
    {
        $this->garantirSlug($obligation);
        abort_unless((bool) config('whatsapp.enabled'), 404);

        Gate::authorize('create', WhatsAppDispatch::class);

        $dados = $request->validate([
            'competence' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'artifact' => ['required', 'string', 'in:guia,recibo,declaracao'],
        ]);

        $accountId = (int) resolve(CurrentTenant::class)->accountId;

        try {
            $resultado = $this->service->dispatch(
                $accountId,
                $client,
                $dados['competence'],
                $dados['artifact'],
                $request->user(),
                $request,
            );
        } catch (ValidationException $exception) {
            return $this->resposta422($exception);
        }

        return response()->json(['data' => $this->payload($resultado['dispatch'])], 202);
    }

    public function index(string $obligation, int $client, Request $request): JsonResponse
    {
        $this->garantirSlug($obligation);
        abort_unless((bool) config('whatsapp.enabled'), 404);

        Gate::authorize('viewAny', WhatsAppDispatch::class);

        $dados = $request->validate([
            'competence' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'artifact' => ['sometimes', 'nullable', 'string', 'in:guia,recibo,declaracao'],
        ]);

        $accountId = (int) resolve(CurrentTenant::class)->accountId;

        // O cliente precisa ser desta conta — id alheio é 404, nunca 403.
        $existe = SerproMonitoring::query()
            ->where('client_id', $client)
            ->where('obligation', $obligation)
            ->exists();

        abort_unless($existe, 404);

        $dispatches = $this->service->rastreio(
            $accountId,
            $client,
            $dados['competence'] ?? null,
            $dados['artifact'] ?? null,
        );

        return response()->json(['data' => $dispatches->map(fn (WhatsAppDispatch $d): array => $this->payload($d))->all()]);
    }

    private function garantirSlug(string $obligation): void
    {
        abort_unless($obligation === WhatsAppDispatch::OBLIGATION_DECLARACOES_PGDAS, 404);
    }

    /**
     * O 422 do Disparo nomeia o que bloqueou — canal, telefone, artefato
     * ou competência — no formato `{"message": ..., "errors": {...}}` do
     * Laravel, com `data.channel_status` quando o bloqueio é do canal.
     */
    private function resposta422(ValidationException $exception): JsonResponse
    {
        $errors = $exception->errors();
        $channel = isset($errors['channel'])
            ? WhatsAppChannel::forAccount((int) resolve(CurrentTenant::class)->accountId)
            : null;

        return response()->json([
            'message' => $exception->getMessage(),
            'errors' => $errors,
            'data' => array_filter([
                'channel_status' => $channel?->status,
            ]),
        ], 422);
    }

    /**
     * O que a folha e o painel leem por linha de Rastreio — o destino vai
     * no sub-objeto `contact` (rótulo e número do Contato de envio), e
     * nada aqui carrega apikey ou conteúdo de documento.
     *
     * @return array<string, mixed>
     */
    private function payload(WhatsAppDispatch $dispatch): array
    {
        $contato = $dispatch->relationLoaded('dispatchContact')
            ? $dispatch->dispatchContact
            : $dispatch->dispatchContact()->first();

        return [
            'id' => $dispatch->getKey(),
            'client_id' => $dispatch->client_id,
            'obligation' => $dispatch->obligation,
            'artifact_type' => $dispatch->artifact_type,
            'competence' => $dispatch->competence,
            'slip_number' => $dispatch->slip_number,
            'contact' => $contato === null ? null : [
                'id' => $contato->getKey(),
                'label' => $contato->label,
                'channel' => $contato->channel,
                'value' => $contato->value,
            ],
            'status' => $dispatch->status,
            'message_id' => $dispatch->message_id,
            'failure_reason' => $dispatch->failure_reason,
            'dispatched_in_support' => (bool) $dispatch->dispatched_in_support,
            'created_at' => $dispatch->created_at?->toIso8601String(),
            'updated_at' => $dispatch->updated_at?->toIso8601String(),
        ];
    }
}
