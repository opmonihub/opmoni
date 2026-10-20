<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpCatalogImportConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Import feature tests require SQLite memory before RefreshDatabase.');
        }
        parent::setUp();
        config(['access.ingress_key' => 'test-key', 'erp.operating_writes_enabled' => true, 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
    }

    private function actor(?ErpCompany $company = null, string $role = 'owner'): array
    {
        $company ??= ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        $user = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);
        ErpSubscription::firstOrCreate(['erp_company_id' => $company->id], ['erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id, 'status' => 'active']);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->withHeaders(['X-Company-Id' => (string) $company->id, 'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token')]);

        return [$company, $user];
    }

    private function preview(string $csv, string $kind = 'products')
    {
        return $this->post('/api/v1/erp/catalog/import/'.$kind.'/preview', ['file' => UploadedFile::fake()->createWithContent('import.csv', $csv)], ['Accept' => 'application/json']);
    }

    private function confirm(string $token, ?int $unit = null, string $key = 'import-key', string $kind = 'products')
    {
        return $this->postJson('/api/v1/erp/catalog/import/'.$kind.'/confirm', ['token' => $token] + ($unit === null ? [] : ['erp_unit_id' => $unit]), ['Idempotency-Key' => $key]);
    }

    public function test_invalid_preview_retains_all_records_and_blocks_every_effect(): void
    {
        [$company] = $this->actor();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $company->id]);
        $data = $this->preview("name,sku,sale_price\nGood,GOOD,1.0000\nBad,BAD,1.00001\n,EMPTY,2\n")->assertOk()->json('data');
        $this->assertSame(['records' => 3, 'valid' => 1, 'invalid' => 2], $data['totals']);
        $this->assertCount(3, $data['rows']);
        $this->assertContains('sale_price', array_column($data['errors'], 'field'));
        $this->confirm($data['token'], $unit->id)->assertUnprocessable();
        $this->assertDatabaseCount('erp_products', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
    }

    public function test_invalid_unit_can_be_corrected_with_same_token_and_key_and_creates_exact_default_variant(): void
    {
        [$company] = $this->actor();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $company->id]);
        $other = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $foreign = ErpUnit::factory()->create(['erp_company_id' => $other->id]);
        $token = $this->preview("name,sku,sale_price,barcode,ncm,kind,track_stock\n Serviço ,0,0001.2300,0000123,00123456,service,1\n")->assertOk()->json('data.token');
        $this->confirm($token, $foreign->id)->assertUnprocessable();
        $this->confirm($token, $unit->id)->assertExactJson(['data' => ['created' => 1]]);
        $product = ErpProduct::firstOrFail();
        $this->assertSame(' Serviço ', $product->name);
        $this->assertSame('0', $product->sku);
        $this->assertSame('00123456', $product->ncm);
        $this->assertFalse($product->track_stock);
        $variant = $product->variants()->sole();
        $this->assertSame('0', $variant->sku);
        $this->assertSame('0000123', $variant->barcode);
        $this->assertSame('C128', $variant->barcode_type);
        $this->assertSame('1.2300', $variant->sale_price);
    }

    public static function badCsv(): array
    {
        return [
            'duplicate' => ["name,Name\nA,B\n"], 'unknown' => ["name,cfop\nA,5102\n"],
            'required' => ["sku\nA\n"], 'extra' => ["name\nA,B\n"], 'missing' => ["name,sku\nA\n"],
            'unclosed' => ["name\n\"A\n"], 'afterquote' => ["name\n\"A\"x\n"], 'embeddedquote' => ["name\nA\"B\n"],
            'utf8' => ["name\n\xff\n"], 'nul' => ["name\nA\0B\n"], 'barecr' => ["name\rA\n"], 'empty' => ["name\n\n"],
        ];
    }

    #[DataProvider('badCsv')]
    public function test_malformed_csv_is_a_file_error_without_preview(string $csv): void
    {
        $this->actor();
        $this->preview($csv)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('erp_products', 0);
    }

    public function test_exact_record_and_actual_byte_boundaries_and_quoted_newlines(): void
    {
        $this->actor();
        $this->preview("name\n".str_repeat("A\n", 500))->assertOk()->assertJsonPath('data.totals.records', 500);
        $this->preview("name\n".str_repeat("A\n", 501))->assertUnprocessable();
        $csv = "name\nA\n";
        $csv .= str_repeat("\n", 524288 - strlen($csv));
        $this->assertSame(524288, strlen($csv));
        $this->preview($csv)->assertOk()->assertJsonPath('data.totals.records', 1);
        $this->preview($csv."\n")->assertUnprocessable();
        $this->preview("\xef\xbb\xbf NAME ,sku\r\n\"A,\n\"\"B\"\"\",00001\r\n")->assertOk()->assertJsonPath('data.rows.0.name', "A,\n\"B\"")->assertJsonPath('data.rows.0.sku', '00001');
    }

    public static function rollbackStatuses(): array
    {
        return [[422], [503]];
    }

    #[DataProvider('rollbackStatuses')]
    public function test_rendered_row_two_failure_rolls_back_rows_variants_receipt_and_completion(int $status): void
    {
        [$company] = $this->actor();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $company->id]);
        $token = $this->preview("name,sku\nFirst,ONE\nSecond,TWO\n")->assertOk()->json('data.token');
        ErpProduct::created(function (ErpProduct $row) use ($status): void {
            if ($row->name === 'Second') {
                abort($status, 'Injected row2');
            }
        });
        try {
            $this->confirm($token, $unit->id)->assertStatus($status);
        } finally {
            ErpProduct::flushEventListeners();
        }
        $this->assertDatabaseCount('erp_products', 0);
        $this->assertDatabaseCount('erp_product_variants', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
        $this->assertNull(DB::table('erp_catalog_imports')->value('completed_at'));
        $this->confirm($token, $unit->id)->assertOk()->assertJsonPath('data.created', 2);
    }

    public function test_durable_read_replay_and_lookup_keep_original_result_and_changed_choices_conflict(): void
    {
        [$company] = $this->actor();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $company->id]);
        $otherUnit = ErpUnit::factory()->create(['erp_company_id' => $company->id]);
        $token = $this->preview("name,sku\nFirst,ONE\nSecond,TWO\n")->assertOk()->json('data.token');
        $this->postJson('/api/v1/erp/catalog/import/products/preview/read', ['token' => $token])->assertOk()->assertJsonCount(2, 'data.rows');
        $original = $this->confirm($token, $unit->id)->assertOk()->json();
        ErpProduct::query()->update(['status' => 'archived']);
        $unit->update(['status' => 'archived']);
        $this->travel(2)->hours();
        $this->confirm($token, $unit->id)->assertExactJson($original);
        $this->confirm($token, $otherUnit->id)->assertConflict();
        $this->confirm($token, $unit->id, 'new-key')->assertConflict();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.catalog.import.products.confirm', ['Idempotency-Key' => 'import-key'])->assertOk()->assertJsonPath('data.result.created', 2);
        $this->postJson('/api/v1/erp/catalog/import/products/preview/read', ['token' => $token])->assertOk()->assertJsonPath('data.state', 'completed')->assertJsonPath('data.result.created', 2);
        $this->assertDatabaseCount('erp_products', 2);
        $this->assertDatabaseCount('erp_operation_receipts', 1);
    }

    public function test_contacts_support_both_roles_and_exact_address_and_normalized_document_policy(): void
    {
        $this->actor();
        $token = $this->preview("name,tax_id,email,phone,is_customer,is_supplier,address_number,address_postal_code\nFornecedor,390.533.447-05,a@example.com,001122,1,1,0007,00123456\n", 'contacts')->assertOk()->json('data.token');
        $this->confirm($token, kind: 'contacts')->assertOk();
        $contact = ErpContact::sole();
        $this->assertSame('39053344705', $contact->tax_id);
        $this->assertSame('001122', $contact->phone);
        $this->assertTrue($contact->is_customer && $contact->is_supplier);
        $this->assertSame('0007', $contact->address['number']);
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.catalog.import.contacts.confirm', ['Idempotency-Key' => 'import-key'])->assertOk()->assertJsonPath('data.result.created', 1);
        $this->preview("name,tax_id\nDup,39053344705\n", 'contacts')->assertOk()->assertJsonPath('data.totals.invalid', 1);
        $this->preview("name,tax_id\nA,30288513000100\nB,30.288.513/0001-00\n", 'contacts')->assertOk()->assertJsonPath('data.totals.invalid', 2);
    }

    public function test_preview_ownership_expiry_and_strict_confirm_identity_do_not_consume(): void
    {
        [$company] = $this->actor();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $company->id]);
        $token = $this->preview("name\nA\n")->assertOk()->json('data.token');
        $this->postJson('/api/v1/erp/catalog/import/products/confirm', ['token' => $token, 'erp_unit_id' => $unit->id])->assertUnprocessable();
        foreach ([true, 1.0] as $id) {
            $this->json('POST', '/api/v1/erp/catalog/import/products/confirm', ['token' => $token, 'erp_unit_id' => $id], ['Idempotency-Key' => 'import-key'], JSON_PRESERVE_ZERO_FRACTION)->assertUnprocessable();
        }
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->actor($company);
        $this->confirm($token, $unit->id)->assertUnprocessable();
        $this->assertDatabaseCount('erp_products', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
        $this->travel(2)->hours();
        $this->assertSame(1, DB::table('erp_catalog_imports')->count());
    }

    public function test_intrafile_and_archived_variant_sku_conflicts_are_field_errors_but_case_is_preserved(): void
    {
        [$company] = $this->actor();
        $product = ErpProduct::factory()->create(['erp_company_id' => $company->id, 'sku' => 'parent']);
        ErpProductVariant::factory()->create(['erp_product_id' => $product->id, 'sku' => 'OLD', 'status' => 'archived']);
        $this->preview("name,sku\nA,OLD\nB,DUP\nC,DUP\nD,old\n")->assertOk()->assertJsonPath('data.totals.invalid', 3)->assertJsonPath('data.rows.3.sku', 'old');
    }

    public function test_after_receipt_and_final_access_fence_failure_roll_back_the_atomic_import(): void
    {
        [$company] = $this->actor();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $company->id]);
        $token = $this->preview("name,sku\nA,FENCE\n")->assertOk()->json('data.token');
        foreach (['receipt', 'fence'] as $boundary) {
            ErpOperationReceipt::created(function () use ($boundary): void {
                if ($boundary === 'receipt') {
                    abort(503, 'Injected receipt failure');
                }
                request()->attributes->set('access.session_lock', new class
                {
                    public function isOwnedByCurrentProcess(): bool
                    {
                        return false;
                    }
                });
            });
            try {
                $this->confirm($token, $unit->id)->assertStatus(503);
            } finally {
                ErpOperationReceipt::flushEventListeners();
            }
            $this->assertDatabaseCount('erp_products', 0);
            $this->assertDatabaseCount('erp_product_variants', 0);
            $this->assertDatabaseCount('erp_operation_receipts', 0);
            $this->assertNull(DB::table('erp_catalog_imports')->value('completed_at'));
        }
        $this->confirm($token, $unit->id)->assertOk();
    }

    public function test_pending_expiry_and_foreign_company_never_consume_the_original_preview(): void
    {
        [$company] = $this->actor();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $company->id]);
        $token = $this->preview("name\nA\n")->assertOk()->json('data.token');
        $this->postJson('/api/v1/logout')->assertNoContent();
        $otherCompany = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $this->actor($otherCompany);
        $this->confirm($token, $unit->id)->assertUnprocessable();
        $this->assertSame(1, DB::table('erp_catalog_imports')->whereNull('completed_at')->count());
        DB::table('erp_catalog_imports')->update(['expires_at' => now()->subSecond()]);
        $this->assertDatabaseCount('erp_products', 0);
        $this->postJson('/api/v1/erp/catalog/import/products/preview/read', ['token' => $token])->assertUnprocessable();
    }

    public function test_current_grant_subscription_and_company_guards_apply_to_replay_read_and_lookup(): void
    {
        [$company, $user] = $this->actor();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $company->id]);
        $pending = $this->preview("name\nExpired\n")->assertOk()->json('data.token');
        DB::table('erp_catalog_imports')->update(['expires_at' => now()->subSecond()]);
        $this->confirm($pending, $unit->id)->assertUnprocessable();
        $token = $this->preview("name,sku\nA,AUTH\n")->assertOk()->json('data.token');
        $this->confirm($token, $unit->id)->assertOk();
        $lookup = '/api/v1/erp/operations/lookup?operation=erp.catalog.import.products.confirm';
        config(['erp.operating_writes_enabled' => false]);
        $this->getJson($lookup, ['Idempotency-Key' => 'import-key'])->assertForbidden();
        $this->confirm($token, $unit->id)->assertForbidden();
        config(['erp.operating_writes_enabled' => true]);
        ErpSubscription::where('erp_company_id', $company->id)->update(['status' => 'canceled']);
        $this->postJson('/api/v1/erp/catalog/import/products/preview/read', ['token' => $token])->assertForbidden();
        ErpSubscription::where('erp_company_id', $company->id)->update(['status' => 'active']);
        $this->getJson($lookup, ['Idempotency-Key' => 'import-key', 'X-Company-Id' => '999'])->assertConflict();
        ErpCompanyMembership::where('user_id', $user->id)->update(['role' => 'viewer']);
        $this->getJson($lookup, ['Idempotency-Key' => 'import-key'])->assertForbidden();
        $this->confirm($token, $unit->id)->assertForbidden();
        $this->postJson('/api/v1/erp/catalog/import/products/preview/read', ['token' => $token])->assertForbidden();
        $this->assertDatabaseCount('erp_products', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 1);
    }
}
