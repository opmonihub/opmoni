<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppDocumentJob;
use App\Models\Client;
use App\Models\ClientDispatchContact;
use App\Models\SerproMonitoring;
use App\Models\SupportAccessLog;
use App\Models\User;
use App\Models\WhatsAppChannel;
use App\Models\WhatsAppDispatch;
use App\Services\SupportAudit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A regra do Disparo explícito: valida canal, destino e artefato, cria a
 * linha `queued` idempotente e enfileira o envio. Nenhum byte de PDF
 * passa por aqui — o job lê do mesmo lugar que o download.
 *
 * A idempotência é a chave, não o relógio: `idempotency_key` deriva do
 * envio lógico (conta + cliente + contato + obrigação + artefato +
 * competência + slip_number), e a unique `(account_id, idempotency_key)`
 * faz do segundo clique um reencontro — inclusive quando dois POSTs
 * correm. O que libera um novo envio da mesma guia é a guia mudar
 * (`slip_number` novo) ou a Ciência de reenvio, que gera chave única.
 */
final class WhatsAppDispatchService
{
    /**
     * O Enviar guias: um Disparo `guia` por Contato de envio WhatsApp
     * marcado, mirando a guia do período mais recente disponível.
     *
     * Contato `email` é listado mas nunca dispara — volta em `skipped`.
     * Contato WhatsApp que já recebeu esta mesma guia (mesmo
     * `slip_number`) também é `skipped`, a menos que o request traga a
     * Ciência de reenvio dele. Telefone fora da régua é `skipped` com o
     * motivo — um cadastro ruim não derruba o lote dos demais.
     *
     * @param  list<int>  $contactIds
     * @param  list<int>  $resendAcknowledged
     * @return array{competence: string, slip_number: ?string, dispatches: list<WhatsAppDispatch>, skipped: list<array{contact_id: int, reason: string}>}
     *
     * @throws ValidationException 422 com o motivo legível (canal, guia,
     *                             contatos, nenhum WhatsApp elegível)
     */
    public function sendGuides(
        int $accountId,
        int $clientId,
        array $contactIds,
        array $resendAcknowledged,
        User $actor,
        ?Request $request = null,
    ): array {
        $this->garantirClienteDaConta($accountId, $clientId);
        $this->garantirCanalConectado($accountId);

        $alvo = $this->guiaAlvo($accountId, $clientId);

        if ($alvo === null) {
            throw ValidationException::withMessages([
                'artifact' => ['Não há guia disponível para envio nesta obrigação.'],
            ]);
        }

        $contatos = ClientDispatchContact::query()
            ->where('account_id', $accountId)
            ->where('client_id', $clientId)
            ->whereIn('id', $contactIds)
            ->orderBy('id')
            ->get();

        if ($contatos->count() !== count(array_unique($contactIds))) {
            // Neutro de propósito: id inexistente, de outro cliente ou de
            // outra conta resolvem igual — o erro não diz qual era qual.
            throw ValidationException::withMessages([
                'contact_ids' => ['Contato de envio inválido para este cliente.'],
            ]);
        }

        $dispatches = [];
        $skipped = [];
        $whatsappMarcados = 0;
        $ciencia = array_flip($resendAcknowledged);

        foreach ($contatos as $contato) {
            if ($contato->channel === ClientDispatchContact::CHANNEL_WHATSAPP) {
                $whatsappMarcados++;
            }

            if ($contato->channel !== ClientDispatchContact::CHANNEL_WHATSAPP) {
                $skipped[] = [
                    'contact_id' => $contato->getKey(),
                    'reason' => 'Contato de e-mail não recebe envio por WhatsApp.',
                ];

                continue;
            }

            if (! ClientDispatchContact::telefonePlausivel((string) $contato->value)) {
                $skipped[] = [
                    'contact_id' => $contato->getKey(),
                    'reason' => 'O telefone do contato está fora do padrão para envio por WhatsApp.',
                ];

                continue;
            }

            if ($this->guiaJaEnviada($accountId, $clientId, $contato->getKey(), $alvo)
                && ! isset($ciencia[$contato->getKey()])) {
                $skipped[] = [
                    'contact_id' => $contato->getKey(),
                    'reason' => 'Este contato já recebeu esta guia.',
                ];

                continue;
            }

            $reenvio = isset($ciencia[$contato->getKey()]);

            $dispatches[] = $this->criarDispatch(
                $accountId,
                $clientId,
                $contato,
                'guia',
                (string) $alvo['period'],
                $alvo['slip_number'] ?? null,
                $actor,
                $request,
                $reenvio,
            )['dispatch'];
        }

        // O 422 é "nenhum WhatsApp marcado" — a modal nem deveria
        // confirmar assim. Com ao menos um WhatsApp na lista, o resultado
        // é 202 mesmo quando todo mundo volta em `skipped` (guia já
        // enviada, telefone inválido): a tela lê os motivos por contato.
        if ($whatsappMarcados === 0) {
            throw ValidationException::withMessages([
                'contact_ids' => ['Nenhum Contato de envio WhatsApp elegível foi marcado.'],
            ]);
        }

        return [
            'competence' => (string) $alvo['period'],
            'slip_number' => $alvo['slip_number'] ?? null,
            'dispatches' => $dispatches,
            'skipped' => $skipped,
        ];
    }

    /**
     * O POST legado por artefato (guia/recibo/declaracao), ainda chamado
     * pelo modal antigo do detalhe da declaração. O destino deixa de ser
     * `clients.phone` e passa a ser o Contato de envio WhatsApp mais
     * antigo do cliente — o mesmo que o backfill de `phone` semeia — e a
     * linha nasce ligada a ele. Sem contato, cai no fallback legado com
     * `client_dispatch_contact_id` nulo.
     *
     * A guia segue a competência pedida; o `slip_number` da linha é o do
     * período, para que a regra de reenvio reconheça o recálculo.
     *
     * @return array{dispatch: WhatsAppDispatch, enqueued: bool}
     *
     * @throws ValidationException 422 com o motivo legível (canal,
     *                             destino, artefato, competência)
     */
    public function dispatch(
        int $accountId,
        int $clientId,
        string $competence,
        string $artifact,
        User $actor,
        ?Request $request = null,
    ): array {
        $this->garantirCanalConectado($accountId);
        $linha = $this->garantirArtefato($accountId, $clientId, $competence, $artifact);
        $contato = $this->contatoDestinoLegado($accountId, $clientId);

        if ($contato === null) {
            $this->garantirTelefone($accountId, $clientId);
        } elseif (! ClientDispatchContact::telefonePlausivel((string) $contato->value)) {
            throw ValidationException::withMessages([
                'phone' => ['O telefone do contato está fora do padrão para envio por WhatsApp.'],
            ]);
        }

        return $this->criarDispatch(
            $accountId,
            $clientId,
            $contato,
            $artifact,
            $competence,
            $linha['slip_number'] ?? null,
            $actor,
            $request,
            false,
        );
    }

    /**
     * Rastreio da linha: os disparos do cliente na obrigação PGDAS, com
     * competência e artefato opcionais — o painel lê o histórico
     * completo, e a célula da folha filtra pela competência.
     *
     * @return Collection<int, WhatsAppDispatch>
     */
    public function rastreio(int $accountId, int $clientId, ?string $competence, ?string $artifact): Collection
    {
        return WhatsAppDispatch::query()
            ->where('account_id', $accountId)
            ->where('client_id', $clientId)
            ->where('obligation', WhatsAppDispatch::OBLIGATION_DECLARACOES_PGDAS)
            ->when($competence !== null, fn ($query) => $query->where('competence', $competence))
            ->when($artifact !== null, fn ($query) => $query->where('artifact_type', $artifact))
            ->with('dispatchContact')
            ->latest('id')
            ->get();
    }

    /**
     * Cria (ou reencontra) o Disparo e o enfileira quando é novo. A
     * Ciência de reenvio entra como `resend`: a chave ganha um sufixo
     * único e a linha nasce sem colidir com a anterior da mesma guia.
     *
     * @return array{dispatch: WhatsAppDispatch, enqueued: bool}
     */
    private function criarDispatch(
        int $accountId,
        int $clientId,
        ?ClientDispatchContact $contato,
        string $artifact,
        string $competence,
        ?string $slipNumber,
        User $actor,
        ?Request $request,
        bool $resend,
    ): array {
        $key = WhatsAppDispatch::idempotencyKey(
            $accountId,
            $clientId,
            WhatsAppDispatch::OBLIGATION_DECLARACOES_PGDAS,
            $artifact,
            $competence,
            $contato?->getKey(),
            $slipNumber,
            $resend ? (string) Str::uuid() : null,
        );

        $existente = WhatsAppDispatch::query()
            ->where('account_id', $accountId)
            ->where('idempotency_key', $key)
            ->first();

        if ($existente !== null) {
            return ['dispatch' => $existente, 'enqueued' => false];
        }

        $supportLogId = $this->auditar($request, $actor, $accountId, $clientId);

        try {
            $dispatch = new WhatsAppDispatch([
                'client_id' => $clientId,
                'client_dispatch_contact_id' => $contato?->getKey(),
                'obligation' => WhatsAppDispatch::OBLIGATION_DECLARACOES_PGDAS,
                'artifact_type' => $artifact,
                'competence' => $competence,
                'slip_number' => $slipNumber,
                'idempotency_key' => $key,
                'status' => WhatsAppDispatch::STATUS_QUEUED,
                'dispatched_by_user_id' => $actor->getKey(),
                'dispatched_in_support' => $supportLogId !== null,
                'support_access_log_id' => $supportLogId,
            ]);
            $dispatch->account_id = $accountId;
            $dispatch->save();
        } catch (UniqueConstraintViolationException) {
            // Corrida: outro POST gravou a chave entre o lookup e o insert.
            // A resposta é a linha que venceu — nunca 500, nunca segundo envio.
            $vencedor = WhatsAppDispatch::query()
                ->where('account_id', $accountId)
                ->where('idempotency_key', $key)
                ->firstOrFail();

            return ['dispatch' => $vencedor, 'enqueued' => false];
        }

        SendWhatsAppDocumentJob::dispatch($accountId, $dispatch->getKey());

        return ['dispatch' => $dispatch, 'enqueued' => true];
    }

    /**
     * A guia alvo do Enviar guias: o período mais recente com guia
     * disponível — `slip_number` (DAS emitido) ou declaração transmitida
     * (GERARDAS12 gera a guia na hora). PGDAS não traz `due_on`
     * sincronizado, então não há filtro de vencimento: "vigente" aqui é
     * "a mais recente disponível", e o sort por `period` desc decide.
     *
     * @return array{period: string, slip_number: ?string}|null
     */
    private function guiaAlvo(int $accountId, int $clientId): ?array
    {
        $registro = $this->monitoringPgdas($accountId, $clientId);

        if ($registro === null) {
            return null;
        }

        $linha = collect($registro->periods ?? [])
            ->filter(fn (array $item): bool => (string) ($item['slip_number'] ?? '') !== ''
                || ($item['declared_at'] ?? null) !== null)
            ->sortByDesc(fn (array $item): string => (string) ($item['period'] ?? ''))
            ->first();

        if ($linha === null) {
            return null;
        }

        $slip = (string) ($linha['slip_number'] ?? '');

        return [
            'period' => (string) $linha['period'],
            'slip_number' => $slip !== '' ? $slip : null,
        ];
    }

    /**
     * A regra de reenvio: esta guia (mesma `slip_number`, incluindo o
     * `null` da guia por `declared_at`) já tem um Disparo bem aceito pelo
     * gateway para este contato — `queued`, `sent`, `delivered` ou `read`.
     * Uma linha `failed` não segura o contato: a falha já está no
     * Rastreio e a nova tentativa é legítima.
     *
     * @param  array{period: string, slip_number: ?string}  $alvo
     */
    private function guiaJaEnviada(int $accountId, int $clientId, int $contactId, array $alvo): bool
    {
        return WhatsAppDispatch::query()
            ->where('account_id', $accountId)
            ->where('client_id', $clientId)
            ->where('client_dispatch_contact_id', $contactId)
            ->where('obligation', WhatsAppDispatch::OBLIGATION_DECLARACOES_PGDAS)
            ->where('artifact_type', 'guia')
            ->where('competence', $alvo['period'])
            ->where('status', '!=', WhatsAppDispatch::STATUS_FAILED)
            ->where(function ($query) use ($alvo): void {
                $alvo['slip_number'] === null
                    ? $query->whereNull('slip_number')
                    : $query->where('slip_number', $alvo['slip_number']);
            })
            ->exists();
    }

    /**
     * O destino do POST legado: o Contato de envio WhatsApp mais antigo
     * do cliente — o registro que o backfill de `clients.phone` criou, ou
     * o primeiro que o escritório cadastrou. `null` cai no telefone da
     * carteira, como antes dos contatos existirem.
     */
    private function contatoDestinoLegado(int $accountId, int $clientId): ?ClientDispatchContact
    {
        return ClientDispatchContact::query()
            ->where('account_id', $accountId)
            ->where('client_id', $clientId)
            ->where('channel', ClientDispatchContact::CHANNEL_WHATSAPP)
            ->orderBy('id')
            ->first();
    }

    /**
     * O Disparo só existe com o canal `connected` — qualquer outro estado
     * é um 422 que nomeia o estado, e a tela desabilita o botão com ele.
     */
    private function garantirCanalConectado(int $accountId): void
    {
        $channel = WhatsAppChannel::forAccount($accountId);

        if ($channel === null) {
            throw ValidationException::withMessages([
                'channel' => ['O escritório não tem Canal WhatsApp pareado.'],
            ]);
        }

        if (! $channel->isConnected()) {
            throw ValidationException::withMessages([
                'channel' => ["O Canal WhatsApp não está conectado (estado: {$channel->status})."],
            ]);
        }
    }

    private function garantirClienteDaConta(int $accountId, int $clientId): void
    {
        $existe = Client::query()
            ->where('account_id', $accountId)
            ->whereKey($clientId)
            ->exists();

        if (! $existe) {
            throw ValidationException::withMessages([
                'client' => ['O cliente não pertence a esta conta.'],
            ]);
        }
    }

    /**
     * O telefone da carteira, exigido só no caminho sem contato: o
     * Disparo legado de antes dos Contatos de envio. BR-plausível:
     * 10–13 dígitos (DDD+fixo até E.164 com nono dígito); a normalização
     * fina — nono dígito, DDI — é do gateway.
     */
    private function garantirTelefone(int $accountId, int $clientId): void
    {
        $client = Client::query()
            ->where('account_id', $accountId)
            ->find($clientId);

        if ($client === null) {
            throw ValidationException::withMessages([
                'client' => ['O cliente não pertence a esta conta.'],
            ]);
        }

        $digits = preg_replace('/\D+/', '', (string) $client->phone);

        if ($digits === '') {
            throw ValidationException::withMessages([
                'phone' => ['O cliente não tem telefone cadastrado na carteira nem Contato de envio WhatsApp.'],
            ]);
        }

        if (strlen($digits) < 10 || strlen($digits) > 13) {
            throw ValidationException::withMessages([
                'phone' => ['O telefone do cliente está fora do padrão para envio por WhatsApp.'],
            ]);
        }
    }

    /**
     * O artefato precisa existir para aquela competência — a mesma regra
     * que o download aplica, lida aqui sem chamar o gateway: guia pede
     * `slip_number` ou declaração transmitida; recibo e declaração pedem
     * `declaration_number`.
     *
     * @return array<string, mixed> a linha do período (o slip_number dela
     *                              entra no envio lógico)
     */
    private function garantirArtefato(int $accountId, int $clientId, string $competence, string $artifact): array
    {
        $linha = $this->linhaDePeriodo($accountId, $clientId, $competence);

        if ($linha === null) {
            throw ValidationException::withMessages([
                'competence' => ['A competência não está sincronizada no monitoramento.'],
            ]);
        }

        $disponivel = match ($artifact) {
            'guia' => (string) ($linha['slip_number'] ?? '') !== ''
                || ($linha['declared_at'] ?? null) !== null,
            'recibo', 'declaracao' => (string) ($linha['declaration_number'] ?? '') !== '',
            default => false,
        };

        if (! $disponivel) {
            throw ValidationException::withMessages([
                'artifact' => ["O artefato '{$artifact}' não está disponível para esta competência."],
            ]);
        }

        return $linha;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function linhaDePeriodo(int $accountId, int $clientId, string $competence): ?array
    {
        $registro = $this->monitoringPgdas($accountId, $clientId);

        if ($registro === null) {
            return null;
        }

        return collect($registro->periods ?? [])
            ->first(fn (array $item): bool => (string) ($item['period'] ?? '') === $competence);
    }

    private function monitoringPgdas(int $accountId, int $clientId): ?SerproMonitoring
    {
        return SerproMonitoring::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('client_id', $clientId)
            ->where('obligation', WhatsAppDispatch::OBLIGATION_DECLARACOES_PGDAS)
            ->first();
    }

    /**
     * Modo suporte: grava o log e devolve o id para a linha linkar. Fora
     * de suporte devolve `null` — a flag `dispatched_in_support` nunca
     * acende por engano.
     */
    private function auditar(?Request $request, User $actor, int $accountId, int $clientId): ?int
    {
        if ($request === null || ! SupportAudit::isSupportMode($actor)) {
            return null;
        }

        $log = (new SupportAccessLog)->forceFill([
            'super_admin_user_id' => $actor->getKey(),
            'account_id' => $accountId,
            'action' => 'dispatch',
            'metadata' => [
                'resource' => 'whatsapp_dispatches',
                'client_id' => $clientId,
            ],
            'ip' => $request->ip(),
        ]);
        $log->save();

        return $log->getKey();
    }
}
