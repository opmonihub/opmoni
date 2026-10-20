<?php

namespace App\Erp\Stock;

use App\Erp\Sales\ErpCommercialDraftService as Decimal;
use App\Models\Erp\Stock\ErpPurchase;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class ErpPurchaseXmlPreviewService
{
    public const VERSION = 'purchase-nfe-moc7-v1';

    private const NS = 'http://www.portalfiscal.inf.br/nfe';

    /** Compatibility entry point; the HTTP and durable importer use the same bounded parser. */
    public function preview(string $xml, int $companyId, string $companyCnpj): array
    {
        try {
            $source = $this->parse($xml, $companyCnpj);
        } catch (RuntimeException $exception) {
            return ['valid' => false, 'errors' => [$exception->getMessage()], 'preview' => null];
        }
        $errors = array_column($source['errors'], 'message');
        if ($this->duplicate($companyId, $source['chave'])) {
            $errors[] = 'XML já importado para a empresa (chave duplicada).';
        }
        $source += ['emitente_cnpj' => preg_replace('/\D+/', '', $source['emit']['cpf_cnpj']), 'destinatario_cnpj' => preg_replace('/\D+/', '', $source['dest']['cpf_cnpj']), 'items' => $source['det'], 'totals' => ['v_prod' => isset($source['total']['vProd']) ? bcadd($source['total']['vProd'], '0', 4) : null, 'v_nf' => isset($source['total']['vNF']) ? bcadd($source['total']['vNF'], '0', 4) : null]];

        return ['valid' => $errors === [], 'errors' => $errors, 'preview' => $source];
    }

    public function duplicate(int $companyId, ?string $key): ?ErpPurchase
    {
        if ($key === null) {
            return null;
        }

        return ErpPurchase::where('erp_company_id', $companyId)->where(fn ($q) => $q->where('source_access_key', $key)->orWhere('document_no', $key)->orWhere('metadata->access_key', $key)->orWhere('metadata->chave_entrada', $key))->first();
    }

    public function assertNotDuplicate(int $companyId, string $chave): void
    {
        if ($this->duplicate($companyId, $chave)) {
            throw new InvalidArgumentException('Chave de NF-e de entrada já confirmada para esta empresa.');
        }
    }

    public function parse(string $xml, string $destination): array
    {
        if (strlen($xml) > 2_000_000 || trim($xml) === '' || str_contains($xml, "\0") || ! mb_check_encoding($xml, 'UTF-8') || preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i', $xml) || (preg_match('/<\?xml[^?]*encoding\s*=\s*[\'"]([^\'"]+)/i', $xml, $encoding) && strtoupper($encoding[1]) !== 'UTF-8')) {
            throw new RuntimeException('XML vazio, codificação inválida, declaração proibida ou limite de 2.000.000 bytes excedido.');
        }
        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) || $document->doctype !== null) {
                throw new RuntimeException('Não foi possível ler este XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('n', self::NS);
        $root = $document->documentElement;
        $path = match (true) {
            $root?->namespaceURI === self::NS && $root->localName === 'nfeProc' => '/n:nfeProc/n:NFe/n:infNFe',
            $root?->namespaceURI === self::NS && $root->localName === 'NFe' => '/n:NFe/n:infNFe',
            default => throw new RuntimeException('Raiz NF-e não suportada.'),
        };
        if ($xpath->query($path)->length !== 1 || $xpath->query('//n:infNFe')->length !== 1 || $xpath->query('//n:NFe')->length !== 1) {
            throw new RuntimeException('Informe exatamente uma NF-e.');
        }
        $note = $xpath->query($path)->item(0);
        $text = static fn (string $path, ?DOMElement $node = null): string => trim($xpath->evaluate('string('.$path.')', $node ?? $note));
        $errors = [];
        $error = static function (string $field, string $message) use (&$errors): void {
            $errors[] = ['field' => $field, 'code' => 'source_validation', 'message' => $message];
        };
        $key = preg_match('/^NFe([0-9]{44})$/D', $note->getAttribute('Id'), $match) ? $match[1] : null;
        if ($key === null || ! FiscalXmlMetadata::isValidChave($key)) {
            $error('chave', 'Chave de acesso ausente ou inválida.');
        }
        $issuer = $text('n:emit/n:CNPJ') ?: $text('n:emit/n:CPF');
        $destinationCnpj = $text('n:dest/n:CNPJ') ?: $text('n:dest/n:CPF');
        if (! preg_match('/^[0-9]{14}$/D', $issuer) || ($key !== null && substr($key, 6, 14) !== $issuer)) {
            $error('emit.CNPJ', 'Emitente difere da chave ou não possui CNPJ válido.');
        }
        if (! preg_match('/^[0-9]{14}$/D', $destination) || $destinationCnpj !== $destination) {
            $error('dest.CNPJ', 'Destinatário do XML não corresponde ao CNPJ da empresa.');
        }
        foreach (['cUF' => [0, 2], 'mod' => [20, 2], 'serie' => [22, 3], 'nNF' => [25, 9], 'tpEmis' => [34, 1], 'cNF' => [35, 8], 'cDV' => [43, 1]] as $name => [$offset, $width]) {
            $value = $text('n:ide/n:'.$name);
            if (($name === 'mod' && ($value !== '55' || ($key !== null && substr($key, 20, 2) !== '55'))) || $value === '' || ! ctype_digit($value) || strlen($value) > $width || ($key !== null && str_pad($value, $width, '0', STR_PAD_LEFT) !== substr($key, $offset, $width))) {
                $error('ide.'.$name, 'Identificação da nota difere da chave.');
            }
        }
        foreach ($xpath->query('/n:nfeProc/n:protNFe/n:infProt/n:chNFe') as $protocol) {
            if (trim($protocol->textContent) !== $key) {
                $error('protNFe.chNFe', 'Protocolo difere da chave.');
            }
        }
        $items = [];
        $seen = [];
        $nodes = $xpath->query('n:det', $note);
        if ($nodes->length < 1 || $nodes->length > 500) {
            $error('det', 'Informe entre 1 e 500 itens.');
        }
        foreach ($nodes as $index => $det) {
            if ($index >= 500) {
                break;
            }
            $nItem = $det->getAttribute('nItem');
            if (! preg_match('/^[1-9][0-9]{0,5}$/D', $nItem) || in_array($nItem, $seen, true)) {
                $error('det.'.$index.'.nItem', 'Identificador de item inválido ou duplicado.');
            }
            $seen[] = $nItem;
            $prod = $xpath->query('n:prod', $det)->item(0);
            $facts = $this->tree($det);
            $item = ['line' => $index + 1, 'source_n_item' => $nItem, 'source_facts' => $facts];
            foreach (['cProd', 'xProd', 'NCM', 'CFOP', 'uCom', 'vUnCom', 'qCom', 'vProd', 'cEAN'] as $name) {
                $item[$name] = $text('n:prod/n:'.$name, $det);
            }
            $item += ['codigo' => $item['cProd'], 'codBarras' => $item['cEAN'], 'imposto' => $this->tree($xpath->query('n:imposto', $det)->item(0))];
            try {
                $item['quantity'] = Decimal::decimal($item['qCom'], 'qCom');
                if (bccomp($item['quantity'], '0', 4) <= 0 || $item['xProd'] === '' || $item['uCom'] === '') {
                    throw ValidationException::withMessages(['prod' => 'Quantidade, descrição ou unidade inválida.']);
                }
                if (! preg_match('/^[0-9]{1,18}(?:\.[0-9]{1,10})?$/D', $item['vUnCom'])) {
                    throw ValidationException::withMessages(['vUnCom' => 'Preço de origem exige até dez casas decimais.']);
                }
                $item['unit_cost'] = Decimal::decimal(bcadd($item['vUnCom'], '0', 4), 'vUnCom');
                if ($text('n:prod/n:indTot', $det) === '0') {
                    $error('det.'.$index.'.indTot', 'Item fora do total exige reconciliação explícita.');
                }
                $item['deson_deduction_flag'] = $text('n:imposto/n:ICMS/*/n:indDeduzDeson', $det) ?: null;
                if (! in_array($item['deson_deduction_flag'], [null, '0', '1'], true)) {
                    $error('det.'.$index.'.indDeduzDeson', 'Indicador de dedução inválido.');
                }
                $item['components'] = [];
                foreach ($this->components() as $name => $relative) {
                    $raw = $text($relative, $det);
                    $item['components'][$name] = Decimal::decimal($raw === '' ? '0' : $raw, $name);
                }
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    $error('det.'.$index.'.'.$field, $messages[0]);
                }
            }
            $items[] = $item;
        }
        $totals = $this->tree($xpath->query('n:total/n:ICMSTot', $note)->item(0));
        foreach ($xpath->query('.//*[local-name()="IBSCBS" or local-name()="vNFTot" or local-name()="vItem" or local-name()="IS" or local-name()="ISSQN" or local-name()="ISSQNtot" or local-name()="PISST" or local-name()="COFINSST"]', $note) as $unsupported) {
            $error('source.'.$unsupported->localName, 'Composição de origem ainda não suportada; mantenha a prévia para reconciliação.');
        }
        $knownAmounts = ['vProd', 'vDesc', 'vFrete', 'vSeg', 'vOutro', 'vNF', 'vUnCom', 'vUnTrib', 'vBC', 'vICMS', 'vICMSDeson', 'vBCST', 'vICMSST', 'vST', 'vFCP', 'vFCPST', 'vFCPSTRet', 'vFCPDif', 'vFCPEfet', 'vBCFCP', 'vBCFCPST', 'vBCFCPSTRet', 'vICMSDif', 'vICMSOp', 'vICMSEfet', 'vBCEfet', 'vBCSTRet', 'vICMSSTRet', 'vICMSSubstituto', 'vICMSUFRemet', 'vBCSTDest', 'vICMSSTDest', 'vCredICMSSN', 'vBCUFDest', 'vBCFCPUFDest', 'vFCPUFDest', 'vICMSUFDest', 'vII', 'vIPI', 'vIPIDevol', 'vPIS', 'vCOFINS', 'vTotTrib', 'vAFRMM', 'vIOF', 'vDespAdu', 'vOrig', 'vLiq', 'vDup', 'vPag', 'vTroco'];
        foreach ($xpath->query('.//*[not(*)]', $note) as $leaf) {
            if (preg_match('/^v[A-Z]/', $leaf->localName) && ! in_array($leaf->localName, $knownAmounts, true)) {
                $error('source.'.$leaf->localName, 'Componente monetário de origem não suportado.');
            }
        }
        $rawTotals = $totals;
        if ($errors === []) {
            try {
                $this->compose($items, $totals);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    $error($field, $messages[0]);
                }
            }
        }
        $duplicates = [];
        foreach ($xpath->query('n:cobr/n:dup', $note) as $dup) {
            $facts = $this->tree($dup);
            $duplicates[] = $facts + ['numero' => $facts['nDup'] ?? null, 'vencimento' => $facts['dVenc'] ?? null, 'valor' => $facts['vDup'] ?? null];
        }

        $emit = $this->displayParty($this->tree($xpath->query('n:emit', $note)->item(0)), $issuer, true);
        $dest = $this->displayParty($this->tree($xpath->query('n:dest', $note)->item(0)), $destinationCnpj, false);
        foreach ($duplicates as &$duplicate) {
            $duplicate['valor_parcela'] = $this->displayMoney($duplicate['vDup'] ?? '');
            if (isset($duplicate['dVenc']) && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $duplicate['dVenc'], $date)) {
                $duplicate['vencimento'] = $date[3].'/'.$date[2].'/'.$date[1];
            }
        }
        unset($duplicate);

        return ['policy_version' => self::VERSION, 'chave' => $key, 'numero' => $text('n:ide/n:nNF'), 'serie' => $text('n:ide/n:serie'), 'ide' => $this->tree($xpath->query('n:ide', $note)->item(0)), 'emit' => $emit, 'dest' => $dest, 'det' => $items, 'total' => $rawTotals, 'accepted_totals' => $totals, 'duplicatas' => $duplicates, 'source_facts' => $this->tree($note), 'errors' => $errors];
    }

    /** @param array<string, mixed> $source */
    private function displayParty(array $source, string $identifier, bool $issuer): array
    {
        $address = $source[$issuer ? 'enderEmit' : 'enderDest'] ?? [];

        return $source + [
            'cpf_cnpj' => $this->displayIdentifier($identifier),
            'name' => $source['xNome'] ?? '',
            'ie_rg' => $issuer ? ($source['IE'] ?? '') : ($source['IE'] ?? ''),
            'ie' => $source['IE'] ?? '',
            'rua' => $address['xLgr'] ?? '',
            'numero' => $address['nro'] ?? '',
            'bairro' => $address['xBairro'] ?? '',
            'cep' => $address['CEP'] ?? '',
            'cMun' => $address['cMun'] ?? '',
            'UF' => $address['UF'] ?? '',
        ];
    }

    private function displayIdentifier(string $identifier): string
    {
        if (strlen($identifier) === 14) {
            return substr($identifier, 0, 2).'.'.substr($identifier, 2, 3).'.'.substr($identifier, 5, 3).'/'.substr($identifier, 8, 4).'-'.substr($identifier, 12, 2);
        }

        if (strlen($identifier) === 11) {
            return substr($identifier, 0, 3).'.'.substr($identifier, 3, 3).'.'.substr($identifier, 6, 3).'-'.substr($identifier, 9, 2);
        }

        return $identifier;
    }

    private function displayMoney(string $value): string
    {
        if (preg_match('/^([0-9]+)(?:\.([0-9]{1,4}))?$/D', $value, $matches) !== 1) {
            return $value;
        }
        $cents = str_pad(substr($matches[2] ?? '', 0, 2), 2, '0');

        return $matches[1].','.$cents;
    }

    private function components(): array
    {
        return ['vProd' => 'n:prod/n:vProd', 'vDesc' => 'n:prod/n:vDesc', 'vFrete' => 'n:prod/n:vFrete', 'vSeg' => 'n:prod/n:vSeg', 'vOutro' => 'n:prod/n:vOutro', 'vST' => 'n:imposto/n:ICMS/*/n:vICMSST', 'vFCPST' => 'n:imposto/n:ICMS/*/n:vFCPST', 'vII' => 'n:imposto/n:II/n:vII', 'vIPI' => 'n:imposto/n:IPI/n:IPITrib/n:vIPI', 'vIPIDevol' => 'n:impostoDevol/n:IPI/n:vIPIDevol', 'vICMSDeson' => 'n:imposto/n:ICMS/*/n:vICMSDeson'];
    }

    private function compose(array &$items, array &$totals): void
    {
        $sourceTotal = Decimal::decimal($totals['vNF'] ?? null, 'total.vNF');
        $weight = array_reduce($items, fn ($sum, $item) => bcadd($sum, $item['components']['vProd'], 4), '0.0000');
        foreach (array_keys($this->components()) as $component) {
            $sum = array_reduce($items, fn ($sum, $item) => bcadd($sum, $item['components'][$component], 4), '0.0000');
            $total = Decimal::decimal($totals[$component] ?? $sum, 'total.'.$component);
            $totals[$component] = $total;
            if (bccomp($total, $sum, 4) < 0 || ($component === 'vProd' && $total !== $sum)) {
                throw ValidationException::withMessages(['total.'.$component => 'Total difere da soma dos itens.']);
            }
            $difference = bcsub($total, $sum, 4);
            $cumulative = $allocated = '0.0000';
            foreach ($items as $index => &$item) {
                $cumulative = bcadd($cumulative, $item['components']['vProd'], 4);
                $next = $index === count($items) - 1 ? $difference : (bccomp($weight, '0', 4) === 0 ? '0.0000' : bcdiv(bcmul($difference, $cumulative, 8), $weight, 4));
                $share = bcsub($next, $allocated, 4);
                $allocated = $next;
                $item['allocated_components'][$component] = $share;
                $item['accepted_components'][$component] = bcadd($item['components'][$component], $share, 4);
            }
            unset($item);
        }
        $known = $missing = '0.0000';
        foreach ($items as $item) {
            $parts = $item['accepted_components'];
            $known = bcadd($known, bcsub(bcadd(bcadd(bcadd($parts['vProd'], $parts['vFrete'], 4), $parts['vSeg'], 4), $parts['vOutro'], 4), $parts['vDesc'], 4), 4);
            foreach (['vST', 'vFCPST', 'vII', 'vIPI', 'vIPIDevol'] as $name) {
                $known = bcadd($known, $parts[$name], 4);
            }
            if ($item['deson_deduction_flag'] === '1') {
                $known = bcsub($known, $parts['vICMSDeson'], 4);
            } elseif ($item['deson_deduction_flag'] === null) {
                $missing = bcadd($missing, $parts['vICMSDeson'], 4);
            }
        }
        $missingMode = $known === $sourceTotal ? 'none' : (bcsub($known, $missing, 4) === $sourceTotal ? 'all' : null);
        if ($missingMode === null && bccomp($missing, '0', 4) > 0) {
            throw ValidationException::withMessages(['total.vICMSDeson' => 'deson_composition_ambiguous: dedução não comprovada pela composição integral.']);
        }
        $sum = '0.0000';
        foreach ($items as &$item) {
            $parts = $item['accepted_components'];
            $gross = array_reduce(['vProd', 'vFrete', 'vSeg', 'vOutro'], fn ($sum, $key) => bcadd($sum, $parts[$key], 4), '0.0000');
            $deducts = $item['deson_deduction_flag'] === '1' || ($item['deson_deduction_flag'] === null && $missingMode === 'all');
            $item['deson_composition'] = ['original_flag' => $item['deson_deduction_flag'], 'deducted' => $deducts, 'mode' => $item['deson_deduction_flag'] === null ? 'exact_document_reconciliation_'.$missingMode : 'explicit_source_flag', 'legal_applicability' => null];
            $discount = bcadd($parts['vDesc'], $deducts ? $parts['vICMSDeson'] : '0', 4);
            $tax = array_reduce(['vST', 'vFCPST', 'vII', 'vIPI', 'vIPIDevol'], fn ($sum, $key) => bcadd($sum, $parts[$key], 4), '0.0000');
            $net = bcsub($gross, $discount, 4);
            if (bccomp($net, '0', 4) < 0) {
                throw ValidationException::withMessages(['det.'.($item['line'] - 1).'.vDesc' => 'Reduções excedem a consideração do item.']);
            }
            $total = Decimal::decimal(bcadd($net, $tax, 4), 'line_total');
            $item['amounts'] = ['gross_amount' => $gross, 'discount_amount' => $discount, 'net_amount' => $net, 'tax_amount' => $tax, 'line_total' => $total];
            $sum = bcadd($sum, $total, 4);
        }
        unset($item);
        if ($sum !== $sourceTotal) {
            throw ValidationException::withMessages(['total.vNF' => 'Composição de origem não reconciliada: soma '.$sum.' difere de vNF '.$sourceTotal.'.']);
        }
        $totals['vNF'] = $sourceTotal;
    }

    private function tree(?\DOMNode $node): array
    {
        if ($node === null) {
            return [];
        }
        $result = [];
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $hasElements = false;
            foreach ($child->childNodes as $descendant) {
                $hasElements = $hasElements || $descendant instanceof DOMElement;
            }
            $value = $hasElements ? $this->tree($child) : trim($child->textContent);
            if (array_key_exists($child->localName, $result)) {
                if (! is_array($result[$child->localName]) || ! array_is_list($result[$child->localName])) {
                    $result[$child->localName] = [$result[$child->localName]];
                }
                $result[$child->localName][] = $value;
            } else {
                $result[$child->localName] = $value;
            }
        }

        return $result;
    }
}
