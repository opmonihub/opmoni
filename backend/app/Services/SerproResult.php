<?php

namespace App\Services;

final readonly class SerproResult
{
    /**
     * @param  list<array{codigo: string, texto: string}>  $mensagens
     */
    public function __construct(
        private int $status,
        private mixed $dados,
        private array $mensagens,
        private ?string $responseId,
        private string $requestTag,
    ) {}

    public function status(): int
    {
        return $this->status;
    }

    public function dados(): mixed
    {
        return $this->dados;
    }

    /**
     * @return list<array{codigo: string, texto: string}>
     */
    public function mensagens(): array
    {
        return $this->mensagens;
    }

    public function responseId(): ?string
    {
        return $this->responseId;
    }

    public function requestTag(): string
    {
        return $this->requestTag;
    }
}
