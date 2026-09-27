<?php

namespace Tests\Unit;

use App\Enums\FiscalFailure;
use PHPUnit\Framework\TestCase;

class FiscalFailureTest extends TestCase
{
    public function test_documents_found_is_not_a_failure(): void
    {
        $this->assertSame(FiscalFailure::DocumentsFound, FiscalFailure::classify(200, '138'));
        $this->assertFalse(FiscalFailure::DocumentsFound->retryable());
        $this->assertFalse(FiscalFailure::DocumentsFound->blocksForAnHour());
    }

    public function test_no_documents_blocks_for_an_hour(): void
    {
        $this->assertSame(FiscalFailure::NoDocuments, FiscalFailure::classify(200, '137'));
        $this->assertTrue(FiscalFailure::NoDocuments->blocksForAnHour());
        $this->assertFalse(FiscalFailure::NoDocuments->retryable());
    }

    public function test_improper_consumption_blocks_for_an_hour(): void
    {
        $this->assertSame(FiscalFailure::Blocked, FiscalFailure::classify(200, '656'));
        $this->assertTrue(FiscalFailure::Blocked->blocksForAnHour());
        $this->assertTrue(FiscalFailure::Blocked->retryable());
    }

    public function test_service_outage_is_blocked_and_retryable(): void
    {
        $this->assertSame(FiscalFailure::Blocked, FiscalFailure::classify(200, '108'));
        $this->assertSame(FiscalFailure::Blocked, FiscalFailure::classify(200, '109'));
        $this->assertTrue(FiscalFailure::Blocked->retryable());
    }

    public function test_cursor_ahead_is_not_retryable(): void
    {
        $this->assertSame(FiscalFailure::CursorAhead, FiscalFailure::classify(200, '589'));
        $this->assertFalse(FiscalFailure::CursorAhead->retryable());
    }

    public function test_credential_mismatch_is_unauthorized(): void
    {
        $this->assertSame(FiscalFailure::Unauthorized, FiscalFailure::classify(200, '593'));
        $this->assertSame(FiscalFailure::Unauthorized, FiscalFailure::classify(200, '472'));
        $this->assertFalse(FiscalFailure::Unauthorized->retryable());
    }

    public function test_document_not_addressed_to_the_cnpj_is_not_interested(): void
    {
        $this->assertSame(FiscalFailure::NotInterested, FiscalFailure::classify(200, '640'));
        $this->assertSame(FiscalFailure::NotInterested, FiscalFailure::classify(200, '641'));
    }

    public function test_schema_and_encoding_rejections_are_our_bug(): void
    {
        foreach (['215', '402', '404', '238', '239', '252', '214', '236', '217'] as $cStat) {
            $this->assertSame(FiscalFailure::Rejected, FiscalFailure::classify(200, $cStat), $cStat);
        }
    }

    public function test_transport_failure_is_upstream(): void
    {
        $this->assertSame(FiscalFailure::Upstream, FiscalFailure::classify(503, ''));
        $this->assertSame(FiscalFailure::Upstream, FiscalFailure::classify(0, ''));
        $this->assertTrue(FiscalFailure::Upstream->retryable());
    }

    public function test_authorization_codes_never_reach_this_service(): void
    {
        // 297 e 539 pertencem ao serviço de autorização e não têm ramo aqui.
        $this->assertSame(FiscalFailure::Rejected, FiscalFailure::classify(200, '297'));
        $this->assertSame(FiscalFailure::Rejected, FiscalFailure::classify(200, '539'));
    }
}
