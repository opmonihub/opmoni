<?php

namespace App\Services\Fiscal\Capture;

use App\Enums\FiscalSkipReason;

/**
 * O que uma execução de captura fez, para quem chamou saber sem ler o banco.
 *
 * `ran` é "consultamos o serviço", e não "terminamos bem": uma rejeição que
 * bloqueia também é uma consulta que aconteceu, e a diferença entre as duas está
 * no cursor, que é onde o estado do cliente vive.
 *
 * `toNsu` é a posição que o cursor tem **depois** desta execução, adotada ou não.
 * É essa propriedade que torna uma falha observável sem coluna nova: num lote que
 * não entrou inteiro, `toNsu === fromNsu`, e quem chamou sabe que a posição não
 * avançou sem precisar saber o tamanho do lote.
 */
final readonly class FiscalCaptureOutcome
{
    public function __construct(
        public bool $ran,
        public ?FiscalSkipReason $skipReason,
        public int $stored,
        public int $fromNsu,
        public int $toNsu,
    ) {}

    /**
     * Uma execução que não consultou o serviço. Não é uma falha: é um cliente que
     * não é consultável agora, e a razão está no `skipReason` para que o painel
     * diga qual, porque um cliente sem certificado e um cliente bloqueado pelo
     * fisco são motivos diferentes e a carteira precisa distinguir os dois.
     */
    public static function skipped(FiscalSkipReason $reason, int $fromNsu): self
    {
        return new self(false, $reason, 0, $fromNsu, $fromNsu);
    }
}
