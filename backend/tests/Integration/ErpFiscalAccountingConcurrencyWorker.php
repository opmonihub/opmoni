<?php

declare(strict_types=1);

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Erp\Accounting\ErpAccountingMappingService;
use App\Exceptions\AccountingMappingBusy;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Services\Fiscal\Capture\FiscalDocumentWriter;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'opmoni_test') {
    throw new RuntimeException('Accounting worker requires isolated PostgreSQL testing.');
}

$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
$started = microtime(true);
try {
    waitFor($input['barrier'] ?? null);
    if (isset($input['entered'])) {
        touch($input['entered']);
    }
    $operation = function () use ($input): array {
        $handoff = app(ErpAccountingHandoffService::class);
        if (in_array($input['operation'], ['approve', 'cross-approve'], true)) {
            $account = Account::query()->findOrFail($input['account_id']);
            $membership = AccountMembership::query()->findOrFail($input['membership_id']);
            $mapping = app(ErpAccountingMappingService::class)->approve(new CurrentAccount($account, $membership), $input['delegation_id'], $input['establishment_id'], $input['client_id']);

            return ['ok' => true, 'mapping_id' => $mapping->id];
        }
        if ($input['operation'] === 'mutate') {
            $table = match ($input['mutation']) {
                'mapping' => 'erp_accounting_mappings',
                'delegation' => 'erp_office_delegations',
                'company' => 'erp_companies',
                'account' => 'accounts',
                'client', 'delete-client' => 'clients',
                'source' => 'erp_fiscal_documents',
            };
            $changes = $input['mutation'] === 'delete-client' ? ['deleted_at' => now()] : ['status' => match ($input['mutation']) {
                'mapping', 'delegation' => 'revoked', 'company', 'account' => 'suspended', 'source' => 'cancelled', default => 'inactive'
            }];
            DB::table($table)->where('id', $input['target_id'])->update($changes);

            return ['ok' => true];
        }
        if ($input['operation'] === 'capture') {
            $client = Client::withoutGlobalScope('account')->findOrFail($input['client_id']);
            $document = new PulledDocument(
                model: FiscalModel::Nfe, kind: FiscalKind::Document, stage: FiscalStage::Document,
                chave: $input['key'], eventId: '', emitenteCnpj: $input['issuer'] ?? null, destinatarioCnpj: null, valorTotal: null, digVal: null,
                nsu: 101, schema: 'procNFe_v4.00.xsd', emissaoAt: null, eventoOcorridoEmAt: null, xml: $input['bytes'],
            );
            $row = app(FiscalDocumentWriter::class)->store($client, FiscalSource::NfeDistribuicao, $document);

            return ['ok' => true, 'document_id' => $row->id];
        }
        if ($input['operation'] === 'ensure') {
            $intent = $handoff->ensureDeliveryIntent(ErpFiscalDocument::findOrFail($input['document_id']));

            return ['ok' => true, 'intent_id' => $intent?->id];
        }
        $delivery = ErpAccountingDelivery::query()->findOrFail($input['delivery_id']);
        $result = $input['operation'] === 'cross-retry' ? $handoff->retry($delivery) : $handoff->deliver($delivery);

        return ['ok' => true, 'status' => $result->status, 'reason' => $result->reason_code, 'attempts' => $result->attempts];
    };
    if (isset($input['parent_lock'])) {
        $result = DB::transaction(function () use ($input, $operation): array {
            if ($input['parent_lock'] === 'account') {
                Account::query()->whereKey($input['account_id'])->lockForUpdate()->firstOrFail();
                if (isset($input['membership_id'])) {
                    AccountMembership::query()->whereKey($input['membership_id'])->lockForUpdate()->firstOrFail();
                }
            } else {
                ErpCompany::query()->whereKey($input['company_id'])->lockForUpdate()->firstOrFail();
            }
            touch($input['ready']);
            waitFor($input['release']);

            return $operation();
        });
    } else {
        $result = $operation();
    }
} catch (AccountingMappingBusy $error) {
    $response = $error->getResponse();
    $result = ['ok' => false, 'http_status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'retry_after' => $response->headers->get('Retry-After'), 'transaction_level' => DB::transactionLevel(), 'usable' => DB::selectOne('SELECT 1 AS n')->n];
} catch (Throwable $error) {
    $result = ['ok' => false, 'error' => $error->getMessage(), 'class' => $error::class];
}
$result['elapsed'] = microtime(true) - $started;
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));

function waitFor(?string $path): void
{
    $deadline = microtime(true) + 15;
    while ($path !== null && ! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Accounting test barrier timed out.');
        }
        usleep(1000);
    }
}
