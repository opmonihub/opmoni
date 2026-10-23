<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Erp\Fiscal\Contracts\ErpFiscalProvider;
use App\Erp\Fiscal\ErpFiscalArtifactStore;
use App\Erp\Fiscal\ErpFiscalAttemptAuthorization;
use App\Erp\Fiscal\ErpFiscalAttemptService;
use App\Erp\Fiscal\ErpFiscalNumberAllocator;
use App\Erp\Fiscal\ErpFiscalProviderResult;
use App\Jobs\ProcessErpFiscalAttempt;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Fiscal\ErpFiscalAttempt;
use App\Models\Erp\Fiscal\ErpFiscalConfiguration;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalNumberSequence;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleReturn;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class ErpFiscalDurableAttemptTest extends TestCase
{
    use RefreshDatabase;

    public function test_attempt_idempotency_binds_actor_action_and_canonical_request(): void
    {
        [$company, $establishment, $actor, $otherActor] = $this->issuerContext();
        $service = app(ErpFiscalAttemptService::class);
        $intent = $this->intent($company, $establishment, $actor, 'document:transmit', 'stable-key', [
            'document_id' => 91,
            'source' => ['sale_id' => 8, 'line_ids' => [3, 4]],
        ]);

        $first = $service->createIntent($intent);
        $replay = $service->createIntent($intent);

        $this->assertSame($first->id, $replay->id);
        $this->assertSame('pending', $first->status);
        $this->assertSame('new', $first->outbox_state);
        $this->assertSame(1, ErpFiscalAttempt::query()->count());

        try {
            $service->createIntent([...$intent, 'actor_user_id' => $otherActor->id]);
            $this->fail('A different actor must not replay an existing fiscal intent.');
        } catch (InvalidArgumentException) {
            $this->assertSame(1, ErpFiscalAttempt::query()->count());
        }

        try {
            $service->createIntent([...$intent, 'request' => ['document_id' => 91, 'source' => ['sale_id' => 9]]]);
            $this->fail('A changed canonical body must not reuse an existing fiscal intent.');
        } catch (InvalidArgumentException) {
            $this->assertSame(1, ErpFiscalAttempt::query()->count());
        }

        $otherAction = $service->createIntent([...$intent, 'action' => 'document:consult']);
        $this->assertNotSame($first->id, $otherAction->id);
        $this->assertSame(2, ErpFiscalAttempt::query()->count());
    }

    public function test_shared_number_sequence_initializes_above_existing_documents_and_never_rewinds(): void
    {
        [$company, $firstEstablishment] = $this->issuerContext();
        $secondEstablishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $this->createDocument($company, $firstEstablishment, number: 804);

        $allocator = app(ErpFiscalNumberAllocator::class);
        $first = $allocator->allocate($company->id, '55', 'homologation', 1, 1);
        $second = $allocator->allocate($company->id, '55', 'homologation', 1, 1);
        $otherEstablishmentAllocation = $allocator->allocate($company->id, '55', 'homologation', 1, 1);

        $this->assertSame(805, $first);
        $this->assertSame(806, $second);
        $this->assertSame(807, $otherEstablishmentAllocation);
        $this->assertSame(808, $allocator->nextNumber($company->id, '55', 'homologation', 1));

        $this->assertNotSame($firstEstablishment->id, $secondEstablishment->id);
    }

    public function test_explicit_draft_number_cannot_bypass_or_lower_the_shared_sequence(): void
    {
        [$company, $establishment] = $this->issuerContext();
        $allocator = app(ErpFiscalNumberAllocator::class);
        $this->assertSame(1, $allocator->allocate($company->id, '65', 'homologation', 2, 1));

        $this->expectException(InvalidArgumentException::class);
        $allocator->assertExplicitNumberAvailable($company->id, '65', 'homologation', 2, 1, $establishment->id);
    }

    public function test_http_draft_freezes_issuer_and_revisions_and_queue_never_transmits(): void
    {
        [$company, $establishment, $actor, $headers] = $this->httpIssuer();
        Queue::fake();
        $provider = \Mockery::mock(ErpFiscalProvider::class);
        $provider->shouldNotReceive('transmit');
        $provider->shouldNotReceive('consult');
        $this->app->instance(ErpFiscalProvider::class, $provider);
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NF-e 55 posted sale'][2];
        $sale = $this->postedSale($company, $establishment, $actor, $source);
        $body = ['erp_sale_id' => $sale->id, 'erp_establishment_id' => $establishment->id, 'model' => '55', 'idempotency_key' => 'http-draft', 'document_kind' => 'posted_sale', 'source' => $source];
        $created = $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents', $body)->assertCreated();
        $id = $created->json('data.id');
        $draftAttempt = ErpFiscalAttempt::query()->where('erp_fiscal_document_id', $id)->where('action', 'document:draft')->firstOrFail();
        $snapshot = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($draftAttempt);
        $this->assertSame($establishment->name, $snapshot['request']['source']['issuer']['legal_name']);
        $this->assertNotNull($draftAttempt->erp_fiscal_configuration_revision_id);
        $this->assertNotNull($draftAttempt->erp_fiscal_certificate_revision_id);
        $establishment->update(['name' => 'Renamed after authorized intent']);
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents', $body)->assertOk()->assertJsonPath('data.id', $id);
        $body['source']['totals']['invoice'] = '101.00';
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents', $body)->assertConflict();
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents/'.$id.'/queue')->assertOk()->assertJsonPath('data.status', 'queued');
        $attempt = ErpFiscalAttempt::query()->where('erp_fiscal_document_id', $id)->where('action', 'document:queue')->firstOrFail();
        app()->call([new ProcessErpFiscalAttempt($attempt->id), 'handle']);
        $this->assertSame('accepted', $attempt->fresh()->status);
        $this->assertSame('queued', ErpFiscalDocument::findOrFail($id)->status);
        $this->assertFalse((bool) $attempt->fresh()->safe_result['artifact_ready']);
        $this->assertSame($draftAttempt->erp_fiscal_configuration_revision_id, $attempt->erp_fiscal_configuration_revision_id);
        $this->assertSame($draftAttempt->erp_fiscal_certificate_revision_id, $attempt->erp_fiscal_certificate_revision_id);
    }

    public function test_missing_model_source_is_rejected_before_document_number_or_outbox_allocation(): void
    {
        [, $establishment, , $headers] = $this->httpIssuer();
        $before = ErpFiscalNumberSequence::query()->firstOrFail()->next_number;
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents', [
            'erp_establishment_id' => $establishment->id, 'model' => '55', 'idempotency_key' => 'missing-source',
        ])->assertUnprocessable()->assertJsonValidationErrors('source');
        $this->assertSame(0, ErpFiscalDocument::query()->count());
        $this->assertSame(0, ErpFiscalAttempt::query()->count());
        $this->assertSame($before, ErpFiscalNumberSequence::query()->firstOrFail()->next_number);
    }

    public function test_explicit_transmit_times_out_then_same_attempt_consults_without_retransmitting(): void
    {
        [$company, $issuer, $actor, $headers] = $this->httpIssuer();
        Queue::fake();
        $document = $this->httpDocument($company, $issuer, $actor, $headers);
        $attempt = $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents/'.$document->id.'/transmit')->assertOk()->json('data.attempt.id');
        $provider = \Mockery::mock(ErpFiscalProvider::class);
        $provider->shouldReceive('transmit')->once()->andReturnUsing(function ($actual, $payload) {
            $this->assertSame(1, DB::transactionLevel(), 'Only the PHPUnit retained outer transaction may remain; worker transaction must be committed.');
            $this->assertStringContainsString('<Signature', $payload['signed_xml']);
            throw new \RuntimeException('synthetic timeout after provider acceptance');
        });
        $provider->shouldReceive('consult')->once()->andReturn(ErpFiscalProviderResult::indeterminate());
        $this->app->instance(ErpFiscalProvider::class, $provider);
        app()->call([new ProcessErpFiscalAttempt($attempt), 'handle']);
        $ledger = ErpFiscalAttempt::findOrFail($attempt);
        $this->assertSame('indeterminate', $ledger->status);
        $this->assertSame('new', $ledger->outbox_state);
        $ledger->update(['available_at' => now()->subSecond()]);
        app()->call([new ProcessErpFiscalAttempt($attempt), 'handle']);
        $this->assertSame(2, $ledger->fresh()->dispatch_attempts);
        $this->assertSame('indeterminate', $document->fresh()->status);
    }

    public function test_revoked_membership_and_preview_scope_block_a_queued_worker(): void
    {
        [$company, $issuer, $actor, $headers] = $this->httpIssuer();
        Queue::fake();
        $document = $this->httpDocument($company, $issuer, $actor, $headers);
        $attemptId = $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents/'.$document->id.'/transmit')->assertOk()->json('data.attempt.id');
        config(['erp.release_mode' => 'preview', 'erp.preview_allowlist' => []]);
        $this->assertNull(app(ErpFiscalAttemptService::class)->claim($attemptId));
        $this->assertSame('blocked_authority', ErpFiscalAttempt::findOrFail($attemptId)->status);
        config(['erp.release_mode' => 'production']);
        $attempt = ErpFiscalAttempt::findOrFail($attemptId);
        $attempt->update(['status' => 'pending', 'outbox_state' => 'new']);
        ErpCompanyMembership::query()->where('erp_company_id', $company->id)->where('user_id', $actor->id)->delete();
        $this->assertNull(app(ErpFiscalAttemptService::class)->claim($attemptId));
        $this->assertSame('blocked_authority', $attempt->fresh()->status);
    }

    public function test_expired_claim_recovers_with_a_new_token_and_stale_result_cannot_publish(): void
    {
        [$company, $issuer, $actor, $headers] = $this->httpIssuer();
        Queue::fake();
        $document = $this->httpDocument($company, $issuer, $actor, $headers);
        $attemptId = $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents/'.$document->id.'/transmit')->assertOk()->json('data.attempt.id');
        $service = app(ErpFiscalAttemptService::class);
        $first = $service->claim($attemptId);
        $this->assertNotNull($first);
        $first->update(['lease_expires_at' => now()->subSecond()]);
        $second = $service->claim($attemptId);
        $this->assertNotNull($second);
        $this->assertNotSame($first->lease_token, $second->lease_token);
        $called = false;
        $this->assertFalse($service->finalize($attemptId, $first->lease_token, 'accepted', [], null, null, function () use (&$called) {
            $called = true;
        }));
        $this->assertFalse($called);
        $this->assertTrue($service->complete($attemptId, $second->lease_token, 'rejected', ['code' => 'synthetic_rejection', 'password' => 'must_never_escape', 'raw_response' => '<xml/>', 'message' => 'secret payload']));
        $this->assertSame(['code' => 'synthetic_rejection'], $second->fresh()->safe_result);
    }

    public function test_event_rejection_preserves_authorized_document_and_durable_range_reserves_numbers(): void
    {
        [$company, $issuer, $actor, $headers] = $this->httpIssuer();
        Queue::fake();
        $document = $this->httpDocument($company, $issuer, $actor, $headers);
        $document->update(['status' => 'authorized', 'authorized_at' => now(), 'metadata' => ['document_kind' => 'posted_sale', 'provider_protocol' => '135260000000001']]);
        $event = $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/events', ['erp_fiscal_document_id' => $document->id, 'event_type' => 'cancel', 'justification' => 'Cancelamento solicitado indevidamente', 'idempotency_key' => 'cancel-attempt'])->assertCreated()->json('data.id');
        $attempt = $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/events/'.$event.'/submit')->assertOk()->json('data.attempt.id');
        $provider = \Mockery::mock(ErpFiscalProvider::class);
        $provider->shouldReceive('submitEvent')->once()->andReturn(ErpFiscalProviderResult::eventRejected('synthetic event rejection'));
        $provider->shouldNotReceive('transmit');
        $this->app->instance(ErpFiscalProvider::class, $provider);
        app()->call([new ProcessErpFiscalAttempt($attempt), 'handle']);
        $this->assertSame('rejected', ErpFiscalAttempt::findOrFail($attempt)->status);
        $this->assertSame('authorized', $document->fresh()->status);
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/events/'.$event.'/submit', ['idempotency_key' => 'fresh-cancel-repeat'])->assertConflict();
        $rangeBody = ['erp_establishment_id' => $issuer->id, 'model' => '55', 'series' => 1, 'year' => (int) date('Y'), 'number_from' => 200, 'number_to' => 205, 'justification' => 'Faixa de numeros ainda nao utilizados', 'idempotency_key' => 'range-one'];
        $range = $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/invalidations', $rangeBody)->assertCreated()->json('data.id');
        $this->assertSame(206, app(ErpFiscalNumberAllocator::class)->nextNumber($company->id, '55', 'homologation', 1));
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/invalidations/'.$range.'/submit')->assertOk();
        $rangeAttempt = ErpFiscalAttempt::query()->where('erp_fiscal_invalidation_id', $range)->where('action', 'invalidation:submit')->firstOrFail();
        $provider->shouldReceive('submitInvalidation')->once()->andThrow(new \RuntimeException('synthetic range timeout'));
        app()->call([new ProcessErpFiscalAttempt($rangeAttempt->id), 'handle']);
        $this->assertSame('indeterminate', $rangeAttempt->fresh()->status);
        $this->assertSame('attention', $rangeAttempt->fresh()->outbox_state);
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/invalidations/'.$range.'/submit', ['idempotency_key' => 'range-blind-repeat'])->assertConflict();
    }

    public function test_known_acceptance_survives_false_short_and_corrupt_artifact_writes_without_ready_download(): void
    {
        [$company, $issuer, $actor, $headers] = $this->httpIssuer();
        foreach (['false', 'short', 'corrupt'] as $fault) {
            Queue::fake();
            $document = $this->httpDocument($company, $issuer, $actor, $headers);
            $attemptId = $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents/'.$document->id.'/transmit')->assertOk()->json('data.attempt.id');
            $provider = \Mockery::mock(ErpFiscalProvider::class);
            $provider->shouldReceive('transmit')->once()->andReturnUsing(fn ($actual, $payload) => ErpFiscalProviderResult::authorized('135260000000001', $this->processedXml($payload['signed_xml'], $actual->access_key)));
            $this->app->instance(ErpFiscalProvider::class, $provider);
            $disk = \Mockery::mock(FilesystemAdapter::class);
            $disk->shouldReceive('exists')->andReturn(false);
            $disk->shouldReceive('put')->once()->andReturn($fault !== 'false');
            if ($fault !== 'false') {
                $disk->shouldReceive('get')->once()->andReturn($fault === 'short' ? '<nfeProc' : str_repeat('x', 10000));
            }
            Storage::shouldReceive('disk')->with('local')->andReturn($disk);
            app()->call([new ProcessErpFiscalAttempt($attemptId), 'handle']);
            $this->assertSame('artifact_pending', ErpFiscalAttempt::findOrFail($attemptId)->status, $fault);
            $this->assertSame('authorized', $document->fresh()->status);
            $this->assertSame('135260000000001', $document->fresh()->metadata['provider_protocol']);
            $this->assertNotNull($document->fresh()->authorized_at);
            $this->assertNull($document->fresh()->storage_path);
            $this->withHeaders($headers)->getJson('/api/v1/erp/fiscal/documents/'.$document->id)->assertOk()->assertJsonPath('data.artifact_ready', false)->assertJsonPath('data.claims_provider_authorization', true);
            $this->withHeaders($headers)->get('/api/v1/erp/fiscal/documents/'.$document->id.'/xml')->assertConflict();
            Storage::clearResolvedInstance('filesystem');
            $this->app->forgetInstance('filesystem');
        }
    }

    public function test_existing_hash_artifact_is_reused_without_any_write_that_could_destroy_accepted_history(): void
    {
        [$company, $issuer] = $this->issuerContext();
        $document = $this->createDocument($company, $issuer, 123);
        $xml = '<accepted xmlns="urn:synthetic">original complete XML bytes</accepted>';
        $path = 'erp/fiscal/'.$company->id.'/'.$document->id.'/authorized-'.hash('sha256', $xml).'.xml';
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->with($path)->andReturn(true);
        $disk->shouldReceive('get')->once()->with($path)->andReturn($xml);
        $disk->shouldNotReceive('put');
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $artifact = app(ErpFiscalArtifactStore::class)->persistAuthorizedXml($document, $xml);
        $this->assertSame($path, $artifact['storage_path']);
        $this->assertSame(strlen($xml), $artifact['artifact_byte_length']);
        $this->assertSame(hash('sha256', $xml), $artifact['artifact_sha256']);
    }

    private function processedXml(string $signed, string $accessKey): string
    {
        $documentXml = preg_replace('/^<\?xml[^>]*>\s*/', '', $signed);

        return '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'.$documentXml.'<protNFe versao="4.00"><infProt><tpAmb>2</tpAmb><verAplic>TESTE</verAplic><chNFe>'.$accessKey.'</chNFe><dhRecbto>2026-10-21T10:00:01-03:00</dhRecbto><nProt>135260000000001</nProt><digVal>YWJjZA==</digVal><cStat>100</cStat><xMotivo>Autorizado o uso</xMotivo></infProt></protNFe></nfeProc>';
    }

    public function test_return_document_requires_the_actual_posted_commercial_return_and_preserves_its_line_source(): void
    {
        [$company, $issuer, $actor, $headers] = $this->httpIssuer();
        Queue::fake();
        $original = $this->httpDocument($company, $issuer, $actor, $headers);
        $original->update(['status' => 'authorized', 'authorized_at' => now(), 'metadata' => ['document_kind' => 'posted_sale', 'provider_protocol' => '135260000000001']]);
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NF-e 55 posted sale'][2];
        $source['payment'] = [['method' => '90', 'amount' => '0.00']];
        $body = ['erp_establishment_id' => $issuer->id, 'model' => '55', 'document_kind' => 'return', 'source_document_id' => $original->id, 'source' => $source, 'idempotency_key' => 'return-source'];
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents', $body)->assertUnprocessable()->assertJsonValidationErrors('erp_sale_return_id');
        $sale = $original->sale()->firstOrFail();
        $line = $sale->lines()->firstOrFail();
        $return = ErpSaleReturn::query()->create(['erp_company_id' => $company->id, 'erp_establishment_id' => $issuer->id, 'erp_sale_id' => $sale->id, 'status' => 'posted', 'subtotal_amount' => '100.00', 'total_amount' => '100.00', 'commercial_snapshot' => ['party' => ['tax_id' => $source['recipient']['cnpj']]]]);
        $returnLine = $return->lines()->create(['erp_company_id' => $company->id, 'source_document_id' => $sale->id, 'source_line_id' => $line->id, 'quantity' => '1.0000', 'base_quantity' => '1.0000', 'unit_conversion_factor' => '1.00000000', 'subtotal_amount' => '100.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00', 'total_amount' => '100.00', 'source_snapshot' => ['line' => $line->attributesToArray(), 'accepted' => ['actor_id' => $actor->id]]]);
        $nature = ErpOperationNature::factory()->create(['erp_company_id' => $company->id, 'cfop' => '1202', 'description' => 'Devolucao de venda', 'movement' => 'in']);
        $configuration = ErpFiscalConfiguration::query()->where('erp_establishment_id', $issuer->id)->firstOrFail()->currentRevision->configuration;
        $configuration['models']['55']['next_number'] = 2;
        $configuration['models']['55']['operation_nature_id'] = $nature->id;
        $this->withHeaders($headers)->patchJson('/api/v1/erp/fiscal/configuration/'.$issuer->id, $configuration)->assertOk();
        $body['source']['items'][0]['cfop'] = '1202';
        $body['source']['identity']['operation_type'] = 0;
        $body['erp_sale_return_id'] = $return->id;
        $body['source']['items'][0]['source_return_line_id'] = $returnLine->id;
        $created = $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents', $body)->assertCreated();
        $attempt = ErpFiscalAttempt::findOrFail($created->json('data.attempt.id'));
        $frozen = app(ErpFiscalAttemptAuthorization::class)->sourceSnapshot($attempt)['request']['source'];
        $this->assertSame($return->id, $frozen['commercial_return']['return_id']);
        $this->assertSame($original->access_key, $frozen['references'][0]['access_key']);
        $this->assertSame($returnLine->id, $frozen['items'][0]['source_return_line_id']);
        $this->assertSame(4, $frozen['identity']['purpose']);
    }

    public function test_nested_array_in_a_decimal_leaf_returns_422_before_any_source_comparison_or_allocation(): void
    {
        [$company, $issuer, $actor, $headers] = $this->httpIssuer();
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NF-e 55 posted sale'][2];
        $sale = $this->postedSale($company, $issuer, $actor, $source);
        $source['totals']['invoice'] = ['not' => 'decimal'];
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents', ['erp_establishment_id' => $issuer->id, 'model' => '55', 'erp_sale_id' => $sale->id, 'idempotency_key' => 'malformed-leaf', 'source' => $source])->assertUnprocessable()->assertJsonValidationErrors('source.totals.invoice');
        $this->assertSame(0, ErpFiscalDocument::query()->count());
        $this->assertSame(0, ErpFiscalAttempt::query()->count());
        $this->assertSame(1, app(ErpFiscalNumberAllocator::class)->nextNumber($company->id, '55', 'homologation', 1));
    }

    public function test_legacy_xml_with_valid_bytes_and_hash_is_not_ready_without_a_correlated_verified_attempt(): void
    {
        [$company, $issuer, , $headers] = $this->httpIssuer();
        $document = $this->createDocument($company, $issuer, 900);
        $bytes = '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"/>';
        $path = 'erp/fiscal/'.$company->id.'/'.$document->id.'/legacy-synthetic.xml';
        Storage::disk('local')->put($path, $bytes);
        $document->update(['status' => 'authorized', 'storage_path' => $path, 'artifact_sha256' => hash('sha256', $bytes), 'artifact_byte_length' => strlen($bytes), 'authorized_at' => now()]);
        $store = app(ErpFiscalArtifactStore::class);
        $this->assertTrue($store->verifyIntegrity($document->fresh()));
        $this->assertFalse($store->verifiedFiscalArtifactReady($document->fresh()));
        $this->withHeaders($headers)->getJson('/api/v1/erp/fiscal/documents/'.$document->id)->assertOk()->assertJsonPath('data.artifact_ready', false)->assertJsonPath('data.legacy_attention_required', true);
        $this->withHeaders($headers)->get('/api/v1/erp/fiscal/documents/'.$document->id.'/xml')->assertConflict();
        $this->assertSame($bytes, Storage::disk('local')->get($path));
        $this->assertSame('authorized', $document->fresh()->status);
        $this->assertSame(0, ErpFiscalAttempt::query()->count());
    }

    public function test_uncertain_event_cannot_be_resubmitted_through_a_new_event_row_and_original_replay_remains_available(): void
    {
        [$company, $issuer, $actor, $headers] = $this->httpIssuer();
        Queue::fake();
        $document = $this->httpDocument($company, $issuer, $actor, $headers);
        $document->update(['status' => 'authorized', 'authorized_at' => now(), 'metadata' => ['document_kind' => 'posted_sale', 'provider_protocol' => '135260000000001']]);
        $body = ['erp_fiscal_document_id' => $document->id, 'event_type' => 'cancel', 'justification' => 'Cancelamento aguardando confirmacao externa', 'idempotency_key' => 'uncertain-cancel-a'];
        $first = $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/events', $body)->assertCreated()->json('data.id');
        $second = $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/events', [...$body, 'idempotency_key' => 'uncertain-cancel-b'])->assertCreated()->json('data.id');
        $attemptId = $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/events/'.$first.'/submit')->assertOk()->json('data.attempt.id');
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/events', $body)->assertOk()->assertJsonPath('data.id', $first);
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/events', [...$body, 'idempotency_key' => 'uncertain-cancel-c'])->assertConflict();
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/events/'.$second.'/submit')->assertConflict();
        $provider = \Mockery::mock(ErpFiscalProvider::class);
        $provider->shouldReceive('submitEvent')->once()->andThrow(new \RuntimeException('synthetic lost event response'));
        $this->app->instance(ErpFiscalProvider::class, $provider);
        app()->call([new ProcessErpFiscalAttempt($attemptId), 'handle']);
        $this->assertSame('indeterminate', ErpFiscalAttempt::findOrFail($attemptId)->status);
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/events', [...$body, 'idempotency_key' => 'uncertain-cancel-c'])->assertConflict();
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/events/'.$second.'/submit')->assertConflict();
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/events/'.$first.'/submit')->assertOk()->assertJsonPath('data.attempt.id', $attemptId)->assertJsonPath('data.attempt.status', 'indeterminate');
        $this->assertSame('authorized', $document->fresh()->status);
        $this->assertSame(1, ErpFiscalAttempt::query()->where('action', 'event:submit')->count());
    }

    private function httpDocument(ErpCompany $company, ErpEstablishment $issuer, User $actor, array $headers): ErpFiscalDocument
    {
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NF-e 55 posted sale'][2];
        $sale = $this->postedSale($company, $issuer, $actor, $source);
        $documentId = $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents', ['erp_establishment_id' => $issuer->id, 'model' => '55', 'erp_sale_id' => $sale->id, 'source' => $source, 'idempotency_key' => 'doc-'.Str::uuid()])->assertCreated()->json('data.id');
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/documents/'.$documentId.'/queue')->assertOk();

        return ErpFiscalDocument::findOrFail($documentId);
    }

    private function postedSale(ErpCompany $company, ErpEstablishment $issuer, User $actor, array &$source): ErpSale
    {
        $sale = ErpSale::query()->create(['erp_company_id' => $company->id, 'erp_establishment_id' => $issuer->id, 'invoice_no' => 'source-'.Str::uuid(), 'status' => 'posted', 'total_amount' => '100.00']);
        $line = $sale->lines()->create(['erp_company_id' => $company->id, 'ordinal' => 1, 'quantity' => '1.0000', 'unit_price' => '100.0000', 'gross_amount' => '100.00', 'line_total' => '100.00',
            'product_snapshot' => ['description' => $source['items'][0]['description'], 'product' => ['sku' => $source['items'][0]['code'], 'ncm' => $source['items'][0]['ncm'], 'origin_code' => '0', 'icms_cst' => '00', 'pis_cst' => '07', 'cofins_cst' => '07'], 'unit' => ['short_name' => 'UN'], 'operation_nature' => ['cfop' => '5102']]]);
        $source['items'][0]['source_line_id'] = $line->id;
        $sale->update(['commercial_snapshot' => ['accepted' => ['actor_id' => $actor->id], 'party' => ['tax_id' => $source['recipient']['cnpj']], 'totals' => ['total_amount' => '100.00'], 'lines' => [$line->attributesToArray()]]]);

        return $sale;
    }

    private function httpIssuer(): array
    {
        config(['access.ingress_key' => 'test-key', 'erp.operating_writes_enabled' => true, 'erp.release_enabled' => true,
            'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        Storage::fake('local');
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
        [$company, $establishment, $actor] = $this->issuerContext();
        $establishment->update(['cnpj' => '30288513000100']);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $actor->id, 'role' => 'owner']);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'erp_plan_id' => $plan->id, 'status' => 'active']);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $actor->email, 'password' => 'password'])->assertOk();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $headers = ['X-Company-Id' => (string) $company->id, 'X-CSRF-Token' => $csrf];
        $nature = ErpOperationNature::factory()->create(['erp_company_id' => $company->id]);
        $this->withHeaders($headers)->patchJson('/api/v1/erp/fiscal/configuration/'.$establishment->id, [
            'environment' => 'homologation', 'issuer' => ['tax_regime' => 3, 'state_registration' => '110042490114', 'uf' => 'SP',
                'address' => ['street' => 'Rua Um', 'number' => '10', 'district' => 'Centro', 'city_code' => '3550308', 'city_name' => 'Sao Paulo', 'zip_code' => '01001000']],
            'models' => ['55' => ['series' => 1, 'next_number' => 1, 'operation_nature_id' => $nature->id]],
        ])->assertOk();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => '30288513000100'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256']);
        $pkcs12 = '';
        $this->assertTrue(openssl_pkcs12_export($cert, $pkcs12, $key, ''));
        $this->withHeaders($headers)->postJson('/api/v1/erp/fiscal/certificates', ['label' => 'Issuer test certificate', 'erp_establishment_id' => $establishment->id, 'certificate_content' => base64_encode($pkcs12), 'password' => ''])->assertCreated();

        return [$company, $establishment->fresh(), $actor, $headers];
    }

    /** @return array{ErpCompany, ErpEstablishment, User, User} */
    private function issuerContext(): array
    {
        $company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);

        return [$company, $establishment, User::factory()->create(), User::factory()->create()];
    }

    /** @return array<string, mixed> */
    private function intent(ErpCompany $company, ErpEstablishment $establishment, User $actor, string $action, string $key, array $request): array
    {
        return [
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'action' => $action,
            'model' => '55',
            'environment' => 'homologation',
            'series' => 1,
            'number' => 91,
            'actor_user_id' => $actor->id,
            'idempotency_key' => $key,
            'request' => $request,
        ];
    }

    private function createDocument(ErpCompany $company, ErpEstablishment $establishment, int $number): ErpFiscalDocument
    {
        return ErpFiscalDocument::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'model' => '55',
            'environment' => 'homologation',
            'series' => 1,
            'number' => $number,
            'status' => 'draft',
            'idempotency_key' => 'existing-'.$number,
        ]);
    }
}
