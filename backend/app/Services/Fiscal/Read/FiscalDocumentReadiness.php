<?php

namespace App\Services\Fiscal\Read;

use App\Enums\FiscalKind;
use App\Enums\FiscalStage;
use App\Enums\FiscalStorageState;
use App\Models\FiscalDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Verified complete-document readiness shared by lists, aggregates and processing exclusion.
 */
final class FiscalDocumentReadiness
{
    /**
     * @param  Builder<FiscalDocument>  $query
     * @return Builder<FiscalDocument>
     */
    public function readyScope(Builder $query): Builder
    {
        return $query
            ->where('kind', FiscalKind::Document)
            ->where('stage', FiscalStage::Document)
            ->where('storage_state', FiscalStorageState::Available);
    }

    public function isReadyRow(?FiscalDocument $document): bool
    {
        if ($document === null) {
            return false;
        }

        return $document->kind === FiscalKind::Document
            && $document->stage === FiscalStage::Document
            && $document->storage_state === FiscalStorageState::Available;
    }

    /**
     * Reconcile storage for candidate rows in bounded chunks.
     *
     * @param  Collection<int, FiscalDocument>  $documents
     *
     * @throws RuntimeException when the storage disk is unavailable
     */
    public function reconcile(Collection $documents): void
    {
        foreach ($documents as $document) {
            if ($document->stage !== FiscalStage::Document) {
                continue;
            }

            $this->reconcileOne($document);
        }
    }

    public function reconcileOne(FiscalDocument $document): void
    {
        if ($document->stage !== FiscalStage::Document) {
            return;
        }

        try {
            $disk = Storage::disk('fiscal');
        } catch (RuntimeException $exception) {
            throw new RuntimeException('Fiscal storage unavailable.', 0, $exception);
        }

        if (! $disk->exists($document->storage_path)) {
            $document->forceFill([
                'storage_state' => FiscalStorageState::Missing,
                'storage_verified_at' => now(),
            ])->saveQuietly();

            return;
        }

        $bytes = $disk->get($document->storage_path);

        if ($bytes === null) {
            $document->forceFill([
                'storage_state' => FiscalStorageState::Missing,
                'storage_verified_at' => now(),
            ])->saveQuietly();

            return;
        }

        $length = strlen($bytes);
        $hash = hash('sha256', $bytes);

        if ($length !== (int) $document->xml_bytes || ! hash_equals((string) $document->sha256, $hash)) {
            $document->forceFill([
                'storage_state' => FiscalStorageState::Corrupt,
                'storage_verified_at' => now(),
            ])->saveQuietly();

            return;
        }

        $document->forceFill([
            'storage_state' => FiscalStorageState::Available,
            'storage_verified_at' => now(),
        ])->saveQuietly();
    }
}
