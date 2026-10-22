<?php

namespace App\Erp\Fiscal;

use App\Models\Erp\Fiscal\ErpFiscalAttempt;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class ErpFiscalArtifactStore
{
    public function __construct(private readonly ErpFiscalAuxiliaryRenderer $auxiliaryRenderer) {}

    public function persistAuthorizedXml(ErpFiscalDocument $document, string $xml): array
    {
        $this->assertCompleteXml($xml);
        $expectedHash = hash('sha256', $xml);
        $expectedLength = strlen($xml);
        $path = sprintf('erp/fiscal/%d/%d/authorized-%s.xml', $document->erp_company_id, $document->id, $expectedHash);
        if ($document->artifact_sha256 === $expectedHash && is_string($document->storage_path) && $document->storage_path !== '') {
            $path = $document->storage_path;
        }
        if (Storage::disk('local')->exists($path)) {
            $existing = Storage::disk('local')->get($path);
            if (! is_string($existing) || strlen($existing) !== $expectedLength || ! hash_equals($expectedHash, hash('sha256', $existing))) {
                throw new RuntimeException('Artefato imutável já existente não corresponde aos bytes esperados.');
            }

            return ['storage_path' => $path, 'artifact_byte_length' => $expectedLength, 'artifact_sha256' => $expectedHash];
        }
        $path = substr($path, 0, -4).'-'.Str::uuid().'.xml';
        if (! Storage::disk('local')->put($path, $xml)) {
            throw new RuntimeException('Falha ao persistir XML autorizado.');
        }

        $bytes = Storage::disk('local')->get($path);
        if (! is_string($bytes) || strlen($bytes) !== $expectedLength || ! hash_equals($expectedHash, hash('sha256', $bytes))) {
            throw new RuntimeException('XML autorizado corrompido após gravação.');
        }

        return [
            'storage_path' => $path,
            'artifact_byte_length' => strlen($bytes),
            'artifact_sha256' => hash('sha256', $bytes),
        ];
    }

    public function persistLocalContingencyXml(ErpFiscalDocument $document, string $xml): array
    {
        $this->assertCompleteXml($xml);
        $expectedHash = hash('sha256', $xml);
        $expectedLength = strlen($xml);
        $path = sprintf('erp/fiscal/%d/%d/contingency-local-%s.xml', $document->erp_company_id, $document->id, $expectedHash);
        if ($document->artifact_sha256 === $expectedHash && is_string($document->storage_path) && $document->storage_path !== '') {
            $path = $document->storage_path;
        }
        if (Storage::disk('local')->exists($path)) {
            $existing = Storage::disk('local')->get($path);
            if (! is_string($existing) || strlen($existing) !== $expectedLength || ! hash_equals($expectedHash, hash('sha256', $existing))) {
                throw new RuntimeException('Artefato imutável já existente não corresponde aos bytes esperados.');
            }

            return ['storage_path' => $path, 'artifact_byte_length' => $expectedLength, 'artifact_sha256' => $expectedHash];
        }
        $path = substr($path, 0, -4).'-'.Str::uuid().'.xml';
        if (! Storage::disk('local')->put($path, $xml)) {
            throw new RuntimeException('Falha ao persistir XML de contingência.');
        }

        $bytes = Storage::disk('local')->get($path);
        if (! is_string($bytes) || strlen($bytes) !== $expectedLength || ! hash_equals($expectedHash, hash('sha256', $bytes))) {
            throw new RuntimeException('XML de contingência corrompido após gravação.');
        }

        return [
            'storage_path' => $path,
            'artifact_byte_length' => strlen($bytes),
            'artifact_sha256' => hash('sha256', $bytes),
        ];
    }

    public function readXml(ErpFiscalDocument $document): ?string
    {
        if ($document->storage_path === null || $document->storage_path === '') {
            return null;
        }
        if (! Storage::disk('local')->exists($document->storage_path)) {
            return null;
        }

        $bytes = Storage::disk('local')->get($document->storage_path);

        return is_string($bytes) ? $bytes : null;
    }

    public function verifyIntegrity(ErpFiscalDocument $document): bool
    {
        if (! is_string($document->artifact_sha256) || ! preg_match('/^[a-f0-9]{64}$/', $document->artifact_sha256) || (int) $document->artifact_byte_length <= 0) {
            return false;
        }
        try {
            $xml = $this->readXml($document);
        } catch (\Throwable) {
            return false;
        }
        if ($xml === null) {
            return false;
        }
        if ($document->artifact_byte_length !== null && strlen($xml) !== (int) $document->artifact_byte_length) {
            return false;
        }
        if ($document->artifact_sha256 !== null && hash('sha256', $xml) !== $document->artifact_sha256) {
            return false;
        }

        return true;
    }

    /** @return array{issuer_cnpj: string, protocol: string, attempt_id: int}|null */
    public function verifiedAuthorizationEvidence(ErpFiscalDocument $document): ?array
    {
        $attempt = $this->matchedFiscalAttempt($document, false);
        if ($attempt === null) {
            return null;
        }
        if (($attempt->safe_result['authorization_verified'] ?? false) !== true && ! $this->verifiedOriginalBytes($document, $attempt, false)) {
            return null;
        }

        return ['issuer_cnpj' => substr((string) $document->access_key, 6, 14), 'protocol' => $attempt->provider_protocol, 'attempt_id' => (int) $attempt->id];
    }

    public function verifiedAuthorizedXmlReady(ErpFiscalDocument $document): bool
    {
        $attempt = $this->matchedFiscalAttempt($document, false, true);

        return $attempt !== null && $this->verifiedOriginalBytes($document, $attempt, false);
    }

    public function verifiedFiscalArtifactReady(ErpFiscalDocument $document): bool
    {
        if (! in_array($document->status, ['authorized', 'contingency_local'], true)) {
            return false;
        }
        $local = $document->status === 'contingency_local';
        $attempt = $this->matchedFiscalAttempt($document, $local, true);

        return $attempt !== null && $this->verifiedOriginalBytes($document, $attempt, $local);
    }

    private function matchedFiscalAttempt(ErpFiscalDocument $document, bool $local, bool $requireBytes = false): ?ErpFiscalAttempt
    {
        if (! $document->exists || ! is_string($document->access_key) || ! preg_match('/^\d{44}$/', $document->access_key)) {
            return null;
        }
        $draft = $document->attempts()->where('action', 'document:draft')->first();
        if (! $draft || ! $draft->erp_fiscal_configuration_revision_id || ! $draft->erp_fiscal_certificate_revision_id) {
            return null;
        }
        $attempts = $document->attempts()->whereIn('status', $local ? ['accepted'] : ['accepted', 'artifact_pending'])
            ->whereIn('outbox_state', ['completed', 'attention'])
            ->whereIn('action', $local ? ['document:contingency'] : ['document:transmit', 'document:consult', 'document:reconcile'])
            ->where('erp_fiscal_configuration_revision_id', $draft->erp_fiscal_configuration_revision_id)
            ->where('erp_fiscal_certificate_revision_id', $draft->erp_fiscal_certificate_revision_id)
            ->where('erp_company_id', $document->erp_company_id)->where('erp_establishment_id', $document->erp_establishment_id)
            ->where('model', $document->model)->where('environment', $document->environment)->where('series', $document->series)->where('number', $document->number)
            ->with(['configurationRevision.configurationOwner', 'certificateRevision.certificate'])->latest('id')->get();
        foreach ($attempts as $attempt) {
            $result = $attempt->safe_result ?? [];
            $configuration = $attempt->configurationRevision;
            $certificate = $attempt->certificateRevision;
            $provedPending = ! $local && $attempt->status === 'artifact_pending' && ($result['authorization_verified'] ?? false) === true;
            if ((! $provedPending && (($result['artifact_sha256'] ?? null) !== $document->artifact_sha256
                    || (int) ($result['artifact_byte_length'] ?? 0) !== (int) $document->artifact_byte_length
                    || ($result['artifact_ready'] ?? null) !== ! $local))
                || ! is_string($result['artifact_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $result['artifact_sha256']) || (int) ($result['artifact_byte_length'] ?? 0) <= 0
                || ! is_string($attempt->schema_release) || $attempt->schema_release === ''
                || ! $configuration || $configuration->environment !== $document->environment
                || (int) $configuration->configurationOwner?->erp_company_id !== (int) $document->erp_company_id
                || (int) $configuration->configurationOwner?->erp_establishment_id !== (int) $document->erp_establishment_id
                || ! $certificate || (int) $certificate->erp_fiscal_certificate_id !== (int) $document->erp_fiscal_certificate_id
                || (int) $certificate->certificate?->erp_company_id !== (int) $document->erp_company_id
                || (int) $certificate->certificate?->erp_establishment_id !== (int) $document->erp_establishment_id
                || $certificate->cnpj !== substr((string) $document->access_key, 6, 14)) {
                continue;
            }
            $snapshot = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($attempt);
            $source = $snapshot['request']['source'] ?? [];
            if (($source['identity']['access_key'] ?? null) !== $document->access_key || ($source['identity']['model'] ?? null) !== $document->model
                || ($source['identity']['environment'] ?? null) !== $document->environment
                || (int) ($source['identity']['series'] ?? -1) !== (int) $document->series || (int) ($source['identity']['number'] ?? 0) !== (int) $document->number
                || ($source['issuer']['cnpj'] ?? null) !== $certificate->cnpj) {
                continue;
            }
            if ($requireBytes && ! $this->verifiedOriginalBytes($document, $attempt, $local)) {
                continue;
            }
            if ($local) {
                if (($result['code'] ?? null) === 'local_preparation_complete' && ($result['provider_outcome'] ?? null) === 'not_dispatched' && substr((string) $document->access_key, 34, 1) === '9') {
                    return $attempt;
                }
            } elseif (is_string($attempt->provider_protocol) && preg_match('/^\d{15}$/', $attempt->provider_protocol)
                && $attempt->provider_protocol === ($document->metadata['provider_protocol'] ?? null)) {
                return $attempt;
            }
        }

        return null;
    }

    private function verifiedOriginalBytes(ErpFiscalDocument $document, ErpFiscalAttempt $attempt, bool $local): bool
    {
        if (! $this->verifyIntegrity($document)
            || ($attempt->safe_result['artifact_sha256'] ?? null) !== $document->artifact_sha256
            || (int) ($attempt->safe_result['artifact_byte_length'] ?? 0) !== (int) $document->artifact_byte_length) {
            return false;
        }
        try {
            $xml = $this->readXml($document);
            $schemas = app(ErpFiscalSchemaValidator::class);
            $schemas->assertValid($xml, $document->model, $local ? 'request' : 'processed');
            if (in_array($document->model, ['57', '58'], true)) {
                $schemas->assertEmbeddedRoadModalValid($xml, $document->model);
            }
            $expected = ['model' => $document->model, 'environment' => $document->environment, 'access_key' => $document->access_key,
                'issuer_cnpj' => $attempt->certificateRevision->cnpj, 'series' => $document->series, 'number' => $document->number,
                'protocol' => $attempt->provider_protocol];
            $correlation = app(ErpFiscalCorrelationValidator::class);
            if ($local) {
                $correlation->assertDocumentMatches($xml, $expected);
            } else {
                $correlation->assertProcessedMatches($xml, $expected);
            }
            $revision = $attempt->certificateRevision;
            $raw = Crypt::decryptString($revision->encrypted_certificate);
            if (! hash_equals((string) $revision->certificate_fingerprint, hash('sha256', $raw))) {
                return false;
            }
            $material = [];
            if (! openssl_pkcs12_read($raw, $material, Crypt::decryptString($revision->encrypted_password))) {
                return false;
            }

            return app(ErpFiscalXmlSigner::class)->verify($xml, $material['cert']);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Persist only after the job validated the correlated processed response and its submitted signature. */
    public function retainAcceptedEvent(ErpFiscalEvent $event, ErpFiscalAttempt $attempt, string $xml, array $source, string $protocol): void
    {
        $identity = $this->eventIdentity($event, $attempt, $source, $protocol);
        $hash = hash('sha256', $xml);
        if ($event->accepted_xml !== null) {
            if (! hash_equals((string) $event->accepted_sha256, $hash) || $event->accepted_identity != $identity
                || (int) $event->accepted_byte_length !== strlen($xml)) {
                throw new RuntimeException('Accepted fiscal event evidence is immutable.');
            }

            return;
        }
        $event->fill(['accepted_xml' => $xml, 'accepted_sha256' => $hash, 'accepted_byte_length' => strlen($xml),
            'accepted_event_identity' => $identity['event_identity'], 'accepted_identity' => $identity])->save();
    }

    /** @return array{xml: string, event_identity: string, issuer_cnpj: string, protocol: string, occurred_at: string}|null */
    public function verifiedAcceptedEvent(ErpFiscalEvent $event, ErpFiscalDocument $document): ?array
    {
        try {
            if ($event->status !== 'accepted' || (int) $event->erp_fiscal_document_id !== (int) $document->id
                || ! is_array($event->accepted_identity) || ! is_string($event->accepted_xml)) {
                return null;
            }
            $xml = $event->accepted_xml;
            if ((int) $event->accepted_byte_length !== strlen($xml) || ! hash_equals((string) $event->accepted_sha256, hash('sha256', $xml))) {
                return null;
            }
            $attempt = $document->attempts()->whereKey($event->accepted_identity['attempt_id'] ?? 0)->where('erp_fiscal_event_id', $event->id)
                ->where('action', 'event:submit')->where('status', 'accepted')->where('outbox_state', 'completed')->first();
            if ($attempt === null || ! $attempt->schema_release) {
                return null;
            }
            $source = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($attempt)['request']['source'] ?? [];
            $identity = $this->eventIdentity($event, $attempt, $source, (string) $event->protocol);
            if ($event->accepted_identity != $identity || (string) $event->accepted_event_identity !== (string) $identity['event_identity']
                || $attempt->provider_protocol !== $event->protocol || ($source['access_key'] ?? null) !== $document->access_key
                || ($source['protocol'] ?? null) !== ($document->metadata['provider_protocol'] ?? null)
                || ($source['model'] ?? null) !== $document->model || ($source['environment'] ?? null) !== $document->environment
                || ($source['issuer']['cnpj'] ?? null) !== substr((string) $document->access_key, 6, 14)
                || ($source['event_type'] ?? null) !== $event->event_type
                || (int) $attempt->erp_company_id !== (int) $document->erp_company_id
                || (int) $attempt->erp_establishment_id !== (int) $document->erp_establishment_id) {
                return null;
            }
            $configuration = $attempt->configurationRevision;
            $revision = $attempt->certificateRevision;
            if (! $configuration || $configuration->environment !== $document->environment
                || (int) $configuration->configurationOwner?->erp_company_id !== (int) $document->erp_company_id
                || (int) $configuration->configurationOwner?->erp_establishment_id !== (int) $document->erp_establishment_id
                || ! $revision || $revision->cnpj !== $source['issuer']['cnpj']
                || (int) $revision->certificate?->erp_company_id !== (int) $document->erp_company_id
                || (int) $revision->certificate?->erp_establishment_id !== (int) $document->erp_establishment_id) {
                return null;
            }
            app(ErpFiscalSchemaValidator::class)->assertValid($xml, $document->model, 'processed_event', $event->event_type);
            $dom = new \DOMDocument;
            $dom->loadXML($xml, LIBXML_NONET);
            $requestName = match ($document->model) {
                '55', '65' => 'evento', '57' => 'eventoCTe', '58' => 'eventoMDFe'
            };
            $request = (new \DOMXPath($dom))->query('/*/*[local-name()="'.$requestName.'"]')->item(0);
            if (! $request instanceof \DOMElement) {
                return null;
            }
            app(ErpFiscalEventRegistry::class)->assertOperationResultMatches($xml, $request->C14N(), $source, (string) $event->protocol);
            $raw = Crypt::decryptString($revision->encrypted_certificate);
            $material = [];
            if (! hash_equals((string) $revision->certificate_fingerprint, hash('sha256', $raw))
                || ! openssl_pkcs12_read($raw, $material, Crypt::decryptString($revision->encrypted_password))
                || ! app(ErpFiscalXmlSigner::class)->verify($xml, $material['cert'])) {
                return null;
            }

            return ['xml' => $xml, 'event_identity' => $identity['event_identity'], 'issuer_cnpj' => $source['issuer']['cnpj'],
                'protocol' => (string) $event->protocol, 'occurred_at' => $source['occurred_at']];
        } catch (\Throwable) {
            return null;
        }
    }

    private function eventIdentity(ErpFiscalEvent $event, ErpFiscalAttempt $attempt, array $source, string $protocol): array
    {
        $code = app(ErpFiscalEventRegistry::class)->eventCode($source['model'], $source['document_kind'], $source['event_type']);
        if ($code === null || ! preg_match('/^\d{15}$/', $protocol) || ! is_int($source['sequence']) || $source['sequence'] < 1) {
            throw new RuntimeException('Fiscal event identity is incomplete.');
        }

        return ['attempt_id' => (int) $attempt->id, 'document_id' => (int) $event->erp_fiscal_document_id,
            'configuration_revision_id' => (int) $attempt->erp_fiscal_configuration_revision_id,
            'certificate_revision_id' => (int) $attempt->erp_fiscal_certificate_revision_id,
            'request_sha256' => $attempt->request_sha256, 'model' => $source['model'], 'environment' => $source['environment'],
            'access_key' => $source['access_key'], 'issuer_cnpj' => $source['issuer']['cnpj'], 'protocol' => $protocol,
            'event_identity' => $code.'-'.$source['sequence']];
    }

    public function renderPdfBytes(ErpFiscalDocument $document): string
    {
        abort_unless($document->status === 'authorized' && $this->verifiedFiscalArtifactReady($document), 409);

        $xml = $this->readXml($document);
        abort_if($xml === null || strlen($xml) !== (int) $document->artifact_byte_length
            || ! is_string($document->artifact_sha256)
            || ! hash_equals($document->artifact_sha256, hash('sha256', $xml)), 409);

        try {
            app(ErpFiscalCorrelationValidator::class)->assertProcessedMatches($xml, [
                'model' => $document->model,
                'environment' => $document->environment,
                'access_key' => $document->access_key,
                'issuer_cnpj' => substr((string) $document->access_key, 6, 14),
                'protocol' => $document->metadata['provider_protocol'] ?? null,
                'series' => $document->series,
                'number' => $document->number,
            ]);
        } catch (\Throwable) {
            abort(409);
        }

        return $this->auxiliaryRenderer->render($document->model, $xml);
    }

    public function cleanupPath(?string $path): void
    {
        if ($path !== null && $path !== '' && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    private function assertCompleteXml(string $xml): void
    {
        if ($xml === '' || strlen($xml) > 5_000_000 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new RuntimeException('O artefato fiscal precisa conter XML completo e limitado.');
        }
        $dom = new \DOMDocument;
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        if (! $dom->loadXML($xml, LIBXML_NONET) || ! $dom->documentElement instanceof \DOMElement) {
            throw new RuntimeException('O artefato fiscal não contém XML íntegro.');
        }
    }
}
