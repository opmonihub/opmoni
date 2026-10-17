<?php

namespace App\Erp\Stock;

use App\Erp\Finance\ErpDecimal;
use App\Models\Erp\Stock\ErpPurchase;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bounded NF-e purchase XML preview ported from sistema PurchaseXmlController / NfeEntrada flows.
 */
final class ErpPurchaseXmlPreviewService
{
    private const NFE_NS = 'http://www.portalfiscal.inf.br/nfe';

    /**
     * @return array{
     *     valid: bool,
     *     errors: list<string>,
     *     preview: array<string, mixed>|null
     * }
     */
    public function preview(string $xml, int $companyId, string $companyCnpj): array
    {
        $errors = [];

        try {
            $document = $this->loadXml($xml);
        } catch (RuntimeException $exception) {
            return [
                'valid' => false,
                'errors' => [$exception->getMessage()],
                'preview' => null,
            ];
        }

        $infNFe = $this->findInfNFe($document);
        if ($infNFe === null) {
            return [
                'valid' => false,
                'errors' => ['Não foi possível localizar infNFe no XML.'],
                'preview' => null,
            ];
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('nfe', self::NFE_NS);

        $chave = $this->extractAccessKey($infNFe);
        if ($chave === null) {
            $errors[] = 'Chave de acesso ausente ou inválida no atributo Id.';
        } elseif (! FiscalXmlMetadata::isValidChave($chave)) {
            $errors[] = 'Chave de acesso não fecha o dígito verificador módulo 11.';
        }

        $emitenteCnpj = $this->digits($this->firstText($xpath, $infNFe, [
            'nfe:emit/nfe:CNPJ',
            'nfe:emit/nfe:CPF',
        ]));
        if ($emitenteCnpj === null) {
            $errors[] = 'Emitente sem CNPJ/CPF.';
        }

        $destinatarioCnpj = $this->digits($this->firstText($xpath, $infNFe, [
            'nfe:dest/nfe:CNPJ',
            'nfe:dest/nfe:CPF',
        ]));
        $expectedDestinatario = $this->digits($companyCnpj);
        if ($destinatarioCnpj === null) {
            $errors[] = 'Destinatário sem CNPJ/CPF.';
        } elseif ($expectedDestinatario !== null && $destinatarioCnpj !== $expectedDestinatario) {
            $errors[] = 'Destinatário do XML não corresponde ao CNPJ da empresa.';
        }

        $items = $this->extractItems($xpath, $infNFe);
        if ($items === []) {
            $errors[] = 'Nenhum item de produto encontrado no XML.';
        }

        $totals = $this->extractTotals($xpath, $infNFe);
        if ($totals === null) {
            $errors[] = 'Totais ICMSTot ausentes no XML.';
        }

        $duplicatePurchaseId = $chave !== null
            ? $this->findDuplicatePurchaseId($companyId, $chave)
            : null;
        if ($duplicatePurchaseId !== null) {
            $errors[] = 'XML já importado para a empresa (chave duplicada).';
        }

        if ($errors !== []) {
            return [
                'valid' => false,
                'errors' => $errors,
                'preview' => null,
            ];
        }

        return [
            'valid' => true,
            'errors' => [],
            'preview' => [
                'chave' => $chave,
                'emitente_cnpj' => $emitenteCnpj,
                'destinatario_cnpj' => $destinatarioCnpj,
                'numero' => $this->firstText($xpath, $infNFe, ['nfe:ide/nfe:nNF']),
                'serie' => $this->firstText($xpath, $infNFe, ['nfe:ide/nfe:serie']),
                'modelo' => $this->firstText($xpath, $infNFe, ['nfe:ide/nfe:mod']),
                'emitente_nome' => $this->firstText($xpath, $infNFe, ['nfe:emit/nfe:xNome']),
                'totals' => $totals,
                'items' => $items,
                'duplicate_purchase_id' => $duplicatePurchaseId,
            ],
        ];
    }

    public function assertNotDuplicate(int $companyId, string $chave): void
    {
        if ($this->findDuplicatePurchaseId($companyId, $chave) !== null) {
            throw new InvalidArgumentException('Chave de NF-e de entrada já confirmada para esta empresa.');
        }
    }

    private function loadXml(string $xml): DOMDocument
    {
        $trimmed = trim($xml);
        if ($trimmed === '') {
            throw new RuntimeException('XML vazio.');
        }

        if (strlen($trimmed) > 2_000_000) {
            throw new RuntimeException('XML excede o limite de tamanho para pré-visualização.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($trimmed, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new RuntimeException('Não foi possível ler este XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function findInfNFe(DOMDocument $document): ?DOMElement
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('nfe', self::NFE_NS);
        $node = $xpath->query('//nfe:infNFe')->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function extractAccessKey(DOMElement $infNFe): ?string
    {
        $id = $infNFe->getAttribute('Id');
        if ($id === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $id) ?? '';
        if (strlen($digits) === 47 && str_starts_with(strtoupper($id), 'NFE')) {
            $digits = substr($digits, -44);
        }

        return strlen($digits) === 44 ? $digits : null;
    }

    /**
     * @param  list<string>  $paths
     */
    private function firstText(DOMXPath $xpath, DOMElement $context, array $paths): ?string
    {
        foreach ($paths as $path) {
            $node = $xpath->query($path, $context)->item(0);
            if ($node !== null) {
                $value = trim($node->textContent);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractItems(DOMXPath $xpath, DOMElement $infNFe): array
    {
        $items = [];
        $nodes = $xpath->query('nfe:det', $infNFe);
        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $index => $det) {
            if (! $det instanceof DOMElement) {
                continue;
            }

            $base = 'nfe:prod/';
            $items[] = [
                'line' => $index + 1,
                'codigo' => $this->firstText($xpath, $det, [$base.'nfe:cProd']),
                'descricao' => $this->firstText($xpath, $det, [$base.'nfe:xProd']),
                'ncm' => $this->firstText($xpath, $det, [$base.'nfe:NCM']),
                'cfop' => $this->firstText($xpath, $det, [$base.'nfe:CFOP']),
                'unidade' => $this->firstText($xpath, $det, [$base.'nfe:uCom']),
                'quantidade' => ErpDecimal::parse($this->firstText($xpath, $det, [$base.'nfe:qCom']) ?? '0'),
                'valor_unitario' => ErpDecimal::parse($this->firstText($xpath, $det, [$base.'nfe:vUnCom']) ?? '0'),
                'valor_total' => ErpDecimal::parse($this->firstText($xpath, $det, [$base.'nfe:vProd']) ?? '0'),
                'codigo_barras' => $this->firstText($xpath, $det, [$base.'nfe:cEAN']),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, string>|null
     */
    private function extractTotals(DOMXPath $xpath, DOMElement $infNFe): ?array
    {
        $base = 'nfe:total/nfe:ICMSTot/';
        $prod = $this->firstText($xpath, $infNFe, [$base.'nfe:vProd']);
        if ($prod === null) {
            return null;
        }

        $discount = $this->firstText($xpath, $infNFe, [$base.'nfe:vDesc']) ?? '0';
        $freight = $this->firstText($xpath, $infNFe, [$base.'nfe:vFrete']) ?? '0';
        $nf = $this->firstText($xpath, $infNFe, [$base.'nfe:vNF']);

        return [
            'v_prod' => ErpDecimal::parse($prod),
            'v_desc' => ErpDecimal::parse($discount),
            'v_frete' => ErpDecimal::parse($freight),
            'v_nf' => ErpDecimal::parse($nf ?? ErpDecimal::sub(ErpDecimal::add($prod, $freight), $discount)),
        ];
    }

    private function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits !== '' ? $digits : null;
    }

    private function findDuplicatePurchaseId(int $companyId, string $chave): ?int
    {
        $purchase = ErpPurchase::query()
            ->where('erp_company_id', $companyId)
            ->where(function ($query) use ($chave): void {
                $query->where('document_no', $chave)
                    ->orWhere('metadata->access_key', $chave)
                    ->orWhere('metadata->chave_entrada', $chave);
            })
            ->value('id');

        return $purchase !== null ? (int) $purchase : null;
    }
}
