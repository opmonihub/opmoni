<?php

namespace App\Erp\LegacyPort;

/**
 * Fiscal XML field builders and tax math from legacy NFe/NFCe/CTe/MDFe/Devolucao services.
 *
 * @source opmonihub/sistema@e7b77be app/Services/NFeService.php
 * @source opmonihub/sistema@e7b77be app/Services/NFCeService.php
 * @source opmonihub/sistema@e7b77be app/Services/CTeService.php
 * @source opmonihub/sistema@e7b77be app/Services/MDFeService.php
 * @source opmonihub/sistema@e7b77be app/Services/DevolucaoService.php
 * @source opmonihub/sistema@e7b77be app/Http/Controllers/NfeEntradaController.php
 */
class LegacyFiscalEngine
{
    /**
     * @source NFeService::format
     */
    public function format(float|int|string $number, int $dec = 4): string
    {
        return number_format((float) $number, $dec, '.', '');
    }

    /**
     * @source NFeService::format2
     */
    public function format2(float|int|string $number, int $dec = 2): string
    {
        return number_format((float) $number, $dec, '.', '');
    }

    /**
     * @source CTeService::format
     */
    public function formatCte(float|int|string $number, int $dec = 2): string
    {
        return number_format((float) $number, $dec, '.', '');
    }

    /**
     * @source CTeService::montaChave (gerarChave / DV)
     */
    public function montaChave(
        int $cUF,
        int $ano,
        int $mes,
        string $cnpj,
        int $mod,
        int $serie,
        int $numero,
        int $tpEmis,
        string $codigo = ''
    ): string {
        if ($codigo == '') {
            $codigo = (string) $numero;
        }
        $forma = '%02d%02d%02d%s%02d%03d%09d%01d%08d';
        $chave = sprintf(
            $forma,
            $cUF,
            $ano,
            $mes,
            $cnpj,
            $mod,
            $serie,
            $numero,
            $tpEmis,
            (int) $codigo
        );

        return $chave.$this->calculaDV($chave);
    }

    /**
     * @source CTeService::calculaDV
     */
    public function calculaDV(string $chave43): string
    {
        $multiplicadores = [2, 3, 4, 5, 6, 7, 8, 9];
        $iCount = 42;
        $somaPonderada = 0;
        while ($iCount >= 0) {
            for ($mCount = 0; $mCount < count($multiplicadores) && $iCount >= 0; $mCount++) {
                $num = (int) substr($chave43, $iCount, 1);
                $peso = (int) $multiplicadores[$mCount];
                $somaPonderada += $num * $peso;
                $iCount--;
            }
        }
        $resto = $somaPonderada % 11;
        if ($resto == '0' || $resto == '1') {
            $cDV = 0;
        } else {
            $cDV = 11 - $resto;
        }

        return (string) $cDV;
    }

    /**
     * @source NFeService::retiraAcentos
     */
    public function retiraAcentos(string $texto): string
    {
        return preg_replace(
            ['/(á|à|ã|â|ä)/', '/(Á|À|Ã|Â|Ä)/', '/(é|è|ê|ë)/', '/(É|È|Ê|Ë)/', '/(í|ì|î|ï)/', '/(Í|Ì|Î|Ï)/', '/(ó|ò|õ|ô|ö)/', '/(Ó|Ò|Õ|Ô|Ö)/', '/(ú|ù|û|ü)/', '/(Ú|Ù|Û|Ü)/', '/(ñ)/', '/(Ñ)/', '/(ç)/', '/(Ç)/', '/(°)/'],
            explode(' ', 'a A e E i I o O u U n N c C o'),
            $texto
        ) ?? $texto;
    }

    /**
     * @source NfeEntradaController::tiposPagamento
     *
     * @return array<string, string>
     */
    public static function tiposPagamentoNfeEntrada(): array
    {
        return [
            '01' => 'Dinheiro',
            '02' => 'Cheque',
            '03' => 'Cartão de Crédito',
            '04' => 'Cartão de Débito',
            '05' => 'Crédito Loja',
            '10' => 'Vale Alimentação',
            '11' => 'Vale Refeição',
            '12' => 'Vale Presente',
            '13' => 'Vale Combustível',
            '14' => 'Duplicata Mercantil',
            '15' => 'Boleto Bancário',
            '90' => 'Sem pagamento',
            '99' => 'Outros',
        ];
    }

    /**
     * @source NFeService::gerarNFe (IBPT vTotTrib accumulation)
     *
     * @param  object{nacional_federal: float|int, estadual: float|int, municipal: float|int}|null  $ibpt
     * @return array{federal: float, estadual: float, municipal: float, total: float}
     */
    public function accumulateIbptTotals(float|string $vProd, ?object $ibpt, float $somaFederal, float $somaEstadual, float $somaMunicipal): array
    {
        if ($ibpt == null) {
            return [
                'federal' => $somaFederal,
                'estadual' => $somaEstadual,
                'municipal' => $somaMunicipal,
                'total' => 0,
            ];
        }

        $vProd = (float) $vProd;
        $federal = ($vProd * ($ibpt->nacional_federal / 100));
        $somaFederal += $federal;
        $estadual = ($vProd * ($ibpt->estadual / 100));
        $somaEstadual += $estadual;
        $municipal = ($vProd * ($ibpt->municipal / 100));
        $somaMunicipal += $municipal;
        $soma = $federal + $estadual + $municipal;

        return [
            'federal' => $somaFederal,
            'estadual' => $somaEstadual,
            'municipal' => $somaMunicipal,
            'total' => $soma,
        ];
    }

    /**
     * @source NFeService::gerarNFe (ICMS regime normal)
     *
     * @param  object{unit_price: float|int, quantity: float|int}  $line
     * @param  object  $product  Product fiscal fields (mutated if $percentualImpostoEstado set)
     * @param  object{vProd: float|string, qTrib?: float|string}  $stdProd
     * @param  object{regime: int, exclusao_icms_pis_cofins?: bool}  $config
     * @param  object{percentual_icms?: float, percentual_pis?: float, percentual_cofins?: float, percentual_ipi?: float}|null  $percentualImpostoEstado
     */
    public function buildStdIcmsRegimeNormal(
        int $itemCont,
        object $line,
        object $product,
        object $stdProd,
        object $config,
        ?object $percentualImpostoEstado,
        float &$somaICMS,
        float &$somaVICMSST,
        float &$vbc
    ): \stdClass {
        $stdICMS = new \stdClass;
        $stdICMS->item = $itemCont;
        $stdICMS->orig = $product->origem;
        $stdICMS->CST = $product->cst_csosn;
        $stdICMS->modBC = 0;

        if ($percentualImpostoEstado != null) {
            $product->perc_icms = $percentualImpostoEstado->percentual_icms ?? $product->perc_icms;
            $product->perc_pis = $percentualImpostoEstado->percentual_pis ?? $product->perc_pis;
            $product->perc_cofins = $percentualImpostoEstado->percentual_cofins ?? $product->perc_cofins;
            $product->perc_ipi = $percentualImpostoEstado->percentual_ipi ?? $product->perc_ipi;
        }

        if ($product->cst_csosn == '60') {
            $stdICMS->vBCSTRet = 0.00;
            $stdICMS->vICMSSTRet = 0.00;
            $stdICMS->vBCSTDest = 0.00;
            $stdICMS->vICMSSTDest = 0.00;
            $stdICMS->vBC = 0;
        } else {
            if ($product->cst_csosn == '51') {
                $stdICMS->vBC = $this->format($line->unit_price * $line->quantity);
                $vbc += (float) $this->format($line->unit_price * $line->quantity);
            } else {
                if ($product->perc_icms > 0) {
                    $stdICMS->vBC = $this->format($line->unit_price * $line->quantity);
                    $vbc += (float) $this->format($line->unit_price * $line->quantity);
                } else {
                    $stdICMS->vBC = 0.00;
                }
            }
        }

        if ($product->cst_csosn == '61') {
            $stdICMS->qBCMonoRet = $this->format($stdProd->qTrib ?? $line->quantity);
            $stdICMS->adRemICMSRet = $this->format($product->adRemICMSRet, 4);
            $stdICMS->vICMSMonoRet = $this->format($product->adRemICMSRet * ($stdProd->qTrib ?? $line->quantity), 4);
        }

        if ($product->cst_csosn == '10') {
            $stdICMS->modBCST = $product->modBCST;
            $stdICMS->vBCST = $stdProd->vProd;
            $stdICMS->pICMSST = $this->format($product->pICMSST);
            $somaVICMSST += $stdICMS->vICMSST = $stdICMS->vBCST * ($stdICMS->pICMSST / 100);
        }

        $stdICMS->pICMS = $this->format($product->perc_icms);
        $stdICMS->vICMS = $stdICMS->vBC * ($stdICMS->pICMS / 100);
        $somaICMS += $stdICMS->vICMS;

        return $stdICMS;
    }

    /**
     * @source NFeService::gerarNFe (ICMS Simples / CSOSN)
     *
     * @param  object{unit_price: float|int, quantity: float|int}  $line
     * @param  object{vProd: float|string, qTrib?: float|string}  $stdProd
     */
    public function buildStdIcmsRegimeSimples(int $itemCont, object $line, object $product, object $stdProd): \stdClass
    {
        $stdICMS = new \stdClass;
        $stdICMS->item = $itemCont;
        $stdICMS->orig = $product->origem;
        $stdICMS->CSOSN = $product->cst_csosn;

        if ($product->cst_csosn == '500') {
            $stdICMS->vBCSTRet = 0.00;
            $stdICMS->pST = 0.00;
            $stdICMS->vICMSSTRet = 0.00;
        }

        $stdICMS->pCredSN = $this->format($product->pCredSN);
        $stdICMS->vCredICMSSN = $this->format($stdProd->vProd * ($stdICMS->pCredSN / 100));

        if ($product->cst_csosn == '61') {
            $stdICMS->CST = $product->cst_csosn;
            $stdICMS->qBCMonoRet = $this->format($stdProd->qTrib ?? $line->quantity);
            $stdICMS->adRemICMSRet = $this->format($product->adRemICMSRet, 4);
            $stdICMS->vICMSMonoRet = $this->format($product->adRemICMSRet * ($stdProd->qTrib ?? $line->quantity), 4);
        }

        return $stdICMS;
    }

    /**
     * @source NFeService::gerarNFe (PIS)
     *
     * @param  object{vProd: float|string}  $stdProd
     * @param  object{vICMS?: float|int|string}  $stdICMS
     * @param  object{exclusao_icms_pis_cofins?: bool}  $config
     */
    public function buildStdPis(int $itemCont, object $product, object $stdProd, object $stdICMS, object $config): \stdClass
    {
        $vbcPis = (float) $stdProd->vProd;
        if (! empty($config->exclusao_icms_pis_cofins)) {
            $vbcPis -= (float) ($stdICMS->vICMS ?? 0);
        }

        $stdPIS = new \stdClass;
        $stdPIS->item = $itemCont;
        $stdPIS->CST = $product->cst_pis;
        $stdPIS->vBC = $this->format($product->perc_pis) > 0 ? $vbcPis : 0.00;
        $stdPIS->pPIS = $this->format($product->perc_pis);
        $stdPIS->vPIS = $this->format($vbcPis * ($product->perc_pis / 100));

        return $stdPIS;
    }

    /**
     * @source NFeService::gerarNFe (COFINS)
     */
    public function buildStdCofins(int $itemCont, object $product, object $stdProd, object $stdICMS, object $config): \stdClass
    {
        $vbcCofins = (float) $stdProd->vProd;
        if (! empty($config->exclusao_icms_pis_cofins)) {
            $vbcCofins -= (float) ($stdICMS->vICMS ?? 0);
        }

        $stdCOFINS = new \stdClass;
        $stdCOFINS->item = $itemCont;
        $stdCOFINS->CST = $product->cst_cofins;
        $stdCOFINS->vBC = $this->format($product->perc_cofins) > 0 ? $vbcCofins : 0.00;
        $stdCOFINS->pCOFINS = $this->format($product->perc_cofins);
        $stdCOFINS->vCOFINS = $this->format($vbcCofins * ($product->perc_cofins / 100));

        return $stdCOFINS;
    }

    /**
     * @source NFeService::gerarNFe (IPI)
     */
    public function buildStdIpi(int $itemCont, object $product, object $stdProd): \stdClass
    {
        $stdIPI = new \stdClass;
        $stdIPI->item = $itemCont;
        $stdIPI->cEnq = $product->cenq_ipi ?? '999';
        $stdIPI->CST = $product->cst_ipi;
        $stdIPI->vBC = $this->format($product->perc_ipi) > 0 ? $stdProd->vProd : 0.00;
        $stdIPI->pIPI = $this->format($product->perc_ipi);
        $stdIPI->vIPI = $stdProd->vProd * $this->format($product->perc_ipi / 100);

        return $stdIPI;
    }

    /**
     * @source NFeService::gerarNFe (DIFAL / ICMSUFDest)
     *
     * @param  object{idDest?: int|string, indFinal?: int|string}  $stdIde
     * @param  object{CFOP?: string}  $stdProd
     * @param  object{vBC?: float|int|string}  $stdICMS
     * @param  object{pFCPUFDest: float|int, pICMSUFDest: float|int, pICMSInter: float|int, pICMSInterPart: float|int}|null  $difal
     */
    public function buildStdIcmsUfDest(
        int $itemCont,
        object $stdIde,
        object $stdProd,
        object $stdICMS,
        ?object $difal
    ): ?\stdClass {
        if (($stdIde->idDest ?? null) != 2 || ($stdIde->indFinal ?? null) != 1 || $difal == null) {
            return null;
        }

        $std = new \stdClass;
        $std->item = $itemCont;
        $std->vBCUFDest = $stdProd->vProd;
        $std->vBCFCPUFDest = $stdProd->vProd;
        $std->pFCPUFDest = $this->format($difal->pFCPUFDest);
        $std->pICMSUFDest = $this->format($difal->pICMSUFDest);
        $std->pICMSInter = $this->format($difal->pICMSInter);
        $std->pICMSInterPart = $this->format($difal->pICMSInterPart);
        $std->vFCPUFDest = $this->format($std->vBCUFDest * ($std->pFCPUFDest / 100));

        $vICMSUFDest = $std->vBCFCPUFDest * ($std->pICMSInter / 100);
        $vICMSUFDestAux = $stdICMS->vBC * ($std->pICMSUFDest / 100);
        $std->vICMSUFDest = $this->format($vICMSUFDestAux - $vICMSUFDest);
        $std->vICMSUFRemet = $this->format($vICMSUFDestAux - $vICMSUFDest) - $std->vICMSUFDest;

        return $std;
    }

    /**
     * @source NFCeService::gerarNFCe (ICMS SN simplificado POS)
     */
    public function buildStdIcmsNfceSimples(int $itemCont, object $line, object $product): \stdClass
    {
        $stdICMS = new \stdClass;
        $stdICMS->item = $itemCont;
        $stdICMS->orig = $product->origem;
        $stdICMS->CSOSN = $product->cst_csosn;
        $stdICMS->vBC = $this->format2($line->unit_price * $line->quantity);
        $stdICMS->pICMS = $this->format2($product->perc_icms);

        return $stdICMS;
    }

    /**
     * @source DevolucaoService::gerarDevolucao (prod vProd formatting)
     */
    public function buildStdProdDevolucao(
        int $itemCont,
        object $line,
        object $config,
        object $product
    ): \stdClass {
        $stdProd = new \stdClass;
        $stdProd->item = $itemCont;
        $dec = $config->casas_decimais_valor ?? 2;
        $stdProd->vUnCom = $this->format($line->valor_unit, (int) $dec);
        $stdProd->vProd = $this->format($line->quantidade * $line->valor_unit, (int) $dec);

        return $stdProd;
    }

    /**
     * @source CTeService::gerarCTe (prestação valores)
     */
    public function buildCteValoresPrestacao(object $cteEmit): \stdClass
    {
        $vPrest = new \stdClass;
        $vPrest->vTPrest = $this->formatCte($cteEmit->valor_transporte);
        $vPrest->vRec = $this->formatCte($cteEmit->valor_receber);

        return $vPrest;
    }

    /**
     * @source CTeService::gerarCTe (ICMS CT-e)
     */
    public function buildCteIcms(object $cteEmit, float $somaVBC): \stdClass
    {
        $icms = new \stdClass;
        $icms->vBC = $this->formatCte($somaVBC);
        $icms->pICMS = $this->formatCte($cteEmit->perc_icms);
        $icms->vICMS = $this->formatCte($somaVBC * ($cteEmit->perc_icms / 100));

        return $icms;
    }
}
