<?php

namespace App\Services\Fiscal\Capture;

use App\Enums\FiscalSource;
use App\Models\Client;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * A trava por cliente e fonte que o módulo consulta o fisco.
 *
 * Uma chave só, e a mesma para a captura incremental e para a reconciliação:
 * `fiscal:capture:{cliente}:{fonte}`. Ela mora num lugar só porque duplicar a
 * string seria o defeito — duas chaves parecidas são duas chaves diferentes
 * para a trava, e a consulta paralela entre uma captura e uma reconciliação é
 * exatamente a condição prevista que a NT classifica como uso indevido.
 *
 * Quem controla é quem chama: `$work` só roda com a chave tomada, e a chave
 * volta para o poço mesmo com a exceção subindo. A trava é de dono e não de
 * tempo — só quem tomou pode devolver.
 */
final class FiscalCaptureLock
{
    /**
     * Executa `$work` sob a trava do cliente e da fonte, ou devolve `null` sem
     * executar nada quando a chave está ocupada.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn|null `null` é a chave ocupada, e só isso
     */
    public static function run(Client $client, FiscalSource $source, Closure $work): mixed
    {
        $lock = Cache::lock(self::key($client, $source), self::seconds());

        if (! $lock->get()) {
            return null;
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    private static function key(Client $client, FiscalSource $source): string
    {
        return "fiscal:capture:{$client->getKey()}:{$source->value}";
    }

    /**
     * O TTL vem do `fiscal.lock_ttl`, acima do --timeout do worker e não do
     * tempo da chamada: um job morto pelo timeout com a trava vencida antes
     * deixa a execução seguinte começar enquanto a antiga ainda escreve, que é
     * a mesma consulta paralela. E a chave volta para o poço no `finally`, de
     * modo que a trava vazada seria o TTL inteiro, uma vez por falha.
     */
    private static function seconds(): int
    {
        return (int) config('fiscal.lock_ttl', 180);
    }
}
