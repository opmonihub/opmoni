<?php

namespace App\Erp\Fiscal;

use Closure;
use InvalidArgumentException;
use NFePHP\DA\CTe\Dacte;
use NFePHP\DA\MDFe\Damdfe;
use NFePHP\DA\NFe\Danfce;
use NFePHP\DA\NFe\Danfe;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class ErpFiscalAuxiliaryRenderer
{
    /**
     * @var array<string, class-string>
     */
    private const RENDERERS = [
        '55' => Danfe::class,
        '65' => Danfce::class,
        '57' => Dacte::class,
        '58' => Damdfe::class,
    ];

    /** @var array<string, string> */
    private const ROOTS = [
        '55' => 'nfeProc',
        '65' => 'nfeProc',
        '57' => 'cteProc',
        '58' => 'mdfeProc',
    ];

    /** @var array<string, string> */
    private const NAMESPACES = [
        '55' => 'http://www.portalfiscal.inf.br/nfe',
        '65' => 'http://www.portalfiscal.inf.br/nfe',
        '57' => 'http://www.portalfiscal.inf.br/cte',
        '58' => 'http://www.portalfiscal.inf.br/mdfe',
    ];

    public function __construct(private readonly ?Closure $renderOverride = null) {}

    public function render(string $model, string $verifiedProcessedXml): string
    {
        if (! isset(self::RENDERERS[$model])) {
            throw new InvalidArgumentException('Modelo fiscal sem documento auxiliar suportado.');
        }

        if ($verifiedProcessedXml === '' || strlen($verifiedProcessedXml) > 5_000_000
            || preg_match('/<!DOCTYPE|<!ENTITY/i', $verifiedProcessedXml)) {
            throw new InvalidArgumentException('XML processado inválido para renderização.');
        }

        $document = new \DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previousLibxmlErrorMode = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($verifiedProcessedXml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlErrorMode);
        }
        if (! $loaded || ! $document->documentElement instanceof \DOMElement
            || $document->documentElement->localName !== self::ROOTS[$model]
            || $document->documentElement->namespaceURI !== self::NAMESPACES[$model]) {
            throw new InvalidArgumentException('XML processado inválido para renderização.');
        }

        try {
            if ($this->renderOverride !== null) {
                $pdf = ($this->renderOverride)($model, $verifiedProcessedXml);
            } else {
                $rendererClass = self::RENDERERS[$model];
                if (! class_exists($rendererClass)) {
                    throw new HttpException(503, 'Documento auxiliar indisponível.');
                }

                $pdf = (new $rendererClass($verifiedProcessedXml))->render();
            }
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new HttpException(503, 'Documento auxiliar indisponível.');
        }

        if (! is_string($pdf) || strlen($pdf) < 10 || strlen($pdf) > 20_000_000
            || ! str_starts_with($pdf, '%PDF-') || ! preg_match('/%%EOF\s*$/', $pdf)) {
            throw new HttpException(503, 'Documento auxiliar indisponível.');
        }

        return $pdf;
    }
}
