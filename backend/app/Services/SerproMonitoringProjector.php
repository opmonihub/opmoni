<?php

namespace App\Services;

use App\Enums\SerproPowerOfAttorneyState;
use App\Enums\SerproSyncRunState;
use App\Models\Client;
use App\Models\SerproClientAuthorization;
use App\Models\SerproMonitoring;
use App\Models\SerproSyncRunItem;

/**
 * A projeção de uma linha `serpro_monitorings` para o contrato que a tela lê.
 *
 * O que a tabela guarda é a leitura do provedor (`state`, `cause`, `fields`,
 * `periods`, `messages`, `due_on`, `source_at`); o que a tela precisa é a
 * interpretação daquele material — e interpretar é aqui, de propósito fora do
 * model: `situacao` é derivada toda vez, e nunca gravada, porque ela muda com
 * o calendário (prazo) e com a execução em curso (`processando`).
 *
 * A ordem do `match` é a semântica: a obrigação em processamento na execução
 * corrente prevalece sobre tudo, a causa gravada sobre o desfecho `encerrado`,
 * e o encerrado sobre o prazo — um `encerrado` contado como pendência
 * inflaria justamente o contador que pede ação.
 *
 * `stale` não entra na situação: é o sinal de "o dado retido envelheceu", e
 * depende só de a outorga da família valer hoje. Obrigação sem família
 * (`procuracao` nulo no catálogo) não lê concessão nenhuma.
 */
final class SerproMonitoringProjector
{
    public function __construct(private readonly SerproObligationCatalog $catalogo) {}

    /**
     * @return array{client_id: int, name: string, tax_id: ?string, situacao: string, cause: ?string, due_on: ?string, stale: bool, power_of_attorney_expires_on: ?string, fields: array<string, string|int|float|null>, periods: ?array<int, array<string, mixed>>, message: ?array{id: int, assunto: string, received_at: ?string, lida_em: ?string, ciencia_em: ?string, prazo_limite: ?string, unread: bool}}
     */
    public function row(
        Client $client,
        SerproMonitoring $record,
        ?SerproClientAuthorization $authorization,
        ?SerproSyncRunItem $activeItem,
    ): array {
        $situacao = match (true) {
            $this->emProcessamento($record, $activeItem) => 'processando',
            $record->cause !== null => 'atencao',
            $record->state === 'encerrado' => 'encerrado',
            $record->due_on !== null && $record->due_on->lte(today()->addDays(30)) => 'pendencias',
            default => 'em_dia',
        };

        return [
            'client_id' => $client->getKey(),
            'name' => $client->name,
            'tax_id' => $client->tax_id,
            'situacao' => $situacao,
            'cause' => $record->cause,
            'due_on' => $record->due_on?->toDateString(),
            'stale' => $this->desatualizado($record, $authorization),
            'power_of_attorney_expires_on' => $authorization?->expires_on?->toDateString(),
            'fields' => $this->campos($record->fields),
            'periods' => $record->periods,
            'message' => $this->mensagem($record->messages),
        ];
    }

    /**
     * A execução corrente está respondendo esta obrigação para este cliente:
     * o item com `current_obligation` é a fronteira que o job marca antes de
     * chamar, e vale enquanto a execução não terminou.
     */
    private function emProcessamento(SerproMonitoring $record, ?SerproSyncRunItem $activeItem): bool
    {
        if ($activeItem === null || $activeItem->current_obligation !== $record->obligation) {
            return false;
        }

        $estado = $activeItem->run?->state;

        return in_array($estado, [SerproSyncRunState::Queued, SerproSyncRunState::Running], true);
    }

    /**
     * Dado retido sem outorga vigente. O retido é o que o provedor respondeu
     * (`source_at`), e a vigência é a da concessão que o oráculo observou —
     * `null` vale "não há concessão observada" para obrigação que exige.
     * Obrigação sem família não pergunta: `mei` não precisa de outorga.
     */
    private function desatualizado(SerproMonitoring $record, ?SerproClientAuthorization $authorization): bool
    {
        if ($record->source_at === null) {
            return false;
        }

        if (($this->catalogo->get($record->obligation)['procuracao'] ?? null) === null) {
            return false;
        }

        if ($authorization === null) {
            return true;
        }

        if ($authorization->state !== SerproPowerOfAttorneyState::Established) {
            return true;
        }

        $vencimento = $authorization->expires_on?->toDateString();

        return $vencimento !== null && $vencimento < today()->toDateString();
    }

    /**
     * Os campos passam como o provedor os deu, menos booleano: o contrato da
     * tela tipa `string|number|null`, e `mais_paginas` da caixa postal é
     * `bool` no payload.
     *
     * @param  array<string, mixed>|null  $fields
     * @return array<string, string|int|float|null>
     */
    private function campos(?array $fields): array
    {
        $saida = [];

        foreach ($fields ?? [] as $chave => $valor) {
            $saida[$chave] = is_bool($valor) ? (int) $valor : $valor;
        }

        return $saida;
    }

    /**
     * O stub da mensagem mais recente — assunto e datas, e nunca corpo: ler a
     * mensagem é ciência da intimação (D19), e a listagem não pode praticá-lo
     * como efeito colateral de desenhar a linha.
     *
     * @param  array<int, array<string, mixed>>|null  $messages
     * @return array{id: int, assunto: string, received_at: ?string, lida_em: ?string, ciencia_em: ?string, prazo_limite: ?string, unread: bool}|null
     */
    private function mensagem(?array $messages): ?array
    {
        if ($messages === null || $messages === []) {
            return null;
        }

        return array_reduce(
            $messages,
            fn (?array $escolhida, array $mensagem): array => $escolhida === null
                || ($mensagem['received_at'] ?? '') > ($escolhida['received_at'] ?? '')
                    ? $mensagem
                    : $escolhida,
        );
    }
}
