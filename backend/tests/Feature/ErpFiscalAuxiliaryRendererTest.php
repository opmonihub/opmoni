<?php

namespace Tests\Feature;

use App\Erp\Fiscal\ErpFiscalArtifactStore;
use App\Erp\Fiscal\ErpFiscalAuxiliaryRenderer;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use InvalidArgumentException;
use NFePHP\DA\CTe\Dacte;
use NFePHP\DA\MDFe\Damdfe;
use NFePHP\DA\NFe\Danfce;
use NFePHP\DA\NFe\Danfe;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ErpFiscalAuxiliaryRendererTest extends TestCase
{
    public function test_unsupported_model_is_rejected_before_renderer_dependency_is_called(): void
    {
        $called = false;
        $renderer = new ErpFiscalAuxiliaryRenderer(function () use (&$called): string {
            $called = true;

            return 'invalid PDF bytes';
        });

        try {
            $renderer->render('99', '<xml/>');
            $this->fail('Unsupported fiscal models must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Modelo fiscal sem documento auxiliar suportado.', $exception->getMessage());
        }

        $this->assertFalse($called);
    }

    public function test_invalid_or_unsafe_xml_is_rejected_before_renderer_dependency_is_called(): void
    {
        foreach (['', '<nfeProc>', '<!DOCTYPE nfeProc [<!ENTITY x "unsafe">]><nfeProc>&x;</nfeProc>'] as $xml) {
            $called = false;
            $renderer = new ErpFiscalAuxiliaryRenderer(function () use (&$called): string {
                $called = true;

                return 'invalid PDF bytes';
            });

            try {
                $renderer->render('55', $xml);
                $this->fail('Invalid fiscal XML must be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('XML processado inválido para renderização.', $exception->getMessage());
            }

            $this->assertFalse($called);
        }
    }

    public function test_invalid_renderer_output_is_not_returned_as_a_pdf(): void
    {
        $renderer = new ErpFiscalAuxiliaryRenderer(static fn (): string => 'not a PDF');

        try {
            $renderer->render('55', '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"/>');
            $this->fail('Invalid renderer output must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame('Documento auxiliar indisponível.', $exception->getMessage());
        }
    }

    public function test_processed_xml_root_must_match_the_selected_fiscal_model(): void
    {
        $called = false;
        $renderer = new ErpFiscalAuxiliaryRenderer(function () use (&$called): string {
            $called = true;

            return '%PDF-1.4'.str_repeat('x', 100).'%%EOF';
        });

        try {
            $renderer->render('57', '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"/>');
            $this->fail('A processed XML for another model must not reach its renderer.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('XML processado inválido para renderização.', $exception->getMessage());
        }

        $this->assertFalse($called);
    }

    public function test_store_refuses_unverified_authorized_artifacts_before_renderer_is_called(): void
    {
        $called = false;
        $renderer = new ErpFiscalAuxiliaryRenderer(function () use (&$called): string {
            $called = true;

            return 'invalid PDF bytes';
        });
        $store = new ErpFiscalArtifactStore($renderer);
        $document = new ErpFiscalDocument([
            'status' => 'authorized',
            'model' => '55',
            'environment' => 'homologation',
            'access_key' => '35251030288513000100550010000000011123456789',
            'artifact_byte_length' => 10,
            'artifact_sha256' => str_repeat('0', 64),
            'metadata' => ['provider_protocol' => '135260000000001'],
        ]);

        try {
            $store->renderPdfBytes($document);
            $this->fail('Unverified fiscal artifacts must not be rendered.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertFalse($called);
    }

    #[DataProvider('supportedFiscalModelProvider')]
    public function test_upstream_renderer_returns_pdf_bytes_for_sanitized_processed_xml(string $model, string $fixture, string $rendererClass): void
    {
        if (! class_exists($rendererClass)) {
            $this->markTestSkipped('NFePHP auxiliary renderer dependencies are not installed in this runtime.');
        }

        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/task20-auxiliary/'.$fixture));
        $this->assertIsString($xml);

        $pdf = app(ErpFiscalAuxiliaryRenderer::class)->render($model, $xml);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertMatchesRegularExpression('/%%EOF\s*$/', $pdf);
        $this->assertGreaterThan(100, strlen($pdf));
    }

    /** @return array<string, array{string, string, class-string}> */
    public static function supportedFiscalModelProvider(): array
    {
        return [
            'NF-e' => ['55', 'danfe-55.xml', Danfe::class],
            'NFC-e' => ['65', 'danfce-65.xml', Danfce::class],
            'CT-e' => ['57', 'dacte-57.xml', Dacte::class],
            'MDF-e' => ['58', 'damdfe-58.xml', Damdfe::class],
        ];
    }
}
