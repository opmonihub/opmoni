<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpCompanySetupState;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpCompanyInitializationConcurrencyTest extends TestCase
{
    private const PREVIEW = '/api/v1/erp/company/initialization/preview';

    private const APPLY = '/api/v1/erp/company/initialization/apply';

    private const LOOKUP = '/api/v1/erp/operations/lookup?operation=erp.company.initialization.apply';

    private ErpCompany $company;

    private User $owner;

    private ErpCompanyMembership $membership;

    private string $directory;

    private string $ip;

    private array $identity;

    /** @var list<Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->identity = ErpCompanyInitializationConcurrencyWorker::assertIsolated();
        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed and visible to independent connections.');
        Http::preventStrayRequests();
        $tag = bin2hex(random_bytes(10));
        $this->directory = sys_get_temp_dir().'/opmoni-initialization-race-'.$tag;
        mkdir($this->directory, 0700);
        $this->ip = '127.'.random_int(1, 254).'.'.random_int(1, 254).'.'.random_int(1, 254);
        $this->company = ErpCompany::factory()->create(['name' => 'Initialization race '.$tag, 'cnpj' => (string) random_int(10000000000000, 99999999999999), 'operating_settings' => []]);
        $this->owner = User::factory()->create(['email' => 'init-race-'.$tag.'@example.test']);
        $this->membership = ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        ErpSubscription::factory()->active()->create(['erp_company_id' => $this->company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id]);
        fwrite(STDERR, 'Retained task10.1 fixture Company '.$this->company->id.PHP_EOL);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    /** @param array<string, mixed> $input */
    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Integration/ErpCompanyInitializationConcurrencyWorker.php')], base_path());
        $process->setInput(json_encode(['ip' => $this->ip, 'label' => 'init10.1-'.bin2hex(random_bytes(8)), ...$input, 'source_hashes' => $this->identity['source_hashes']], JSON_THROW_ON_ERROR));
        $process->setTimeout(45);
        $this->workers[] = $process;
        $process->start();

        return $process;
    }

    private function response(Process $worker): array
    {
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), 'Worker exited '.$worker->getExitCode().'; stderr: '.$worker->getErrorOutput());
        $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $result['transaction_level']);
        $this->assertSame($this->identity['checkout'], $result['checkout']);
        $this->assertSame($this->identity['source_hashes'], $result['source_hashes']);
        $this->assertNotSame($this->identity['pid'], $result['pid']);
        $this->assertNotSame($this->identity['database_pid'], $result['database_pid']);

        return $result;
    }

    private function request(array $input): array
    {
        return $this->response($this->worker($input));
    }

    private function bodyAtStatus(array $result, int $expected): array
    {
        $this->assertSame($expected, $result['status'], json_encode($result['body'], JSON_THROW_ON_ERROR));

        return $result['body'];
    }

    private function login(): array
    {
        $csrf = $this->request(['path' => '/api/v1/csrf']);
        $this->bodyAtStatus($csrf, 200);
        $login = $this->request(['path' => '/api/v1/login', 'method' => 'POST', 'cookies' => $csrf['cookies'], 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']], 'body' => ['email' => $this->owner->email, 'password' => 'password']]);
        $this->bodyAtStatus($login, 200);
        $cookies = array_replace($csrf['cookies'], $login['cookies']);
        $csrf = $this->request(['path' => '/api/v1/csrf', 'cookies' => $cookies]);
        $this->bodyAtStatus($csrf, 200);
        $session = ['cookies' => array_replace($cookies, $csrf['cookies']), 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token'], 'X-Company-Id' => (string) $this->company->id]];
        $switched = $this->request([...$session, 'path' => '/api/v1/erp/company/switch', 'method' => 'POST', 'body' => ['company_id' => $this->company->id]]);
        $this->bodyAtStatus($switched, 200);
        $session['cookies'] = array_replace($session['cookies'], $switched['cookies']);
        $csrf = $this->request([...$session, 'path' => '/api/v1/csrf']);
        $this->bodyAtStatus($csrf, 200);
        $session['cookies'] = array_replace($session['cookies'], $csrf['cookies']);
        $session['headers']['X-CSRF-Token'] = $csrf['body']['csrf_token'];

        return $session;
    }

    private function preview(array $session): array
    {
        $result = $this->request([...$session, 'path' => self::PREVIEW, 'method' => 'POST']);
        $body = $this->bodyAtStatus($result, 200);
        $this->assertStringContainsString('no-store', $result['cache_control']);

        return ['preview_version' => $body['data']['preview_version'], 'fingerprint' => $body['data']['fingerprint']];
    }

    private function applyInput(array $session, array $review, string $key): array
    {
        return [...$session, 'path' => self::APPLY, 'method' => 'POST', 'headers' => [...$session['headers'], 'Idempotency-Key' => $key], 'body' => $review];
    }

    private function initialize(): array
    {
        return $this->bodyAtStatus($this->request(['mode' => 'initialize', 'company_id' => $this->company->id]), 200)['data'];
    }

    private function ready(Process $worker, string $base): array
    {
        $deadline = microtime(true) + 20;
        $path = $base.'.ready';
        do {
            clearstatcache(true, $path);
            if (is_file($path)) {
                $identity = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($worker->getPid(), $identity['pid']);
                $this->assertNotSame($this->identity['database_pid'], $identity['database_pid']);
                $this->assertSame($this->identity['source_hashes'], $identity['source_hashes']);

                return $identity;
            }
            if (! $worker->isRunning()) {
                $this->fail('Worker exited before barrier; exit='.$worker->getExitCode().'; stderr='.$worker->getErrorOutput());
            }
            usleep(5000);
        } while (microtime(true) < $deadline);
        $this->fail('Worker did not reach the initialization barrier.');
    }

    /** @param list<array<string, mixed>> $identities */
    private function blockedBy(array $identities, int $holder): void
    {
        $pids = array_column($identities, 'database_pid');
        $placeholders = implode(',', array_fill(0, count($pids), '?'));
        $deadline = microtime(true) + 1.25;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $rows = DB::select("WITH RECURSIVE chain(root, pid) AS (
                SELECT pid, unnest(pg_blocking_pids(pid)) FROM pg_stat_activity WHERE pid IN ({$placeholders})
                UNION SELECT chain.root, unnest(pg_blocking_pids(chain.pid)) FROM chain
            ) SELECT activity.pid, activity.wait_event_type, activity.query,
                EXISTS (SELECT 1 FROM chain WHERE root = activity.pid AND chain.pid = ?) AS blocked_by_holder
                FROM pg_stat_activity activity WHERE activity.pid IN ({$placeholders})", [...$pids, $holder, ...$pids]);
            if (count($rows) === count($pids) && collect($rows)->every(fn ($row): bool => $row->wait_event_type === 'Lock' && $row->blocked_by_holder && str_contains($row->query, 'erp_companies') && str_contains(strtolower($row->query), 'for update'))) {
                fwrite(STDERR, json_encode(['barrier' => 'Company row lock', 'holder_database_pid' => $holder, 'worker_database_pids' => $pids, 'worker_pids' => array_column($identities, 'pid')], JSON_THROW_ON_ERROR).PHP_EOL);

                return;
            }
            usleep(5000);
        } while (microtime(true) < $deadline);
        $this->fail('Independent workers were not observed blocked on the Company lock within the native two-second lock budget.');
    }

    /** @param list<array<string, mixed>> $inputs
     * @param  Closure(): void|null  $whileLocked
     */
    private function race(array $inputs, ?Closure $whileLocked = null): array
    {
        $workers = $identities = $bases = [];
        DB::beginTransaction();
        DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
        try {
            foreach ($inputs as $index => $input) {
                $base = $this->directory.'/company-'.$index;
                $bases[] = $base;
                $worker = $this->worker([...$input, 'company_barrier' => $base]);
                $workers[] = $worker;
            }
            foreach ($workers as $index => $worker) {
                $identities[] = $this->ready($worker, $bases[$index]);
            }
            $this->assertCount(count($identities), array_unique(array_column($identities, 'pid')));
            $this->assertCount(count($identities), array_unique(array_column($identities, 'database_pid')));
            foreach ($bases as $base) {
                touch($base.'.release');
            }
            $this->blockedBy($identities, $this->identity['database_pid']);
            if ($whileLocked !== null) {
                $whileLocked();
            }
            DB::commit();

            return array_map(fn (Process $worker): array => $this->response($worker), $workers);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    private function baseline(bool $numberingAdopted): ErpCompanySetupState
    {
        $state = ErpCompanySetupState::query()->where('erp_company_id', $this->company->id)->sole();
        $this->assertSame('br-basic-v1', $state->preset_version);
        $this->assertSame(1, $state->revision);
        $this->assertSame('BRL', $state->currency);
        $this->assertSame($numberingAdopted, $state->sale_numbering_adopted);
        $records = [
            ['erp_units', 'br-basic-v1.unit.un', $state->default_unit_id],
            ['erp_contacts', 'br-basic-v1.customer.counter-sale', $state->default_customer_id],
            ['erp_invoice_schemes', 'br-basic-v1.sale-scheme', $state->default_invoice_scheme_id],
            ['erp_invoice_layouts', 'br-basic-v1.sale-layout', $state->default_invoice_layout_id],
        ];
        foreach ($records as [$table, $key, $id]) {
            $row = DB::table($table)->where('erp_company_id', $this->company->id)->sole();
            $this->assertSame($id, $row->id);
            $this->assertSame($key, $row->native_key);
        }
        $this->assertSame('UN', $state->defaultUnit->short_name);
        $this->assertFalse($state->defaultUnit->allow_decimal);
        $this->assertNull($state->defaultUnit->base_unit_id);
        $this->assertNull($state->defaultUnit->conversion_factor);
        $this->assertNull($state->defaultCustomer->tax_id);
        $this->assertNull($state->defaultCustomer->address);
        $this->assertSame('', $state->defaultInvoiceScheme->prefix);
        $this->assertSame(4, $state->defaultInvoiceScheme->total_digits);
        $this->assertSame(1, $state->defaultInvoiceScheme->start_number);

        return $state;
    }

    private function snapshot(): array
    {
        $snapshot = ['settings' => $this->company->fresh()->getRawOriginal('operating_settings')];
        foreach (['erp_company_setup_states', 'erp_units', 'erp_contacts', 'erp_invoice_schemes', 'erp_invoice_layouts', 'erp_operation_receipts', 'erp_catalog_attributions', 'erp_commercial_number_sequences'] as $table) {
            $snapshot[$table] = DB::table($table)->where('erp_company_id', $this->company->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $snapshot;
    }

    private function noBusinessEffects(int $existingSequences = 0): void
    {
        foreach (['erp_sales', 'erp_stock_movements', 'erp_obligations', 'erp_fiscal_documents', 'erp_tax_rates', 'erp_operation_natures'] as $table) {
            $this->assertSame(0, DB::table($table)->where('erp_company_id', $this->company->id)->count(), $table);
        }
        $this->assertSame($existingSequences, DB::table('erp_commercial_number_sequences')->where('erp_company_id', $this->company->id)->count());
    }

    public function test_independent_initializers_converge_on_one_baseline_and_preserve_explicit_empty_preferences(): void
    {
        $explicit = ['time_zone' => null, 'date_format' => '', 'time_format' => 0, 'stock_overselling_enabled' => false, 'pos_settings' => ['nested' => ['empty' => [], 'zero' => 0, 'null' => null]]];
        $this->company->update(['operating_settings' => $explicit]);
        $input = ['mode' => 'initialize', 'company_id' => $this->company->id];
        [$first, $second] = $this->race([$input, $input]);
        $firstState = $this->bodyAtStatus($first, 200)['data'];
        $secondState = $this->bodyAtStatus($second, 200)['data'];
        ksort($firstState);
        ksort($secondState);
        $this->assertSame($firstState, $secondState);
        $this->baseline(true);
        $settings = $this->company->fresh()->operating_settings;
        $expected = [...$explicit, 'fy_start_month' => 1, 'accounting_method' => 'fifo'];
        $this->assertCount(count($expected), $settings);
        foreach ($expected as $field => $value) {
            $this->assertArrayHasKey($field, $settings);
            $this->assertSame($value, $settings[$field]);
        }
        $this->noBusinessEffects();
    }

    public function test_rerunning_initializer_race_never_replaces_explicit_custom_or_null_default_choices(): void
    {
        $initial = $this->initialize();
        $custom = ErpUnit::factory()->create(['erp_company_id' => $this->company->id, 'short_name' => 'CX', 'actual_name' => 'Caixa escolhida']);
        DB::transaction(function () use ($initial, $custom): void {
            DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
            ErpCompanySetupState::query()->whereKey($initial['id'])->update(['default_unit_id' => $custom->id, 'default_customer_id' => null, 'currency' => null, 'revision' => 7]);
            ErpUnit::query()->whereKey($initial['default_unit_id'])->update(['status' => 'archived']);
            $this->company->update(['operating_settings' => ['time_format' => 0, 'stock_overselling_enabled' => false, 'pos_settings' => []]]);
        });
        $before = $this->snapshot();
        $input = ['mode' => 'initialize', 'company_id' => $this->company->id];
        [$first, $second] = $this->race([$input, $input]);
        $this->assertSame(7, $this->bodyAtStatus($first, 200)['data']['revision']);
        $this->assertSame($first['body'], $this->bodyAtStatus($second, 200));
        $this->assertSame($before, $this->snapshot());
        $this->noBusinessEffects();
    }

    public function test_equal_reconciliation_keys_from_distinct_sessions_return_one_original_result_and_receipt(): void
    {
        $first = $this->login();
        $second = $this->login();
        $this->assertNotSame($first['cookies']['opmoni-session'], $second['cookies']['opmoni-session']);
        $review = $this->preview($first);
        $this->assertSame($review, $this->preview($second));
        [$a, $b] = $this->race([$this->applyInput($first, $review, 'same-review'), $this->applyInput($second, $review, 'same-review')]);
        $this->assertSame($this->bodyAtStatus($a, 200), $this->bodyAtStatus($b, 200));
        $this->baseline(false);
        $this->assertSame(1, ErpOperationReceipt::query()->where('erp_company_id', $this->company->id)->count());
        $this->noBusinessEffects();
    }

    public function test_competing_distinct_keys_cannot_apply_a_preview_after_the_other_request_commits(): void
    {
        $first = $this->login();
        $second = $this->login();
        $review = $this->preview($first);
        [$a, $b] = $this->race([$this->applyInput($first, $review, 'review-a'), $this->applyInput($second, $review, 'review-b')]);
        $statuses = [$a['status'], $b['status']];
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $stale = $a['status'] === 409 ? $a : $b;
        $this->assertSame('initialization_preview_stale', $stale['body']['code']);
        $this->baseline(false);
        $this->assertSame(1, ErpOperationReceipt::query()->where('erp_company_id', $this->company->id)->count());
        $this->noBusinessEffects();
    }

    public static function changedPreconditions(): iterable
    {
        yield 'explicit preference' => ['settings'];
        yield 'setup default and revision' => ['setup'];
        yield 'selected native record' => ['record'];
    }

    #[DataProvider('changedPreconditions')]
    public function test_waiting_apply_detects_committed_preference_default_or_record_changes_without_overwrite(string $change): void
    {
        $initial = $this->initialize();
        $session = $this->login();
        $review = $this->preview($session);
        $expected = [];
        [$result] = $this->race([$this->applyInput($session, $review, 'stale-review')], function () use ($change, $initial, &$expected): void {
            match ($change) {
                'settings' => $this->company->update(['operating_settings' => ['time_format' => 0, 'pos_settings' => ['kept' => false]]]),
                'setup' => ErpCompanySetupState::query()->whereKey($initial['id'])->update(['revision' => 2, 'currency' => null, 'default_customer_id' => null]),
                'record' => ErpUnit::query()->whereKey($initial['default_unit_id'])->update(['actual_name' => 'Unidade revisada pelo proprietário']),
            };
            $expected = $this->snapshot();
        });
        $this->assertSame('initialization_preview_stale', $this->bodyAtStatus($result, 409)['code']);
        $this->assertSame($expected, $this->snapshot());
        $this->noBusinessEffects();
    }

    public static function ownerActions(): iterable
    {
        yield 'preview' => ['preview'];
        yield 'new apply' => ['apply'];
        yield 'completed replay' => ['replay'];
        yield 'completed lookup' => ['lookup'];
    }

    #[DataProvider('ownerActions')]
    public function test_waiting_request_rechecks_direct_owner_before_preview_effects_replay_or_lookup(string $action): void
    {
        $session = $this->login();
        $review = $this->preview($session);
        $input = $this->applyInput($session, $review, 'owner-review');
        if (in_array($action, ['replay', 'lookup'], true)) {
            $this->bodyAtStatus($this->request($input), 200);
        }
        if ($action === 'preview') {
            $input = [...$session, 'path' => self::PREVIEW, 'method' => 'POST'];
        } elseif ($action === 'lookup') {
            $input = [...$input, 'path' => self::LOOKUP, 'method' => 'GET', 'body' => []];
        }
        $before = $this->snapshot();
        [$result] = $this->race([$input], function (): void {
            $this->membership->update(['role' => 'manager', 'action_permissions' => ['*']]);
        });
        $body = $this->bodyAtStatus($result, 403);
        $this->assertArrayNotHasKey('data', $body);
        $this->assertSame('manager', $this->membership->fresh()->role);
        $this->assertSame($before, $this->snapshot());
        $this->noBusinessEffects();
    }

    public static function mandatoryFailures(): iterable
    {
        yield 'required customer already inserted' => ['customer'];
        yield 'receipt already inserted' => ['receipt'];
    }

    #[DataProvider('mandatoryFailures')]
    public function test_failed_transaction_rolls_back_while_independent_equal_retry_waits_then_commits_once(string $fault): void
    {
        $first = $this->login();
        $second = $this->login();
        $review = $this->preview($first);
        $before = $this->snapshot();
        $faultBase = $this->directory.'/fault';
        $a = $this->worker([...$this->applyInput($first, $review, 'rollback-retry'), 'fault' => $fault, 'fault_barrier' => $faultBase]);
        $holder = $this->ready($a, $faultBase);
        $this->assertSame($before, $this->snapshot(), 'Uncommitted baseline and receipt must be invisible on the independent parent connection.');
        $waitBase = $this->directory.'/retry';
        $b = $this->worker([...$this->applyInput($second, $review, 'rollback-retry'), 'company_barrier' => $waitBase]);
        $waiting = $this->ready($b, $waitBase);
        $this->assertNotSame($holder['pid'], $waiting['pid']);
        $this->assertNotSame($holder['database_pid'], $waiting['database_pid']);
        touch($waitBase.'.release');
        $this->blockedBy([$waiting], $holder['database_pid']);
        touch($faultBase.'.release');
        $this->bodyAtStatus($this->response($a), 503);
        $this->bodyAtStatus($this->response($b), 200);
        $this->baseline(false);
        $this->assertSame(1, ErpOperationReceipt::query()->where('erp_company_id', $this->company->id)->count());
        $this->assertSame(3, DB::table('erp_catalog_attributions')->where('erp_company_id', $this->company->id)->count());
        $this->noBusinessEffects();
    }

    public function test_response_loss_after_commit_recovers_original_result_without_overwriting_later_preferences(): void
    {
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        DB::table('erp_commercial_number_sequences')->insert(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $establishment->id, 'document_type' => 'sale', 'next_number' => 37]);
        $session = $this->login();
        $review = $this->preview($session);
        $input = $this->applyInput($session, $review, 'lost-response');
        $this->bodyAtStatus($this->request([...$input, 'session_write_failure' => true]), 503);
        $receipt = ErpOperationReceipt::query()->where('erp_company_id', $this->company->id)->sole();
        $original = $receipt->response_body;
        $this->baseline(false);
        DB::transaction(function (): void {
            DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
            $this->company->update(['operating_settings' => ['time_format' => 0, 'stock_overselling_enabled' => false, 'pos_settings' => ['empty' => []]]]);
        });
        $before = $this->snapshot();
        $this->assertSame($original, $this->bodyAtStatus($this->request($input), 200));
        $lookup = $this->bodyAtStatus($this->request([...$input, 'path' => self::LOOKUP, 'method' => 'GET', 'body' => []]), 200);
        $this->assertSame('completed', $lookup['data']['state']);
        $this->assertSame($original['data'], $lookup['data']['result']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(37, DB::table('erp_commercial_number_sequences')->where('erp_company_id', $this->company->id)->sole()->next_number);
        $this->noBusinessEffects(1);
    }
}
