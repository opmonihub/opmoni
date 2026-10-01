<?php

namespace App\Services;

use App\Enums\ClientPersonType;
use App\Enums\SerproPowerOfAttorneyState;
use App\Models\Client;
use App\Models\SerproClientAuthorization;

/**
 * A elegibilidade por família: "este cliente pode ser chamado neste
 * serviço agora?", respondida **sem nenhuma ida à rede**.
 *
 * As recusas têm um código próprio porque cada uma tem um conserto
 * diferente:
 *
 * - `pessoa_fisica` — a integração só age por pessoa jurídica;
 * - `sem_procuracao` — o provedor nunca respondeu uma outorga para a
 *   família;
 * - `procuracao_invalida` — a outorga existe e não vale: pendente,
 *   recusada ou vencida;
 * - `sem_termo` — o escritório não tem token de autorização vigente, e
 *   aí nenhuma procuração importa.
 *
 * `PROCURACOES` é a exceção que a tabela do provedor declara: a consulta de
 * procuração não exige procuração — é ela quem descobre que o cliente nunca
 * teve uma —, e por isso basta o termo vigente.
 */
final class SerproEligibility
{
    /**
     * A família que não precisa de família: a consulta ao oráculo.
     */
    private const ORACULO = 'PROCURACOES';

    public function __construct(private readonly SerproTermManager $terms) {}

    /**
     * @return array{eligible: bool, reason: ?string, expires_on: ?string}
     */
    public function for(int $accountId, int $clientId, string $family): array
    {
        // O `account_id` é parâmetro e o escopo é do chamador: este serviço
        // roda de job, onde `CurrentTenant` é o que sobrou da entrega
        // anterior, e um cliente encontrado pelo escopo errado seria um
        // elegível alheio.
        $client = Client::query()->where('account_id', $accountId)->findOrFail($clientId);

        if ($client->person_type !== ClientPersonType::Company) {
            return $this->inaptidao('pessoa_fisica');
        }

        if ($this->terms->validToken($accountId) === null) {
            return $this->inaptidao('sem_termo');
        }

        if ($family === self::ORACULO) {
            return ['eligible' => true, 'reason' => null, 'expires_on' => null];
        }

        $authorization = SerproClientAuthorization::query()
            ->where('account_id', $accountId)
            ->where('client_id', $clientId)
            ->where('family', $family)
            ->first();

        if ($authorization === null) {
            return $this->inaptidao('sem_procuracao');
        }

        $expiresOn = $authorization->expires_on?->toDateString();
        $hoje = today()->toDateString();

        if ($authorization->state !== SerproPowerOfAttorneyState::Established
            || ($expiresOn !== null && $expiresOn < $hoje)) {
            return ['eligible' => false, 'reason' => 'procuracao_invalida', 'expires_on' => $expiresOn];
        }

        return ['eligible' => true, 'reason' => null, 'expires_on' => $expiresOn];
    }

    /**
     * @return array{eligible: false, reason: string, expires_on: ?string}
     */
    private function inaptidao(string $reason): array
    {
        return ['eligible' => false, 'reason' => $reason, 'expires_on' => null];
    }
}
