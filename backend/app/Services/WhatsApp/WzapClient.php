<?php

namespace App\Services\WhatsApp;

use App\Support\AccessSession;
use App\Support\ExternalHttpBudget;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP fino para o gateway wzap.
 *
 * O wzap autentica por header `apikey` em dois planos: a chave global
 * (`whatsapp.wzap.api_key`) gerencia instâncias — criar, listar, rotacionar;
 * a chave de instância, emitida uma única vez na criação e guardada cifrada
 * no `WhatsAppChannel`, autentica as operações da própria instância
 * (pareamento, status, envio).
 *
 * Respostas seguem o envelope `{"data": ...}` / `{"error": {code, message}}`;
 * este cliente desfaz o envelope e converte erro em `WzapException`.
 */
final class WzapClient
{
    /**
     * Cria a instância da Account (external_ref) e devolve o envelope com a
     * `instance_api_key` em claro — o wzap só a devolve nesta resposta.
     *
     * @return array<string, mixed>
     */
    public function createInstance(string $externalRef, string $name, ?ExternalHttpBudget $budget = null, ?Closure $beforeSend = null, ?Closure $onResult = null): array
    {
        $payload = [
            'name' => $name,
            'external_ref' => $externalRef,
        ];

        if ($webhook = $this->webhookConfiguration()) {
            $payload['webhook'] = $webhook;
        }

        return $this->request(fn (): Response => $this->globalRequest($budget)->post('/instances', $payload), null, $budget, $beforeSend, $onResult, $this->instance(...));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function instance(array $data): array
    {
        $instance = array_key_exists('instance', $data) ? $data['instance'] : $data;

        if (! is_array($instance) || ! is_string($instance['id'] ?? null) || $instance['id'] === '') {
            throw new WzapException('O wzap retornou uma instância inválida.');
        }

        if (array_key_exists('instance', $data)) {
            $instance['instance_api_key'] = $data['instance_api_key'] ?? null;
        }

        return $instance;
    }

    /**
     * Cria a instância ou, quando o wzap responde 409 por `external_ref`
     * duplicada, reutiliza o id já vinculado à Account ou encontra o nome
     * determinístico na listagem atual. A listagem legada permite comparar
     * `external_ref`; a atual não o publica. A instância encontrada não traz
     * `instance_api_key` — quem já tem canal guardado usa a chave cifrada.
     *
     * @return array<string, mixed>
     */
    public function ensureInstance(string $externalRef, string $name, ?string $knownInstanceId = null, ?ExternalHttpBudget $budget = null, ?Closure $beforeSend = null, ?Closure $onResult = null): array
    {
        $created = null;
        $creationStatus = 201;
        $captureStatus = function (array $result, int $status) use (&$creationStatus): void {
            $creationStatus = $status;
        };
        $creationAttempted = false;
        $beforeCreate = function () use ($beforeSend, &$creationAttempted): void {
            if ($beforeSend !== null) {
                $beforeSend();
            }
            $creationAttempted = true;
        };
        try {
            $created = $this->createInstance($externalRef, $name, $budget, $beforeCreate, $captureStatus);
        } catch (WzapException $e) {
            if (! $creationAttempted || $e->status() !== 409) {
                throw $e;
            }
        }
        if ($created !== null) {
            if ($onResult !== null) {
                $onResult($created, $creationStatus);
            }

            return $created;
        }

        if ($knownInstanceId !== null && $knownInstanceId !== '') {
            $this->configureWebhook($knownInstanceId, $budget);
            $instance = ['id' => $knownInstanceId];
            if ($onResult !== null) {
                $onResult($instance, 200);
            }

            return $instance;
        }

        $instance = $this->findInstance($externalRef, $name, $budget);
        if ($instance !== null) {
            $this->configureWebhook($instance['id'], $budget);
            if ($onResult !== null) {
                $onResult($instance, 200);
            }

            return $instance;
        }

        throw new WzapException(
            'O wzap recusou a criação por external_ref duplicada, mas a instância não apareceu na listagem.',
            409,
        );
    }

    /**
     * Read-only discovery: GET pages only, never creates or configures a webhook.
     * Returns null when absent; malformed/bounded pagination and HTTP failures throw WzapException.
     *
     * @return array<string, mixed>|null
     */
    public function findInstance(string $externalRef, string $name, ?ExternalHttpBudget $budget = null): ?array
    {
        $cursor = null;
        $seen = [];
        $pages = 0;

        do {
            if (++$pages > 20 || ($cursor !== null && isset($seen[$cursor]))) {
                throw new WzapException('O wzap retornou paginação repetida ou excedeu o limite seguro.');
            }
            if ($cursor !== null) {
                $seen[$cursor] = true;
            }
            $response = $this->request(fn (): Response => $this->globalRequest($budget)->get('/instances', array_filter([
                'limit' => 100,
                'cursor' => $cursor,
            ])), null, $budget);

            $modern = array_key_exists('instances', $response);
            $instances = $modern ? $response['instances'] : ($response['items'] ?? []);

            if (! is_array($instances)) {
                throw new WzapException('O wzap retornou uma listagem inválida.');
            }

            foreach ($instances as $instance) {
                if (! is_array($instance) || ! is_string($instance['id'] ?? null) || $instance['id'] === '') {
                    continue;
                }

                $matches = $modern
                    ? ($instance['name'] ?? null) === $name
                        && (! array_key_exists('external_ref', $instance) || $instance['external_ref'] === $externalRef)
                    : ($instance['external_ref'] ?? null) === $externalRef;

                if ($matches) {
                    return $instance;
                }
            }

            $cursor = $response['next_cursor'] ?? null;
            if ($cursor !== null && (! is_string($cursor) || strlen($cursor) > 2048)) {
                throw new WzapException('O wzap retornou paginação inválida.');
            }
        } while ($cursor);

        return null;
    }

    /**
     * Só recibos e estado: a assinatura exclui mensagens inbound.
     *
     * @return array{url: string, enabled: bool, events: list<string>}|null
     */
    private function webhookConfiguration(): ?array
    {
        $url = config('whatsapp.wzap.webhook_url');

        return is_string($url) && $url !== '' ? [
            'url' => $url,
            'enabled' => true,
            'events' => ['receipt', 'connection', 'message.status'],
        ] : null;
    }

    /**
     * O POST em conflito não atualiza configuração. Reaplicar no recurso
     * recuperado também repara instâncias criadas pelo contrato anterior,
     * que enviava o webhook fora do objeto esperado pelo gateway.
     * Sem configuração não envia HTTP e não invoca nenhum callback.
     *
     * @param  Closure(): void|null  $beforeSend
     * @param  Closure(array<string, mixed>, int): void|null  $onResult  Runs before return, only after a successful PATCH.
     */
    public function configureWebhook(string $instanceId, ?ExternalHttpBudget $budget = null, ?Closure $beforeSend = null, ?Closure $onResult = null): void
    {
        if ($webhook = $this->webhookConfiguration()) {
            $this->request(fn (): Response => $this->globalRequest($budget)->patch("/instances/{$instanceId}", [
                'webhook' => $webhook,
            ]), null, $budget, $beforeSend, $onResult);
        }
    }

    /**
     * Inicia (ou retoma) o pareamento e devolve `{status, qr_code,
     * qr_expires_at}` (200). O wzap responde este POST com
     * `{"status":"connected"}` sem QR quando a instância já está conectada
     * — o **409** desta rota não é "já conectada" (caso da `qr()` abaixo);
     * aqui um conflito é estado transitório e chega como `WzapException`
     * com `status() === 409`.
     *
     * @return array<string, mixed>
     */
    public function connect(string $instanceId, string $instanceApiKey, ?ExternalHttpBudget $budget = null, ?Closure $beforeSend = null, ?Closure $onResult = null): array
    {
        return $this->request(
            fn (): Response => $this->instanceRequest($instanceApiKey, $budget)->post("/instances/{$instanceId}/connect"),
            $instanceApiKey, $budget, $beforeSend, $onResult, $this->connection(...),
        );
    }

    /**
     * Lê o QR corrente do pareamento. Instância já conectada não tem QR e o
     * wzap responde 409 (`{"error":{"code":"conflict","message":"instance
     * already connected"}}`) — quem chama distingue o caso por
     * `WzapException::status() === 409` (o `errorCode()` é só `conflict`,
     * compartilhado com "not connected" e "no open pairing channel").
     *
     * @return array<string, mixed>
     */
    public function qr(string $instanceId, string $instanceApiKey, ?ExternalHttpBudget $budget = null, ?Closure $beforeSend = null, ?Closure $onResult = null): array
    {
        return $this->request(
            fn (): Response => $this->instanceRequest($instanceApiKey, $budget)->get("/instances/{$instanceId}/qr"),
            $instanceApiKey, $budget, $beforeSend, $onResult, $this->connection(...),
        );
    }

    /**
     * Lê `data.connection`, aceitando também o envelope plano legado.
     *
     * @return array<string, mixed>
     */
    public function status(string $instanceId, string $instanceApiKey, ?ExternalHttpBudget $budget = null, ?Closure $beforeSend = null, ?Closure $onResult = null): array
    {
        return $this->request(
            fn (): Response => $this->instanceRequest($instanceApiKey, $budget)->get("/instances/{$instanceId}/status"),
            $instanceApiKey, $budget, $beforeSend, $onResult, $this->connection(...),
        );
    }

    /**
     * Encerra a sessão e remove as credenciais no wzap (204).
     */
    public function disconnect(string $instanceId, string $instanceApiKey, ?ExternalHttpBudget $budget = null, ?Closure $beforeSend = null, ?Closure $onResult = null): void
    {
        $this->request(
            fn (): Response => $this->instanceRequest($instanceApiKey, $budget)->post("/instances/{$instanceId}/disconnect"),
            $instanceApiKey, $budget, $beforeSend, $onResult,
        );
    }

    /**
     * Rotaciona a chave da instância (só chave global). A nova chave vem em
     * claro uma única vez e a antiga morre na hora.
     *
     * @return array<string, mixed>
     */
    public function rotateInstanceKey(string $instanceId, ?ExternalHttpBudget $budget = null, ?Closure $beforeSend = null, ?Closure $onResult = null): array
    {
        return $this->request(
            fn (): Response => $this->globalRequest($budget)->post("/instances/{$instanceId}/apikey/rotate"),
            null, $budget, $beforeSend, $onResult,
        );
    }

    /**
     * Remove a instância no wzap (204): encerra a sessão e apaga mensagens e
     * mídias. Irreversível.
     */
    public function deleteInstance(string $instanceId): void
    {
        $this->request(fn (): Response => $this->globalRequest()->delete("/instances/{$instanceId}"));
    }

    /**
     * Envia um documento como mensagem de mídia (`multipart/form-data`).
     * O `Idempotency-Key` tem TTL de 24 h por instância no wzap: repetir a
     * mesma chave com o mesmo conteúdo devolve a resposta original.
     *
     * @return array<string, mixed> envelope `data` com `message_id`/`status`
     */
    public function sendMedia(
        string $instanceId,
        string $instanceApiKey,
        string $contents,
        string $filename,
        string $mimeType,
        string $phone,
        string $idempotencyKey,
        ?string $caption = null,
    ): array {
        $request = $this->instanceRequest($instanceApiKey)
            ->timeout((float) config('whatsapp.wzap.media_timeout', 60))
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->attach('file', $contents, $filename, ['Content-Type' => $mimeType]);

        $payload = ['to' => $phone, 'type' => 'document', 'filename' => $filename];

        if ($caption !== null) {
            $payload['caption'] = $caption;
        }

        $data = $this->request(
            fn (): Response => $request->post("/instances/{$instanceId}/messages/media", $payload),
            $instanceApiKey,
        );

        if (! array_key_exists('message', $data)) {
            return $data;
        }

        $message = $data['message'];

        if (! is_array($message) || ! is_string($message['id'] ?? null) || $message['id'] === '') {
            throw new WzapException('O wzap retornou uma mensagem inválida.');
        }

        return array_merge($message, [
            'message_id' => $message['id'],
            'status' => $message['send_status'] ?? null,
        ]);
    }

    /**
     * @param  Closure(): Response  $send
     * @param  Closure(): void|null  $beforeSend
     * @param  Closure(array<string, mixed>, int): void|null  $onResult  Normalized result and HTTP status; never retried or classified as HTTP failure.
     * @return array<string, mixed>
     */
    private function request(Closure $send, ?string $instanceApiKey = null, ?ExternalHttpBudget $budget = null, ?Closure $beforeSend = null, ?Closure $onResult = null, ?Closure $normalize = null): array
    {
        if ($budget !== null) {
            $budget->beforeSend($beforeSend);
        } else {
            AccessSession::assertOwnership(request());
            if ($beforeSend !== null) {
                $beforeSend();
                AccessSession::assertOwnership(request());
            }
        }
        try {
            $response = $send();
            $data = $this->unwrap($response);
            if ($normalize !== null) {
                $data = $normalize($data);
            }
        } catch (ConnectionException) {
            throw new WzapException('Não foi possível conectar ao gateway wzap. Tente novamente.');
        } catch (WzapException $exception) {
            throw new WzapException(
                WhatsAppFailureReason::sanitizar($exception->getMessage(), $instanceApiKey),
                $exception->status(),
                $exception->errorCode(),
            );
        }
        if ($onResult !== null) {
            $onResult($data, $response->status());
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function connection(array $data): array
    {
        $connection = array_key_exists('connection', $data) ? $data['connection'] : $data;

        if (! is_array($connection)) {
            throw new WzapException('O wzap retornou uma conexão inválida.');
        }

        if (array_key_exists('status', $connection) && ! is_string($connection['status'])) {
            throw new WzapException('O wzap retornou um estado de conexão inválido.');
        }

        return $connection;
    }

    /**
     * Chamadas de gestão de instância usam a chave global da config.
     */
    private function globalRequest(?ExternalHttpBudget $budget = null): PendingRequest
    {
        $apiKey = config('whatsapp.wzap.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new WzapException('Chave global do wzap não configurada (WZAP_API_KEY).');
        }

        return $this->baseRequest($budget)->withHeaders(['apikey' => $apiKey]);
    }

    /**
     * Operações de instância usam a chave própria, emitida na criação e
     * guardada cifrada no `WhatsAppChannel`.
     */
    private function instanceRequest(string $instanceApiKey, ?ExternalHttpBudget $budget = null): PendingRequest
    {
        return $this->baseRequest($budget)->withHeaders(['apikey' => $instanceApiKey]);
    }

    private function baseRequest(?ExternalHttpBudget $budget = null): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('whatsapp.wzap.base_url'), '/'))
            ->timeout((float) config('whatsapp.wzap.timeout', 15))
            ->withOptions($budget?->options((float) config('whatsapp.wzap.timeout', 15)) ?? [])
            ->acceptJson();
    }

    /**
     * Desfaz o envelope `{"data": ...}` e converte `{"error": ...}` e falhas
     * de transporte em `WzapException`. O motivo leva apenas `code`/`message`
     * do wzap — o corpo bruto nunca entra na exceção.
     *
     * @return array<string, mixed>
     */
    private function unwrap(Response $response): array
    {
        if ($response->successful()) {
            $data = $response->json('data') ?? [];

            if (! is_array($data)) {
                throw new WzapException('O wzap retornou uma resposta inválida.', $response->status());
            }

            return $data;
        }

        $error = $response->json('error');

        throw new WzapException(
            is_array($error)
                ? sprintf('wzap %s: %s', $error['code'] ?? 'erro', $error['message'] ?? 'resposta inesperada')
                : sprintf('wzap respondeu HTTP %d', $response->status()),
            $response->status(),
            is_array($error) ? ($error['code'] ?? null) : null,
        );
    }
}
