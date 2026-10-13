<?php

namespace App\Services\WhatsApp;

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
    public function createInstance(string $externalRef, string $name): array
    {
        $payload = [
            'name' => $name,
            'external_ref' => $externalRef,
        ];

        if ($webhook = $this->webhookConfiguration()) {
            $payload['webhook'] = $webhook;
        }

        $data = $this->request(fn (): Response => $this->globalRequest()->post('/instances', $payload));
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
    public function ensureInstance(string $externalRef, string $name, ?string $knownInstanceId = null): array
    {
        try {
            return $this->createInstance($externalRef, $name);
        } catch (WzapException $e) {
            if ($e->status() !== 409) {
                throw $e;
            }
        }

        if ($knownInstanceId !== null && $knownInstanceId !== '') {
            $this->configureWebhook($knownInstanceId);

            return ['id' => $knownInstanceId];
        }

        $cursor = null;

        do {
            $response = $this->request(fn (): Response => $this->globalRequest()->get('/instances', array_filter([
                'limit' => 100,
                'cursor' => $cursor,
            ])));

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
                    $this->configureWebhook($instance['id']);

                    return $instance;
                }
            }

            $cursor = $response['next_cursor'] ?? null;
        } while ($cursor);

        throw new WzapException(
            'O wzap recusou a criação por external_ref duplicada, mas a instância não apareceu na listagem.',
            409,
        );
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
     */
    private function configureWebhook(string $instanceId): void
    {
        if ($webhook = $this->webhookConfiguration()) {
            $this->request(fn (): Response => $this->globalRequest()->patch("/instances/{$instanceId}", [
                'webhook' => $webhook,
            ]));
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
    public function connect(string $instanceId, string $instanceApiKey): array
    {
        return $this->connection($this->request(
            fn (): Response => $this->instanceRequest($instanceApiKey)->post("/instances/{$instanceId}/connect"),
            $instanceApiKey,
        ));
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
    public function qr(string $instanceId, string $instanceApiKey): array
    {
        return $this->connection($this->request(
            fn (): Response => $this->instanceRequest($instanceApiKey)->get("/instances/{$instanceId}/qr"),
            $instanceApiKey,
        ));
    }

    /**
     * Lê `data.connection`, aceitando também o envelope plano legado.
     *
     * @return array<string, mixed>
     */
    public function status(string $instanceId, string $instanceApiKey): array
    {
        return $this->connection($this->request(
            fn (): Response => $this->instanceRequest($instanceApiKey)->get("/instances/{$instanceId}/status"),
            $instanceApiKey,
        ));
    }

    /**
     * Encerra a sessão e remove as credenciais no wzap (204).
     */
    public function disconnect(string $instanceId, string $instanceApiKey): void
    {
        $this->request(
            fn (): Response => $this->instanceRequest($instanceApiKey)->post("/instances/{$instanceId}/disconnect"),
            $instanceApiKey,
        );
    }

    /**
     * Rotaciona a chave da instância (só chave global). A nova chave vem em
     * claro uma única vez e a antiga morre na hora.
     *
     * @return array<string, mixed>
     */
    public function rotateInstanceKey(string $instanceId): array
    {
        return $this->request(
            fn (): Response => $this->globalRequest()->post("/instances/{$instanceId}/apikey/rotate"),
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
     * @return array<string, mixed>
     */
    private function request(Closure $send, ?string $instanceApiKey = null): array
    {
        try {
            return $this->unwrap($send());
        } catch (ConnectionException) {
            throw new WzapException('Não foi possível conectar ao gateway wzap. Tente novamente.');
        } catch (WzapException $exception) {
            throw new WzapException(
                WhatsAppFailureReason::sanitizar($exception->getMessage(), $instanceApiKey),
                $exception->status(),
                $exception->errorCode(),
            );
        }
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
    private function globalRequest(): PendingRequest
    {
        $apiKey = config('whatsapp.wzap.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new WzapException('Chave global do wzap não configurada (WZAP_API_KEY).');
        }

        return $this->baseRequest()->withHeaders(['apikey' => $apiKey]);
    }

    /**
     * Operações de instância usam a chave própria, emitida na criação e
     * guardada cifrada no `WhatsAppChannel`.
     */
    private function instanceRequest(string $instanceApiKey): PendingRequest
    {
        return $this->baseRequest()->withHeaders(['apikey' => $instanceApiKey]);
    }

    private function baseRequest(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('whatsapp.wzap.base_url'), '/'))
            ->timeout((float) config('whatsapp.wzap.timeout', 15))
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
