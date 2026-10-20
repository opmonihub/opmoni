<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Erp\Fiscal\ErpFiscalPayloadBuilder;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpCommercialWorkflowConcurrencyTest extends TestCase
{
    private ErpCompany $company;

    private ErpEstablishment $establishment;

    private ErpProductVariant $variant;

    private array $first;

    private array $second;

    private array $baseline = [];

    private int $sequence = 0;

    protected function setUp(): void
    {
        if (getenv('ERP_COMMERCIAL_ISOLATED') !== 'task9-20261020') {
            throw new \RuntimeException('Dedicated marker required before fixtures.');
        }
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('opmoni_test', config('database.connections.pgsql.database'));
        $this->assertSame('opmoni_test', DB::selectOne('select current_database() as name')->name);
        $this->assertSame('opmoni-access-test-', config('database.redis.options.prefix'));
        foreach (['default' => '13', 'cache' => '14', 'sessions' => '15'] as $name => $database) {
            $this->assertSame($database, (string) config('database.redis.'.$name.'.database'));
            $this->assertSame($database, (string) Redis::connection($name)->client()->getDBNum());
        }
        $this->assertStringStartsWith('18.', DB::selectOne('show server_version')->server_version);
        $this->assertStringStartsWith('8.', Redis::connection('cache')->info('server')['redis_version']);
        foreach (['accounts', 'account_memberships', 'erp_plans', 'erp_plan_modules', 'erp_subscriptions', 'erp_office_delegations', 'users', 'erp_companies', 'erp_company_memberships', 'erp_company_membership_establishments', 'erp_establishments', 'erp_contacts', 'erp_customer_groups', 'erp_price_groups', 'erp_units', 'erp_products', 'erp_product_variants', 'erp_tax_rates', 'erp_operation_natures', 'erp_discount_rules', 'erp_quotes', 'erp_quote_lines', 'erp_orders', 'erp_order_lines', 'erp_sales', 'erp_sale_lines', 'erp_sale_returns', 'erp_sale_return_lines', 'erp_purchases', 'erp_purchase_lines', 'erp_purchase_imports', 'erp_purchase_returns', 'erp_purchase_return_lines', 'erp_commercial_number_sequences', 'erp_catalog_imports', 'erp_stock_adjustments', 'erp_stock_transfers', 'erp_stock_balances', 'erp_stock_movements', 'erp_stock_lots', 'erp_stock_allocations', 'erp_stock_cost_settlements', 'erp_obligations', 'erp_payments', 'erp_payment_allocations', 'erp_financial_accounts', 'erp_cash_registers', 'erp_cash_sessions', 'erp_cash_movements', 'erp_operation_receipts'] as $table) {
            $column = $table === 'erp_company_membership_establishments' ? 'erp_company_membership_id' : 'id';
            $ids = DB::table($table)->pluck($column)->all();
            $rows = DB::table($table)->whereIn($column, $ids)->orderBy($column);
            if ($column !== 'id') {
                $rows->orderBy('erp_establishment_id');
            }
            $this->baseline[$table] = [$column, $ids, hash('sha256', $rows->get()->toJson())];
        }
        $this->company = ErpCompany::factory()->create(['cnpj' => (string) random_int(10000000000000, 99999999999999)]);
        ErpSubscription::factory()->create(['erp_company_id' => $this->company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id, 'status' => 'active']);
        $this->establishment = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $unit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id]);
        $product = ErpProduct::factory()->create(['erp_company_id' => $this->company->id, 'erp_unit_id' => $unit->id, 'kind' => 'goods', 'track_stock' => true, 'sale_price' => '10']);
        $this->variant = ErpProductVariant::factory()->create(['erp_product_id' => $product->id, 'sale_price' => '10']);
        $this->first = $this->login();
        $this->second = $this->login();
        $this->assertNotSame($this->first['cookies']['opmoni-session'], $this->second['cookies']['opmoni-session']);
    }

    protected function tearDown(): void
    {
        foreach ($this->baseline as $table => [$column,$ids,$hash]) {
            $rows = DB::table($table)->whereIn($column, $ids)->orderBy($column);
            if ($column !== 'id') {
                $rows->orderBy('erp_establishment_id');
            }
            $this->assertSame($hash, hash('sha256', $rows->get()->toJson()), 'Retained original rows: '.$table);
        }
        if (isset($this->company)) {
            fwrite(STDERR, 'Retained Task9 synthetic company '.$this->company->id.' establishment '.$this->establishment->id."\n");
        }
        parent::tearDown();
    }

    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Integration/ErpCommercialConcurrencyWorker.php')], base_path());
        $input['client_ip'] = '10.89.'.intdiv($this->company->id % 60000, 250).'.'.($this->company->id % 250 + 1);
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function response(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $result['transaction_level']);

        return $result;
    }

    private function login(): array
    {
        $user = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id, 'user_id' => $user->id, 'role' => 'owner']);
        $csrf = $this->response($this->worker(['path' => '/api/v1/csrf']));
        $login = $this->response($this->worker(['path' => '/api/v1/login', 'method' => 'POST', 'cookies' => $csrf['cookies'], 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']], 'body' => ['email' => $user->email, 'password' => 'password']]));
        $this->assertSame(200, $login['status']);
        $csrf = $this->response($this->worker(['path' => '/api/v1/csrf', 'cookies' => $login['cookies']]));

        return ['cookies' => $csrf['cookies'] ?: $login['cookies'], 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token'], 'X-Company-Id' => (string) $this->company->id]];
    }

    private function input(string $path, array $body, array $session, ?string $key = null): array
    {
        return ['path' => '/api/v1/erp/'.$path, 'method' => 'POST', 'body' => $body, 'cookies' => $session['cookies'], 'headers' => $session['headers'] + ['Idempotency-Key' => $key ?? 'commercial-'.++$this->sequence]];
    }

    private function send(string $path, array $body, int $status = 201): array
    {
        $result = $this->response($this->worker($this->input($path, $body, $this->first)));
        $this->assertSame($status, $result['status'], json_encode($result['body']));

        return $result['body']['data'];
    }

    private function race(array $a, array $b): array
    {
        $suffix = bin2hex(random_bytes(5));
        $workers = [];
        $markers = [];
        DB::beginTransaction();
        DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
        try {
            foreach ([$a, $b] as $index => $input) {
                $label = 'erp-commercial-'.$suffix.'-'.$index;
                $marker = tempnam(sys_get_temp_dir(), 'erp-commercial-');
                unlink($marker);
                $markers[] = $marker;
                $workers[] = $this->worker($input + ['label' => $label, 'ready' => $marker]);
                $deadline = microtime(true) + 10;
                do {
                    DB::select('select pg_stat_clear_snapshot()');
                    $row = DB::selectOne("select pid from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'", [$label]);
                    if ($row && file_exists($marker) && (int) file_get_contents($marker) === (int) $row->pid) {
                        fwrite(STDERR, 'Company lock barrier '.$label.' backend PID '.$row->pid."\n");
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertNotNull($row, 'Worker must wait on actual Company lock');
                $this->assertFileExists($marker);
            }
            DB::commit();
            $results = array_map(fn ($worker) => $this->response($worker), $workers);
            $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
            $this->assertNotSame($results[0]['backend_pid'], $results[1]['backend_pid']);
            foreach ($results as $result) {
                fwrite(STDERR, json_encode(array_diff_key($result, ['cookies' => true]), JSON_THROW_ON_ERROR)."\n");
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            foreach ($markers as $marker) {
                @unlink($marker);
            }
        }
    }

    private function service(string $kind = 'sales'): array
    {
        return $this->send($kind, ['erp_establishment_id' => $this->establishment->id, 'lines' => [['description' => 'Synthetic task9 service', 'quantity' => '3', 'unit_price' => '10']]]);
    }

    public function test_same_draft_posts_once_under_independent_authenticated_sessions(): void
    {
        $sale = $this->service();
        $body = ['draft_revision' => 1];
        $results = $this->race($this->input('sales/'.$sale['id'].'/post', $body, $this->first), $this->input('sales/'.$sale['id'].'/post', $body, $this->second));
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, ErpObligation::where('source_type', 'erp_sale')->where('source_id', $sale['id'])->count());
        $this->assertSame(1, DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->where('operation', 'erp.sales.post')->count());
    }

    public function test_sales_compete_for_the_final_stock_without_double_issue(): void
    {
        $supplier = ErpContact::factory()->create(['erp_company_id' => $this->company->id, 'is_supplier' => true]);
        $purchase = $this->send('purchases', ['erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $supplier->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '1', 'unit_cost' => '7']]]);
        $this->send('purchases/'.$purchase['id'].'/post', [], 200);
        $body = ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '1']]];
        $a = $this->send('sales', $body);
        $b = $this->send('sales', $body);
        $results = $this->race($this->input('sales/'.$a['id'].'/post', ['draft_revision' => 1], $this->first), $this->input('sales/'.$b['id'].'/post', ['draft_revision' => 1], $this->second));
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 422], $statuses);
        $this->assertSame('0.0000', ErpStockBalance::where('erp_company_id', $this->company->id)->firstOrFail()->quantity_on_hand);
        $this->assertSame(1, DB::table('erp_stock_movements')->where('erp_company_id', $this->company->id)->where('source_type', 'erp_sale')->count());
    }

    public function test_partial_returns_compete_after_prior_credit_and_never_over_return(): void
    {
        $sale = $this->service();
        $this->send('sales/'.$sale['id'].'/post', ['draft_revision' => 1], 200);
        $line = ErpSale::findOrFail($sale['id'])->lines()->firstOrFail()->id;
        $body = ['erp_sale_id' => $sale['id'], 'reason' => 'Synthetic partial', 'lines' => [['source_line_id' => $line, 'quantity' => '1']]];
        $prior = $this->send('sale-returns', $body);
        $this->send('sale-returns/'.$prior['id'].'/post', [], 200);
        $body['lines'][0]['quantity'] = '2';
        $a = $this->send('sale-returns', $body);
        $b = $this->send('sale-returns', $body);
        $results = $this->race($this->input('sale-returns/'.$a['id'].'/post', [], $this->first), $this->input('sale-returns/'.$b['id'].'/post', [], $this->second));
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 422], $statuses);
        $obligation = ErpObligation::where('source_type', 'erp_sale')->where('source_id', $sale['id'])->firstOrFail();
        $this->assertSame('30.0000', $obligation->open_amount);
        $this->assertSame('30.0000', bcadd((string) ErpObligation::where('return_of_id', $obligation->id)->sum('amount'), '0', 4));
        $this->assertSame('3.0000', bcadd((string) DB::table('erp_sale_return_lines')->join('erp_sale_returns', 'erp_sale_returns.id', '=', 'erp_sale_return_lines.erp_sale_return_id')->where('erp_sale_returns.erp_sale_id', $sale['id'])->where('erp_sale_returns.status', 'posted')->sum('quantity'), '0', 4));
    }

    public function test_competing_quote_and_order_conversions_create_one_child_each(): void
    {
        foreach (['quotes', 'orders'] as $kind) {
            $source = $this->service($kind);
            $results = $this->race($this->input($kind.'/'.$source['id'].'/convert', ['draft_revision' => 1], $this->first), $this->input($kind.'/'.$source['id'].'/convert', ['draft_revision' => 1], $this->second));
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame([200, 409], $statuses);
            $this->assertSame(1, DB::table($kind === 'quotes' ? 'erp_orders' : 'erp_sales')->where($kind === 'quotes' ? 'erp_quote_id' : 'erp_order_id', $source['id'])->count());
        }
    }

    public function test_auto_numbers_serialize_and_cash_post_races_close_in_a_valid_order(): void
    {
        $body = ['erp_establishment_id' => $this->establishment->id, 'lines' => [['description' => 'Number', 'quantity' => '1', 'unit_price' => '10']]];
        $results = $this->race($this->input('sales', $body, $this->first), $this->input('sales', $body, $this->second));
        $this->assertSame([201, 201], array_column($results, 'status'));
        $this->assertNotSame($results[0]['body']['data']['invoice_no'], $results[1]['body']['data']['invoice_no']);
        $sale = $results[0]['body']['data'];
        $register = $this->send('cash-registers', ['erp_establishment_id' => $this->establishment->id, 'name' => 'Task9']);
        $session = $this->send('cash-sessions', ['erp_cash_register_id' => $register['id'], 'currency' => 'BRL', 'opening_amount' => '0']);
        $pay = ['draft_revision' => 1, 'payment' => ['direction' => 'in', 'amount' => '10', 'method' => 'cash', 'erp_cash_session_id' => $session['id']]];
        $results = $this->race($this->input('sales/'.$sale['id'].'/post', $pay, $this->first), $this->input('cash-sessions/'.$session['id'].'/close', ['declared_closing_amount' => '0'], $this->second));
        $this->assertSame(200, $results[1]['status']);
        $this->assertContains($results[0]['status'], [200, 422]);
        $count = DB::table('erp_cash_movements')->where('erp_cash_session_id', $session['id'])->count();
        $this->assertSame($results[0]['status'] === 200 ? 1 : 0, $count);
        $closed = DB::table('erp_cash_sessions')->find($session['id']);
        $this->assertSame('closed', $closed->status);
        $this->assertSame($count === 1 ? '10.0000' : '0.0000', bcadd($closed->expected_closing_amount, '0', 4));
    }

    public function test_derived_unit_price_width_matches_nested_http_validation_on_postgresql(): void
    {
        $this->variant->update(['sale_price' => '9999999999999999']);
        $unit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id, 'base_unit_id' => $this->variant->product->erp_unit_id, 'conversion_factor' => '1000.00000000', 'allow_decimal' => true]);
        $body = ['erp_establishment_id' => $this->establishment->id, 'erp_price_group_id' => null, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'erp_unit_id' => $unit->id, 'quantity' => '0.0001']]];
        $result = $this->response($this->worker($this->input('sales', $body, $this->first, 'width-reject')));
        $this->assertSame(422, $result['status'], json_encode($result['body']));
        $this->assertArrayHasKey('lines.0.unit_price', $result['body']['errors']);
        foreach (['erp_sales', 'erp_commercial_number_sequences', 'erp_operation_receipts'] as $table) {
            $this->assertSame(0, DB::table($table)->where('erp_company_id', $this->company->id)->count());
        }
        $unit->update(['conversion_factor' => '100.00000000']);
        $valid = $this->send('sales', $body);
        $this->assertSame('99999999999999.9900', $valid['total_amount']);
        $saved = ErpSale::findOrFail($valid['id'])->lines()->firstOrFail();
        $this->assertSame('999999999999999900.0000', $saved->unit_price);
        $this->assertSame('0.0100', $saved->base_quantity);
        fwrite(STDERR, json_encode(['derived_width_invalid_status' => $result['status'], 'errors' => $result['body']['errors'], 'boundary_source_id' => $valid['id'], 'boundary_unit_price' => $saved->unit_price, 'boundary_total' => $valid['total_amount']], JSON_THROW_ON_ERROR)."\n");
    }

    public function test_independent_xml_confirm_race_creates_one_draft_for_a_company_access_key(): void
    {
        $this->establishment->update(['cnpj' => '30288513000100']);
        $supplier = ErpContact::factory()->create(['erp_company_id' => $this->company->id, 'tax_id' => '11222333000181', 'is_supplier' => true]);
        $key = app(ErpFiscalPayloadBuilder::class)->buildAccessKey(['uf' => 'SP', 'cnpj' => $supplier->tax_id, 'model' => '55', 'series' => 1, 'number' => 123, 'cnf' => '87654321', 'issued_at' => '2026-10-17T10:00:00-03:00']);
        $identification = '<cUF>'.substr($key, 0, 2).'</cUF><mod>'.substr($key, 20, 2).'</mod><serie>'.(int) substr($key, 22, 3).'</serie><nNF>'.(int) substr($key, 25, 9).'</nNF><tpEmis>'.substr($key, 34, 1).'</tpEmis><cNF>'.substr($key, 35, 8).'</cNF><cDV>'.substr($key, 43, 1).'</cDV>';
        $xml = '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"><NFe><infNFe Id="NFe'.$key.'"><ide>'.$identification.'</ide><emit><CNPJ>'.$supplier->tax_id.'</CNPJ><xNome>Race supplier</xNome></emit><dest><CNPJ>30288513000100</CNPJ><xNome>Race destination</xNome></dest><det nItem="1"><prod><cProd>RACE-1</cProd><xProd>Race goods</xProd><uCom>UN</uCom><qCom>3</qCom><vUnCom>0.3333333333</vUnCom><vProd>1.00</vProd></prod></det><total><ICMSTot><vProd>1.00</vProd><vNF>1.00</vNF></ICMSTot></total></infNFe></NFe></nfeProc>';
        $payload = ['erp_establishment_id' => $this->establishment->id, 'xml' => $xml];
        $firstPreviewResponse = $this->response($this->worker($this->input('purchases/xml-preview', $payload, $this->first)));
        $secondPreviewResponse = $this->response($this->worker($this->input('purchases/xml-preview', $payload, $this->second)));
        $this->assertSame(200, $firstPreviewResponse['status'], json_encode($firstPreviewResponse['body'], JSON_THROW_ON_ERROR));
        $this->assertSame(200, $secondPreviewResponse['status'], json_encode($secondPreviewResponse['body'], JSON_THROW_ON_ERROR));
        $firstPreview = $firstPreviewResponse['body']['data'];
        $secondPreview = $secondPreviewResponse['body']['data'];
        $items = [['line' => 1, 'erp_product_variant_id' => $this->variant->id, 'unit_conversion_factor' => '1.00000000']];
        $first = $this->input('purchases/xml-confirm', ['token' => $firstPreview['token'], 'erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $supplier->id, 'items' => $items], $this->first, 'purchase-xml-race-a');
        $second = $this->input('purchases/xml-confirm', ['token' => $secondPreview['token'], 'erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $supplier->id, 'items' => $items], $this->second, 'purchase-xml-race-b');
        $results = $this->race($first, $second);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, DB::table('erp_purchases')->where('erp_company_id', $this->company->id)->where('source_access_key', $key)->count());
        $this->assertSame(1, DB::table('erp_purchase_imports')->where('erp_company_id', $this->company->id)->whereNotNull('completed_at')->count());
        $this->assertSame(1, DB::table('erp_purchase_lines')->join('erp_purchases', 'erp_purchases.id', '=', 'erp_purchase_lines.erp_purchase_id')->where('erp_purchases.erp_company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('erp_stock_movements')->where('erp_company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('erp_obligations')->where('erp_company_id', $this->company->id)->count());
    }
}
