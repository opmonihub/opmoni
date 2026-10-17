<?php

namespace App\Erp\Fiscal;

use App\Models\Erp\Fiscal\ErpFiscalDocument;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ErpFiscalArtifactStore
{
    public function persistAuthorizedXml(ErpFiscalDocument $document, string $xml): array
    {
        $path = sprintf('erp/fiscal/%d/%d/authorized.xml', $document->erp_company_id, $document->id);
        if (! Storage::disk('local')->put($path, $xml)) {
            throw new RuntimeException('Falha ao persistir XML autorizado.');
        }

        $bytes = Storage::disk('local')->get($path);
        if (! is_string($bytes) || $bytes === '') {
            Storage::disk('local')->delete($path);
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
        $path = sprintf('erp/fiscal/%d/%d/contingency-local.xml', $document->erp_company_id, $document->id);
        if (! Storage::disk('local')->put($path, $xml)) {
            throw new RuntimeException('Falha ao persistir XML de contingência.');
        }

        $bytes = Storage::disk('local')->get($path);
        if (! is_string($bytes) || $bytes === '') {
            Storage::disk('local')->delete($path);

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
        $xml = $this->readXml($document);
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

    public function renderPdfBytes(ErpFiscalDocument $document): string
    {
        $chave = $document->access_key ?? 'sem-chave';

        return "%PDF-1.4\n% Opmoni ERP fiscal auxiliary\n/Chave {$chave}\n";
    }

    public function cleanupPath(?string $path): void
    {
        if ($path !== null && $path !== '' && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }
}
