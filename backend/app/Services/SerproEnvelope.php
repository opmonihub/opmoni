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
                'tipo' => self::tipo($autor),
            ],
            'contribuinte' => [
                'numero' => $contribuinte,
                'tipo' => self::tipo($contribuinte),
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
     * O envelope da resposta, lido do jeito que o provedor o manda.
     *
     * `status` é o status do envelope e não o HTTP: `0` é o valor para "o
     * provedor não disse", e é o que uma falha do gateway devolve, já que ela
     * não tem envelope. O que não veio não é preenchido com palpite.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: int, response_id: ?string, dados: mixed, mensagens: list<array{codigo: string, texto: string}>}
     */
    public function parse(array $payload): array
    {
        return [
            'status' => (int) ($payload['status'] ?? 0),
            'response_id' => $this->responseId($payload),
            'dados' => $this->dados($payload['dados'] ?? null),
            'mensagens' => $this->mensagens($payload['mensagens'] ?? []),
        ];
    }

    /**
     * O `dados` volta como a mesma string escapada que a requisição manda e, em
     * parte dos serviços, como string dentro de string. Duas passagens é o
     * máximo, e cada uma só acontece quando o texto é JSON; o que sobra é o
     * que o serviço mandou.
     *
     * Um número solto não é payload de serviço. `'00000000000000'` é um
     * documento com zeros à esquerda, e lido como número vira `0` — um
     * identificador que existe, que casa com outros e que ninguém reconheceria
     * como documento. Fica string.
     */
    private function dados(mixed $raw): mixed
    {
        for ($pass = 0; $pass < 2 && is_string($raw); $pass++) {
            $decoded = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE || is_int($decoded) || is_float($decoded)) {
                break;
            }

            $raw = $decoded;
        }

        return $raw;
    }

    /**
     * O `responseId` é o que se cita ao suporte, então um identificador vazio
     * no registro da chamada é pior do que nenhum: parece quotável e não
     * resolve nada.
     *
     * @param  array<string, mixed>  $payload
     */
    private function responseId(array $payload): ?string
    {
        $responseId = $this->textoDe($payload, 'responseId');

        return $responseId === '' ? null : $responseId;
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
                'codigo' => $this->textoDe($mensagem, 'codigo'),
                'texto' => $this->textoDe($mensagem, 'texto'),
            ],
            array_filter($mensagens, is_array(...)),
        ));
    }

    /**
     * Texto que veio do corpo da resposta, e só isso: um campo que o provedor
     * mandou como objeto viraria `"Array"` acompanhado de um aviso, e nenhum
     * dos dois serve para nada.
     */
    private function textoDe(mixed $fonte, string $campo): string
    {
        $valor = is_array($fonte) ? ($fonte[$campo] ?? null) : null;

        return is_scalar($valor) ? trim((string) $valor) : '';
    }

    /**
     * O tipo do documento: `1` é pessoa física e `2` é pessoa jurídica.
     *
     * É a fonte única do número, e é pública e estática porque três lugares do
     * protocolo precisam dele: o `autorPedidoDados.tipo` e o `contribuinte.tipo`
     * que este envelope monta, a coluna `contratante_tipo` que o
     * `SerproConnectionManager` grava a partir dela, e o `X-Request-Tag` de
     * `SerproRequestTag`. Cada cópia da regra é uma chance de o documento e o
     * tipo discordarem, e o provedor nem sempre diz qual dos dois está errado.
     */
    public static function tipo(string $documento): int
    {
        return strlen($documento) === 11 ? 1 : 2;
    }
}
