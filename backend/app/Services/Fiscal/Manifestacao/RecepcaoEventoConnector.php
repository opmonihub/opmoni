<?php

namespace App\Services\Fiscal\Manifestacao;

use App\Enums\FiscalManifestationOutcome;
use App\Models\Client;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use App\Services\Fiscal\Support\ClientCertificateMaterializer;
use App\Services\Fiscal\Support\DfeEndpoint;
use Carbon\CarbonInterface;

/**
 * O conector da ciência da emissão (210210) pelo `nfeRecepcaoEvento` do
 * Ambiente Nacional.
 *
 * O fluxo é uma linha só, com guardas na ordem em que elas custam menos:
 * prazo legal antes de montar, certificado antes de assinar, XSD antes do
 * primeiro byte — e a assinatura é requisito do serviço, não um adendo: um
 * evento que não assinou não sai.
 *
 * Não escreve estado intermediário: o registro sai de `Pending`/`Queued`
 * direto para o veredito (`Sent`, `AlreadyManifested`, `DeadlineMissed`,
 * `NotSendable`) ou fica onde estava quando a resposta é rejeição — quem
 * decide retry é quem enfileirou, com a `FiscalException` na mão.
 *
 * Não consome `FiscalLookupBudget` de propósito: `nfeRecepcaoEvento` não é
 * consulta e o fisco não publica teto de eventos por hora — o orçamento é da
 * `consChNFe` que recupera o XML depois.
 */
final class RecepcaoEventoConnector
{
    /**
     * O prazo legal da ciência da emissão (NT 2014.002): passado ele, o fisco
     * rejeita e a tentativa foi gasta à toa — o veredito é registrado sem
     * envio.
     */
    private const PRAZO_DIAS = 90;

    public function __construct(
        private ManifestationEventBuilder $builder,
        private EventSigner $signer,
        private ManifestationEventTransport $transport,
        private ManifestationResult $result,
        private FiscalManifestationStore $store,
        private ClientCertificateMaterializer $materializer,
    ) {}

    /**
     * Devolve `true` quando a manifestação tem um veredito gravado — aceite,
     * já-manifestado, prazo perdido ou impossibilidade nossa — e `false` nunca:
     * o que não é veredito (rejeição, indisponibilidade, transporte) sobe como
     * `FiscalException` para quem enfileirou decidir a retentativa.
     *
     * @throws FiscalRequestNotSent quando a requisição não pode sair
     * @throws FiscalException quando o serviço
     *                         recusou ou respondeu fora do contrato
     */
    public function cienciaDaEmissao(
        Client $client,
        FiscalManifestation $manifestation,
        ?CarbonInterface $emissaoAt,
        ?CarbonInterface $agora = null,
    ): bool {
        $agora ??= now();

        // O prazo vem antes de qualquer material sensível: fora dele não há
        // evento a montar, nem certificado a abrir, nem byte a viajar.
        if ($emissaoAt === null || $emissaoAt->diffInDays($agora) > self::PRAZO_DIAS) {
            $this->store->veredito(
                $manifestation,
                FiscalManifestationOutcome::DeadlineMissed,
                null,
                'Prazo de 90 dias da ciência da emissão não atendido.',
            );

            return true;
        }

        $endpoint = DfeEndpoint::of(config('fiscal.endpoints', []), 'nfe_recepcao_evento');

        $certificate = $client->currentCertificate;

        if ($certificate === null) {
            throw new FiscalRequestNotSent('Cliente sem certificado A1 vigente.');
        }

        $evento = $this->builder->evento(
            chaveAcesso: $manifestation->chave_acesso,
            autor: $client->tax_id,
            eventType: $manifestation->event_type,
            eventSeq: $manifestation->event_seq,
            agora: $agora,
        );

        $envEvento = $this->materializer->withCertificateBytes(
            $certificate,
            fn (string $bytes, string $password): string => $this->builder->envEvento(
                $this->signer->sign($evento, $bytes, $password),
            ),
        );

        $resposta = $this->transport->send($client, $endpoint, $envEvento);

        $veredito = $this->result->classify(
            $resposta['lote_cstat'],
            $resposta['lote_xmotivo'],
            $resposta['evento_cstat'],
            $resposta['evento_xmotivo'],
        );

        $this->store->veredito($manifestation, $veredito->outcome, $veredito->cStat, $veredito->xMotivo);

        return true;
    }
}
