<?php

namespace App\Erp\Fiscal;

use App\Erp\Fiscal\Contracts\AttemptBoundErpFiscalProvider;
use App\Erp\Fiscal\Contracts\ErpFiscalProvider;
use App\Erp\Fiscal\Contracts\ErpFiscalSubmittedRequestProvider;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\Fiscal\ErpFiscalAttempt;
use App\Models\Erp\Fiscal\ErpFiscalCertificateRevision;
use App\Models\Erp\Fiscal\ErpFiscalConfigurationRevision;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Models\Erp\Fiscal\ErpFiscalInvalidation;
use DOMDocument;
use DOMElement;
use DOMXPath;
use NFePHP\Common\Certificate;
use RuntimeException;
use Throwable;

final class NfePhpErpFiscalProvider implements AttemptBoundErpFiscalProvider, ErpFiscalProvider, ErpFiscalSubmittedRequestProvider
{
    private ?ErpFiscalAttempt $attempt = null;

    /** @var array<string, mixed> */
    private array $source = [];

    private ?ErpEstablishment $issuer = null;

    private ?ErpFiscalConfigurationRevision $configuration = null;

    private ?ErpFiscalCertificateRevision $certificateRevision = null;

    /** @var array{certificate: string, password: string}|null */
    private ?array $certificateMaterial = null;

    private ?string $signedXml = null;

    private ?string $submittedXml = null;

    public function __construct(
        private readonly NfePhpToolsFactory $toolsFactory,
        private readonly NfePhpSoapTransportFactory $transportFactory,
        private readonly ErpFiscalAttemptAuthorization $authorization,
        private readonly ErpFiscalCertificateRevisionService $certificates,
    ) {}

    public function forAttempt(ErpFiscalAttempt $attempt, string $signedXml): ErpFiscalProvider
    {
        $issuer = $attempt->establishment()->first();
        $configuration = $attempt->configurationRevision()->first();
        $certificate = $attempt->certificateRevision()->first();
        $source = $this->authorization->sourceSnapshot($attempt)['request']['source'] ?? null;
        if (! $issuer instanceof ErpEstablishment || ! $configuration instanceof ErpFiscalConfigurationRevision
            || ! $certificate instanceof ErpFiscalCertificateRevision || ! is_array($source)
            || $attempt->status !== 'submitting' || ! in_array($attempt->action, ['document:transmit', 'document:consult', 'document:reconcile', 'event:submit', 'invalidation:submit'], true)
            || $attempt->environment !== $configuration->environment
            || (int) $attempt->erp_company_id !== (int) $issuer->erp_company_id
            || (int) $attempt->erp_establishment_id !== (int) $issuer->id
            || (int) $configuration->configurationOwner?->erp_company_id !== (int) $attempt->erp_company_id
            || (int) $configuration->configurationOwner?->erp_establishment_id !== (int) $issuer->id
            || (int) $certificate->certificate?->erp_company_id !== (int) $attempt->erp_company_id
            || (int) $certificate->certificate?->erp_establishment_id !== (int) $issuer->id
            || $certificate->cnpj !== $issuer->cnpj
            || ($source['issuer']['cnpj'] ?? null) !== $issuer->cnpj) {
            throw new RuntimeException('Revisões fiscais da tentativa não correspondem ao emissor fixado.');
        }
        $configurationJson = json_encode($this->canonicalValue($configuration->configuration), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! hash_equals((string) $configuration->configuration_sha256, hash('sha256', $configurationJson))) {
            throw new RuntimeException('Configuração fiscal diverge da revisão fixada.');
        }
        if (str_starts_with($attempt->action, 'document:')) {
            $document = $attempt->document()->first();
            $draft = $document?->attempts()->where('action', 'document:draft')->first();
            if (! $document || $draft && ((int) $draft->erp_fiscal_configuration_revision_id !== (int) $configuration->id
                || (int) $draft->erp_fiscal_certificate_revision_id !== (int) $certificate->id)) {
                throw new RuntimeException('Revisões fiscais divergem da intenção original.');
            }
            app(ErpFiscalCorrelationValidator::class)->assertDocumentMatches($signedXml, [
                'model' => $attempt->model, 'environment' => $attempt->environment, 'access_key' => $document->access_key,
                'issuer_cnpj' => $issuer->cnpj, 'series' => $attempt->series, 'number' => $attempt->number,
            ]);
        }
        $material = $this->certificates->material($certificate, $issuer);
        $pkcs12 = [];
        if (! openssl_pkcs12_read($material['certificate'], $pkcs12, $material['password'])
            || ! is_string($pkcs12['cert'] ?? null) || ! is_string($pkcs12['pkey'] ?? null)) {
            throw new RuntimeException('Certificado fiscal fixado indisponível.');
        }
        if ($attempt->action === 'document:transmit' && $signedXml === '') {
            throw new RuntimeException('XML assinado da tentativa ausente.');
        }

        if (str_starts_with($attempt->action, 'document:')) {
            $builder = app(match ($attempt->model) {
                '55' => Nfe55DocumentBuilder::class, '65' => Nfce65DocumentBuilder::class,
                '57' => Cte57DocumentBuilder::class, '58' => Mdfe58DocumentBuilder::class,
            });
            $intentSource = $source;
            if ($attempt->model === '65' && ($source['nfce_qr']['mode'] ?? null) === 'offline') {
                $intentSource = app(ErpFiscalEventRegistry::class)->signOfflineQr($source, $pkcs12['pkey']);
            }
            $unsignedIntent = $builder->build($intentSource);
        } else {
            $registry = app(ErpFiscalEventRegistry::class);
            $unsignedIntent = $attempt->action === 'event:submit' ? $registry->buildEvent($source) : $registry->buildInvalidation($source);
        }
        $expectedSigned = app(ErpFiscalXmlSigner::class)->sign($unsignedIntent, $pkcs12['pkey'], $pkcs12['cert']);
        if (! hash_equals((string) $this->loadXml($expectedSigned)->C14N(), (string) $this->loadXml($signedXml)->C14N())) {
            throw new RuntimeException('XML assinado diverge da intenção fiscal imutável.');
        }

        $bound = clone $this;
        $bound->attempt = $attempt;
        $bound->source = $source;
        $bound->issuer = $issuer;
        $bound->configuration = $configuration;
        $bound->certificateRevision = $certificate;
        $bound->certificateMaterial = $material;
        $bound->signedXml = $signedXml;

        return $bound;
    }

    public function transmit(ErpFiscalDocument $document, array $payload): ErpFiscalProviderResult
    {
        if (! $this->isBoundTo('document:transmit', $document) || (int) $this->attempt?->dispatch_attempts > 1
            || ! is_string($payload['signed_xml'] ?? null) || ! hash_equals((string) $this->signedXml, $payload['signed_xml'])
            || (int) ($payload['configuration_revision_id'] ?? 0) !== (int) $this->attempt?->erp_fiscal_configuration_revision_id
            || (int) ($payload['certificate_revision_id'] ?? 0) !== (int) $this->attempt?->erp_fiscal_certificate_revision_id) {
            return ErpFiscalProviderResult::indeterminate($this->attempt?->provider_receipt, 'Operação fiscal não corresponde à tentativa fixada.');
        }

        try {
            $tools = $this->tools();
            $response = match ($document->model) {
                '55', '65' => $tools->sefazEnviaLote([$this->signedXml], $this->batchId(), 1),
                '57' => $tools->sefazEnviaCTe($this->signedXml),
                '58' => $tools->sefazEnviaLote([$this->signedXml], $this->batchId(), 1),
            };
            $this->submittedXml = $this->guard($tools)->submittedRequestXml();

            return $this->documentOutcome((string) $response, $document, false);
        } catch (Throwable) {
            return ErpFiscalProviderResult::indeterminate($this->attempt?->provider_receipt, 'Retorno SEFAZ indeterminado.');
        }
    }

    public function consult(ErpFiscalDocument $document): ErpFiscalProviderResult
    {
        if (! $this->isBoundTo(['document:consult', 'document:reconcile', 'document:transmit'], $document)) {
            return ErpFiscalProviderResult::indeterminate($this->attempt?->provider_receipt, 'Consulta fiscal fora da tentativa fixada.');
        }

        try {
            $tools = $this->tools();
            $receipt = $this->attempt?->provider_receipt;
            $response = is_string($receipt) && preg_match('/^\d{15}$/', $receipt)
                && in_array($document->model, ['55', '65', '58'], true)
                ? $tools->sefazConsultaRecibo($receipt, $document->environment === 'production' ? 1 : 2)
                : $tools->sefazConsultaChave((string) $document->access_key, $document->environment === 'production' ? 1 : 2);

            return $this->documentOutcome((string) $response, $document, true);
        } catch (Throwable) {
            return ErpFiscalProviderResult::indeterminate($this->attempt?->provider_receipt, 'Consulta SEFAZ indeterminada.');
        }
    }

    public function submitEvent(ErpFiscalDocument $document, ErpFiscalEvent $event): ErpFiscalProviderResult
    {
        if (! $this->isBoundTo('event:submit', $document) || (int) $this->attempt?->dispatch_attempts > 1 || ($event->payload_snapshot['signed_xml'] ?? null) !== $this->signedXml) {
            return ErpFiscalProviderResult::indeterminate(message: 'Evento fora da tentativa fiscal fixada.');
        }

        try {
            $tools = $this->tools();
            $response = $this->submitNativeEvent($tools, $document, $event);
            $this->submittedXml = $this->guard($tools)->submittedRequestXml();
            if (! is_string($this->submittedXml) || $this->submittedXml === '') {
                return ErpFiscalProviderResult::indeterminate(message: 'Solicitação nativa do evento não comprovada.');
            }
            $outcome = $this->eventOutcome((string) $response, $document->model, $document->environment, (string) $document->access_key, (string) ($this->source['event_type'] ?? ''), (int) ($this->source['sequence'] ?? 0));
            if ($outcome->outcome !== 'event_accepted') {
                return $outcome;
            }
            $processed = $this->assembleOperationResult((string) $this->submittedXml, (string) $response, $document->model, true);

            return new ErpFiscalProviderResult('event_accepted', $outcome->protocol, xml: $processed);
        } catch (Throwable) {
            return ErpFiscalProviderResult::indeterminate(message: 'Resultado SEFAZ do evento indeterminado.');
        }
    }

    public function submitInvalidation(ErpFiscalInvalidation $invalidation): ErpFiscalProviderResult
    {
        if (! $this->isBoundTo('invalidation:submit', null) || (int) $this->attempt?->dispatch_attempts > 1 || (int) $invalidation->erp_company_id !== (int) $this->attempt?->erp_company_id
            || (int) $invalidation->erp_establishment_id !== (int) $this->attempt?->erp_establishment_id
            || (int) $invalidation->id !== (int) $this->attempt?->erp_fiscal_invalidation_id
            || ! in_array($invalidation->model, ['55', '65'], true) || $invalidation->model !== $this->attempt?->model || $invalidation->environment !== $this->attempt?->environment) {
            return ErpFiscalProviderResult::indeterminate(message: 'Inutilização fora da tentativa fiscal fixada.');
        }

        try {
            $tools = $this->tools();
            $year = (string) ($this->source['year'] ?? '');
            if (! preg_match('/^\d{4}$/', $year)) {
                return ErpFiscalProviderResult::rejected('Dados da inutilização fixada inválidos.');
            }
            if ((int) $invalidation->series !== (int) ($this->source['series'] ?? -1)
                || (int) $invalidation->number_from !== (int) ($this->source['number_from'] ?? -1)
                || (int) $invalidation->number_to !== (int) ($this->source['number_to'] ?? -1)
                || ! hash_equals((string) $invalidation->justification, (string) ($this->source['justification'] ?? ''))) {
                return ErpFiscalProviderResult::indeterminate(message: 'Inutilização diverge da intenção fixada.');
            }
            $response = $tools->sefazInutiliza((int) $invalidation->series, (int) $invalidation->number_from, (int) $invalidation->number_to, (string) $invalidation->justification, $invalidation->environment === 'production' ? 1 : 2, substr($year, -2));
            $this->submittedXml = $this->guard($tools)->submittedRequestXml();
            $outcome = $this->eventOutcome((string) $response, $invalidation->model, $invalidation->environment, '', 'invalidation', 0);

            return $outcome;
        } catch (Throwable) {
            return ErpFiscalProviderResult::indeterminate(message: 'Resultado SEFAZ da inutilização indeterminado.');
        }
    }

    public function submittedRequestXml(): ?string
    {
        return $this->submittedXml;
    }

    private function tools(): object
    {
        if (! $this->attempt || ! $this->issuer || ! $this->configuration || ! $this->certificateMaterial) {
            throw new RuntimeException('Contexto de tentativa NFePHP ausente.');
        }
        $document = $this->attempt->document()->first();
        $key = (string) ($document?->access_key ?? '');
        $pkcs12 = [];
        if (! openssl_pkcs12_read($this->certificateMaterial['certificate'], $pkcs12, $this->certificateMaterial['password']) || ! is_string($pkcs12['cert'] ?? null)) {
            throw new RuntimeException('Certificado fixado indisponível para transporte.');
        }
        $guard = new NfePhpRequestGuardSoap($this->transportFactory->make(), $this->attempt, $this->source, (string) $this->signedXml, app(ErpFiscalXmlSigner::class), $pkcs12['cert']);
        $this->submittedXml = null;

        return $this->toolsFactory->make($this->attempt->model, $this->attempt->environment, $key, $this->issuer, $this->configuration, $this->certificateMaterial['certificate'], $this->certificateMaterial['password'], $guard);
    }

    private function guard(object $tools): NfePhpRequestGuardSoap
    {
        $soap = $tools->soap ?? null;
        if (! $soap instanceof NfePhpRequestGuardSoap) {
            throw new RuntimeException('Proteção de solicitação NFePHP ausente.');
        }

        return $soap;
    }

    private function submitNativeEvent(object $tools, ErpFiscalDocument $document, ErpFiscalEvent $event): string
    {
        $source = $this->source;
        $type = (string) ($source['event_type'] ?? '');
        $sequence = (int) ($source['sequence'] ?? 0);
        $key = (string) $document->access_key;
        $protocol = (string) ($source['protocol'] ?? '');
        $occurredAt = new \DateTimeImmutable((string) ($source['occurred_at'] ?? ''));

        if (in_array($document->model, ['55', '65'], true)) {
            $std = (object) ['evento' => [(object) ['chave' => $key, 'tpEvento' => (int) app(ErpFiscalEventRegistry::class)->eventCode($document->model, (string) $source['document_kind'], $type), 'nSeqEvento' => $sequence, 'tagAdic' => $this->nfeEventDetail()]]];

            return (string) $tools->sefazEventoLote($this->issuerUf(), $std, $occurredAt, $this->batchId());
        }
        if ($document->model === '57') {
            if (! $tools instanceof NfePhpCteSignedEventTools) {
                throw new RuntimeException('Ferramenta de evento CT-e sem envio assinado nativo.');
            }

            return $tools->submitSignedEventXml((string) $this->signedXml, $this->issuerUf());
        }
        if ($document->model === '58') {
            if (! $tools instanceof NfePhpMdfeSignedEventTools) {
                throw new RuntimeException('Ferramenta de evento MDF-e sem envio assinado nativo.');
            }

            return $tools->submitSignedEventXml((string) $this->signedXml, $this->issuerUf());
        }
        throw new RuntimeException('Modelo de evento indisponível.');
    }

    private function nfeEventDetail(): string
    {
        $signed = $this->loadXml((string) $this->signedXml);
        $det = $this->firstElement($signed, 'detEvento');
        if (! $det instanceof DOMElement) {
            throw new RuntimeException('Detalhe assinado do evento ausente.');
        }
        $detail = '';
        foreach ($det->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName !== 'descEvento') {
                $detail .= $child->C14N();
            }
        }

        return $detail;
    }

    private function documentOutcome(string $response, ErpFiscalDocument $document, bool $consult): ErpFiscalProviderResult
    {
        $dom = $this->loadXml($response);
        [$namespace, $responseRootNames, $protocolName] = match ($document->model) {
            '55', '65' => ['http://www.portalfiscal.inf.br/nfe', $consult ? ['retConsSitNFe', 'retConsReciNFe'] : ['retEnviNFe'], 'protNFe'],
            '57' => ['http://www.portalfiscal.inf.br/cte', $consult ? ['retConsSitCTe'] : ['retCTe'], 'protCTe'],
            '58' => ['http://www.portalfiscal.inf.br/mdfe', $consult ? ['retConsSitMDFe', 'retConsReciMDFe'] : ['retMDFe'], 'protMDFe'],
        };
        $responseNames = ['retEnviNFe', 'retConsSitNFe', 'retConsReciNFe', 'retCTe', 'retConsSitCTe', 'retMDFe', 'retEnviMDFe', 'retConsSitMDFe', 'retConsReciMDFe'];
        $responseRoots = $this->query($dom, '//*['.implode(' or ', array_map(static fn (string $name): string => 'local-name()="'.$name.'"', $responseNames)).']');
        if ($responseRoots->length !== 1) {
            return ErpFiscalProviderResult::indeterminate($this->attempt?->provider_receipt, 'Resposta SEFAZ exige um único resultado fiscal.');
        }
        $responseRoot = $responseRoots->item(0);
        $expectedReceipt = $consult && in_array($document->model, ['55', '65', '58'], true)
            && is_string($this->attempt?->provider_receipt) && preg_match('/^\d{15}$/', $this->attempt->provider_receipt)
            ? $this->attempt->provider_receipt : null;
        if ($consult) {
            $responseRootNames = [match ($document->model) {
                '55', '65' => $expectedReceipt === null ? 'retConsSitNFe' : 'retConsReciNFe',
                '57' => 'retConsSitCTe',
                '58' => $expectedReceipt === null ? 'retConsSitMDFe' : 'retConsReciMDFe',
            }];
        }
        if (! $responseRoot instanceof DOMElement || ! in_array($responseRoot->localName, $responseRootNames, true)
            || $this->childText($responseRoot, 'tpAmb') !== ($document->environment === 'production' ? '1' : '2')
            || $expectedReceipt !== null && $this->childText($responseRoot, 'nRec') !== $expectedReceipt) {
            return ErpFiscalProviderResult::indeterminate($this->attempt?->provider_receipt, 'Resposta SEFAZ diverge da consulta fixada.');
        }
        $protocolNodes = $this->query($dom, '//*[local-name()="protNFe" or local-name()="protCTe" or local-name()="protMDFe"]');
        if (! $responseRoot instanceof DOMElement || $responseRoot->namespaceURI !== $namespace || $protocolNodes->length > 1
            || $protocolNodes->length === 1 && (! $protocolNodes->item(0) instanceof DOMElement || $protocolNodes->item(0)->localName !== $protocolName || $protocolNodes->item(0)->namespaceURI !== $namespace || $protocolNodes->item(0)->parentNode !== $responseRoot)) {
            return ErpFiscalProviderResult::indeterminate($this->attempt?->provider_receipt, 'Resposta SEFAZ fora do modelo ou namespace da tentativa.');
        }
        $protocolInfoNodes = $this->query($dom, '//*[local-name()="infProt"]');
        if ($protocolNodes->length === 1 && ($protocolInfoNodes->length !== 1 || ! $protocolInfoNodes->item(0) instanceof DOMElement)
            || $protocolNodes->length === 0 && $protocolInfoNodes->length !== 0) {
            return ErpFiscalProviderResult::indeterminate($this->attempt?->provider_receipt, 'Resposta SEFAZ sem protocolo único da tentativa.');
        }
        $rootStatus = $this->childText($responseRoot, 'cStat');
        $receipt = $this->childText($responseRoot, 'nRec') ?? $this->attempt?->provider_receipt;
        $protocol = $protocolNodes->length === 1 ? $protocolNodes->item(0) : null;
        if ($protocol instanceof DOMElement) {
            $info = $this->child($protocol, 'infProt');
            if (! $info instanceof DOMElement) {
                return ErpFiscalProviderResult::indeterminate(is_string($receipt) ? $receipt : null, 'Protocolo SEFAZ sem identidade contida.');
            }
            $number = $this->childText($info, 'nProt');
            $protocolDigest = $this->childText($info, 'digVal');
            $signedDigest = $this->value($this->loadXml((string) $this->signedXml), 'DigestValue');
            if ($this->childText($info, $this->keyName($document->model)) !== $document->access_key
                || $this->childText($info, 'tpAmb') !== ($document->environment === 'production' ? '1' : '2')
                || $number !== null && ! preg_match('/^\d{15}$/', $number)
                || $protocolDigest !== null && (! is_string($signedDigest) || ! hash_equals($signedDigest, $protocolDigest))) {
                return ErpFiscalProviderResult::indeterminate(is_string($receipt) ? $receipt : null, 'Protocolo SEFAZ sem correlação integral.');
            }
            $status = $this->childText($info, 'cStat');
            $processedLot = in_array($responseRoot->localName, ['retEnviNFe', 'retConsReciNFe', 'retConsReciMDFe'], true);
            if ($processedLot ? $rootStatus !== '104' : $rootStatus !== $status) {
                return ErpFiscalProviderResult::indeterminate(is_string($receipt) ? $receipt : null, 'Status SEFAZ externo e protocolo não formam resultado coerente.');
            }
            $authorizedStatuses = in_array($document->model, ['55', '65'], true) ? ['100', '150'] : ['100'];
            if (in_array($status, $authorizedStatuses, true) && is_string($number)) {
                $xml = $this->assembleProcessed((string) $this->signedXml, $protocol, $document->model);

                return ErpFiscalProviderResult::authorized($number, $xml, is_string($receipt) ? $receipt : null);
            }
            if ($this->isDefinitiveRejection($status, $document->model, false)) {
                return ErpFiscalProviderResult::rejected('A SEFAZ recusou o documento fiscal.', ['status_code' => $status]);
            }

            return ErpFiscalProviderResult::indeterminate(is_string($receipt) ? $receipt : null, 'Protocolo SEFAZ sem resultado conclusivo.');
        }
        if (in_array($rootStatus, ['103', '104', '105'], true) || $consult && $rootStatus === '217') {
            return ErpFiscalProviderResult::indeterminate(is_string($receipt) ? $receipt : null, 'Documento ainda não confirmado pela SEFAZ.');
        }
        $returnedUf = $this->childText($responseRoot, 'cUF');
        if ($returnedUf !== null && $returnedUf !== substr((string) $document->access_key, 0, 2)) {
            return ErpFiscalProviderResult::indeterminate(is_string($receipt) ? $receipt : null, 'Retorno SEFAZ de outra unidade autorizadora.');
        }
        if ($this->isDefinitiveRejection($rootStatus, $document->model, false)) {
            return ErpFiscalProviderResult::rejected('A SEFAZ recusou a solicitação fiscal.', ['status_code' => $rootStatus]);
        }

        return ErpFiscalProviderResult::indeterminate(is_string($receipt) ? $receipt : null, 'Resposta SEFAZ sem resultado conclusivo.');
    }

    private function eventOutcome(string $response, string $model, string $environment, string $key, string $eventType, int $sequence): ErpFiscalProviderResult
    {
        $dom = $this->loadXml($response);
        [, $info] = $this->operationResponseIdentity($dom, $model, $eventType !== 'invalidation');
        if ($this->childText($info, 'tpAmb') !== ($environment === 'production' ? '1' : '2')) {
            return ErpFiscalProviderResult::indeterminate(message: 'Ambiente da operação fiscal não corresponde à tentativa.');
        }
        $identity = $eventType === 'invalidation'
            ? ['CNPJ' => $this->source['issuer']['cnpj'] ?? null, 'mod' => $model, 'serie' => (string) ($this->source['series'] ?? ''),
                'ano' => substr((string) ($this->source['year'] ?? ''), -2), 'nNFIni' => (string) ($this->source['number_from'] ?? ''), 'nNFFin' => (string) ($this->source['number_to'] ?? '')]
            : [$this->keyName($model) => $key, 'tpEvento' => app(ErpFiscalEventRegistry::class)->eventCode($model, (string) ($this->source['document_kind'] ?? ''), $eventType), 'nSeqEvento' => (string) $sequence];
        foreach ($identity as $field => $expected) {
            if ($this->childText($info, $field) !== $expected) {
                return ErpFiscalProviderResult::indeterminate(message: 'Identidade da operação fiscal não corresponde à tentativa.');
            }
        }
        $status = $this->childText($info, 'cStat');
        $protocol = $this->childText($info, 'nProt');
        if ($protocol !== null && ! preg_match('/^\d{15}$/', $protocol)) {
            return ErpFiscalProviderResult::indeterminate(message: 'Protocolo da operação fiscal inválido.');
        }
        if ($this->isDefinitiveRejection($status, $model, true)) {
            return ErpFiscalProviderResult::eventRejected('A SEFAZ recusou a operação fiscal.', ['status_code' => $status]);
        }
        $expectedStatus = $eventType === 'invalidation' ? '102' : '135';
        if ($status !== $expectedStatus || ! is_string($protocol)) {
            return ErpFiscalProviderResult::indeterminate(message: 'Resposta da operação fiscal sem resultado conclusivo.');
        }

        $processed = $this->assembleOperationResult((string) $this->submittedXml, $response, $model, $eventType !== 'invalidation');

        return new ErpFiscalProviderResult('event_accepted', $protocol, xml: $processed);
    }

    /** @return array{DOMElement, DOMElement} */
    private function operationResponseIdentity(DOMDocument $dom, string $model, bool $event): array
    {
        $namespace = match ($model) {
            '55', '65' => 'http://www.portalfiscal.inf.br/nfe', '57' => 'http://www.portalfiscal.inf.br/cte', '58' => 'http://www.portalfiscal.inf.br/mdfe',
        };
        $returnName = $event ? match ($model) {
            '55', '65' => 'retEvento', '57' => 'retEventoCTe', '58' => 'retEventoMDFe',
        } : 'retInutNFe';
        $batched = $event && in_array($model, ['55', '65'], true);
        $rootName = $batched ? 'retEnvEvento' : $returnName;
        $resultNames = ['retEnvEvento', 'retEvento', 'retEventoCTe', 'retEventoMDFe', 'retInutNFe',
            'retEnviNFe', 'retConsSitNFe', 'retConsReciNFe', 'retCTe', 'retConsSitCTe', 'retMDFe', 'retEnviMDFe', 'retConsSitMDFe', 'retConsReciMDFe'];
        $results = $this->query($dom, '//*['.implode(' or ', array_map(static fn (string $name): string => 'local-name()="'.$name.'"', $resultNames)).']');
        $roots = $this->query($dom, '//*[local-name()="'.$rootName.'"]');
        $returns = $this->query($dom, '//*[local-name()="'.$returnName.'"]');
        $identities = $this->query($dom, '//*[local-name()="infEvento" or local-name()="infInut" or local-name()="infProt"]');
        if ($results->length !== ($batched ? 2 : 1) || $roots->length !== 1 || $returns->length !== 1 || $identities->length !== 1) {
            throw new RuntimeException('Resposta da operação fiscal exige resultado e identidade únicos.');
        }
        $root = $roots->item(0);
        $returned = $returns->item(0);
        $info = $identities->item(0);
        if (! $root instanceof DOMElement || ! $returned instanceof DOMElement || ! $info instanceof DOMElement
            || $root->namespaceURI !== $namespace || $returned->namespaceURI !== $namespace || $info->namespaceURI !== $namespace
            || $info->localName !== ($event ? 'infEvento' : 'infInut') || $info->parentNode !== $returned
            || $batched && $returned->parentNode !== $root) {
            throw new RuntimeException('Resposta da operação fiscal fora do modelo, namespace ou contenção da tentativa.');
        }
        if ($batched) {
            if ($this->childText($root, 'cStat') !== '128'
                || $this->childText($root, 'tpAmb') !== (($this->source['environment'] ?? null) === 'production' ? '1' : '2')) {
                throw new RuntimeException('Lote de evento fiscal sem processamento correlacionado.');
            }
            foreach ($root->childNodes as $field) {
                if ($field instanceof DOMElement && $this->child($root, $field->localName) !== $field) {
                    throw new RuntimeException('Resultado de lote de evento fiscal ambíguo.');
                }
            }
        }
        foreach ($info->childNodes as $field) {
            if ($field instanceof DOMElement && ($field->childElementCount !== 0 || $this->child($info, $field->localName) !== $field)) {
                throw new RuntimeException('Identidade da operação fiscal ambígua.');
            }
        }

        return [$returned, $info];
    }

    private function isDefinitiveRejection(?string $status, string $model, bool $operation): bool
    {
        // These are explicit validation refusals; duplicate/unknown outcomes require reconciliation.
        return in_array($status, ['215', '225'], true)
            || ! $operation && in_array($model, ['55', '65'], true) && $status === '610';
    }

    private function assembleOperationResult(string $sentXml, string $response, string $model, bool $event): string
    {
        $dom = $this->loadXml($response);
        [$returned] = $this->operationResponseIdentity($dom, $model, $event);
        $sent = $this->loadXml($sentXml);
        $requestNode = $event ? $this->firstElement($sent, match ($model) {
            '55', '65' => 'evento', '57' => 'eventoCTe', '58' => 'eventoMDFe'
        }) : $sent->documentElement;
        if (! $requestNode instanceof DOMElement) {
            throw new RuntimeException('Evidência da operação fiscal incompleta.');
        }
        $rootName = $event ? match ($model) {
            '55', '65' => 'procEventoNFe', '57' => 'procEventoCTe', '58' => 'procEventoMDFe'
        } : 'ProcInutNFe';
        $version = $event ? ($model === '58' ? '3.00' : ($model === '57' ? '4.00' : '1.00')) : '4.00';
        $namespace = (string) $requestNode->namespaceURI;

        return '<?xml version="1.0" encoding="UTF-8"?>'.'<'.$rootName.' xmlns="'.$namespace.'" versao="'.$version.'">'.$requestNode->C14N().$returned->C14N().'</'.$rootName.'>';
    }

    private function assembleProcessed(string $signedXml, DOMElement $protocol, string $model): string
    {
        $dom = $this->loadXml($signedXml);
        $rootName = match ($model) {
            '55', '65' => 'nfeProc', '57' => 'cteProc', '58' => 'mdfeProc'
        };
        $original = $dom->documentElement;
        if (! $original instanceof DOMElement) {
            throw new RuntimeException('Documento assinado ausente.');
        }
        $version = $model === '58' ? '3.00' : '4.00';

        // Reparenting through DOM rewrites the signature namespace and invalidates SignedInfo.
        return '<?xml version="1.0" encoding="UTF-8"?><'.$rootName.' xmlns="'.$protocol->namespaceURI.'" versao="'.$version.'">'.$original->C14N().$protocol->C14N().'</'.$rootName.'>';
    }

    private function loadXml(string $xml): DOMDocument
    {
        if ($xml === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new RuntimeException('Resposta XML SEFAZ inválida.');
        }
        $dom = new DOMDocument;
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if (! $dom->loadXML($xml, LIBXML_NONET) || ! $dom->documentElement instanceof DOMElement) {
                throw new RuntimeException('Resposta XML SEFAZ inválida.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        return $dom;
    }

    private function query(DOMDocument $dom, string $expression): \DOMNodeList
    {
        $nodes = (new DOMXPath($dom))->query($expression);
        if ($nodes === false) {
            throw new RuntimeException('Consulta XML SEFAZ inválida.');
        }

        return $nodes;
    }

    private function firstElement(DOMDocument $dom, string $name): ?DOMElement
    {
        $node = $this->query($dom, '//*[local-name()="'.$name.'"]')->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function value(DOMDocument $dom, string $name): ?string
    {
        $node = $this->firstElement($dom, $name);

        return $node instanceof DOMElement ? trim($node->textContent) : null;
    }

    private function child(DOMElement $parent, string $name): ?DOMElement
    {
        $found = null;
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                if ($found !== null || $node->namespaceURI !== $parent->namespaceURI) {
                    throw new RuntimeException('Identidade de resposta SEFAZ ambígua.');
                }
                $found = $node;
            }
        }

        return $found;
    }

    private function childText(DOMElement $parent, string $name): ?string
    {
        $node = $this->child($parent, $name);

        return $node instanceof DOMElement ? trim($node->textContent) : null;
    }

    private function keyName(string $model): string
    {
        return match ($model) {
            '55', '65' => 'chNFe', '57' => 'chCTe', '58' => 'chMDFe'
        };
    }

    private function isBoundTo(string|array $actions, ?ErpFiscalDocument $document): bool
    {
        $allowed = is_array($actions) ? $actions : [$actions];
        if (! $this->attempt || ! in_array($this->attempt->action, $allowed, true)) {
            return false;
        }
        if ($document !== null && ((int) $document->erp_company_id !== (int) $this->attempt->erp_company_id
            || (int) $document->erp_establishment_id !== (int) $this->attempt->erp_establishment_id
            || $document->model !== $this->attempt->model || $document->environment !== $this->attempt->environment
            || (int) $document->id !== (int) $this->attempt->erp_fiscal_document_id)) {
            return false;
        }

        return true;
    }

    /** @param array<mixed> $value @return array<mixed> */
    private function canonicalValue(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalValue($item);
            }
        }

        return $value;
    }

    private function batchId(): string
    {
        return str_pad((string) hexdec(substr(hash('sha256', (string) $this->attempt?->idempotency_key), 0, 12)), 15, '0', STR_PAD_LEFT);
    }

    private function issuerUf(): string
    {
        return (string) ($this->configuration?->configuration['issuer']['uf'] ?? '');
    }
}
