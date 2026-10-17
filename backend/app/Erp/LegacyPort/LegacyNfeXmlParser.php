<?php

namespace App\Erp\LegacyPort;

use SimpleXMLElement;

/**
 * NF-e entrada XML extraction (purchase import).
 *
 * @source opmonihub/sistema@e7b77be app/Http/Controllers/PurchaseXmlController.php::verXml
 * @source opmonihub/sistema@e7b77be app/Http/Controllers/NfeEntradaController.php (tipos pagamento — see LegacyFiscalEngine)
 */
class LegacyNfeXmlParser
{
    /**
     * @return array{
     *   chave: string,
     *   ide: array<string, mixed>,
     *   emit: array<string, mixed>,
     *   dest: array<string, mixed>,
     *   itens: array<int, array<string, mixed>>,
     *   totais: array<string, mixed>,
     *   fatura: array<int, array<string, mixed>>,
     *   dados_nf: array<string, mixed>
     * }
     */
    public function parse(string $xmlContent): array
    {
        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) {
            throw new \InvalidArgumentException('Não foi possível ler este XML!!');
        }

        if (! isset($xml->NFe->infNFe)) {
            throw new \InvalidArgumentException('Não foi possível ler este XML!!');
        }

        $inf = $xml->NFe->infNFe;
        $chave = substr((string) $inf->attributes()->Id, 3, 44);

        $ide = $this->extractIde($inf);
        $emit = $this->extractEmit($inf);
        $dest = $this->extractDest($inf);

        $itens = [];
        foreach ($inf->det as $det) {
            $itens[] = $this->extractDetItem($det);
        }

        $vFrete = number_format((float) $inf->total->ICMSTot->vFrete, 2, ',', '.');
        $vDesc = (string) $inf->total->ICMSTot->vDesc;

        $dadosNf = [
            'chave' => $chave,
            'vProd' => (string) $inf->total->ICMSTot->vProd,
            'indPag' => (string) $inf->ide->indPag,
            'nNf' => (string) $inf->ide->nNF,
            'vFrete' => $vFrete,
            'vDesc' => $vDesc,
            'vFinal' => (float) $inf->total->ICMSTot->vProd - (float) $vDesc,
        ];

        $fatura = [];
        if (! empty($inf->cobr->dup)) {
            foreach ($inf->cobr->dup as $dup) {
                $titulo = (string) $dup->nDup;
                $vencimento = (string) $dup->dVenc;
                $parts = explode('-', $vencimento);
                $vencimentoFmt = count($parts) === 3 ? $parts[2].'/'.$parts[1].'/'.$parts[0] : $vencimento;
                $vlr_parcela = number_format((float) $dup->vDup, 2, ',', '');

                $fatura[] = [
                    'numero' => $titulo,
                    'vencimento' => $vencimentoFmt,
                    'valor_parcela' => $vlr_parcela,
                ];
            }
        }

        return [
            'chave' => $chave,
            'ide' => $ide,
            'emit' => $emit,
            'dest' => $dest,
            'itens' => $itens,
            'totais' => $this->extractTotais($inf),
            'fatura' => $fatura,
            'dados_nf' => $dadosNf,
        ];
    }

    /**
     * @source PurchaseXmlController::verXml (emit block)
     *
     * @return array<string, mixed>
     */
    private function extractEmit(SimpleXMLElement $inf): array
    {
        $emit = $inf->emit;

        return [
            'cpf_cnpj' => isset($emit->CNPJ)
                ? $this->formataCnpj((string) $emit->CNPJ)
                : $this->formataCpf((string) $emit->CPF),
            'ie_rg' => (string) $emit->IE,
            'name' => (string) $emit->xNome,
            'rua' => (string) $emit->enderEmit->xLgr,
            'numero' => (string) $emit->enderEmit->nro,
            'bairro' => (string) $emit->enderEmit->xBairro,
            'cep' => (string) $emit->enderEmit->CEP,
            'cMun' => (string) $emit->enderEmit->cMun,
            'UF' => (string) $emit->enderEmit->UF,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractDest(SimpleXMLElement $inf): array
    {
        if (! isset($inf->dest)) {
            return [];
        }
        $dest = $inf->dest;

        return [
            'cpf_cnpj' => isset($dest->CNPJ)
                ? $this->formataCnpj((string) $dest->CNPJ)
                : (isset($dest->CPF) ? $this->formataCpf((string) $dest->CPF) : ''),
            'ie' => (string) ($dest->IE ?? ''),
            'name' => (string) ($dest->xNome ?? ''),
            'rua' => (string) ($dest->enderDest->xLgr ?? ''),
            'numero' => (string) ($dest->enderDest->nro ?? ''),
            'bairro' => (string) ($dest->enderDest->xBairro ?? ''),
            'cep' => (string) ($dest->enderDest->CEP ?? ''),
            'cMun' => (string) ($dest->enderDest->cMun ?? ''),
            'UF' => (string) ($dest->enderDest->UF ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractIde(SimpleXMLElement $inf): array
    {
        $ide = $inf->ide;

        return [
            'cUF' => (string) ($ide->cUF ?? ''),
            'natOp' => (string) ($ide->natOp ?? ''),
            'mod' => (string) ($ide->mod ?? ''),
            'serie' => (string) ($ide->serie ?? ''),
            'nNF' => (string) ($ide->nNF ?? ''),
            'dhEmi' => (string) ($ide->dhEmi ?? ''),
            'tpNF' => (string) ($ide->tpNF ?? ''),
            'idDest' => (string) ($ide->idDest ?? ''),
            'indFinal' => (string) ($ide->indFinal ?? ''),
            'indPag' => (string) ($ide->indPag ?? ''),
        ];
    }

    /**
     * @source PurchaseXmlController::verXml (det loop)
     *
     * @return array<string, mixed>
     */
    private function extractDetItem(SimpleXMLElement $item): array
    {
        $row = [
            'codigo' => (string) $item->prod->cProd,
            'xProd' => (string) $item->prod->xProd,
            'NCM' => (string) $item->prod->NCM,
            'CFOP' => (string) $item->prod->CFOP,
            'uCom' => (string) $item->prod->uCom,
            'vUnCom' => (string) $item->prod->vUnCom,
            'qCom' => (string) $item->prod->qCom,
            'codBarras' => (string) $item->prod->cEAN,
            'imposto' => $this->extractImposto($item),
        ];

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractImposto(SimpleXMLElement $det): array
    {
        if (! isset($det->imposto)) {
            return [];
        }

        $imposto = $det->imposto;
        $out = [];

        if (isset($imposto->ICMS->ICMS00)) {
            $node = $imposto->ICMS->ICMS00;
            $out['icms'] = [
                'CST' => (string) ($node->CST ?? ''),
                'vBC' => (string) ($node->vBC ?? ''),
                'pICMS' => (string) ($node->pICMS ?? ''),
                'vICMS' => (string) ($node->vICMS ?? ''),
            ];
        } elseif (isset($imposto->ICMS->ICMSSN102)) {
            $node = $imposto->ICMS->ICMSSN102;
            $out['icms'] = [
                'CSOSN' => (string) ($node->CSOSN ?? ''),
                'orig' => (string) ($node->orig ?? ''),
            ];
        }

        if (isset($imposto->PIS->PISAliq)) {
            $node = $imposto->PIS->PISAliq;
            $out['pis'] = [
                'CST' => (string) ($node->CST ?? ''),
                'vBC' => (string) ($node->vBC ?? ''),
                'pPIS' => (string) ($node->pPIS ?? ''),
                'vPIS' => (string) ($node->vPIS ?? ''),
            ];
        }

        if (isset($imposto->COFINS->COFINSAliq)) {
            $node = $imposto->COFINS->COFINSAliq;
            $out['cofins'] = [
                'CST' => (string) ($node->CST ?? ''),
                'vBC' => (string) ($node->vBC ?? ''),
                'pCOFINS' => (string) ($node->pCOFINS ?? ''),
                'vCOFINS' => (string) ($node->vCOFINS ?? ''),
            ];
        }

        if (isset($imposto->IPI->IPITrib)) {
            $node = $imposto->IPI->IPITrib;
            $out['ipi'] = [
                'CST' => (string) ($node->CST ?? ''),
                'vBC' => (string) ($node->vBC ?? ''),
                'pIPI' => (string) ($node->pIPI ?? ''),
                'vIPI' => (string) ($node->vIPI ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTotais(SimpleXMLElement $inf): array
    {
        $tot = $inf->total->ICMSTot;

        return [
            'vBC' => (string) ($tot->vBC ?? ''),
            'vICMS' => (string) ($tot->vICMS ?? ''),
            'vProd' => (string) ($tot->vProd ?? ''),
            'vFrete' => (string) ($tot->vFrete ?? ''),
            'vDesc' => (string) ($tot->vDesc ?? ''),
            'vPIS' => (string) ($tot->vPIS ?? ''),
            'vCOFINS' => (string) ($tot->vCOFINS ?? ''),
            'vNF' => (string) ($tot->vNF ?? ''),
        ];
    }

    /**
     * @source PurchaseXmlController (formataCnpj / formataCpf helpers)
     */
    public function formataCnpj(string $cnpj): string
    {
        $cnpj = preg_replace('/\D/', '', $cnpj) ?? $cnpj;
        if (strlen($cnpj) !== 14) {
            return $cnpj;
        }

        return substr($cnpj, 0, 2).'.'.substr($cnpj, 2, 3).'.'.substr($cnpj, 5, 3).'/'.
            substr($cnpj, 8, 4).'-'.substr($cnpj, 12, 2);
    }

    public function formataCpf(string $cpf): string
    {
        $cpf = preg_replace('/\D/', '', $cpf) ?? $cpf;
        if (strlen($cpf) !== 11) {
            return $cpf;
        }

        return substr($cpf, 0, 3).'.'.substr($cpf, 3, 3).'.'.substr($cpf, 6, 3).'-'.substr($cpf, 9, 2);
    }
}
