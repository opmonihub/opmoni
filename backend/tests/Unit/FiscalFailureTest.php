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

    public function test_a_service_outage_is_retried_without_blocking_the_client(): void
    {
        // 108 e 109 são do serviço inteiro, não do CNPJ: um retry, não uma hora de silêncio.
        $this->assertSame(FiscalFailure::Upstream, FiscalFailure::classify(200, '108'));
        $this->assertSame(FiscalFailure::Upstream, FiscalFailure::classify(200, '109'));
        $this->assertTrue(FiscalFailure::Upstream->retryable());
        $this->assertFalse(FiscalFailure::Upstream->blocksForAnHour());
    }

    public function test_cursor_ahead_is_not_retryable(): void
    {
        $this->assertSame(FiscalFailure::CursorAhead, FiscalFailure::classify(200, '589'));
        $this->assertFalse(FiscalFailure::CursorAhead->retryable());
        $this->assertFalse(FiscalFailure::CursorAhead->blocksForAnHour());
    }

    public function test_credential_mismatch_is_unauthorized(): void
    {
        $this->assertSame(FiscalFailure::Unauthorized, FiscalFailure::classify(200, '593'));
        $this->assertSame(FiscalFailure::Unauthorized, FiscalFailure::classify(200, '472'));
        $this->assertSame(FiscalFailure::Unauthorized, FiscalFailure::classify(200, '473'));
        $this->assertFalse(FiscalFailure::Unauthorized->retryable());
        $this->assertFalse(FiscalFailure::Unauthorized->blocksForAnHour());
    }

    public function test_document_not_addressed_to_the_cnpj_is_not_interested(): void
    {
        $this->assertSame(FiscalFailure::NotInterested, FiscalFailure::classify(200, '640'));
        $this->assertFalse(FiscalFailure::NotInterested->retryable());
        $this->assertFalse(FiscalFailure::NotInterested->blocksForAnHour());
    }

    public function test_document_unavailable_to_its_issuer_is_its_own_reason(): void
    {
        $this->assertSame(FiscalFailure::UnavailableToIssuer, FiscalFailure::classify(200, '641'));
        $this->assertNotSame(FiscalFailure::classify(200, '640'), FiscalFailure::classify(200, '641'));
        $this->assertFalse(FiscalFailure::UnavailableToIssuer->retryable());
        $this->assertFalse(FiscalFailure::UnavailableToIssuer->blocksForAnHour());
    }

    public function test_schema_and_encoding_rejections_are_our_bug(): void
    {
        foreach (['215', '402', '404', '238', '239', '252', '214', '236', '217'] as $cStat) {
            $this->assertSame(FiscalFailure::Rejected, FiscalFailure::classify(200, $cStat), $cStat);
        }
    }

    public function test_the_remaining_rejection_codes_are_classified(): void
    {
        $this->assertSame(FiscalFailure::Blocked, FiscalFailure::classify(200, '678'));

        foreach (['632', '653', '654', '999'] as $cStat) {
            $this->assertSame(FiscalFailure::Rejected, FiscalFailure::classify(200, $cStat), $cStat);
        }
    }

    public function test_transport_failure_is_upstream(): void
    {
        $this->assertSame(FiscalFailure::Upstream, FiscalFailure::classify(503, ''));
        $this->assertSame(FiscalFailure::Upstream, FiscalFailure::classify(0, ''));
        $this->assertTrue(FiscalFailure::Upstream->retryable());
    }

    public function test_an_unknown_code_with_a_client_error_is_our_own_rejection(): void
    {
        // O outro lado do `default`: 4xx sem código conhecido é erro nosso, não do serviço.
        $this->assertSame(FiscalFailure::Rejected, FiscalFailure::classify(400, ''));
        $this->assertSame(FiscalFailure::Rejected, FiscalFailure::classify(404, '000'));
    }

    public function test_authorization_codes_never_reach_this_service(): void
    {
        // 297 e 539 pertencem ao serviço de autorização e não têm ramo aqui.
        $this->assertSame(FiscalFailure::Rejected, FiscalFailure::classify(200, '297'));
        $this->assertSame(FiscalFailure::Rejected, FiscalFailure::classify(200, '539'));
    }

    public function test_the_block_and_retry_axes_are_pinned_for_every_case(): void
    {
        // Verdade inteira, caso a caso: um `default` absorvendo um caso novo
        // transformaria um 5xx passageiro em uma hora de silêncio sem quebrar
        // mais nada. Por isso a comparação de conjuntos vem antes do laço.
        $expected = [
            'documents_found' => [false, false],
            'no_documents' => [true, false],
            'blocked' => [true, true],
            'cursor_ahead' => [false, false],
            'unauthorized' => [false, false],
            'not_interested' => [false, false],
            'unavailable_to_issuer' => [false, false],
            'rejected' => [false, false],
            'upstream' => [false, true],
        ];

        $cases = array_column(FiscalFailure::cases(), 'value');
        $pinned = array_keys($expected);
        sort($cases);
        sort($pinned);

        $this->assertSame($pinned, $cases, 'Todo caso do enum precisa de uma expectativa explícita.');

        foreach (FiscalFailure::cases() as $case) {
            [$blocks, $retryable] = $expected[$case->value];

            $this->assertSame($blocks, $case->blocksForAnHour(), $case->value);
            $this->assertSame($retryable, $case->retryable(), $case->value);
        }
    }
}
