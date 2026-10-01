<?php

namespace App\Services;

use App\Enums\DeadlineStatus;
use App\Enums\SerproPowerOfAttorneyState;
use App\Models\Client;
use App\Models\SerproClientAuthorization;
use App\Models\SerproMonitoring;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A procuração e-CAC derivada: a menor validade entre as famílias que os
 * módulos associados exigem — ou, sem módulo que exija família, todas as
 * famílias que o provedor já confirmou para o cliente.
 *
 * A resposta é o que `ClientResource.ecac_power_of_attorney` publica: o
 * estado na escala de `DeadlineStatus`, a menor validade e a lista de
 * famílias. Nenhum dado digitado participa — a procuração é o que o provedor
 * respondeu, e só ele.
 *
 * A precedência é a que o painel lê: `expired` derruba tudo, `missing` é
 * família exigida e não confirmada, `expiring` é a menor validade em até
 * trinta dias, `valid` é o que sobra.
 */
final class ClientPowerOfAttorneySummary
{
    /** O mapa `procuracao` → famílias, por obrigação. */
    private const VAZIO = ['status' => DeadlineStatus::Missing, 'expires_on' => null, 'families' => []];

    /**
     * O resumo de um cliente só, pelo caminho em lote — a lista de um é o
     * caso degenerado da lista de muitos, e duas implementações divergem.
     *
     * @return array{status: string, expires_on: ?string, families: list<array{family: string, state: string, expires_on: ?string}>}
     */
    public function for(Client $client): array
    {
        return $this->forClients(collect([$client]))->get($client->getKey(), self::VAZIO);
    }

    /**
     * Calcula a mesma projeção para filtros e ordenação, antes da paginação.
     * A consulta original conserva os próprios campos e relações.
     *
     * @param  Builder<Client>  $query
     * @return Collection<int, array{status: string, expires_on: ?string, families: list<array{family: string, state: string, expires_on: ?string}>}>
     */
    public function forQuery(Builder $query): Collection
    {
        $clients = (clone $query)
            ->withoutEagerLoads()
            ->reorder()
            ->select(['clients.id', 'clients.account_id'])
            ->get();

        return $this->forClients($clients);
    }

    /**
     * O resumo de uma coleção de clientes, com três consultas no total —
     * nenhuma por cliente.
     *
     * @param  Collection<int, Client>  $clients
     * @return Collection<int, array{status: string, expires_on: ?string, families: list<array{family: string, state: string, expires_on: ?string}>}>
     */
    public function forClients(Collection $clients): Collection
    {
        if ($clients->isEmpty()) {
            return collect();
        }

        $ids = $clients->map(fn (Client $client): int|string => $client->getKey())->all();
        $catalogo = resolve(SerproObligationCatalog::class);

        // As famílias que cada cliente precisa: a união de `procuracao` dos
        // módulos associados a ele, já quebrada em alternativas.
        $vinculos = SerproMonitoring::query()
            ->whereIntegerInRaw('client_id', $ids)
            ->get(['client_id', 'obligation'])
            ->groupBy('client_id');

        $autorizacoes = SerproClientAuthorization::query()
            ->whereIntegerInRaw('client_id', $ids)
            ->get()
            ->groupBy('client_id');

        return $clients->mapWithKeys(fn (Client $client): array => [
            $client->getKey() => $this->resumo($client, $vinculos, $autorizacoes, $catalogo),
        ]);
    }

    /**
     * O resumo de um cliente, a partir dos mapas já carregados.
     *
     * @param  Collection<int, Collection<int, SerproMonitoring>>  $vinculos
     * @param  Collection<int, Collection<int, SerproClientAuthorization>>  $autorizacoes
     * @return array{status: string, expires_on: ?string, families: list<array{family: string, state: string, expires_on: ?string}>}
     */
    private function resumo(
        Client $client,
        Collection $vinculos,
        Collection $autorizacoes,
        SerproObligationCatalog $catalogo,
    ): array {
        $doCliente = $autorizacoes->get($client->getKey(), collect());
        $exigidas = $this->familiasExigidas($vinculos->get($client->getKey(), collect()), $catalogo);

        // Sem módulo que exija família, o resumo é das que o provedor
        // confirmou — que é o que se tem para mostrar quando nenhuma
        // obrigação associada pede uma família específica.
        if ($exigidas === []) {
            $familias = $this->linhasDeFamilia(
                $doCliente->pluck('family')->unique()->sort()->values()->all(),
                $doCliente,
            );
            $status = $this->estado($familias, false);

            return [
                'status' => $status->value,
                'expires_on' => $status === DeadlineStatus::Missing ? null : $this->menorValidade($familias),
                'families' => $familias,
            ];
        }

        // Cada obrigação contribui as alternativas do `procuracao` dela, e
        // cada uma escolhe a alternativa que melhor cobre o cliente — a
        // união das escolhidas é o que o resumo lista.
        $consideradas = collect($exigidas)
            ->map(fn (array $alternativas): array => $this->melhorAlternativa($alternativas, $doCliente))
            ->flatten()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $familias = $this->linhasDeFamilia($consideradas, $doCliente);
        $status = $this->estado($familias, true);

        // Quando o que falta decide, o resumo lista só o que falta — é o
        // "outorgue estas famílias" que a tela mostra, e a família já
        // confirmada dentro da mesma conjunção não é uma pendência.
        if ($status === DeadlineStatus::Missing) {
            $familias = array_values(array_filter(
                $familias,
                fn (array $familia): bool => $familia['state'] !== 'established',
            ));
        }

        return [
            'status' => $status->value,
            // Falta família? O resumo não publica data — uma `expires_on` ao
            // lado de `missing` sugeriria uma procuração que não existe.
            'expires_on' => $status === DeadlineStatus::Missing ? null : $this->menorValidade($familias),
            'families' => $familias,
        ];
    }

    /**
     * As alternativas de família que a união dos módulos exige, em lista.
     * Cada entrada é uma conjunção de famílias — `['00146']` ou
     * `['00076', '00188']` — e a resposta vale pela melhor coberta.
     *
     * @param  Collection<int, SerproMonitoring>  $vinculos
     * @return list<list<string>>
     */
    private function familiasExigidas(Collection $vinculos, SerproObligationCatalog $catalogo): array
    {
        $exigidas = [];

        foreach ($vinculos as $vinculo) {
            $obrigacao = $catalogo->get((string) $vinculo->obligation);

            if ($obrigacao === null || $obrigacao['procuracao'] === null) {
                continue;
            }

            // Cada obrigação contribui as próprias alternativas — e elas não
            // se misturam com as de outra obrigação: duas obrigações não são
            // alternativas entre si, cada uma exige as famílias que pede.
            $exigidas[] = array_values(array_filter(
                $catalogo->procuracaoAlternatives($obrigacao['procuracao']),
                fn (array $alternativa): bool => $alternativa !== [],
            ));
        }

        return $exigidas;
    }

    /**
     * A alternativa que melhor cobre o cliente, entre as que a obrigação
     * aceita. A pontuação é a precedência do estado em número: alternativa
     * com família vencida vale menos que a com família faltando, e a com
     * família faltando vale menos que a coberta.
     *
     * @param  list<list<string>>  $alternativas
     * @param  Collection<int, SerproClientAuthorization>  $autorizacoes
     * @return list<string>
     */
    private function melhorAlternativa(array $alternativas, Collection $autorizacoes): array
    {
        $melhor = [];
        $melhorNota = -3;

        foreach ($alternativas as $alternativa) {
            $nota = 0;
            $temVencida = false;
            $temFaltante = false;

            foreach ($alternativa as $familia) {
                $autorizacao = $autorizacoes->firstWhere('family', $familia);
                $expires = $autorizacao?->expires_on?->toDateString();

                if ($autorizacao !== null
                    && ($autorizacao->state === SerproPowerOfAttorneyState::Expired
                        || ($expires !== null && $expires < today()->toDateString()))) {
                    $temVencida = true;

                    break;
                }

                if ($autorizacao === null || $autorizacao->state !== SerproPowerOfAttorneyState::Established) {
                    $temFaltante = true;
                }
            }

            // A vencida derruba a alternativa inteira; a faltante a marca.
            $nota = $temVencida ? -2 : ($temFaltante ? -1 : 0);

            if ($nota > $melhorNota) {
                $melhor = $alternativa;
                $melhorNota = $nota;
            }
        }

        return $melhor;
    }

    /**
     * As famílias consideradas, cada uma com o estado e a validade que o
     * provedor deixou — `missing` para a que a alternativa exige e ele não
     * confirmou.
     *
     * @param  list<string>  $consideradas
     * @param  Collection<int, SerproClientAuthorization>  $autorizacoes
     * @return list<array{family: string, state: string, expires_on: ?string}>
     */
    private function linhasDeFamilia(array $consideradas, Collection $autorizacoes): array
    {
        $linhas = [];

        foreach ($consideradas as $familia) {
            $autorizacao = $autorizacoes->firstWhere('family', $familia);

            $linhas[] = [
                'family' => $familia,
                'state' => $autorizacao?->state?->value ?? 'missing',
                'expires_on' => $autorizacao?->expires_on?->toDateString(),
            ];
        }

        return $linhas;
    }

    /**
     * A precedência do design: `expired` derruba tudo, `missing` é família
     * exigida e não confirmada, `expiring` é validade em até trinta dias,
     * `valid` é o resto.
     *
     * @param  list<array{family: string, state: string, expires_on: ?string}>  $familias
     */
    private function estado(array $familias, bool $haviaExigencia): DeadlineStatus
    {
        if ($familias === []) {
            return DeadlineStatus::Missing;
        }

        $hoje = today()->toDateString();
        $estabelecidas = 0;

        foreach ($familias as $familia) {
            if ($familia['state'] === 'expired'
                || ($familia['expires_on'] !== null && $familia['expires_on'] < $hoje)) {
                return DeadlineStatus::Expired;
            }
        }

        foreach ($familias as $familia) {
            if ($familia['state'] !== 'established') {
                // Quando o resumo veio do fallback (sem exigência), uma família
                // que não é `established` não é `missing` — é só uma linha que
                // o provedor devolveu em outro estado, e ela não decide nada.
                if ($haviaExigencia) {
                    return DeadlineStatus::Missing;
                }

                continue;
            }

            $estabelecidas++;
        }

        if ($estabelecidas === 0) {
            return DeadlineStatus::Missing;
        }

        $menor = $this->menorValidade($familias);

        if ($menor === null) {
            return DeadlineStatus::Valid;
        }

        return CarbonImmutable::parse($menor)->startOfDay()->lte(today()->addDays(30))
            ? DeadlineStatus::Expiring
            : DeadlineStatus::Valid;
    }

    /**
     * A menor validade entre as famílias consideradas, ou `null` quando
     * nenhuma tem data — a resposta `expires_on` é a data mais perto de
     * acabar, e uma família sem validade não decide o pior caso.
     *
     * @param  list<array{family: string, state: string, expires_on: ?string}>  $familias
     */
    private function menorValidade(array $familias): ?string
    {
        $datas = array_filter(array_column($familias, 'expires_on'));

        return $datas === [] ? null : min($datas);
    }
}
