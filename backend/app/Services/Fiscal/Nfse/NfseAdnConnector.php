<?php

namespace App\Services\Fiscal\Nfse;

use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Models\Client;
use App\Services\Fiscal\Capture\FiscalLookupBudget;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use App\Services\Fiscal\Exceptions\FiscalLookupDeferred;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use RuntimeException;

/**
 * Conector da API REST de contribuintes da ADN NFS-e.
 *
 * É o terceiro conector do módulo, e o primeiro REST: fala JSON com
 * `https://adn.nfse.gov.br/contribuintes` (produção restrita em
 * `FISCAL_ENVIRONMENT=homologacao`), autentica pelo A1 do cliente no mTLS e
 * entrega documento pronto ao mesmo `FiscalDocumentWriter` dos dois irmãos
 * SOAP. Não escreve no banco.
 *
 * Quatro regras que este arquivo existe para sustentar:
 *
 * 1. **A posição nunca é incrementada.** O pedido vai com a posição que o
 *    consumidor já tem, e `lastNsu` no resultado é a maior NSU que a resposta
 *    devolveu — a ADN não publica `UltimoNSU` no corpo, então a posição do
 *    lote é a posição que ele próprio entregou (ver design, decisão 6).
 * 2. **Consulta pontual é a cara cara, e ela tem teto.** As duas consultas de
 *    uma posição só reservam uma vaga no limite horário do CNPJ **antes** de
 *    qualquer byte na rede, e `pull` não gasta dessa cota — a mesma trava que
 *    os conectores SOAP usam, porque o fisco conta por CNPJ e não por serviço.
 * 3. **A consulta por chave segue o manual oficial.** O caminho
 *    `/NFSe/{ChaveAcesso}/Eventos` é o publicado no manual dos contribuintes
 *    ADN (gov.br/nfse, v1.0 de 12/02/2026) — não era hipótese de código: era
 *    `/DFe/chave/{chave}` de um rascunho, e o manual fechou a dúvida.
 * 4. **O status HTTP engana.** O transporte entrega a resposta inteira e o
 *    `NfseAdnPullReader` classifica o corpo antes de confiar no número — um
 *    `404` com corpo de negócio é desfecho classificado, não queda de rede.
 */
final class NfseAdnConnector implements FiscalConnector
{
    /**
     * O documento que este conector traz: a chave de acesso da NFS-e nacional
     * tem 50 posições e guarda de família é o que impede que uma chave de
     * outro leiaute entre sob a etiqueta errada.
     */
    private const MODEL = FiscalModel::Nfse;

    public function __construct(
        private NfseAdnTransport $transport,
        private NfseAdnPullReader $reader,
        private FiscalLookupBudget $lookupBudget,
    ) {}

    public function source(): FiscalSource
    {
        return FiscalSource::NfseAdn;
    }

    /**
     * `$limit` é informativo: o teto do lote da ADN é o mesmo 50 de
     * `fiscal.batch_limit`, e quem chama já leu a configuração.
     */
    public function pull(Client $client, int $fromNsu, int $limit): PullResult
    {
        // O veredito do certificado vem antes de qualquer chamada: um cliente
        // sem A1 não é conhecido pela ADN, que identifica o contribuinte pelo
        // certificado no mTLS.
        $this->transport->requireCertificate($client);

        return $this->reader->read(
            $this->transport->get($client, $this->urlOf($fromNsu)),
            $fromNsu,
        );
    }

    /**
     * Recupera o documento de uma posição específica, para fechar lacuna de
     * captura. A reserva do teto vem antes da chamada, e a que não consegue
     * vaga é adiada com `FiscalLookupDeferred` — sem consulta, sem gasto.
     */
    public function fetchByNsu(Client $client, int $nsu): ?PulledDocument
    {
        return $this->pointLookup($client, $this->urlOf($nsu));
    }

    /**
     * Recupera o documento pela chave de acesso, para fechar lacuna de
     * posição. O caminho é o do manual oficial dos contribuintes ADN (v1.0,
     * 12/02/2026, gov.br/nfse): `GET /NFSe/{ChaveAcesso}/Eventos` retorna os
     * DF-e do tipo Evento vinculados à chave — é a consulta por chave que o
     * serviço publica.
     */
    public function fetchByChave(Client $client, string $chave): ?PulledDocument
    {
        if (! FiscalXmlMetadata::isValidChave($chave)) {
            throw new RuntimeException("Chave de acesso inválida: {$chave}.");
        }

        return $this->pointLookup($client, $this->urlOfChave($chave));
    }

    /**
     * O que as duas consultas pontuais têm em comum, e que por isso mora em um
     * método só: a reserva do teto **antes** de qualquer chamada — a mesma
     * ordem de `NfeDistributionConnector::pointLookup()`, porque uma consulta
     * que não saiu não pode ser cobrada como consulta que saiu.
     */
    private function pointLookup(Client $client, string $url): ?PulledDocument
    {
        $this->transport->requireCertificate($client);

        if (! $this->lookupBudget->reserve($client)) {
            throw new FiscalLookupDeferred('Limite horário de consultas pontuais atingido.');
        }

        return $this->reader->readOne($this->transport->get($client, $url));
    }

    /**
     * A base da API contribuintes para o ambiente configurado. Ambiente
     * desconhecido cai em homologação — produção restrita, o lado que não
     * produz efeito legal: errar o `FISCAL_ENVIRONMENT` não pode consultar o
     * ambiente de produção.
     */
    public function baseUrl(): string
    {
        $environment = config('fiscal.environment') === 'producao' ? 'producao' : 'homologacao';
        $base = (string) config("fiscal.endpoints.nfse_adn.{$environment}", '');

        if ($base === '') {
            throw new RuntimeException("A base da ADN NFS-e não está configurada para o ambiente {$environment}.");
        }

        return rtrim($base, '/');
    }

    /**
     * A consulta do lote incremental: a posição pedida no caminho. O NSU vai
     * como número — o formato exato (zeros à esquerda, largura) é uma das
     * coisas que o probe confirma, e o ajuste é deste método para baixo.
     */
    public function urlOf(int $nsu): string
    {
        return $this->baseUrl().'/DFe/'.$nsu;
    }

    private function urlOfChave(string $chave): string
    {
        return $this->baseUrl().'/NFSe/'.$chave.'/Eventos';
    }
}
