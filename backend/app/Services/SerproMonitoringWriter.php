<?php

namespace App\Services;

use App\Models\SerproMonitoring;

/**
 * O único caminho que grava a projeção `(conta, cliente, obrigação)`.
 *
 * A linha é encontrada ou criada pelo vínculo — a unique do banco é a
 * barreira do re-sync — e a projeção chega já mapeada: o writer copia as
 * chaves que a tabela conhece e nada mais, porque o que a mapper produz é
 * o que o contrato da tela declara.
 *
 * Duas regras fazem a re-escrita idempotente:
 *
 * - `state` só se move quando a projeção traz a chave. `encerrado` é uma
 *   decisão que nenhum serviço deste conjunto devolve, e uma sincronização
 *   nova não desfaz o que ela não produziu;
 * - `source_at` só se preenche quando `sourceAt` vem: é o carimbo de "o
 *   serviço respondeu", e a marca de "a obrigação foi tentada" — `null` —
 *   não finge resposta.
 */
final class SerproMonitoringWriter
{
    /** As colunas que uma projeção pode tocar; o resto da linha é identidade. */
    private const PROJETAVEIS = ['state', 'cause', 'due_on', 'fields', 'periods', 'messages'];

    /**
     * @param  array<string, mixed>  $projecao
     */
    public function store(
        int $accountId,
        int $clientId,
        string $obligation,
        array $projecao,
        ?string $sourceAt,
    ): SerproMonitoring {
        $monitoring = SerproMonitoring::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('client_id', $clientId)
            ->where('obligation', $obligation)
            ->first() ?? new SerproMonitoring;

        $atributos = [
            'account_id' => $accountId,
            'client_id' => $clientId,
            'obligation' => $obligation,
            ...collect($projecao)->only(self::PROJETAVEIS)->all(),
        ];

        if ($sourceAt !== null) {
            $atributos['source_at'] = $sourceAt;
        }

        $monitoring->forceFill($atributos)->save();

        return $monitoring;
    }

    /**
     * Grava a projeção direta e, quando o mapper define derivadas da mesma
     * resposta, replica o carimbo nas linhas filtradas — uma chamada, várias
     * obrigações, como a caixa postal e-CAC alimenta FGTS Digital e DET.
     *
     * @param  array<string, mixed>  $projecao
     */
    public function storeWithDerived(
        int $accountId,
        int $clientId,
        string $obligation,
        string $idServico,
        array $projecao,
        ?string $sourceAt,
    ): SerproMonitoring {
        if ($obligation === 'parcelamentos/receita-federal') {
            $existente = SerproMonitoring::query()
                ->withoutGlobalScope('account')
                ->where('account_id', $accountId)
                ->where('client_id', $clientId)
                ->where('obligation', $obligation)
                ->first();

            if ($existente !== null && $existente->source_at !== null) {
                $projecao = resolve(SerproMonitoringMapper::class)->mesclarPedidosParcelamento([
                    'fields' => is_array($existente->fields) ? $existente->fields : [],
                    'periods' => is_array($existente->periods) ? $existente->periods : [],
                    'cause' => $existente->cause,
                ], $projecao);
            }
        }

        $monitoring = $this->store($accountId, $clientId, $obligation, $projecao, $sourceAt);

        foreach (resolve(SerproMonitoringMapper::class)->derived($idServico, $projecao) as $slug => $derivada) {
            $this->store($accountId, $clientId, $slug, $derivada, $sourceAt);
        }

        return $monitoring;
    }
}
