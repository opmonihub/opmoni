<?php

namespace Tests\Feature;

use App\Erp\ErpCompanyInitializer;
use App\Erp\ErpCompanyProvisioning;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanySetupState;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\Sales\ErpInvoiceScheme;
use App\Models\Erp\Sales\ErpSale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ErpCommercialTestCase;

class ErpCommercialNumberingTest extends ErpCommercialTestCase
{
    public function test_new_company_uses_native_sale_scheme_and_existing_quote_order_formats_through_conversion(): void
    {
        $state = $this->provisionNewCompany();
        $scheme = $state->defaultInvoiceScheme;
        $this->assertNotNull($scheme);
        $this->assertSame('', $scheme->prefix);
        $this->assertSame(1, (int) $scheme->start_number);
        $this->assertSame(4, (int) $scheme->total_digits);
        $this->assertSame('br-basic-v1.sale-scheme', $scheme->native_key);
        $this->assertSame('br-basic-v1.sale-scheme', $state->preset_owned_keys['sale_scheme']);
        $this->assertTrue($state->sale_numbering_adopted);

        $sale = $this->send('sales', $this->body())->assertCreated()->assertJsonPath('data.invoice_no', '0001')->json('data.id');
        $this->assertSame($scheme->id, ErpSale::findOrFail($sale)->erp_invoice_scheme_id);
        $quote = $this->send('quotes', $this->body())->assertCreated()->assertJsonPath('data.quote_no', 'Q-000001')->json('data.id');
        $order = $this->send('quotes/'.$quote.'/convert', ['draft_revision' => 1])->assertOk()->assertJsonPath('data.order.order_no', 'O-000001')->json('data.order.id');
        $converted = $this->send('orders/'.$order.'/convert', ['draft_revision' => 1])->assertOk()->assertJsonPath('data.sale.invoice_no', '0002')->json('data.sale.id');
        $this->assertSame($scheme->id, ErpSale::findOrFail($converted)->erp_invoice_scheme_id);
        $this->assertSame(0, (int) $scheme->fresh()->invoice_count);
        $this->assertDatabaseCount('erp_stock_movements', 0);
        $this->assertDatabaseCount('erp_obligations', 0);
    }

    public function test_existing_company_retains_legacy_numbering_even_with_unadopted_default_scheme_pointer(): void
    {
        $scheme = $this->scheme(['prefix' => 'NEW-', 'start_number' => 50, 'total_digits' => 4, 'is_default' => true]);
        ErpCompanySetupState::query()->create([
            'erp_company_id' => $this->company->id, 'preset_version' => 'br-basic-v1', 'revision' => 1,
            'default_invoice_scheme_id' => $scheme->id,
        ]);
        $sale = $this->send('sales', $this->body())->assertCreated()->assertJsonPath('data.invoice_no', 'S-000001')->json('data.id');
        $this->assertNull(ErpSale::findOrFail($sale)->erp_invoice_scheme_id);
        $this->send('quotes', $this->body())->assertCreated()->assertJsonPath('data.quote_no', 'Q-000001');
        $this->send('orders', $this->body())->assertCreated()->assertJsonPath('data.order_no', 'O-000001');
    }

    public function test_manual_numbers_keep_their_contract_and_automatic_allocation_skips_collisions_without_using_invoice_count(): void
    {
        $state = $this->provisionNewCompany();
        $state->defaultInvoiceScheme->update(['invoice_count' => 999]);
        $this->send('sales', $this->body(['invoice_no' => '0001']))->assertCreated()->assertJsonPath('data.invoice_no', '0001');
        $this->assertDatabaseCount('erp_commercial_number_sequences', 0);
        $this->send('sales', $this->body())->assertCreated()->assertJsonPath('data.invoice_no', '0002');
        $this->send('sales', $this->body(['invoice_no' => '0001']))->assertUnprocessable()->assertJsonValidationErrors('invoice_no');
        $this->send('sales', $this->body(['invoice_no' => ' bad ']))->assertUnprocessable()->assertJsonValidationErrors('invoice_no');
        $this->send('sales', $this->body())->assertCreated()->assertJsonPath('data.invoice_no', '0003');
        $this->assertSame(4, $this->nextNumber());
        $this->assertSame(999, (int) $state->defaultInvoiceScheme->fresh()->invoice_count);
    }

    public function test_start_is_honored_once_and_scheme_changes_and_initializer_retry_never_reset_a_sequence(): void
    {
        $state = $this->provisionNewCompany();
        $state->defaultInvoiceScheme->update(['start_number' => 27]);
        $this->send('sales', $this->body())->assertCreated()->assertJsonPath('data.invoice_no', '0027');
        $state->defaultInvoiceScheme->update(['start_number' => 900, 'prefix' => 'V-', 'total_digits' => 5]);
        $this->send('sales', $this->body())->assertCreated()->assertJsonPath('data.invoice_no', 'V-00028');
        $replacement = $this->scheme(['prefix' => 'R-', 'start_number' => 400, 'total_digits' => 6]);
        $state->update(['default_invoice_scheme_id' => $replacement->id]);
        $this->send('sales', $this->body())->assertCreated()->assertJsonPath('data.invoice_no', 'R-000029');
        $before = DB::table('erp_commercial_number_sequences')->first();

        app(ErpCompanyInitializer::class)->initialize($this->company);

        $this->assertEquals($before, DB::table('erp_commercial_number_sequences')->first());
        $this->assertSame($replacement->id, $state->fresh()->default_invoice_scheme_id);
        $this->assertSame(2, ErpInvoiceScheme::query()->count());
        $this->send('sales', $this->body())->assertCreated()->assertJsonPath('data.invoice_no', 'R-000030');
        $other = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $this->send('sales', $this->body(['erp_establishment_id' => $other->id]))->assertCreated()->assertJsonPath('data.invoice_no', 'R-000400');
    }

    public function test_draft_number_and_captured_scheme_remain_immutable_after_default_changes_and_retry(): void
    {
        $state = $this->provisionNewCompany();
        $originalScheme = $state->default_invoice_scheme_id;
        $body = $this->body();
        $created = $this->send('sales', $body, 'lost-number-response')->assertCreated();
        $id = $created->json('data.id');
        $replacement = $this->scheme(['prefix' => 'OTHER-', 'start_number' => 100]);
        $state->update(['default_invoice_scheme_id' => $replacement->id]);
        $this->send('sales', $body, 'lost-number-response')->assertCreated()->assertExactJson($created->json());
        $this->patchJson('/api/v1/erp/sales/'.$id, ['draft_revision' => 1], ['Idempotency-Key' => 'retained-edit'])->assertOk()->assertJsonPath('data.invoice_no', '0001');
        $this->patchJson('/api/v1/erp/sales/'.$id, $body + ['draft_revision' => 2], ['Idempotency-Key' => 'explicit-edit'])->assertOk()->assertJsonPath('data.invoice_no', '0001');
        $this->patchJson('/api/v1/erp/sales/'.$id, ['invoice_no' => '9999'], ['Idempotency-Key' => 'rename-draft'])->assertUnprocessable()->assertJsonValidationErrors('invoice_no');
        $this->assertSame($originalScheme, ErpSale::findOrFail($id)->erp_invoice_scheme_id);
        $this->assertSame(2, $this->nextNumber());
        $this->send('sales', $body)->assertCreated()->assertJsonPath('data.invoice_no', 'OTHER-0002');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidSchemes(): iterable
    {
        yield 'archived' => [['status' => 'archived']];
        yield 'zero start' => [['start_number' => 0]];
        yield 'zero digits' => [['total_digits' => 0]];
        yield 'oversize digits' => [['total_digits' => 65]];
        yield 'control prefix' => [['prefix' => "X\n"]];
    }

    #[DataProvider('invalidSchemes')]
    public function test_adopted_invalid_scheme_fails_explicitly_without_fallback_or_allocation(array $changes): void
    {
        $state = $this->provisionNewCompany();
        $state->defaultInvoiceScheme->update($changes);
        $this->send('sales', $this->body())->assertUnprocessable()->assertJsonValidationErrors('default_invoice_scheme_id');
        $this->assertDatabaseCount('erp_sales', 0);
        $this->assertDatabaseCount('erp_commercial_number_sequences', 0);
    }

    public function test_adopted_missing_pointer_fails_instead_of_silently_using_legacy_numbering(): void
    {
        $state = $this->provisionNewCompany();
        $state->update(['default_invoice_scheme_id' => null]);
        $this->send('sales', $this->body())->assertUnprocessable()->assertJsonValidationErrors('default_invoice_scheme_id');
        $this->assertDatabaseCount('erp_sales', 0);
        $this->assertDatabaseCount('erp_commercial_number_sequences', 0);
    }

    public function test_foreign_adopted_scheme_is_rejected_by_database_without_changing_selection_or_counter(): void
    {
        $state = $this->provisionNewCompany();
        $originalId = $state->default_invoice_scheme_id;
        $foreign = ErpCompany::factory()->create();
        $scheme = ErpInvoiceScheme::query()->create(['erp_company_id' => $foreign->id, 'name' => 'Foreign']);
        try {
            $state->update(['default_invoice_scheme_id' => $scheme->id]);
            $this->fail('Foreign adopted schemes must be rejected.');
        } catch (QueryException $exception) {
            $this->assertMatchesRegularExpression('/foreign key/i', $exception->getMessage());
        }
        $this->assertSame($originalId, $state->fresh()->default_invoice_scheme_id);
        $this->send('sales', $this->body(['erp_invoice_scheme_id' => $scheme->id]))->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertDatabaseCount('erp_sales', 0);
        $this->assertDatabaseCount('erp_commercial_number_sequences', 0);
    }

    public function test_failed_draft_creation_rolls_back_the_authoritative_sequence_before_retry(): void
    {
        $this->provisionNewCompany();
        $dispatcher = ErpSale::getEventDispatcher();
        ErpSale::setEventDispatcher(clone $dispatcher);
        ErpSale::creating(fn () => abort(422, 'Draft storage failed.'));
        try {
            $this->send('sales', $this->body(), 'retry-after-failure')->assertUnprocessable();
        } finally {
            ErpSale::setEventDispatcher($dispatcher);
        }
        $this->assertDatabaseCount('erp_sales', 0);
        $this->assertDatabaseCount('erp_commercial_number_sequences', 0);
        $this->send('sales', $this->body(), 'retry-after-failure')->assertCreated()->assertJsonPath('data.invoice_no', '0001');
    }

    public function test_scheme_storage_failure_rolls_back_new_company_provisioning(): void
    {
        $tables = ['erp_companies', 'erp_company_memberships', 'erp_establishments', 'erp_subscriptions', 'erp_units', 'erp_contacts', 'erp_company_setup_states', 'erp_invoice_schemes'];
        $before = array_map(fn ($table) => DB::table($table)->count(), array_combine($tables, $tables));
        $dispatcher = ErpInvoiceScheme::getEventDispatcher();
        ErpInvoiceScheme::setEventDispatcher(clone $dispatcher);
        ErpInvoiceScheme::creating(function (): void {
            throw new RuntimeException('Scheme storage failed.');
        });
        try {
            app(ErpCompanyProvisioning::class)->createCompanyForOwner($this->user, ['name' => 'Fail', 'cnpj' => '11222333000181']);
            $this->fail('Required scheme storage must be atomic with provisioning.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Scheme storage failed.', $exception->getMessage());
        } finally {
            ErpInvoiceScheme::setEventDispatcher($dispatcher);
        }
        foreach ($before as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
    }

    public function test_additive_upgrade_never_adopts_existing_company_or_resets_existing_sequence(): void
    {
        $migration = require database_path('migrations/2026_10_24_010000_add_native_sale_numbering_adoption.php');
        $migration->down();
        $scheme = $this->scheme(['prefix' => 'NEW-', 'start_number' => 900]);
        $state = ErpCompanySetupState::query()->create([
            'erp_company_id' => $this->company->id, 'preset_version' => 'br-basic-v1', 'revision' => 1,
            'default_invoice_scheme_id' => $scheme->id,
        ]);
        DB::table('erp_commercial_number_sequences')->insert([
            'erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id,
            'document_type' => 'sale', 'next_number' => 42,
        ]);
        $before = DB::table('erp_commercial_number_sequences')->first();
        $migration->up();
        $this->assertFalse($state->fresh()->sale_numbering_adopted);
        $this->assertNull($scheme->fresh()->native_key);
        $this->assertEquals($before, DB::table('erp_commercial_number_sequences')->first());
        $this->send('sales', $this->body())->assertCreated()->assertJsonPath('data.invoice_no', 'S-000042');
    }

    private function provisionNewCompany(): ErpCompanySetupState
    {
        $this->company = app(ErpCompanyProvisioning::class)->createCompanyForOwner($this->user, ['name' => 'Nova', 'cnpj' => '11222333000181']);
        $this->company->subscription->update(['status' => 'active']);
        $this->establishment = $this->company->establishments()->firstOrFail();
        $this->postJson('/api/v1/erp/company/switch', ['company_id' => $this->company->id])->assertOk();
        $this->withHeaders(['X-Company-Id' => (string) $this->company->id, 'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token')]);

        return $this->company->setupState()->firstOrFail();
    }

    private function body(array $changes = []): array
    {
        return array_replace(['erp_establishment_id' => $this->establishment->id, 'lines' => [['description' => 'Serviço', 'quantity' => '1', 'unit_price' => '10']]], $changes);
    }

    private function scheme(array $changes = []): ErpInvoiceScheme
    {
        return ErpInvoiceScheme::query()->create(array_replace(['erp_company_id' => $this->company->id, 'name' => 'Vendas', 'prefix' => '', 'start_number' => 1, 'total_digits' => 4], $changes));
    }

    private function nextNumber(): int
    {
        return (int) DB::table('erp_commercial_number_sequences')->where('erp_company_id', $this->company->id)->where('erp_establishment_id', $this->establishment->id)->where('document_type', 'sale')->value('next_number');
    }
}
