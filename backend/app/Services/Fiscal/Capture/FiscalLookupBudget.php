<?php

namespace App\Services\Fiscal\Capture;

use App\Models\Client;
use Illuminate\Support\Facades\Cache;

/**
 * O teto de consultas pontuais por CNPJ e por hora.
 *
 * O fisco bloqueia o CNPJ por uma hora depois de consumo indevido, e consumo
 * indevido é consulta além do que a NT publica. A captura incremental não
 * entra nessa conta — um lote por cliente por hora é o uso normal — mas a
 * reconciliação é a máquina de disparar consulta por posição, e é ela que
 * precisa de teto: sem ele, o mecanismo que fecha buraco é exatamente o que
 * provoca o bloqueio que silencia a carteira inteira.
 *
 * Quatro decisões que a classe existe para sustentar:
 *
 * 1. **A identidade é o CNPJ, nunca a conta.** Dois escritórios com o mesmo
 *    cliente são a mesma pessoa jurídica para o fisco, e o fisco não sabe de
 *    qual escritório a consulta saiu. Um teto por conta seria duas vezes o
 *    limite para o CNPJ, que é o que o fisco conta.
 * 2. **`false` adia, não insiste.** Repetir consulta para dentro de um limite
 *    já gasto é o caminho curto para o bloqueio.
 * 3. **A reserva é atômica.** A leitura e a gravação do contador acontecem
 *    dentro de uma trava: sem ela, vinte e uma consultas concorrentes passam
 *    todas num contador que valia dezenove.
 * 4. **A janela é a hora, não a hora contada a partir da primeira consulta.** O
 *    contador expira no topo da hora seguinte, então o fisco que reinicia a
 *    contagem é o nosso relógio, e não o primeiro buraco do dia.
 *
 * O limite vem de `config('fiscal.consulta_hourly_limit')` — o número publicado
 * muda, e um número escrito aqui passaria a divergir do fisco em silêncio. A
 * chave do contador é o CNPJ e nada mais: nenhum material de certificado, senha
 * ou XML entra em chave de cache, e a chave não vai para log.
 */
final class FiscalLookupBudget
{
    /**
     * A trava cobre a leitura e a gravação do contador, e é curta porque a
     * janela crítica é a de dois comandos de cache — cinco segundos é folga,
     * não espera.
     */
    private const LOCK_SECONDS = 5;

    /**
     * Quanto se espera pela trava. Quem não consegue a trava nesse tempo leva o
     * `LockTimeoutException` do próprio Laravel — e nenhuma requisição sai
     * mesmo assim, porque a reserva é anterior à chamada. A trava é curta e a
     * janela crítica é a de dois comandos de cache: esperar mais seria esperar
     * por quem não existe.
     */
    private const LOCK_WAIT_SECONDS = 1;

    public function reserve(Client $client): bool
    {
        $cnpj = (string) $client->tax_id;

        return Cache::lock(self::lockKey($cnpj), self::LOCK_SECONDS)->block(
            self::LOCK_WAIT_SECONDS,
            fn (): bool => $this->charge($cnpj, (int) config('fiscal.consulta_hourly_limit')),
        );
    }

    /**
     * A janela crítica: lê, decide e grava sem sair da trava. A chave da trava
     * e a chave do contador são nomes diferentes de propósito — um store que
     * divida o mesmo espaço para os dois trataria o contador como trava.
     */
    private function charge(string $cnpj, int $limit): bool
    {
        $used = (int) (Cache::get(self::counterKey($cnpj)) ?? 0);

        if ($used >= $limit) {
            return false;
        }

        Cache::put(self::counterKey($cnpj), $used + 1, now()->addHour()->startOfHour());

        return true;
    }

    private static function lockKey(string $cnpj): string
    {
        return 'fiscal:consulta:'.$cnpj;
    }

    private static function counterKey(string $cnpj): string
    {
        return 'fiscal:consulta:hora:'.$cnpj;
    }
}
