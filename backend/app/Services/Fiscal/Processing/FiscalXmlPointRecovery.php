<?php

namespace App\Services\Fiscal\Processing;

use App\Enums\FiscalFailure;
use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Enums\FiscalStorageState;
use App\Models\Client;
use App\Models\FiscalCursor;
use App\Services\Fiscal\Capture\FiscalCaptureLock;
use App\Services\Fiscal\Capture\FiscalConnectorRegistry;
use App\Services\Fiscal\Capture\FiscalDocumentWriter;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalLookupDeferred;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Uma consulta pontual `consChNFe` por chave, com validação antes de gravar.
 */
final class FiscalXmlPointRecovery
{
    public function __construct(
        private readonly FiscalConnectorRegistry $connectors,
        private readonly FiscalDocumentWriter $writer,
    ) {}

    /**
     * @return 'recovered'|'deferred'|'not_found'|'invalid_response'|'storage_failed'|'blocked'|'not_sendable'
     */
    public function recover(Client $client, string $chaveAcesso): string
    {
        $cursor = FiscalCursor::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->getKey())
            ->where('source', FiscalSource::NfeDistribuicao)
            ->first();

        if ($cursor?->isBlocked() === true) {
            return 'blocked';
        }

        $result = FiscalCaptureLock::run(
            $client,
            FiscalSource::NfeDistribuicao,
            fn (): string => $this->fetchAndStore($client, $chaveAcesso),
        );

        return $result ?? 'deferred';
    }

    private function fetchAndStore(Client $client, string $chaveAcesso): string
    {
        $connector = $this->connectors->for(FiscalSource::NfeDistribuicao);

        try {
            $document = $connector->fetchByChave($client, $chaveAcesso);
        } catch (FiscalLookupDeferred) {
            return 'deferred';
        } catch (FiscalRequestNotSent) {
            return 'not_sendable';
        } catch (FiscalException $exception) {
            if ($exception->failure === FiscalFailure::Blocked) {
                $this->bloquear($client);

                return 'blocked';
            }

            return 'invalid_response';
        } catch (ConnectionException|RuntimeException) {
            return 'invalid_response';
        }

        if ($document === null) {
            return 'not_found';
        }

        if (! $this->isCompleteDocumentForKey($document, $chaveAcesso, $client)) {
            return 'invalid_response';
        }

        try {
            $stored = $this->writer->store($client, FiscalSource::NfeDistribuicao, $document);
            if ($stored->storage_state !== FiscalStorageState::Available) {
                return 'storage_failed';
            }
        } catch (RuntimeException $exception) {
            Log::warning('fiscal.xml_point_recovery.gravacao_recusada', [
                'account_id' => (int) $client->account_id,
                'client_id' => (int) $client->getKey(),
                'chave_acesso' => $chaveAcesso,
                'reason' => class_basename($exception),
            ]);

            return 'storage_failed';
        }

        return 'recovered';
    }

    private function isCompleteDocumentForKey(PulledDocument $document, string $chaveAcesso, Client $client): bool
    {
        return $document->chave === $chaveAcesso
            && $document->stage === FiscalStage::Document
            && $document->kind === FiscalKind::Document
            && $document->model === FiscalModel::Nfe
            && preg_replace('/\D/', '', (string) $document->destinatarioCnpj) !== ''
            && preg_replace('/\D/', '', (string) $document->destinatarioCnpj) === preg_replace('/\D/', '', (string) $client->tax_id);
    }

    private function bloquear(Client $client): void
    {
        FiscalCursor::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->getKey())
            ->where('source', FiscalSource::NfeDistribuicao)
            ->first()
            ?->forceFill([
                'blocked_until' => CarbonImmutable::now()->addMinutes((int) config('fiscal.block_minutes', 60)),
                'last_error' => 'blocked_consumption',
            ])->save();
    }
}
