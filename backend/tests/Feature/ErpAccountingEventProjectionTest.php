<?php

namespace Tests\Feature;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Erp\Accounting\ErpAccountingDispatchOutbox;
use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Erp\Fiscal\ErpFiscalArtifactStore;
use App\Erp\Fiscal\ErpFiscalAttemptService;
use App\Erp\Fiscal\ErpFiscalEventRegistry;
use App\Erp\Fiscal\ErpFiscalSchemaValidator;
use App\Erp\Fiscal\ErpFiscalXmlSigner;
use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\Accounting\ErpAccountingDeliveryDispatch;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Capture\FiscalDocumentWriter;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Support\ErpCompanyContext;
use App\Tenant\CurrentTenant;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ApprovesErpAccountingMappings;
use Tests\Support\BuildsVerifiedErpFiscalEvidence;
use Tests\TestCase;

class ErpAccountingEventProjectionTest extends TestCase
{
    use ApprovesErpAccountingMappings;
    use BuildsVerifiedErpFiscalEvidence;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('fiscal');
        Queue::fake();
    }

    public function test_unpublished_canceled_original_and_correction_events_converge_without_rewriting_document_intent(): void
    {
        [$document, $event, $client] = $this->scenario();
        $handoff = app(ErpAccountingHandoffService::class);
        $documentIntent = $handoff->ensureDeliveryIntent($document);
        $before = $documentIntent->fresh()->getAttributes();
        $document->update(['status' => 'canceled']);
        $intent = $handoff->ensureEventDeliveryIntent($document, $event);
        $this->assertSame('delivered', $handoff->deliver($intent)->status);
        $this->assertSame($before, $documentIntent->fresh()->getAttributes());
        $this->assertDatabaseCount('fiscal_documents', 2);
        $this->assertSame(1, FiscalDocument::withoutGlobalScope('account')->where('client_id', $client->id)->where('stage', 'event')->count());
        $this->assertSame('canceled', $document->fresh()->status);
        $this->assertSame(1, $document->attempts()->where('action', 'event:submit')->count());
    }

    public function test_event_storage_failure_retries_from_encrypted_evidence_and_restores_context(): void
    {
        [$document, $event] = $this->scenario();
        $handoff = app(ErpAccountingHandoffService::class);
        $original = $handoff->deliver($handoff->ensureDeliveryIntent($document));
        $history = $original->getAttributes();
        $intent = $handoff->ensureEventDeliveryIntent($document, $event);
        $disk = Storage::disk('fiscal');
        $local = Storage::disk('local');
        $manager = Storage::getFacadeRoot();
        $proxy = \Mockery::mock(FilesystemAdapter::class);
        $proxy->shouldReceive('exists')->andReturnUsing(fn ($path) => $disk->exists($path));
        $proxy->shouldReceive('get')->andReturnUsing(fn ($path) => $disk->get($path));
        $proxy->shouldReceive('put')->andReturn(false);
        Storage::shouldReceive('disk')->with('fiscal')->andReturn($proxy);
        Storage::shouldReceive('disk')->with('local')->andReturn($local);
        $companyContext = app(ErpCompanyContext::class)->companyId;
        $accountContext = app(CurrentTenant::class)->accountId;
        $failed = $handoff->deliver($intent);
        $this->assertSame('failed', $failed->status);
        $this->assertSame('publication_failed', $failed->reason_code);
        $this->assertSame($event->accepted_xml, $event->fresh()->accepted_xml);
        $this->assertSame($companyContext, app(ErpCompanyContext::class)->companyId);
        $this->assertSame($accountContext, app(CurrentTenant::class)->accountId);
        Storage::swap($manager);
        $this->assertSame('pending', $handoff->retry($failed)->status);
        $this->assertSame('delivered', $handoff->deliver($intent->fresh())->status);
        $this->assertSame($history, $original->fresh()->getAttributes());
    }

    public function test_capture_first_and_event_first_equal_replay_and_conflict_preserve_accepted_bytes(): void
    {
        [$document, $event, $client] = $this->scenario();
        $writer = app(FiscalDocumentWriter::class);
        $captured = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled($document, $event));
        $handoff = app(ErpAccountingHandoffService::class);
        $intent = $handoff->ensureEventDeliveryIntent($document, $event);
        $delivered = $handoff->deliver($intent);
        $this->assertSame('delivered', $delivered->status);
        $this->assertSame($captured->id, $delivered->fiscal_document_id);
        $this->assertSame('nfe_distribuicao', $captured->fresh()->source->value);
        $equal = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled($document, $event));
        $this->assertSame($captured->id, $equal->id);
        $conflict = $writer->store($client, FiscalSource::NfeDistribuicao, $this->pulled($document, $event, str_replace('135260000000002', '135260000000003', $event->accepted_xml)));
        $this->assertSame('artifact_conflict', $conflict->processing_reason->value);
        $this->assertSame($event->accepted_xml, Storage::disk('fiscal')->get($conflict->storage_path));
        $this->assertSame($event->accepted_sha256, $conflict->sha256);
    }

    public function test_revoked_grant_blocks_new_event_and_new_grant_gets_distinct_projection_history(): void
    {
        [$document, $event, $client, $delegation, $issuer] = $this->scenario();
        $handoff = app(ErpAccountingHandoffService::class);
        $old = $handoff->ensureEventDeliveryIntent($document, $event);
        $delegation->update(['status' => 'revoked']);
        $blocked = $handoff->deliver($old);
        $this->assertSame('delegation_revoked', $blocked->reason_code);
        $history = $blocked->getAttributes();
        $next = ErpOfficeDelegation::factory()->create(['erp_company_id' => $document->erp_company_id, 'account_id' => $client->account_id]);
        $this->approveAccountingMapping($next, $issuer, $client);
        $new = $handoff->ensureEventDeliveryIntent($document, $event);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('delivered', $handoff->deliver($new)->status);
        $this->assertSame($history, $blocked->fresh()->getAttributes());
        $this->assertSame(1, FiscalDocument::withoutGlobalScope('account')->where('stage', 'event')->count());
    }

    public function test_event_intent_and_dispatch_rollback_together_and_lost_wake_is_recoverable(): void
    {
        [$document, $event] = $this->scenario();
        $handoff = app(ErpAccountingHandoffService::class);
        DB::beginTransaction();
        $intent = $handoff->ensureEventDeliveryIntent($document, $event);
        $handoff->queueAfterCommit($intent);
        DB::rollBack();
        $this->assertDatabaseCount('erp_accounting_deliveries', 0);
        $this->assertDatabaseCount('erp_accounting_delivery_dispatches', 0);
        $intent = $handoff->ensureEventDeliveryIntent($document, $event);
        $handoff->queueAfterCommit($intent);
        $dispatch = ErpAccountingDeliveryDispatch::sole();
        $this->assertSame('pending', $dispatch->status);
        config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database']);
        $this->travel(6)->minutes();
        $this->assertSame(1, app(ErpAccountingDispatchOutbox::class)->dispatchDue(), json_encode($dispatch->fresh()->getAttributes()));
        $dispatch->refresh();
        $delivered = $handoff->deliver($intent, $dispatch->id, $dispatch->attempt_sequence, $dispatch->generation, $dispatch->lease_token);
        $this->assertSame('delivered', $delivered->status);
        $this->assertSame('completed', $dispatch->fresh()->status);
        $this->assertSame('accepted', $event->fresh()->status);
    }

    public function test_conflicting_capture_that_arrived_first_is_preserved_and_event_intent_is_attention(): void
    {
        [$document, $event, $client] = $this->scenario();
        $conflictingXml = str_replace('135260000000002', '135260000000003', $event->accepted_xml);
        $captured = app(FiscalDocumentWriter::class)->store($client, FiscalSource::NfeDistribuicao, $this->pulled($document, $event, $conflictingXml));
        $handoff = app(ErpAccountingHandoffService::class);
        $result = $handoff->deliver($handoff->ensureEventDeliveryIntent($document, $event));
        $this->assertSame('artifact_conflict', $result->reason_code);
        $this->assertSame($conflictingXml, Storage::disk('fiscal')->get($captured->storage_path));
        $this->assertSame('artifact_conflict', $captured->fresh()->processing_reason->value);
        $this->assertSame('accepted', $event->fresh()->status);
    }

    public function test_corrupted_accepted_event_identity_cannot_publish_or_destroy_original_authorization(): void
    {
        [$document, $event] = $this->scenario();
        $before = $document->fresh()->getAttributes();
        $event->update(['accepted_identity' => [...$event->accepted_identity, 'protocol' => '135260000000009']]);
        $handoff = app(ErpAccountingHandoffService::class);
        $result = $handoff->deliver($handoff->ensureEventDeliveryIntent($document, $event));
        $this->assertSame('fiscal_event_unverified', $result->reason_code);
        $this->assertSame($before, $document->fresh()->getAttributes());
        $this->assertDatabaseCount('fiscal_documents', 0);
    }

    public function test_queue_push_failure_keeps_accepted_event_and_dispatch_for_scanner_repair(): void
    {
        [$document, $event] = $this->scenario();
        $handoff = app(ErpAccountingHandoffService::class);
        $intent = $handoff->ensureEventDeliveryIntent($document, $event);
        $handoff->queueAfterCommit($intent);
        $dispatch = ErpAccountingDeliveryDispatch::sole();
        $dispatch->update(['available_at' => now()]);
        config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database']);
        $queue = Queue::getFacadeRoot();
        Queue::shouldReceive('connection')->with('database')->once()->andThrow(new \RuntimeException('Synthetic queue push failure'));
        $this->assertSame(0, app(ErpAccountingDispatchOutbox::class)->dispatchDue());
        $this->assertSame('queue_publication_failed', $dispatch->fresh()->last_error);
        $this->assertSame('accepted', $event->fresh()->status);
        $this->assertNotNull($event->fresh()->accepted_xml);
        Queue::swap($queue);
        $this->travel(6)->minutes();
        $this->assertSame(1, app(ErpAccountingDispatchOutbox::class)->dispatchDue());
        $dispatch->refresh();
        $this->assertSame('delivered', $handoff->deliver($intent, $dispatch->id, $dispatch->attempt_sequence, $dispatch->generation, $dispatch->lease_token)->status);
        $this->assertSame(1, $document->attempts()->where('action', 'event:submit')->count());
    }

    private function pulled(ErpFiscalDocument $document, ErpFiscalEvent $event, ?string $xml = null): PulledDocument
    {
        return new PulledDocument(model: FiscalModel::Nfe, kind: FiscalKind::Event, stage: FiscalStage::Event,
            chave: $document->access_key, eventId: $event->accepted_event_identity, emitenteCnpj: substr($document->access_key, 6, 14),
            destinatarioCnpj: null, valorTotal: null, digVal: null, nsu: 1, schema: 'procEventoNFe_v1.00.xsd',
            emissaoAt: null, eventoOcorridoEmAt: null, xml: $xml ?? $event->accepted_xml);
    }

    private function scenario(): array
    {
        $account = Account::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        $issuer = ErpEstablishment::factory()->create(['erp_company_id' => $company->id, 'cnpj' => '30288513000100']);
        $client = app(CurrentTenant::class)->run($account->id, fn () => Client::factory()->company()->create(['account_id' => $account->id]));
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => $account->id]);
        $this->approveAccountingMapping($delegation, $issuer, $client);
        $document = ErpFiscalDocument::create(['erp_company_id' => $company->id, 'erp_establishment_id' => $issuer->id, 'model' => '55',
            'environment' => 'homologation', 'series' => 1, 'number' => 1, 'idempotency_key' => 'event-original', 'metadata' => ['document_kind' => 'posted_sale']]);
        $this->attachVerifiedFiscalEvidence($document);
        $draft = $document->attempts()->where('action', 'document:draft')->sole();
        $event = ErpFiscalEvent::create(['erp_fiscal_document_id' => $document->id, 'event_type' => 'correction', 'status' => 'accepted', 'idempotency_key' => 'event-correction', 'protocol' => '135260000000002']);
        $source = ['model' => '55', 'document_kind' => 'posted_sale', 'event_type' => 'correction', 'environment' => 'homologation',
            'issuer' => ['cnpj' => $company->cnpj], 'access_key' => $document->access_key, 'protocol' => '135260000000001', 'sequence' => 1,
            'occurred_at' => '2026-10-22T10:00:00-03:00', 'correction' => 'Correcao dos dados complementares da operacao', 'legal_scope_attested' => true];
        $revision = $draft->certificateRevision;
        openssl_pkcs12_read(Crypt::decryptString($revision->encrypted_certificate), $material, '');
        $signed = app(ErpFiscalXmlSigner::class)->sign(app(ErpFiscalEventRegistry::class)->buildEvent($source), $material['pkey'], $material['cert']);
        $request = new \DOMDocument;
        $request->loadXML($signed);
        $node = (new \DOMXPath($request))->query('//*[local-name()="evento"]')->item(0);
        $xml = '<procEventoNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">'.$request->saveXML($node).'<retEvento versao="1.00"><infEvento><tpAmb>2</tpAmb><verAplic>TESTE</verAplic><cOrgao>35</cOrgao><cStat>135</cStat><xMotivo>Evento registrado e vinculado</xMotivo><chNFe>'.$document->access_key.'</chNFe><tpEvento>110110</tpEvento><nSeqEvento>1</nSeqEvento><dhRegEvento>2026-10-22T10:00:01-03:00</dhRegEvento><nProt>135260000000002</nProt></infEvento></retEvento></procEventoNFe>';
        app(ErpFiscalSchemaValidator::class)->assertValid($xml, '55', 'processed_event', 'correction');
        app(ErpFiscalEventRegistry::class)->assertOperationResultMatches($xml, $signed, $source, $event->protocol);
        $attempt = app(ErpFiscalAttemptService::class)->createIntent([...$draft->only(['erp_company_id', 'erp_establishment_id', 'model', 'environment', 'series', 'number', 'actor_user_id', 'erp_fiscal_configuration_revision_id', 'erp_fiscal_certificate_revision_id', 'schema_release']),
            'erp_fiscal_document_id' => $document->id, 'erp_fiscal_event_id' => $event->id, 'action' => 'event:submit', 'idempotency_key' => 'event-projection', 'request' => ['source' => $source]]);
        $attempt->forceFill(['status' => 'accepted', 'outbox_state' => 'completed', 'provider_protocol' => $event->protocol])->save();
        app(ErpFiscalArtifactStore::class)->retainAcceptedEvent($event, $attempt, $xml, $source, $event->protocol);

        return [$document, $event, $client, $delegation, $issuer];
    }
}
