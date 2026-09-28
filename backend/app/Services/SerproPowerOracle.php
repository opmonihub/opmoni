<?php

namespace App\Services;

use App\Enums\SerproPowerOfAttorneyState;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\ClientEcacPowerOfAttorney;
use App\Models\SerproClientAuthorization;
use Illuminate\Support\Facades\DB;

/**
 * O oráculo de autorização: lê `PROCURACOES/OBTERPROCURACAO41` e grava o que
 * o provedor respondeu — nunca o que este sistema gostaria que fosse verdade.
 *
 * A consulta é um dos serviços que **não** exigem procuração, e é por isso
 * que ela pode rodar para descobrir que o cliente nunca teve uma. O que ela
 * precisa é do token do termo do escritório, e sem ele este método não chama
 * nada: uma procuração `pending` por não ter sido verificada é um estado
 * honesto, enquanto `rejected` sem resposta seria uma mentira.
 *
 * O retorno traz **nomes** de sistemas e-CAC, não códigos — a tradução é do
 * `SerproPowerNames`, e um nome que ela não conhece não autoriza nada. A
 * linha já gravada cuja família não veio na resposta vira `rejected`, e a
 * que veio com `dtexpiracao` no passado vira `expired`: os dois caminhos
 * levam a não consultar o cliente, e o operador precisa saber qual dos dois
 * foi.
 */
final class SerproPowerOracle
{
    public function __construct(
        private readonly SerproClient $client,
        private readonly SerproPowerNames $names,
        private readonly SerproTermManager $terms,
    ) {}

    public function refresh(int $accountId, int $clientId): void
    {
        $token = $this->terms->validToken($accountId);
        $certificate = AccountCertificate::currentFor($accountId);
        $client = Client::query()->where('account_id', $accountId)->find($clientId);

        if ($token === null || $certificate === null || $client === null || $client->tax_id === null) {
            return;
        }

        $result = $this->client->call(
            'PROCURACOES',
            'OBTERPROCURACAO41',
            [
                'outorgante' => $client->tax_id,
                'tipoOutorgante' => '2',
                'outorgado' => $certificate->document,
                'tipoOutorgado' => '2',
            ],
            $certificate->document,
            $client->tax_id,
            $token,
        );

        DB::transaction(function () use ($accountId, $clientId, $result): void {
            $granted = $this->familiasConcedidas($result->dados());
            $now = now();

            foreach ($granted as $family => $expiresOn) {
                // `account_id` e `client_id` ficam fora do `Fillable` de
                // propósito, e é por isso que nem `updateOrCreate` os
                // escreve: a linha nasce por `forceFill` com os dois
                // explícitos — o `account_id` desta execução, e nunca o
                // que sobrou no `CurrentTenant` de um worker de fila.
                $autorizacao = SerproClientAuthorization::query()
                    ->withoutGlobalScope('account')
                    ->where('account_id', $accountId)
                    ->where('client_id', $clientId)
                    ->where('family', $family)
                    ->first() ?? new SerproClientAuthorization;

                $autorizacao->forceFill([
                    'account_id' => $accountId,
                    'client_id' => $clientId,
                    'family' => $family,
                    'code' => $family,
                    'state' => $this->estadoPorValidade($expiresOn),
                    'expires_on' => $expiresOn,
                    'verified_at' => $now,
                ])->saveQuietly();
            }

            // O que a conta tinha gravado e a resposta não repetiu foi
            // recusado lá — e `rejected` é a forma de dizer isso sem apagar
            // a linha, que apagada fingiria nunca ter sido consultada.
            SerproClientAuthorization::query()
                ->withoutGlobalScope('account')
                ->where('account_id', $accountId)
                ->where('client_id', $clientId)
                ->whereNotIn('family', array_keys($granted))
                ->update(['state' => SerproPowerOfAttorneyState::Rejected->value, 'verified_at' => $now]);

            $this->refletirNaProcuracao($accountId, $clientId, $granted);
        });
    }

    /**
     * As famílias que a resposta concede, na forma `family => expires_on`.
     * `dados` é a lista de procurações que o serviço devolve — cada uma com
     * `dtexpiracao` em `aaaammdd` e `sistemas` com os nomes e-CAC — e o nome
     * que o `SerproPowerNames` não conhece não entra: desconhecido não é
     * autorizado.
     *
     * @return array<string, string|null>
     */
    private function familiasConcedidas(mixed $dados): array
    {
        if (! is_array($dados)) {
            return [];
        }

        $concedidas = [];

        foreach ($dados as $procuracao) {
            if (! is_array($procuracao)) {
                continue;
            }

            $expiresOn = $this->dataDeExpiracao($procuracao['dtexpiracao'] ?? null);

            foreach ($procuracao['sistemas'] ?? [] as $sistema) {
                if (! is_string($sistema)) {
                    continue;
                }

                foreach ($this->names->familiesFor($sistema) as $family) {
                    // Duas outorgas cobrindo a mesma família valem pela
                    // mais longa, e uma sem data não apaga uma datada.
                    if (! array_key_exists($family, $concedidas)
                        || ($expiresOn !== null && ($concedidas[$family] === null || $expiresOn > $concedidas[$family]))) {
                        $concedidas[$family] = $expiresOn;
                    }
                }
            }
        }

        return $concedidas;
    }

    /**
     * `aaaammdd` → `Y-m-d`, sem inventar data: um `dtexpiracao` ilegível é
     * uma outorga sem validade, e a elegibilidade decide o que fazer com
     * `null` em vez de receber um `1970-01-01` disfarçado.
     */
    private function dataDeExpiracao(mixed $dtexpiracao): ?string
    {
        if (! is_scalar($dtexpiracao)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $dtexpiracao);

        if (strlen($digits) !== 8) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Ymd', $digits);

        return $date === false ? null : $date->format('Y-m-d');
    }

    private function estadoPorValidade(?string $expiresOn): SerproPowerOfAttorneyState
    {
        if ($expiresOn !== null && $expiresOn < today()->toDateString()) {
            return SerproPowerOfAttorneyState::Expired;
        }

        return SerproPowerOfAttorneyState::Established;
    }

    /**
     * O `integration_state` da procuração que o Membro registrou espelha o
     * que o provedor respondeu: `established` quando há família concedida em
     * vigor, `expired` quando só sobrou outorga vencida, `rejected` quando a
     * resposta não trouxe nenhuma — inclusive a lista vazia, que é o provedor
     * dizendo "não consta", e não uma ausência de resposta.
     *
     * Datas e código do Membro não são deste método: quem os escreveu foi a
     * request do operador, e o oracle só fala do estado.
     *
     * @param  array<string, string|null>  $granted
     */
    private function refletirNaProcuracao(int $accountId, int $clientId, array $granted): void
    {
        $procuracao = ClientEcacPowerOfAttorney::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('client_id', $clientId)
            ->first();

        if ($procuracao === null) {
            return;
        }

        $estado = match (true) {
            $granted === [] => SerproPowerOfAttorneyState::Rejected,
            collect($granted)->contains(
                fn (?string $expiresOn) => $expiresOn === null || $expiresOn >= today()->toDateString()
            ) => SerproPowerOfAttorneyState::Established,
            default => SerproPowerOfAttorneyState::Expired,
        };

        // `forceFill` + `saveQuietly`: o `saving` do model devolveria
        // `pending` se `serpro_code` aparecesse sujo, e o estado não passa
        // por `Fillable` — quem o escreve aqui é a resposta do provedor.
        $procuracao->forceFill(['integration_state' => $estado])->saveQuietly();
    }
}
