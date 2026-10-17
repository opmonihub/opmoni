<?php

declare(strict_types=1);

use App\Erp\Fiscal\ErpFiscalIssuanceService;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
$barrier = (string) ($input['barrier'] ?? '');

while ($barrier !== '' && ! is_file($barrier)) {
    usleep(1000);
}

$result = match ($input['operation'] ?? '') {
    'fiscal-transmit' => fiscalTransmit($input),
    'fiscal-event-submit' => fiscalEventSubmit($input),
    default => ['ok' => false, 'error' => 'unknown operation'],
};

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));

/** @param array<string, mixed> $input */
function fiscalTransmit(array $input): array
{
    try {
        $document = ErpFiscalDocument::query()->whereKey($input['document_id'])->firstOrFail();
        $company = ErpCompany::query()->whereKey($document->erp_company_id)->firstOrFail();
        app(ErpFiscalIssuanceService::class)->transmit($document, $company, isset($input['user_id']) ? (int) $input['user_id'] : null);

        return ['ok' => true];
    } catch (Throwable $exception) {
        return ['ok' => false, 'error' => $exception->getMessage()];
    }
}

/** @param array<string, mixed> $input */
function fiscalEventSubmit(array $input): array
{
    try {
        return DB::transaction(function () use ($input): array {
            $event = ErpFiscalEvent::query()->whereKey($input['event_id'])->lockForUpdate()->firstOrFail();
            $document = ErpFiscalDocument::query()->whereKey($event->erp_fiscal_document_id)->firstOrFail();
            app(ErpFiscalIssuanceService::class)->submitEvent($event, $document);

            return ['ok' => true];
        });
    } catch (Throwable $exception) {
        return ['ok' => false, 'error' => $exception->getMessage()];
    }
}
