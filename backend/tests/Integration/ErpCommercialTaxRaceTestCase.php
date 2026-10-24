<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpCompanyProductTaxDefault;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductTaxProfile;
use App\Models\Erp\ErpProductTaxProfileRevision;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Sales\ErpSale;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\ErpFiscalModelBuilderSchemaTest;
use Tests\TestCase;

abstract class ErpCommercialTaxRaceTestCase extends TestCase
{
    protected const NATURES = '/api/v1/erp/tax-settings/operation-natures';

    protected const PROFILES = '/api/v1/erp/tax-settings/product-tax-profiles';

    protected ErpCompany $company;

    protected ErpEstablishment $establishment;

    protected User $owner;

    protected ErpCompanyMembership $membership;

    protected ErpContact $customer;

    protected array $products = [];

    protected array $variants = [];

    protected array $consumerSession;

    protected array $writerSession;

    protected array $thirdSession;

    protected int $profileId;

    protected int $defaultProfileId;

    protected int $replacementProfileId;

    protected int $natureId;

    protected int $exceptionId;

    protected array $choices = [];

    private array $identity;

    private string $directory;

    private string $ip;

    private int $sequence = 0;

    private array $workers = [];

    private array $retainedRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->identity = ErpCommercialTaxRaceWorker::assertIsolated();
        $this->assertSame(0, DB::transactionLevel(), 'Independent fixture visibility requires no test transaction.');
        Http::preventStrayRequests();
        foreach (['erp_companies', 'erp_company_memberships', 'erp_establishments', 'erp_contacts', 'erp_units',
            'erp_products', 'erp_product_variants', 'erp_product_tax_profiles', 'erp_product_tax_profile_revisions',
            'erp_company_product_tax_defaults', 'erp_operation_natures', 'erp_operation_nature_cfop_choices',
            'erp_operation_nature_product_exceptions', 'erp_sales', 'erp_sale_lines', 'erp_stock_movements',
            'erp_stock_lots', 'erp_stock_allocations', 'erp_stock_balances', 'erp_stock_cost_settlements', 'erp_obligations', 'erp_payments',
            'erp_operation_receipts', 'erp_catalog_attributions', 'erp_commercial_number_sequences',
            'erp_fiscal_configurations', 'erp_fiscal_configuration_revisions', 'erp_fiscal_documents', 'erp_fiscal_attempts'] as $table) {
            $rows = DB::table($table)->orderBy('id')->get();
            $this->retainedRows[$table] = [$rows->pluck('id')->all(), hash('sha256', $rows->toJson())];
        }
        $tag = (string) Str::uuid();
        $this->directory = sys_get_temp_dir().'/opmoni-tax-races-'.$tag;
        mkdir($this->directory, 0700);
        $this->ip = '127.'.random_int(1, 254).'.'.random_int(1, 254).'.'.random_int(1, 254);
        $this->company = ErpCompany::factory()->create(['name' => 'Tax races '.$tag,
            'cnpj' => (string) random_int(10000000000000, 99999999999999),
            'operating_settings' => ['regime' => '3', 'accounting_method' => 'fifo', 'stock_overselling_enabled' => false]]);
        $this->owner = User::factory()->create(['email' => 'tax-races-'.$tag.'@example.test']);
        $this->membership = ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id,
            'user_id' => $this->owner->id, 'role' => 'owner']);
        ErpSubscription::factory()->active()->create(['erp_company_id' => $this->company->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id]);
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NF-e 55 posted sale'][2];
        $this->establishment = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id,
            'cnpj' => $source['issuer']['cnpj']]);
        $this->customer = ErpContact::factory()->create(['erp_company_id' => $this->company->id,
            'name' => $source['recipient']['legal_name'], 'tax_id' => $source['recipient']['cnpj'],
            'address' => $source['recipient']['address'], 'is_customer' => true]);
        $unit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id, 'short_name' => 'UN',
            'allow_decimal' => false]);
        for ($index = 0; $index < 2; $index++) {
            $product = ErpProduct::factory()->create(['erp_company_id' => $this->company->id, 'erp_unit_id' => $unit->id,
                'name' => 'Tax race goods '.$tag.' '.$index, 'sku' => 'TAX-'.$tag.'-'.$index,
                'kind' => 'goods', 'track_stock' => true, 'sale_price' => '10.0000', 'ncm' => '12345678', 'origin_code' => '0']);
            $this->products[] = $product;
            $this->variants[] = ErpProductVariant::factory()->create(['erp_product_id' => $product->id, 'sale_price' => '10.0000']);
        }
        $this->consumerSession = $this->taxLogin();
        $this->writerSession = $this->taxLogin();
        $this->thirdSession = $this->taxLogin();
        $this->assertCount(3, array_unique([$this->consumerSession['cookies']['opmoni-session'],
            $this->writerSession['cookies']['opmoni-session'], $this->thirdSession['cookies']['opmoni-session']]));
        $applicability = ['tax_regime' => '3', 'kind' => 'goods', 'movement' => 'out'];
        $this->profileId = $this->taxData($this->taxRequest($this->taxInput(self::PROFILES, [
            'name' => 'Selected '.$tag, 'fields' => ['icms_cst' => '00', 'pis_cst' => '07', 'icms_rate' => '0.0000'],
            'applicability' => $applicability,
        ], $this->writerSession)), 201)['id'];
        $this->defaultProfileId = $this->taxData($this->taxRequest($this->taxInput(self::PROFILES, [
            'name' => 'Default '.$tag, 'fields' => ['cofins_cst' => '07', 'cofins_rate' => '0.0000'],
            'applicability' => $applicability,
        ], $this->writerSession)), 201)['id'];
        $this->replacementProfileId = $this->taxData($this->taxRequest($this->taxInput(self::PROFILES, [
            'name' => 'Replacement '.$tag, 'fields' => ['cofins_cst' => '08', 'cofins_rate' => '2.0000'],
            'applicability' => $applicability,
        ], $this->writerSession)), 201)['id'];
        $this->taxData($this->taxRequest($this->taxInput('/api/v1/erp/company/settings', [
            'default_product_tax_profile_id' => $this->defaultProfileId, 'expected_tax_default_revision' => 0,
        ], $this->writerSession, 'PATCH')), 200);
        foreach ($this->products as $product) {
            $this->taxData($this->taxRequest($this->taxInput('/api/v1/erp/products/'.$product->id, [
                'erp_product_tax_profile_id' => $this->profileId,
                'tax_field_intents' => array_fill_keys(['icms_cst', 'pis_cst', 'cofins_cst', 'icms_rate', 'cofins_rate'], 'inherit'),
            ], $this->writerSession, 'PATCH')), 200);
        }
        $this->natureId = $this->taxData($this->taxRequest($this->taxInput(self::NATURES, [
            'description' => 'Tax race nature '.$tag, 'cfop' => '5102', 'movement' => 'out',
            'allowed_cfops' => ['5102', '5101', '5103'], 'expected_revision' => 0,
        ], $this->writerSession)), 201)['id'];
        $this->choices = ErpOperationNature::findOrFail($this->natureId)->cfopChoices()->pluck('id', 'cfop')->all();
        $this->exceptionId = $this->taxData($this->taxRequest($this->taxInput(self::NATURES.'/'.$this->natureId.'/product-exceptions', [
            'erp_product_id' => $this->products[0]->id, 'erp_operation_nature_cfop_choice_id' => $this->choices['5101'],
            'expected_revision' => 1,
        ], $this->writerSession)), 201)['id'];
        $address = $source['issuer']['address'];
        $this->taxData($this->taxRequest($this->taxInput('/api/v1/erp/fiscal/configuration/'.$this->establishment->id, [
            'environment' => 'homologation',
            'issuer' => ['tax_regime' => 3, 'state_registration' => $source['issuer']['state_registration'], 'uf' => $address['uf'],
                'address' => ['street' => $address['street'], 'number' => $address['number'], 'district' => $address['district'],
                    'city_code' => $address['city_code'], 'city_name' => $address['city'], 'zip_code' => $address['postal_code']]],
            'models' => ['55' => ['series' => 1, 'next_number' => 1, 'operation_nature_id' => $this->natureId],
                '65' => ['series' => 1, 'next_number' => 1, 'operation_nature_id' => $this->natureId,
                    'nfce_qr' => ['version' => 3, 'mode' => 'online', 'consultation_url' => 'https://test.invalid/consulta']]],
        ], $this->writerSession, 'PATCH')), 200);
        foreach ($this->variants as $variant) {
            $this->taxData($this->taxRequest($this->taxInput('/api/v1/erp/stock/adjustments', [
                'erp_establishment_id' => $this->establishment->id, 'erp_product_variant_id' => $variant->id,
                'quantity_delta' => '10.0000', 'reason' => 'Retained task10 stock fixture '.$tag,
            ], $this->writerSession)), 201);
        }
        fwrite(STDERR, 'Retained task10.2/10.4 Company '.$this->company->id.' source '.$this->identity['checkout'].PHP_EOL);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($this->directory);
        }
        foreach ($this->retainedRows as $table => [$ids, $hash]) {
            $this->assertSame($hash, hash('sha256', DB::table($table)->whereIn('id', $ids)->orderBy('id')->get()->toJson()),
                'Pre-existing rows must remain unchanged: '.$table);
        }
        parent::tearDown();
    }

    protected function taxInput(string $path, array $body, array $session, string $method = 'POST', ?string $key = null): array
    {
        return [...$session, 'path' => $path, 'method' => $method, 'body' => $body,
            'headers' => [...$session['headers'], 'Idempotency-Key' => $key ?? 'tax-race-'.++$this->sequence]];
    }

    private function taxWorker(array $input): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Integration/ErpCommercialTaxRaceWorker.php')], base_path());
        $worker->setInput(json_encode(['ip' => $this->ip, 'company_id' => $this->company->id,
            'label' => 'tax10-'.bin2hex(random_bytes(8)), ...$input, 'source_hashes' => $this->identity['source_hashes']], JSON_THROW_ON_ERROR));
        $worker->setTimeout(50);
        $this->workers[] = $worker;
        $worker->start();

        return $worker;
    }

    private function taxWorkerResponse(Process $worker): array
    {
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), 'Harness worker exit='.$worker->getExitCode().'; stderr bytes='.strlen($worker->getErrorOutput()));
        $response = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $response['transaction_level']);
        $this->assertSame($this->identity['checkout'], $response['checkout']);
        $this->assertSame($this->identity['source_hashes'], $response['source_hashes']);
        $this->assertNotSame($this->identity['pid'], $response['pid']);
        $this->assertNotSame($this->identity['database_pid'], $response['database_pid']);

        return $response;
    }

    protected function taxRequest(array $input): array
    {
        return $this->taxWorkerResponse($this->taxWorker($input));
    }

    protected function taxBodyAtStatus(array $response, int $expected): array
    {
        $safeError = array_intersect_key($response['body'] ?? [], array_flip(['code', 'message', 'errors']));
        $this->assertSame($expected, $response['status'], json_encode($safeError, JSON_THROW_ON_ERROR));

        return $response['body'];
    }

    protected function taxData(array $response, int $expected = 200): array
    {
        return $this->taxBodyAtStatus($response, $expected)['data'];
    }

    protected function taxLogin(): array
    {
        $csrf = $this->taxRequest(['path' => '/api/v1/csrf']);
        $this->taxBodyAtStatus($csrf, 200);
        $login = $this->taxRequest(['path' => '/api/v1/login', 'method' => 'POST', 'cookies' => $csrf['cookies'],
            'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']],
            'body' => ['email' => $this->owner->email, 'password' => 'password']]);
        $this->taxBodyAtStatus($login, 200);
        $session = ['cookies' => array_replace($csrf['cookies'], $login['cookies']), 'headers' => ['X-Company-Id' => (string) $this->company->id]];
        $csrf = $this->taxRequest([...$session, 'path' => '/api/v1/csrf']);
        $this->taxBodyAtStatus($csrf, 200);
        $session['cookies'] = array_replace($session['cookies'], $csrf['cookies']);
        $session['headers']['X-CSRF-Token'] = $csrf['body']['csrf_token'];
        $switch = $this->taxRequest($this->taxInput('/api/v1/erp/company/switch', ['company_id' => $this->company->id], $session));
        $this->taxBodyAtStatus($switch, 200);
        $session['cookies'] = array_replace($session['cookies'], $switch['cookies']);
        $csrf = $this->taxRequest([...$session, 'path' => '/api/v1/csrf']);
        $this->taxBodyAtStatus($csrf, 200);
        $session['cookies'] = array_replace($session['cookies'], $csrf['cookies']);
        $session['headers']['X-CSRF-Token'] = $csrf['body']['csrf_token'];

        return $session;
    }

    private function taxReady(Process $worker, string $base): array
    {
        $deadline = microtime(true) + 20;
        do {
            clearstatcache(true, $base.'.ready');
            if (is_file($base.'.ready')) {
                $identity = json_decode(file_get_contents($base.'.ready'), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($worker->getPid(), $identity['pid']);
                $this->assertNotSame($this->identity['database_pid'], $identity['database_pid']);
                $this->assertSame($this->identity['source_hashes'], $identity['source_hashes']);

                return $identity;
            }
            if (! $worker->isRunning()) {
                $this->fail('Harness barrier not reached; worker exit='.$worker->getExitCode().'; stderr bytes='.strlen($worker->getErrorOutput()));
            }
            usleep(5000);
        } while (microtime(true) < $deadline);
        $this->fail('Harness barrier timed out; this is not a product-contract RED.');
    }

    private function taxAssertBlocked(array $identities, int $holder): void
    {
        $pids = array_column($identities, 'database_pid');
        $slots = implode(',', array_fill(0, count($pids), '?'));
        $deadline = microtime(true) + 1.25;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $rows = DB::select("WITH RECURSIVE chain(root, pid) AS (
                SELECT pid, unnest(pg_blocking_pids(pid)) FROM pg_stat_activity WHERE pid IN ({$slots})
                UNION SELECT chain.root, unnest(pg_blocking_pids(chain.pid)) FROM chain
            ) SELECT activity.pid, activity.wait_event_type, activity.query,
                EXISTS (SELECT 1 FROM chain WHERE root = activity.pid AND chain.pid = ?) AS holder_blocks
                FROM pg_stat_activity activity WHERE activity.pid IN ({$slots})", [...$pids, $holder, ...$pids]);
            if (count($rows) === count($pids) && collect($rows)->every(fn ($row): bool => $row->wait_event_type === 'Lock'
                && in_array($row->holder_blocks, [true, 1, '1', 't'], true)
                && str_contains($row->query, 'erp_companies') && str_contains(strtolower($row->query), 'for update'))) {
                fwrite(STDERR, 'Observed tax race PG blockers '.json_encode(['holder' => $holder, 'waiters' => $pids], JSON_THROW_ON_ERROR).PHP_EOL);

                return;
            }
            usleep(5000);
        } while (microtime(true) < $deadline);
        $this->fail('Harness did not observe every real Company lock chain inside the native 2s budget.');
    }

    /** @return array{array<string, mixed>, list<array<string, mixed>>} */
    protected function taxOrderedRace(array $holderInput, array $hold, array $waitingInputs, ?Closure $whileHeld = null): array
    {
        $tag = bin2hex(random_bytes(6));
        $gate = $this->directory.'/'.$tag.'-holder-company';
        $held = $this->directory.'/'.$tag.'-holder-write';
        $holder = $this->taxWorker([...$holderInput, 'company_gate' => $gate, 'hold' => [...$hold, 'barrier' => $held]]);
        $holderIdentity = $this->taxReady($holder, $gate);
        touch($gate.'.release');
        $heldIdentity = $this->taxReady($holder, $held);
        $this->assertSame($holderIdentity['database_pid'], $heldIdentity['database_pid']);
        $this->assertSame($this->company->id, $heldIdentity['company_id']);
        $this->assertGreaterThan(0, $heldIdentity['transaction_level']);
        $workers = $identities = $gates = [];
        try {
            foreach ($waitingInputs as $index => $input) {
                $base = $this->directory.'/'.$tag.'-waiter-'.$index;
                $gates[] = $base;
                $workers[] = $this->taxWorker([...$input, 'company_gate' => $base]);
            }
            foreach ($workers as $index => $worker) {
                $identities[] = $this->taxReady($worker, $gates[$index]);
            }
            $all = [$holderIdentity, ...$identities];
            $this->assertCount(count($all), array_unique(array_column($all, 'pid')));
            $this->assertCount(count($all), array_unique(array_column($all, 'database_pid')));
            foreach ($gates as $base) {
                touch($base.'.release');
            }
            $this->taxAssertBlocked($identities, $holderIdentity['database_pid']);
            fwrite(STDERR, 'Observed tax race processes '.json_encode([
                'parent' => array_intersect_key($this->identity, ['pid' => true, 'database_pid' => true]),
                'holder' => array_intersect_key($holderIdentity, ['pid' => true, 'database_pid' => true]),
                'waiters' => array_map(fn (array $identity): array => array_intersect_key($identity, ['pid' => true, 'database_pid' => true]), $identities),
            ], JSON_THROW_ON_ERROR).PHP_EOL);
            if ($whileHeld !== null) {
                $whileHeld();
            }
            touch($held.'.release');

            return [$this->taxWorkerResponse($holder), array_map(fn (Process $worker): array => $this->taxWorkerResponse($worker), $workers)];
        } finally {
            touch($held.'.release');
            foreach ($gates as $base) {
                touch($base.'.release');
            }
            foreach ([$holder, ...$workers] as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
        }
    }

    protected function taxSaleBody(string $model = '55'): array
    {
        return ['erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $this->customer->id,
            'erp_operation_nature_id' => $this->natureId, 'currency' => 'BRL', 'fiscal_model' => $model,
            'tax_context' => ['origin_uf' => 'SP', 'destination_uf' => 'SP', 'model' => $model, 'purpose' => 'normal', 'final_consumer' => true, 'presence' => '1'],
            'lines' => array_map(fn ($variant): array => ['erp_product_variant_id' => $variant->id, 'quantity' => '1.0000', 'unit_price' => '10.0000'], $this->variants)];
    }

    protected function taxCreateSale(string $model = '55'): int
    {
        $data = $this->taxData($this->taxRequest($this->taxInput('/api/v1/erp/sales', $this->taxSaleBody($model), $this->consumerSession)), 201);
        $sale = ErpSale::findOrFail($data['id']);
        $this->assertSame('source_prerequisites_checked', $sale->commercial_snapshot['fiscal_context']['readiness']);

        return $data['id'];
    }

    protected function taxPreview(int $id): array
    {
        return $this->taxData($this->taxRequest($this->taxInput('/api/v1/erp/sales/'.$id.'/tax-refresh-preview',
            ['draft_revision' => ErpSale::findOrFail($id)->draft_revision], $this->consumerSession)));
    }

    protected function taxRefreshInput(int $id, array $preview, string $key): array
    {
        return $this->taxInput('/api/v1/erp/sales/'.$id, ['draft_revision' => $preview['draft_revision'],
            'tax_refresh' => ['reviewed_fingerprint' => $preview['reviewed_fingerprint']]], $this->consumerSession, 'PATCH', $key);
    }

    protected function taxPostInput(int $id, string $key): array
    {
        return $this->taxInput('/api/v1/erp/sales/'.$id.'/post', ['draft_revision' => ErpSale::findOrFail($id)->draft_revision], $this->consumerSession, 'POST', $key);
    }

    protected function taxProfileWriter(string $family, ?array $session = null): array
    {
        $session ??= $this->writerSession;
        if ($family === 'profile') {
            $profile = ErpProductTaxProfile::findOrFail($this->profileId);

            return [$this->taxInput(self::PROFILES.'/'.$profile->id, ['expected_revision' => $profile->current_revision,
                'fields' => ['pis_cst' => '09', 'icms_rate' => '18.0000']], $session, 'PATCH'), ['kind' => 'profile', 'id' => $profile->id]];
        }
        $default = ErpCompanyProductTaxDefault::where('erp_company_id', $this->company->id)->sole();

        return [$this->taxInput('/api/v1/erp/company/settings', ['default_product_tax_profile_id' => $this->replacementProfileId,
            'expected_tax_default_revision' => $default->revision], $session, 'PATCH'), ['kind' => 'default', 'id' => $default->id]];
    }

    protected function taxNatureWriter(string $family, ?array $session = null): array
    {
        $session ??= $this->writerSession;
        $revision = ErpOperationNature::findOrFail($this->natureId)->configuration_revision;
        [$path, $method, $body] = match ($family) {
            'default' => [self::NATURES.'/'.$this->natureId, 'PATCH', ['cfop' => '5103', 'expected_revision' => $revision]],
            'choice' => [self::NATURES.'/'.$this->natureId.'/choices/'.$this->choices['5102'], 'DELETE', ['cfop' => '5103', 'expected_revision' => $revision]],
            'exception' => [self::NATURES.'/'.$this->natureId.'/product-exceptions/'.$this->exceptionId, 'PATCH',
                ['erp_operation_nature_cfop_choice_id' => $this->choices['5103'], 'expected_revision' => $revision]],
            'legacy' => [self::NATURES.'/'.$this->natureId, 'PATCH', ['cfop' => '5104']],
        };

        return [$this->taxInput($path, $body, $session, $method), ['kind' => 'nature', 'id' => $this->natureId]];
    }

    protected function taxCommittedVector(): array
    {
        $profile = ErpProductTaxProfile::findOrFail($this->profileId);
        $default = ErpCompanyProductTaxDefault::where('erp_company_id', $this->company->id)->sole();
        $defaultProfile = ErpProductTaxProfile::findOrFail($default->erp_product_tax_profile_id);
        $nature = ErpOperationNature::findOrFail($this->natureId);
        $exception = $nature->productExceptions()->where('erp_product_id', $this->products[0]->id)->where('status', 'active')->first();

        return ['profile' => $profile->current_revision, 'default' => $default->revision,
            'default_profile' => $defaultProfile->id, 'default_profile_revision' => $defaultProfile->current_revision,
            'nature' => $nature->configuration_revision, 'default_cfop' => $nature->cfop,
            'effective' => [$exception === null ? $nature->cfop : $nature->cfopChoices()->findOrFail($exception->erp_operation_nature_cfop_choice_id)->cfop, $nature->cfop]];
    }

    protected function taxAssertCoherent(int $id, array $vector): void
    {
        $sale = ErpSale::findOrFail($id)->load('lines');
        $this->assertCount(2, $sale->lines);
        $this->assertSame('20.0000', $sale->total_amount);
        $this->assertSame('0.0000', $sale->tax_amount, 'Classification profile rates must not create commercial tax amounts.');
        $selected = ErpProductTaxProfileRevision::where('erp_product_tax_profile_id', $this->profileId)->where('revision', $vector['profile'])->sole();
        $fallback = ErpProductTaxProfileRevision::where('erp_product_tax_profile_id', $vector['default_profile'])->where('revision', $vector['default_profile_revision'])->sole();
        foreach ($sale->lines as $index => $line) {
            $facts = $line->product_snapshot;
            $tax = $facts['product_tax_resolution'];
            $cfop = $facts['cfop_resolution'];
            $this->assertSame($vector['profile'], $tax['profile']['revision']);
            $this->assertSame($selected->id, $tax['profile']['revision_id']);
            $this->assertSame($vector['default'], $tax['company_default']['revision']);
            $this->assertSame($vector['default_profile'], $tax['company_default']['profile_id']);
            $this->assertSame($vector['default_profile_revision'], $tax['company_default']['profile_revision']);
            foreach (['icms_cst', 'pis_cst', 'icms_rate'] as $field) {
                $this->assertSame($selected->fields[$field], $tax['values'][$field]);
                $this->assertSame($tax['values'][$field], $facts['product'][$field]);
                $this->assertSame($selected->revision, $tax['sources'][$field]['revision']);
            }
            foreach (['cofins_cst', 'cofins_rate'] as $field) {
                $this->assertSame($fallback->fields[$field], $tax['values'][$field]);
                $this->assertSame($tax['values'][$field], $facts['product'][$field]);
                $this->assertSame('company_profile', $tax['sources'][$field]['source']);
            }
            $this->assertSame($vector['nature'], $cfop['configuration_revision']);
            $this->assertSame($this->natureId, $cfop['nature_id']);
            $this->assertSame($vector['default_cfop'], $cfop['default_cfop']);
            $this->assertSame($vector['effective'][$index], $cfop['effective_cfop']);
            $this->assertSame($index === 0 ? 'product_exception' : 'nature_default', $cfop['source']);
            $this->assertSame($index === 0 ? $this->exceptionId : null, $cfop['exception_id']);
            $this->assertSame($cfop['effective_cfop'], $facts['operation_nature']['cfop']);
            $this->assertSame($cfop['context_fingerprint'], hash('sha256', json_encode($cfop['context'], JSON_THROW_ON_ERROR)));
            $unsignedTax = $tax;
            unset($unsignedTax['fingerprint']);
            $this->assertSame($tax['fingerprint'], hash('sha256', json_encode($unsignedTax, JSON_THROW_ON_ERROR)));
            $unsignedCfop = $cfop;
            unset($unsignedCfop['fingerprint'], $unsignedCfop['explicit_choice']);
            $this->assertSame($cfop['fingerprint'], hash('sha256', json_encode($unsignedCfop, JSON_THROW_ON_ERROR)));
            $this->assertSame($line->erp_product_variant_id, $facts['variant']['id']);
            $this->assertSame($this->products[$index]->id, $tax['product_id']);
            $this->assertSame('10.0000', $line->line_total);
        }
    }

    protected function taxBusinessState(): array
    {
        $state = [];
        foreach (['erp_stock_movements', 'erp_stock_balances', 'erp_stock_lots', 'erp_stock_allocations', 'erp_stock_cost_settlements',
            'erp_obligations', 'erp_payments', 'erp_fiscal_documents', 'erp_fiscal_attempts'] as $table) {
            $state[$table] = DB::table($table)->where('erp_company_id', $this->company->id)->orderBy('id')->get()->toJson();
        }

        return $state;
    }

    protected function taxAssertPostedOnce(int $id, array $input, array $original): void
    {
        $before = $this->taxBusinessState();
        $this->assertSame($original['body'], $this->taxBodyAtStatus($this->taxRequest($input), 200));
        $lookup = $this->taxRequest([...$this->consumerSession, 'path' => '/api/v1/erp/operations/lookup?operation=erp.sales.post',
            'headers' => [...$this->consumerSession['headers'], 'Idempotency-Key' => $input['headers']['Idempotency-Key']]]);
        $this->assertSame($id, $this->taxData($lookup)['result']['id']);
        $this->assertSame($before, $this->taxBusinessState());
        $this->assertSame(2, DB::table('erp_stock_movements')->where('erp_company_id', $this->company->id)->where('source_type', 'erp_sale')->where('source_id', $id)->count());
        $issues = DB::table('erp_stock_movements')->where('erp_company_id', $this->company->id)->where('source_type', 'erp_sale')->where('source_id', $id)->pluck('id')->all();
        $allocations = DB::table('erp_stock_allocations')->where('erp_company_id', $this->company->id)->whereIn('erp_stock_movement_id', $issues)->get();
        $this->assertCount(2, $allocations);
        $this->assertSame('2.0000', $allocations->reduce(fn (string $sum, $row): string => bcadd($sum, (string) $row->quantity, 4), '0.0000'));
        foreach ($this->variants as $variant) {
            $this->assertSame('9.0000', bcadd((string) DB::table('erp_stock_balances')->where('erp_company_id', $this->company->id)
                ->where('erp_establishment_id', $this->establishment->id)->where('erp_product_variant_id', $variant->id)->value('quantity_on_hand'), '0', 4));
        }
        $this->assertSame(1, DB::table('erp_obligations')->where('erp_company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('erp_payments')->where('erp_company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->where('operation', 'erp.sales.post')->count());
        foreach (['erp_fiscal_documents', 'erp_fiscal_attempts'] as $table) {
            $this->assertSame(0, DB::table($table)->where('erp_company_id', $this->company->id)->count());
        }
    }

    protected function taxRecoverAndPost(int $id, string $postKey): void
    {
        $preview = $this->taxPreview($id);
        $this->taxData($this->taxRequest($this->taxRefreshInput($id, $preview, 'recover-'.$postKey)));
        $this->taxAssertCoherent($id, $this->taxCommittedVector());
        $this->taxPostCurrent($id, $postKey);
    }

    protected function taxPostCurrent(int $id, string $postKey): void
    {
        $post = $this->taxPostInput($id, $postKey);
        $result = $this->taxRequest($post);
        $this->taxData($result);
        $this->taxAssertPostedOnce($id, $post, $result);
    }

    protected function taxAssertNatureInvariants(): void
    {
        $nature = ErpOperationNature::findOrFail($this->natureId);
        $active = $nature->cfopChoices()->where('status', 'active')->get();
        $this->assertContains($nature->cfop, $active->pluck('cfop')->all());
        $this->assertCount($active->count(), array_unique($active->pluck('cfop')->all()));
        $exceptions = $nature->productExceptions()->where('status', 'active')->get();
        $this->assertCount($exceptions->count(), array_unique($exceptions->pluck('erp_product_id')->all()));
        foreach ($exceptions as $exception) {
            $this->assertSame($this->company->id, $exception->erp_company_id);
            $this->assertContains($exception->erp_operation_nature_cfop_choice_id, $active->pluck('id')->all());
        }
    }
}
