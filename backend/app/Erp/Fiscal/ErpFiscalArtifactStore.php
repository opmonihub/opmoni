<?php

namespace App\Erp\Fiscal;

use App\Models\Erp\Fiscal\ErpFiscalDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class ErpFiscalArtifactStore
{
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

    public function verifiedFiscalArtifactReady(ErpFiscalDocument $document): bool
    {
        if (! in_array($document->status, ['authorized', 'contingency_local'], true) || ! $this->verifyIntegrity($document)) {
            return false;
        }
        $draft = $document->attempts()->where('action', 'document:draft')->first();
        if (! $draft || ! $draft->erp_fiscal_configuration_revision_id || ! $draft->erp_fiscal_certificate_revision_id) {
            return false;
        }
        $local = $document->status === 'contingency_local';
        $attempts = $document->attempts()->where('status', 'accepted')->where('outbox_state', 'completed')
            ->whereIn('action', $local ? ['document:contingency'] : ['document:transmit', 'document:consult', 'document:reconcile'])
            ->where('erp_fiscal_configuration_revision_id', $draft->erp_fiscal_configuration_revision_id)
            ->where('erp_fiscal_certificate_revision_id', $draft->erp_fiscal_certificate_revision_id)
            ->where('erp_company_id', $document->erp_company_id)->where('erp_establishment_id', $document->erp_establishment_id)
            ->where('model', $document->model)->where('environment', $document->environment)->where('series', $document->series)->where('number', $document->number)
            ->with(['configurationRevision.configurationOwner', 'certificateRevision.certificate'])->latest('id')->limit(20)->get();
        foreach ($attempts as $attempt) {
            $result = $attempt->safe_result ?? [];
            $configuration = $attempt->configurationRevision;
            $certificate = $attempt->certificateRevision;
            if (($result['artifact_sha256'] ?? null) !== $document->artifact_sha256 || (int) ($result['artifact_byte_length'] ?? 0) !== (int) $document->artifact_byte_length
                || ($result['artifact_ready'] ?? null) !== ! $local || ! is_string($attempt->schema_release) || $attempt->schema_release === ''
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
            if ($local) {
                if (($result['code'] ?? null) === 'local_preparation_complete' && ($result['provider_outcome'] ?? null) === 'not_dispatched' && substr((string) $document->access_key, 34, 1) === '9') {
                    return true;
                }
            } elseif (is_string($attempt->provider_protocol) && preg_match('/^\d{15}$/', $attempt->provider_protocol)
                && $attempt->provider_protocol === ($document->metadata['provider_protocol'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    public function renderPdfBytes(ErpFiscalDocument $document): string
    {
        abort(503, 'Documento auxiliar indisponível: renderizador fiscal não implementado');
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
