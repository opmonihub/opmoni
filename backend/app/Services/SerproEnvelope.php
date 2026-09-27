<?php

namespace App\Services;

use JsonException;

final class SerproEnvelope
{
    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function build(
        string $contratante,
        int $contratanteTipo,
        string $autor,
        string $contribuinte,
        string $idSistema,
        string $idServico,
        string $versaoSistema,
        array $dados,
    ): array {
        return [
            'contratante' => [
                'numero' => $contratante,
                'tipo' => $contratanteTipo,
            ],
            'autorPedidoDados' => [
                'numero' => $autor,
                'tipo' => $this->tipo($autor),
            ],
            'contribuinte' => [
                'numero' => $contribuinte,
                'tipo' => $this->tipo($contribuinte),
            ],
            'pedidoDados' => [
                'idSistema' => $idSistema,
                'idServico' => $idServico,
                'versaoSistema' => $versaoSistema,
                'dados' => $dados === []
                    ? ''
                    : json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, response_id: ?string, dados: mixed, mensagens: list<array{codigo: string, texto: string}>}
     */
    public function parse(array $payload): array
    {
        $raw = $payload['dados'] ?? null;

        return [
            'status' => (int) ($payload['status'] ?? 0),
            'response_id' => isset($payload['responseId']) ? (string) $payload['responseId'] : null,
            'dados' => is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw,
            'mensagens' => $this->mensagens($payload['mensagens'] ?? []),
        ];
    }

    /**
     * @return list<array{codigo: string, texto: string}>
     */
    private function mensagens(mixed $mensagens): array
    {
        if (! is_array($mensagens)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $mensagem): array => [
                'codigo' => (string) data_get($mensagem, 'codigo', ''),
                'texto' => (string) data_get($mensagem, 'texto', ''),
            ],
            array_filter($mensagens, is_array(...)),
        ));
    }

    private function tipo(string $documento): int
    {
        return strlen($documento) === 11 ? 1 : 2;
    }
}
