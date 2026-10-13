<?php

namespace App\Services\Fiscal\Manifestacao;

use App\Enums\FiscalFailure;
use App\Enums\FiscalManifestationOutcome;
use App\Services\Fiscal\Exceptions\FiscalException;

/**
 * O que o `retEnvEvento` significa para a manifestação.
 *
 * São dois cStats, não um: o do lote e o do evento dentro dele. Um lote que o
 * serviço não processou (o `retEnvEvento` sem `retEvento`) não fala nada sobre
 * a chave — a classificação é da resposta como um todo, e o evento só tem
 * veredito quando o lote foi aceito (`128`) e trouxe o `retEvento`.
 *
 * A regra que não cabe na taxonomia do `FiscalFailure`: o `573` ("duplicidade
 * de evento") é **estado conhecido**, não rejeição. Ele diz que alguém — nós
 * ou terceiro — já manifestou esta chave, e a consequência é a mesma de uma
 * ciência nossa registrada: seguir para o `consChNFe`. Tratá-lo como falha
 * transitória queimaria retries num veredito que não muda.
 *
 * O `xMotivo` que vai para o registro é condensado pelo mesmo limite que o
 * fault do transporte, porque é o que vai para a coluna e para o log.
 */
final class ManifestationResult
{
    private const REASON_LIMIT = 300;

    /**
     * cStats do evento que significam "registrado": `135` (registrado e
     * vinculado à NF-e) e `136` (registrado, não vinculado — quando a NF-e ainda
     * não consta no ambiente do autor do evento).
     *
     * @var list<string>
     */
    private const EVENTO_ACEITO = ['135', '136'];

    /**
     * cStats do evento que são veredito e não falha: o `573` é o único hoje.
     *
     * @var array<string, FiscalManifestationOutcome>
     */
    private const ESTADOS_CONHECIDOS = [
        '573' => FiscalManifestationOutcome::AlreadyManifested,
    ];

    /**
     * O veredito, ou a `FiscalException` quando a resposta é rejeição de
     * verdade (transitória ou não) — quem chama só grava `result_*` quando o
     * método devolve, porque uma rejeição ainda não é veredito do evento.
     *
     * @throws FiscalException quando a resposta é recusa do serviço
     */
    public function classify(string $cStatLote, string $xMotivoLote, ?string $cStatEvento, ?string $xMotivoEvento): ManifestationVerdict
    {
        // Lote não processado: o `retEvento` não existe e a rejeição é do lote
        // inteiro — a taxonomia lê o cStat do lote como lê o do `retDistDFeInt`.
        if ($cStatLote !== '128') {
            throw new FiscalException(
                'O serviço de eventos recusou o lote da manifestação: '.$this->condense($xMotivoLote),
                FiscalFailure::classify(200, $cStatLote),
            );
        }

        if ($cStatEvento === null) {
            throw new FiscalException(
                'O lote foi processado sem veredito para o evento.',
                FiscalFailure::Upstream,
            );
        }

        $motivo = $this->condense($xMotivoEvento ?? '');

        if (in_array($cStatEvento, self::EVENTO_ACEITO, true)) {
            return new ManifestationVerdict(FiscalManifestationOutcome::Sent, $cStatEvento, $motivo);
        }

        $estado = self::ESTADOS_CONHECIDOS[$cStatEvento] ?? null;

        if ($estado !== null) {
            return new ManifestationVerdict($estado, $cStatEvento, $motivo);
        }

        // Rejeição definitiva do evento: `Failed` é o outcome do registro, mas
        // quem lança é a taxonomia — o `default` de `classify()` lê cStat
        // desconhecido como rejeição não retentável, e a lista dela já cobre
        // os transitórios (108/109 → Upstream, 656/678 → Blocked).
        throw new FiscalException(
            'O evento de manifestação foi rejeitado: '.$motivo,
            FiscalFailure::classify(200, $cStatEvento),
        );
    }

    /**
     * A frase do fisco cabe numa coluna e numa linha de log — a mesma regra do
     * `faultstring` condensado do transporte de distribuição.
     */
    private function condense(string $text): string
    {
        $condensed = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return mb_strlen($condensed) > self::REASON_LIMIT
            ? mb_substr($condensed, 0, self::REASON_LIMIT).'…'
            : $condensed;
    }
}
