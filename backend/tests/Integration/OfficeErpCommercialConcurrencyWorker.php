<?php

namespace Tests\Integration;

use App\Erp\Commercial\OfficeErpContracts;
use App\Erp\Commercial\OfficeErpCustomerEnrollment;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpInvitationContinuation;
use App\Erp\Commercial\OfficeErpInvoices;
use App\Erp\Commercial\OfficeErpLicenceAllocator;
use App\Erp\Commercial\OfficeErpOwnerAcceptance;
use App\Erp\Commercial\OfficeErpReceiptProofs;
use App\Erp\Commercial\OfficeErpReceipts;
use App\Erp\Commercial\OfficeErpRecords;
use App\Erp\Commercial\OfficeErpTrialLinks;
use App\Erp\Commercial\OfficeErpTrialRegistration;
use App\Erp\Commercial\OfficeErpRecurringBilling;
use App\Erp\Commercial\ErpTrialLifecycle;
use App\Http\Controllers\Api\V1\Admin\PlanController;
use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Plan;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class OfficeErpCommercialConcurrencyWorker
{
    /** @param array<string, mixed> $input */
    public static function run(array $input): void
    {
        $identity = OfficeErpCommercialProcessTestCase::assertIsolatedStores();
        if (($input['source_hashes'] ?? null) !== $identity['source_hashes']) {
            throw new RuntimeException('Parent and worker commercial race sources differ.');
        }
        Http::preventStrayRequests();
        $base = $input['base'];
        self::signal($base.'.ready', $identity);

        $request = Request::create('http://localhost:3000');
        app()->instance('request', $request);
        if (! in_array($input['action'], ['reserve', 'issue_period', 'adjust', 'receipt', 'reverse', 'proof_cleanup', 'owner_accept', 'remove_allowance', 'cancel_contract', 'generate_recurring', 'trial_register', 'trial_rotate', 'trial_expire', 'trial_convert'], true)) {
            throw new RuntimeException('Unsupported action in the accepted commercial race subset: '.$input['action']);
        }
        if (isset($input['hold_row_lock']) && (! is_string($input['hold_row_lock']) || preg_match('/\A[a-z_]+\z/', $input['hold_row_lock']) !== 1)) {
            throw new RuntimeException('Invalid table name for the commercial row-lock barrier.');
        }
        $context = null;
        if (! in_array($input['action'], ['owner_accept', 'remove_allowance', 'generate_recurring', 'trial_register', 'trial_expire'], true)) {
            $account = Account::query()->findOrFail($input['account_id']);
            $actor = User::query()->findOrFail($input['actor_id']);
            $membership = AccountMembership::query()
                ->whereKey($input['membership_id'])
                ->where('account_id', $input['account_id'])
                ->where('user_id', $input['actor_id'])
                ->where('role', 'admin')
                ->firstOrFail();
            $request->attributes->set(CurrentAccount::class, new CurrentAccount($account, $membership));
            $resourceId = $input['contract_id'] ?? $input['trial_link_id'] ?? 0;
            $context = OfficeErpExecutionContext::officeAdmin($account, $actor, 'commercial-race-worker:'.$resourceId);
        }

        if (($input['hold_account_lock'] ?? false) || ($input['hold_invoice_lock'] ?? false) || ($input['hold_proof_lock'] ?? false) || ($input['hold_after_proof_claim'] ?? false) || ($input['hold_owner_lock'] ?? false) || ($input['hold_plan_lock'] ?? false) || isset($input['hold_row_lock']) || ($input['hold_advisory_lock'] ?? false)) {
            $paused = false;
            DB::listen(function (QueryExecuted $query) use ($base, &$paused, $input): void {
                $sql = strtolower($query->sql);
                $target = ($input['hold_advisory_lock'] ?? false)
                    ? str_contains($sql, 'pg_advisory_xact_lock')
                    : (isset($input['hold_row_lock'])
                    ? str_contains($sql, 'from "'.$input['hold_row_lock'].'"')
                    : (($input['hold_plan_lock'] ?? false)
                    ? str_contains($sql, 'from "plans"')
                    : (($input['hold_owner_lock'] ?? false)
                    ? str_contains($sql, 'from "users"')
                    : (($input['hold_proof_lock'] ?? false)
                    ? str_contains($sql, 'from "office_erp_receipt_proofs"')
                    : (($input['hold_after_proof_claim'] ?? false)
                        ? str_contains($sql, 'insert into "office_erp_receipt_allocations"')
                        : (($input['hold_invoice_lock'] ?? false)
                            ? str_contains($sql, 'from "office_erp_invoices"')
                            : str_contains($sql, 'from "accounts"')))))));
                $requiresForUpdate = ! ($input['hold_after_proof_claim'] ?? false) && ! ($input['hold_advisory_lock'] ?? false);
                if ($paused || ! $target || ($requiresForUpdate && ! str_contains($sql, 'for update'))) {
                    return;
                }
                if (DB::transactionLevel() < 1) {
                    throw new RuntimeException('Commercial row-lock barrier must remain inside its transaction.');
                }
                $paused = true;
                self::signal($base.'.held', [
                    'database_pid' => (int) DB::selectOne('select pg_backend_pid() as pid')->pid,
                    'transaction_level' => DB::transactionLevel(),
                    'lock_query' => $query->sql,
                ]);
                self::await($base.'.release');
            });
        }

        try {
            if (isset($input['frozen_now'])) {
                $frozenNow = CarbonImmutable::parse($input['frozen_now']);
                CarbonImmutable::setTestNow($frozenNow);
                Carbon::setTestNow($frozenNow);
            }
            $contract = null;
            if (! in_array($input['action'], ['owner_accept', 'remove_allowance', 'generate_recurring', 'trial_register', 'trial_rotate', 'trial_expire'], true)) {
                $contract = OfficeErpRecords::query($context, OfficeErpContract::class)
                    ->whereKey($input['contract_id'])
                    ->firstOrFail();
            }
            if ($input['action'] === 'trial_register') {
                config(['erp.office_trial_enabled' => true]);
                $cnpj = preg_replace('/\D/', '', (string) $input['registration']['cnpj']);
                if (! is_string($cnpj) || strlen($cnpj) !== 14) {
                    throw new RuntimeException('Trial race fixture requires a normalized 14-digit CNPJ.');
                }
                Http::fake([
                    'https://publica.cnpj.ws/cnpj/'.$cnpj => Http::response([
                        'razao_social' => 'Trial race company '.$cnpj,
                        'estabelecimento' => [
                            'cnpj' => $cnpj,
                            'nome_fantasia' => 'Trial race company '.$cnpj,
                            'situacao_cadastral' => 'Ativa',
                            'data_situacao_cadastral' => '2020-01-01',
                            'data_inicio_atividade' => '2020-01-01',
                            'atividade_principal' => ['id' => '0000000', 'descricao' => 'Atividade de teste'],
                            'tipo_logradouro' => 'Rua',
                            'logradouro' => 'Teste',
                            'numero' => '1',
                            'cep' => '01001000',
                            'cidade' => ['nome' => 'São Paulo'],
                            'estado' => ['sigla' => 'SP'],
                        ],
                        'porte' => ['descricao' => 'Demais'],
                        'natureza_juridica' => ['descricao' => 'Sociedade Empresária Limitada'],
                        'simples' => ['mei' => 'Não', 'simples' => 'Sim'],
                    ]),
                ]);
                $trialRequest = Request::create('http://localhost:3000/api/v1/erp/trials/'.$input['code'].'/register', 'POST', $input['registration']);
                if (isset($input['owner_id'])) {
                    $owner = User::query()->findOrFail($input['owner_id']);
                    $trialRequest->setUserResolver(static fn (): User => $owner);
                }
                $result = app(OfficeErpTrialRegistration::class)->register(
                    $trialRequest,
                    (string) $input['code'],
                    $input['registration'],
                    (string) $input['idempotency_key'],
                );
                $result = ['status' => 201, ...$result];
            } elseif ($input['action'] === 'trial_rotate') {
                $result = app(OfficeErpTrialLinks::class)->rotate($context, (string) $input['idempotency_key']);
                $result = ['status' => 201, ...$result];
            } elseif ($input['action'] === 'trial_expire') {
                $trial = \App\Models\Erp\ErpSubscriptionTrial::query()->findOrFail($input['trial_id']);
                app(ErpTrialLifecycle::class)->expire($trial, CarbonImmutable::parse($input['now']));
                $result = ['status' => 200, 'trial_id' => (int) $trial->id];
            } elseif ($input['action'] === 'trial_convert') {
                $contract = OfficeErpRecords::query($context, OfficeErpContract::class)->whereKey($input['contract_id'])->firstOrFail();
                $result = app(OfficeErpCustomerEnrollment::class)->convertTrial(
                    $context,
                    $contract,
                    $input['terms'],
                    (string) $input['idempotency_key'],
                );
                $result = ['status' => 201, ...$result];
            } elseif ($input['action'] === 'reserve') {
                $allocation = $context->run(fn () => app(OfficeErpLicenceAllocator::class)->reserve(
                    $context,
                    $contract,
                    CarbonImmutable::parse($input['expires_at']),
                ));
                $result = ['status' => 201, 'allocation_id' => (int) $allocation->id];
            } elseif ($input['action'] === 'issue_period') {
                $revision = OfficeErpRecords::query($context, OfficeErpContractRevision::class)
                    ->where('contract_id', $contract->id)
                    ->whereKey($input['revision_id'])
                    ->firstOrFail();
                $invoiceService = app(OfficeErpInvoices::class);
                $invoice = $context->run(fn () => $invoiceService->issuePeriod(
                    $context,
                    $contract,
                    $revision,
                    $input['period'],
                    $input['idempotency_key'],
                ));
                $operation = OfficeErpRecords::query($context, OfficeErpCommercialOperation::class)
                    ->where('idempotency_key', $input['idempotency_key'])
                    ->firstOrFail();
                $result = [
                    'status' => 201,
                    'invoice_id' => (int) $invoice->id,
                    'number' => (string) $invoice->number,
                    'operation_id' => (int) $operation->id,
                    'operation_outcome' => $operation->outcome,
                ];
            } elseif ($input['action'] === 'adjust') {
                $invoice = OfficeErpRecords::query($context, OfficeErpInvoice::class)->whereKey($input['invoice_id'])->firstOrFail();
                $adjustment = $context->run(fn () => app(OfficeErpInvoices::class)->adjust($context, $invoice, $input['adjustment'], $input['idempotency_key']));
                $result = ['status' => 201, 'adjustment_id' => (int) $adjustment->id];
            } elseif ($input['action'] === 'receipt') {
                $receipt = $context->run(fn () => app(OfficeErpReceipts::class)->post($context, $input['receipt'], $input['idempotency_key']));
                $result = ['status' => 201, 'receipt_id' => (int) $receipt->id];
            } elseif ($input['action'] === 'reverse') {
                $receipt = OfficeErpRecords::query($context, OfficeErpReceipt::class)->whereKey($input['receipt_id'])->firstOrFail();
                $reversal = $context->run(fn () => app(OfficeErpReceipts::class)->reverse($context, $receipt, $input['reason'], $input['idempotency_key']));
                $result = ['status' => 201, 'reversal_id' => (int) $reversal->id];
            } elseif ($input['action'] === 'proof_cleanup') {
                $deleted = app(OfficeErpReceiptProofs::class)->cleanExpired(CarbonImmutable::parse($input['now']));
                $result = ['status' => 200, 'deleted' => $deleted];
            } elseif ($input['action'] === 'owner_accept') {
                $request = self::ownerRequest($input);
                $continuations = app(OfficeErpInvitationContinuation::class);
                $continuation = $continuations->exchange($request, (string) $input['token']);
                $key = (string) ($continuation['operation_key'] ?? '');
                $contract = app(OfficeErpOwnerAcceptance::class)->accept($request, [], $key);
                $result = [
                    'status' => ($continuation['state'] ?? null) === 'accepted' ? 200 : 201,
                    'contract_id' => (int) $contract->id,
                    'company_id' => (int) $contract->erp_company_id,
                    'agreement_status' => (string) $contract->agreement_status,
                ];
            } elseif ($input['action'] === 'cancel_contract') {
                app(OfficeErpContracts::class)->cancel(
                    $context,
                    $contract,
                    (string) $input['mode'],
                    (string) $input['reason'],
                    (string) $input['idempotency_key'],
                );
                $storedContract = DB::table('office_erp_contracts')->where('id', $contract->id)->first();
                $result = [
                    'status' => 201,
                    'agreement_status' => $storedContract->agreement_status,
                    'cancel_effective_on' => $storedContract->cancel_effective_on,
                ];
            } elseif ($input['action'] === 'generate_recurring') {
                $billingResult = app(OfficeErpRecurringBilling::class)->generate(
                    (int) $input['contract_id'],
                    CarbonImmutable::parse($input['now']),
                    (int) ($input['batch_size'] ?? 50),
                );
                $storedContract = DB::table('office_erp_contracts')->where('id', $input['contract_id'])->first();
                $result = [
                    'status' => 200,
                    'generated' => $billingResult['generated'],
                    'pending' => $billingResult['pending'],
                    'next_period_index' => $storedContract->next_period_index === null ? null : (int) $storedContract->next_period_index,
                    'next_period_month_offset' => $storedContract->next_period_month_offset === null ? null : (int) $storedContract->next_period_month_offset,
                    'tenant_account_id' => app(CurrentTenant::class)->accountId,
                ];
            } else {
                $admin = User::query()->findOrFail($input['admin_id']);
                $request = UpdatePlanRequest::create('/api/v1/admin/plans/'.$input['plan_id'], 'PATCH', [
                    'erp_commercial' => ['enabled' => false, 'licence_limit' => null, 'module_keys' => []],
                ]);
                $request->setUserResolver(static fn (): User => $admin);
                $request->setContainer(app())->setRedirector(app('redirect'));
                $request->validateResolved();
                app(PlanController::class)->update($request, Plan::query()->findOrFail($input['plan_id']));
                $result = ['status' => 200, 'plan_id' => (int) $input['plan_id']];
            }
        } catch (HttpExceptionInterface $exception) {
            $message = $exception->getMessage();
            $result = ['status' => $exception->getStatusCode()];
            if (preg_match('/\A[a-z][a-z0-9_]{2,80}\z/', $message) === 1) {
                $result['code'] = $message;
            }
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
            $data = json_decode($response->getContent(), true);
            $code = is_array($data) ? ($data['code'] ?? null) : null;
            $result = ['status' => $response->getStatusCode()];
            if (is_string($code) && preg_match('/\A[a-z][a-z0-9_]{2,80}\z/', $code) === 1) {
                $result['code'] = $code;
            }
        } catch (ValidationException $exception) {
            $result = ['status' => $exception->status];
        } catch (UniqueConstraintViolationException) {
            $result = ['status' => 500, 'code' => 'unmapped_unique_constraint'];
        }

        $accessLock = $request->attributes->get('access.session_lock');
        if (is_object($accessLock) && method_exists($accessLock, 'release')) {
            $accessLock->release();
        }

        echo json_encode([
            ...$identity,
            ...$result,
            'transaction_level' => DB::transactionLevel(),
        ], JSON_THROW_ON_ERROR);
        DB::disconnect();
    }

    /** @param array<string, mixed> $input */
    private static function ownerRequest(array $input): Request
    {
        $session = app('session')->driver();
        $session->setId(bin2hex(random_bytes(20)));
        $session->start();
        $request = Request::create('http://localhost:3000/api/v1/erp-invitations/accept', 'POST');
        $request->setLaravelSession($session);
        $lock = Cache::lock('access-session:'.hash('sha256', $session->getId()), (int) config('access.lock_seconds', 30));
        if (! $lock->get()) {
            throw new RuntimeException('Unable to acquire the isolated owner session lock.');
        }
        $request->attributes->set('access.session_lock', $lock);
        app()->instance('request', $request);
        $owner = User::query()->findOrFail($input['owner_id']);
        $guard = Auth::guard('web');
        $guard->setRequest($request);
        $guard->login($owner);
        $request->setUserResolver(static fn (): ?User => $guard->user());

        return $request;
    }

    /** @param array<string, mixed> $payload */
    private static function signal(string $path, array $payload): void
    {
        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX) === false
            || ! rename($temporary, $path)) {
            throw new RuntimeException('Unable to publish commercial race worker signal.');
        }
        chmod($path, 0600);
    }

    private static function await(string $path): void
    {
        $deadline = hrtime(true) + 15_000_000_000;
        do {
            clearstatcache(true, $path);
            if (file_exists($path)) {
                return;
            }
            usleep(5000);
        } while (hrtime(true) < $deadline);

        throw new RuntimeException('Commercial race worker barrier expired without release.');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require __DIR__.'/bootstrap.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    OfficeErpCommercialConcurrencyWorker::run($input);
}
