<?php

namespace App\Services;

use App\Models\AccountCertificate;
use App\Support\ExternalHttpBudget;
use App\Tenant\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use RuntimeException;
use Throwable;

/** The provider read is a legal act; only an explicit consent may arm it. */
final class SerproMailboxReader
{
    private const SISTEMA = 'CAIXAPOSTAL';

    private const SERVICO = 'MSGDETALHAMENTO62';

    public function __construct(
        private readonly SerproTermManager $termos,
        private readonly SerproClient $client,
        private readonly SerproCallRecorder $recorder,
        private readonly SerproMonitoringMapper $mapper,
        private readonly ExternalEffectJournal $journal,
        private readonly SerproMailboxRecovery $recovery,
    ) {}

    /** @return array<string, mixed> */
    public function read(int $accountId, string $slug, int $clientId, int $isn): array
    {
        return resolve(CurrentTenant::class)->run($accountId, function () use ($accountId, $slug, $clientId, $isn): array {
            $resource = $this->recovery->resource($accountId, $slug, $clientId, $isn);
            abort_unless(in_array(request()->input('ciencia'), [true, 1, '1', 'yes', 'on', 'true'], true), 422);
            if ($this->recovery->confirmed($accountId, $resource['hash']) !== null) {
                $this->recovery->conflict('confirmed');
            }
            $token = $this->termos->validToken($accountId);
            if ($token === null) {
                $this->beforeSendConflict('O termo de autorização do escritório não está vigente.');
            }
            $certificado = AccountCertificate::currentFor($accountId);
            if ($certificado === null) {
                $this->beforeSendConflict('O escritório não tem e-CNPJ cadastrado.');
            }
            $effect = $this->journal->begin($accountId, (int) request()->user()->getAuthIdentifier(), $resource['hash'], SerproMailboxRecovery::STEP);

            try {
                $resultado = $this->recorder->record(
                    null, $accountId, $clientId, self::SISTEMA, self::SERVICO,
                    function () use (&$effect, $isn, $certificado, $resource, $token): SerproResult {
                        return $this->client->call(
                            self::SISTEMA, self::SERVICO,
                            ['isn' => str_pad((string) $isn, 10, '0', STR_PAD_LEFT)],
                            (string) $certificado->document, (string) $resource['cliente']->tax_id, $token,
                            budget: ExternalHttpBudget::fromRequest(request()),
                            beforeServiceSend: function () use (&$effect): void {
                                $effect = $this->journal->arm($effect);
                            },
                            onServiceResult: function (SerproResult $result, int $httpStatus) use (&$effect, $isn): void {
                                $payload = $this->receipt($result, $isn, $httpStatus);
                                $effect = $this->journal->confirm($effect, 'applied', $payload);
                            },
                            retryUnauthorized: false,
                        );
                    },
                );
                $mensagem = $this->mapper->detalheMensagem($isn, $resultado->dados());
                $this->recovery->apply($resource['registro'], $isn, $effect['payload']);

                return [...$mensagem, 'effect_status' => 'confirmed', 'body_available' => true];
            } catch (Throwable $error) {
                if ($effect['state'] === 'confirmed' || $effect['state'] === 'uncertain') {
                    $this->recovery->conflict($effect['state']);
                }
                if ($error instanceof SerproException) {
                    throw new HttpResponseException(response()->json([
                        'code' => 'operation_before_send', 'effect_status' => 'pending',
                        'message' => $error->failure->label(),
                    ], 502, ['Cache-Control' => 'no-store']));
                }
                throw $error;
            }
        });
    }

    private function beforeSendConflict(string $message): never
    {
        throw new HttpResponseException(response()->json([
            'code' => 'operation_before_send', 'effect_status' => 'pending', 'message' => $message,
        ], 409, ['Cache-Control' => 'no-store']));
    }

    /** Confirmation maps only dates and bounded identifiers, never subject/body. @return array<string, mixed> */
    private function receipt(SerproResult $result, int $isn, int $httpStatus): array
    {
        $dados = $result->dados();
        if (! is_array($dados) || ! is_array($dados['conteudo'] ?? null)) {
            throw new RuntimeException('Resposta da ciência não confirmável.');
        }
        $content = $dados['conteudo'];
        $item = array_is_list($content) ? (count($content) === 1 ? $content[0] : null) : $content;
        if (! is_array($item) || array_is_list($item) || $result->status() !== 200
            || (isset($dados['codigo']) && $dados['codigo'] !== '00')
            || ! is_string($item['assuntoModelo'] ?? null) || ! is_string($item['corpoModelo'] ?? null)
            || (trim($item['assuntoModelo']) === '' && trim($item['corpoModelo']) === '')) {
            throw new RuntimeException('Resposta da ciência não confirmável.');
        }
        // The trusted call identifies the requested mailbox message; observed responses omit its ISN.
        if (array_key_exists('isn', $item)) {
            $returnedIsn = $item['isn'];
            if ((! is_string($returnedIsn) && ! is_int($returnedIsn))
                || ! preg_match('/\A\d{1,10}\z/', (string) $returnedIsn) || (int) $returnedIsn !== $isn) {
                throw new RuntimeException('Resposta da ciência não confirmável.');
            }
        }
        $known = array_intersect_key($item, array_flip(['dataLeitura', 'horaLeitura', 'dataCiencia', 'dataValidade', 'dataExpiracao']));
        foreach ($known as $key => $value) {
            if ($value === null || $value === '') {
                $known[$key] = null;

                continue;
            }
            $pattern = $key === 'horaLeitura' ? '/\A\d{6}\z/' : ($key === 'dataLeitura' ? '/\A(?:\d{8}|\d{14})\z/' : '/\A\d{8}\z/');
            if ((! is_string($value) && ! is_int($value)) || ! preg_match($pattern, (string) $value)) {
                throw new RuntimeException('Datas da ciência não confirmáveis.');
            }
            $digits = (string) $value;
            if ($key !== 'horaLeitura' && ! checkdate((int) substr($digits, 4, 2), (int) substr($digits, 6, 2), (int) substr($digits, 0, 4))) {
                throw new RuntimeException('Datas da ciência não confirmáveis.');
            }
            $time = $key === 'horaLeitura' ? $digits : (strlen($digits) === 14 ? substr($digits, 8) : null);
            if ($time !== null && ! preg_match('/\A(?:[01]\d|2[0-3])[0-5]\d[0-5]\d\z/', $time)) {
                throw new RuntimeException('Datas da ciência não confirmáveis.');
            }
        }
        $mapped = $this->mapper->detalheMensagem($isn, ['conteudo' => [$known]]);
        $payload = array_intersect_key($mapped, array_flip(['lida_em', 'ciencia_em', 'prazo_limite']));
        if (is_string($payload['lida_em']) && str_contains($payload['lida_em'], ' ')) {
            $payload['lida_em'] = str_replace(' ', 'T', $payload['lida_em']).now()->format('P');
        }
        $payload['http_status'] = $httpStatus;
        foreach (['response_id' => $result->responseId(), 'request_tag' => $result->requestTag(),
            'provider_code' => $result->mensagens()[0]['codigo'] ?? null, 'codigo' => $item['numeroControle'] ?? null] as $key => $value) {
            $pattern = $key === 'response_id' ? '/\A[a-zA-Z0-9_.:\/-]{1,256}\z/'
                : ($key === 'request_tag' ? '/\A[a-zA-Z0-9_.:-]{1,64}\z/' : '/\A[a-zA-Z0-9_.:-]{1,128}\z/');
            if (is_string($value) && preg_match($pattern, $value)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
