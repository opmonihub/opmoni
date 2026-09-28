<?php

namespace App\Services;

use App\Tenant\CurrentTenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

final class CnpjWsLookup
{
    private const CACHE_TTL_SECONDS = 86400;

    /**
     * A recusa da fonte pública por documento que ela não indexa. É a mesma
     * frase e o mesmo `status` do `404` do provedor, e é uma constante porque as
     * duas vias precisam responder igual: quem lê a recusa não tem como saber —
     * nem precisa saber — se ela veio de uma ida à rede ou do conhecimento do que
     * a fonte tem.
     */
    private const NAO_ENCONTRADO = 'CNPJ não encontrado.';

    public function __construct(private BrazilianTaxId $taxId) {}

    /**
     * O mesmo que `lookup()`, para o chamador que trata "a fonte não conhece este
     * documento" como recusa em vez de como ausência de dado.
     *
     * Documento alfanumérico é recusado sem gastar a consulta: a resposta seria
     * sempre a mesma, e ela é conhecida. O `404` continua sendo o `404` que o
     * provedor daria — só não custa uma das três consultas por minuto da conta
     * para ser descoberto.
     *
     * @return array<string, mixed>
     */
    public function lookupOrFail(string $cnpj): array
    {
        $normalized = $this->taxId->normalize($cnpj);

        if (! $this->taxId->isNumericCnpj($normalized)) {
            throw new CnpjLookupException(self::NAO_ENCONTRADO, 404);
        }

        return $this->lookup($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function lookup(string $cnpj): array
    {
        $normalized = $this->taxId->normalize($cnpj);

        if (! $this->taxId->isValidCnpj($normalized)) {
            throw new CnpjLookupException('CNPJ inválido.', 422);
        }

        $accountId = resolve(CurrentTenant::class)->accountId;
        $rateKey = 'cnpj-ws:'.($accountId ?? 'public');

        return Cache::remember("cnpj-ws:{$normalized}", self::CACHE_TTL_SECONDS, function () use ($normalized, $rateKey): array {
            $result = null;
            $exception = null;
            $allowed = RateLimiter::attempt($rateKey, 3, function () use ($normalized, &$result, &$exception): bool {
                try {
                    $result = $this->request($normalized);
                } catch (\Throwable $caught) {
                    $exception = $caught;
                }

                return true;
            }, 60);

            if (! $allowed) {
                throw new CnpjLookupException('Limite temporário de consultas atingido. Tente novamente em um minuto.', 429);
            }

            if ($exception !== null) {
                throw $exception;
            }

            if (! is_array($result)) {
                throw new CnpjLookupException('O serviço de consulta de CNPJ está indisponível.', 503);
            }

            return $result;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $cnpj): array
    {
        try {
            $response = Http::acceptJson()->timeout(8)->get("https://publica.cnpj.ws/cnpj/{$cnpj}");
        } catch (ConnectionException) {
            throw new CnpjLookupException('O serviço de consulta de CNPJ está indisponível.', 503);
        }

        if ($response->status() === 404) {
            throw new CnpjLookupException(self::NAO_ENCONTRADO, 404);
        }

        if ($response->status() === 429) {
            throw new CnpjLookupException('O provedor limitou temporariamente as consultas.', 429);
        }

        if ($response->serverError()) {
            throw new CnpjLookupException('O serviço de consulta de CNPJ está indisponível.', 503);
        }

        $response->throw();
        $payload = $response->json();
        $establishment = data_get($payload, 'estabelecimento', []);

        return [
            'tax_id' => (string) data_get($establishment, 'cnpj'),
            'name' => (string) data_get($payload, 'razao_social'),
            'trade_name' => data_get($establishment, 'nome_fantasia'),
            'registration_status' => data_get($establishment, 'situacao_cadastral'),
            'registration_status_date' => data_get($establishment, 'data_situacao_cadastral'),
            'opened_at' => data_get($establishment, 'data_inicio_atividade'),
            'company_size' => data_get($payload, 'porte.descricao'),
            'legal_nature' => data_get($payload, 'natureza_juridica.descricao'),
            'primary_activity_code' => data_get($establishment, 'atividade_principal.id'),
            'primary_activity_description' => data_get($establishment, 'atividade_principal.descricao'),
            'street_type' => data_get($establishment, 'tipo_logradouro'),
            'street' => data_get($establishment, 'logradouro'),
            'address_number' => data_get($establishment, 'numero'),
            'address_complement' => data_get($establishment, 'complemento'),
            'district' => data_get($establishment, 'bairro'),
            'postal_code' => data_get($establishment, 'cep'),
            'city' => data_get($establishment, 'cidade.nome'),
            'state' => data_get($establishment, 'estado.sigla'),
            'email' => data_get($establishment, 'email'),
            'phone' => trim((string) data_get($establishment, 'ddd1').(string) data_get($establishment, 'telefone1')) ?: null,
            'mei' => data_get($payload, 'simples.mei') === 'Sim',
            'simple_national' => data_get($payload, 'simples.simples') === 'Sim',
            'source_updated_at' => data_get($establishment, 'atualizado_em'),
            'looked_up_at' => now()->toISOString(),
        ];
    }
}
