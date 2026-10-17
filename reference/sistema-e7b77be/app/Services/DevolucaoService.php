<?php

namespace App\Services;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Common\Standardize;
use App\Models\Certificado;
use App\Models\Business;
use App\Models\Devolucao;
use NFePHP\NFe\Complements;
use NFePHP\DA\NFe\Danfe;
use NFePHP\DA\Legacy\FilesFolders;
use NFePHP\Common\Soap\SoapCurl;
use Illuminate\Support\Facades\Log;

error_reporting(E_ALL);
ini_set('display_errors', 'On');

class DevolucaoService{

	private $config; 
	private $tools;

	public function __construct($config, $certificado){
		// $config = Business::getConfig($business_id, $devolucao);
		$this->config = $config;
		$this->tools = new Tools(json_encode($config), Certificate::readPfx($certificado->certificado, base64_decode($certificado->senha_certificado)));

		$soapCurl = new SoapCurl();
		$soapCurl->httpVersion('1.1');
		$this->tools->loadSoapClass($soapCurl);
		$this->tools->model('55');
	}

	public function gerarDevolucao($devolucao){
		date_default_timezone_set('America/Belem');
		$business_id = request()->session()->get('user.business_id');
		$config = Business::getConfig($business_id, $devolucao);

		$nfe = new Make();
		$stdInNFe = new \stdClass();
		$stdInNFe->versao = '4.00'; 
		$stdInNFe->Id = null; 
		$stdInNFe->pk_nItem = ''; 

		$infNFe = $nfe->taginfNFe($stdInNFe);

		$lastNumero = $devolucao->lastNFe();
		
		$stdIde = new \stdClass();
		$stdIde->cUF = $config->getcUF($config->cidade->uf);
		$stdIde->cNF = rand(11111,99999);
		// $stdIde->natOp = $venda->natureza->natureza;
		$stdIde->natOp = $this->retiraAcentos($devolucao->natureza->natureza);

		// $stdIde->indPag = 1; //NÃO EXISTE MAIS NA VERSÃO 4.00 // forma de pagamento

		$stdIde->mod = 55;
		$stdIde->serie = $config->numero_serie_nfe;
		$stdIde->nNF = (int)$lastNumero+1;
		$stdIde->dhEmi = date("Y-m-d\TH:i:sP");
		$stdIde->dhSaiEnt = date("Y-m-d\TH:i:sP");
		// $stdIde->tpNF = 1;
		$stdIde->tpNF = $devolucao->tipo;

		$stdIde->idDest = $config->cidade->uf != $devolucao->contact->cidade->uf ? 2 : 1;
		$stdIde->cMunFG = $config->cidade->codigo;

		$stdIde->tpImp = 1;
		$stdIde->tpEmis = 1;
		$stdIde->cDV = 0;
		$stdIde->tpAmb = $config->ambiente;
		$stdIde->finNFe = $devolucao->natureza->finNFe; 
		$stdIde->indFinal = $devolucao->contact->consumidor_final;
		$stdIde->indPres = 1;
		// $stdIde->procEmi = '0';
		// $stdIde->verProc = '2.0';
		if($config->ambiente == 2){
			$stdIde->indIntermed = 0;
		}
		$stdIde->procEmi = '0';
		$stdIde->verProc = '3.10.31';

		$tagide = $nfe->tagide($stdIde);

		$stdEmit = new \stdClass();
		$stdEmit->xNome = $config->razao_social;
		$stdEmit->xFant = $config->name;

		$ie = preg_replace('/[^0-9]/', '', $config->ie);
		$stdEmit->IE = $ie;

		$regime = (int) $config->regime === 1 ? 1 : 3;

		$stdEmit->CRT = $regime;

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		$stdEmit->CNPJ = $cnpj;
		// $stdEmit->IM = $ie;

		$emit = $nfe->tagemit($stdEmit);

		// ENDERECO EMITENTE
		$stdEnderEmit = new \stdClass();
		$stdEnderEmit->xLgr = $config->rua;
		$stdEnderEmit->nro = $config->numero;
		$stdEnderEmit->xCpl = "";

		$stdEnderEmit->xBairro = $config->bairro;
		$stdEnderEmit->cMun = $config->cidade->codigo;
		$stdEnderEmit->xMun = $config->cidade->nome;
		$stdEnderEmit->UF = $config->cidade->uf;

		$fone = str_replace(" ", "", $config->telefone);
		$stdEnderEmit->fone = $fone;

		$cep = str_replace("-", "", $config->cep);
		$cep = str_replace(".", "", $cep);
		$stdEnderEmit->CEP = $cep;
		$stdEnderEmit->cPais = '1058';
		$stdEnderEmit->xPais = 'BRASIL';

		$enderEmit = $nfe->tagenderEmit($stdEnderEmit);

		// DESTINATARIO
		$stdDest = new \stdClass();
		$stdDest->xNome = $devolucao->contact->name;

		if($devolucao->contact->contribuinte){
			if($devolucao->contact->ie_rg == 'ISENTO' || $devolucao->contact->ie_rg == NULL){
				$stdDest->indIEDest = "2";
			}else{
				$stdDest->indIEDest = "1";
			}

		}else{
			$stdDest->indIEDest = "9";
		}

		$cnpj_cpf = preg_replace('/[^0-9]/', '', $devolucao->contact->cpf_cnpj);

		if(strlen($cnpj_cpf) == 14){
			$stdDest->CNPJ = $cnpj_cpf;
			$ie = preg_replace('/[^0-9]/', '', $devolucao->contact->ie_rg);
			$stdDest->IE = $ie;
		}
		else{
			$stdDest->CPF = $cnpj_cpf;
		} 

		$dest = $nfe->tagdest($stdDest);

		$stdEnderDest = new \stdClass();
		$stdEnderDest->xLgr = $devolucao->contact->rua;
		$stdEnderDest->nro = $devolucao->contact->numero;
		$stdEnderDest->xCpl = "";
		$stdEnderDest->xBairro = $devolucao->contact->bairro;
		$stdEnderDest->cMun = $devolucao->contact->cidade->codigo;
		$stdEnderDest->xMun = strtoupper($devolucao->contact->cidade->nome);
		$stdEnderDest->UF = $devolucao->contact->cidade->uf;

		$cep = str_replace("-", "", $devolucao->contact->cep);
		$cep = str_replace(".", "", $cep);
		$stdEnderDest->CEP = $cep;
		$stdEnderDest->cPais = "1058";
		$stdEnderDest->xPais = "BRASIL";

		$enderDest = $nfe->tagenderDest($stdEnderDest);

		$somaProdutos = 0;
		$somaVbc = 0;
		$somaICMS = 0;
		//PRODUTOS
		$itemCont = 0;

		$totalItens = count($devolucao->itens);
		$somaFrete = 0;

		$std = new \stdClass();
		$std->refNFe = $devolucao->chave_nf_entrada;

		$nfe->tagrefNFe($std);
		$VBC = 0;
		$somaST = 0;
		$somaAcrescimo = 0;
		$somaIPI = 0;
		$somaPIS = 0; $somaCOFINS = 0;
		$somaDesconto = 0;
		foreach($devolucao->itens as $i){
			$itemCont++;

			// Devolução parcial: bases/valores da origem são integrais, mas a
			// qtd devolvida pode ser menor. Proporcionaliza pelas quantidades
			// quando a unidade tributária é a mesma da comercial.
			$fatorQtd = 1.0;
			$qtdDev = (float) $i->quantidade;
			$qTribOrig = (float) $i->quantidade_tributavel;
			$uTribItem = (string) ($i->unidade_tributavel != "" ? $i->unidade_tributavel : $i->unidade_medida);
			if ($qTribOrig > 0 && $qtdDev > 0 && $qTribOrig > $qtdDev
				&& $uTribItem == (string) $i->unidade_medida) {
				$fatorQtd = $qtdDev / $qTribOrig;
			}
			$vBCItem = (float) $i->vBC * $fatorQtd;
			$vbcIpiItem = (float) $i->vbcIpi * $fatorQtd;
			$vbcPisItem = (float) $i->vbcPis * $fatorQtd;
			$vbcCofinsItem = (float) $i->vbcCofins * $fatorQtd;
			$vBCSTItem = (float) $i->vBCST * $fatorQtd;
			$vICMSSTItem = (float) $i->vICMSST * $fatorQtd;
			$vBCSTRetItem = (float) $i->vBCSTRet * $fatorQtd;
			$vICMSSubstitutoItem = (float) $i->vICMSSubstituto * $fatorQtd;
			$vICMSSTRetItem = (float) $i->vICMSSTRet * $fatorQtd;

			$stdProd = new \stdClass();
			$stdProd->item = $itemCont;
			$cod = $this->validate_EAN13Barcode($i->codBarras);

			$stdProd->cEAN = $cod ? $i->codBarras : 'SEM GTIN';
			$stdProd->cEANTrib = $cod ? $i->codBarras : 'SEM GTIN';

			$stdProd->cProd = $i->cod;
			$stdProd->xProd = \App\Helpers\FiscalHelper::truncate($i->nome, 120);
			$ncm = $i->ncm;
			$ncm = str_replace(".", "", $ncm);
			$stdProd->NCM = $ncm;
			if($devolucao->tipo == 1){
				$stdProd->CFOP = $config->cidade->uf == $devolucao->contact->cidade->uf ?
				$devolucao->natureza->cfop_saida_estadual : $devolucao->natureza->cfop_saida_inter_estadual;

			}else{
				// $stdProd->CFOP = $config->UF != $devolucao->fornecedor->cidade->uf ?
				// $devolucao->natureza->CFOP_entrada_inter_estadual : $devolucao->natureza->CFOP_entrada_estadual;

				$stdProd->CFOP = $config->cidade->uf == $devolucao->contact->cidade->uf ?
				$devolucao->natureza->cfop_entrada_estadual : $devolucao->natureza->cfop_entrada_inter_estadual;
			}
			
			$stdProd->uCom = $i->unidade_medida;
			$stdProd->qCom = $i->quantidade;
			$stdProd->vUnCom = $this->format($i->valor_unit, $config->casas_decimais_valor);
			$stdProd->vProd = $this->format(($i->quantidade * $i->valor_unit), $config->casas_decimais_valor);
			// $stdProd->uTrib = $i->unidade_medida;
			$stdProd->uTrib = $i->unidade_tributavel == "" ? $i->unidade_medida : $i->unidade_tributavel;

			// $stdProd->qTrib = $i->quantidade;
			$stdProd->qTrib = $fatorQtd < 1 ? $qtdDev : ($i->quantidade_tributavel == 0 ? $i->quantidade : $i->quantidade_tributavel);

			$vUnTrib = (float) $i->vUnTrib > 0 ? $i->vUnTrib : $i->valor_unit;
			$stdProd->vUnTrib = $this->format($vUnTrib, $config->casas_decimais_valor);
			$stdProd->indTot = 1;

			$somaProdutos += ($i->quantidade * $i->valor_unit);

			if($i->vBC > 0 && $devolucao->devolucao_parcial == 0){
				$somaVbc += $i->vBC;
			}

			if($devolucao->vDesc > 0){
				if($itemCont < $totalItens){
					$percentual = $stdProd->vProd / $somaProdutos;
					$vDesc = $devolucao->vDesc * $percentual;
					$somaDesconto += $vDesc;
					if ($vDesc > 0) {
						$stdProd->vDesc = $this->format($vDesc);
					}
				}else{
					$vDesc = $devolucao->vDesc - $somaDesconto;
					if ($vDesc > 0) {
						$stdProd->vDesc = $this->format($vDesc);
					}
				}
			}

			if($devolucao->vOutro > 0){
				if($itemCont < sizeof($devolucao->itens)){
					$totalVenda = $devolucao->valor_devolvido;

					$media = (((($stdProd->vProd - $totalVenda)/$totalVenda))*100);
					$media = 100 - ($media * -1);

					$tempAcrescimo = ($devolucao->vOutro * $media)/100;
					$somaAcrescimo += $tempAcrescimo;
					if($tempAcrescimo > 0.1)
						$stdProd->vOutro = $this->format($tempAcrescimo);
				}else{
					if($devolucao->vOutro - $somaAcrescimo > 0.1)
						$stdProd->vOutro = $this->format($devolucao->vOutro - $somaAcrescimo);
				}
			}

			if($devolucao->vSeguro > 0){
				if($itemCont < sizeof($devolucao->itens)){
					$totalVenda = $devolucao->valor_devolvido;

					$media = (((($stdProd->vProd - $totalVenda)/$totalVenda))*100);
					$media = 100 - ($media * -1);

					$tempAcrescimo = ($devolucao->vSeguro * $media)/100;
					$somaAcrescimo += $tempAcrescimo;
					if($tempAcrescimo > 0.1)
						$stdProd->vSeg = $this->format($tempAcrescimo);
				}else{
					if($devolucao->vSeguro - $somaAcrescimo > 0.1)
						$stdProd->vSeg = $this->format($devolucao->vSeguro - $somaAcrescimo);
				}
			}

			// if($venda->frete){
			// 	if($venda->frete->valor > 0){
			// 		$somaFrete += $vFt = $venda->frete->valor/$totalItens;
			// 		$stdProd->vFrete = $this->format($vFt);
			// 	}
			// }


			if($devolucao->vFrete > 0){
				$somaFrete += $vFt = $devolucao->vFrete/$totalItens;
				$stdProd->vFrete = $this->format($vFt);
				// $somaProdutos += $vFt;
			}

			$prod = $nfe->tagprod($stdProd);
			if (!empty($i->cest)) {
			    $stdCEST = new \stdClass();
			    $stdCEST->item = $itemCont;
			    $stdCEST->CEST = $i->cest;
			    $nfe->tagCEST($stdCEST);
			}

		//TAG IMPOSTO

			$stdImposto = new \stdClass();
			$stdImposto->item = $itemCont;

			$imposto = $nfe->tagimposto($stdImposto);

			// ICMS

			
			// Emitente SN (CRT 1/4) só pode usar CSOSN (nunca CST) — SEFAZ
			// rejeição 590. O CSOSN 900 com vBC/pICMS/vICMS preenchidos é o
			// mecanismo do SN para repassar o crédito na devolução.
			if($regime == 3){ // regime normal
			// if($i->cst_csosn != 101 || $i->cst_csosn != 102){ // regime normal


				$stdICMS = new \stdClass();
				$stdICMS->item = $itemCont; 
				$stdICMS->orig = $i->orig;
				$stdICMS->CST = $i->cst_csosn;

				$stdICMS->modBC = (int) ($i->modBC > 0 ? $i->modBC : 3);
				if($i->pRedBC == 0){

					if($i->cst_csosn == 60){

						$stdICMS->vBC = $this->format($vBCItem);
						$stdICMS->pST = $this->format($i->pST);
						$stdICMS->vBCSTRet = $this->format($vBCSTRetItem);
						$stdICMS->vICMSSubstituto = $this->format($vICMSSubstitutoItem);
						$stdICMS->vICMSSTRet = $this->format($vICMSSTRetItem);

						if(strlen($i->codigo_anp) > 0){
							$stdICMS->vBCSTDest = 0;
							$stdICMS->vICMSSTDest = 0;
							$stdICMS->pRedBCEfet = 0;
							$stdICMS->vBCEfet = 0;
							$stdICMS->pICMSEfet = 0;
							$stdICMS->vICMSEfet = 0;
						}
						// $stdICMS->vBCEfet = $stdProd->vProd;
						// $stdICMS->pICMSEfet = 17;
						// $stdICMS->vICMSEfet = 4088.50;
					}else{
						
						$stdICMS->vBC = $this->format($vBCItem);
						if($i->cst_csosn == 40 || $i->cst_csosn == 41){
							$stdICMS->vBCSTRet = 0;
							$stdICMS->vICMSSTRet = $this->format($vICMSSTRetItem);
							// $stdICMS->vBCSTDest = 0;
							// $stdICMS->vICMSSTDest = 0;
						}else{
							$VBC += $stdICMS->vBC;
						} 
					}

					if ($config->UF != $devolucao->contact->cidade->uf
					    && !in_array((string) $i->cst_csosn, ['00', '900'], true)) {
						$stdICMS->pST = $this->format($i->pST);
						$stdICMS->vBCSTRet = 0;
						$stdICMS->vICMSSubstituto = $this->format($vICMSSubstitutoItem);
						$stdICMS->vICMSSTRet = $this->format($vICMSSTRetItem);
						// $stdICMS->vBCSTDest = 0;
						// $stdICMS->vICMSSTDest = 0;
						// $stdICMS->pRedBCEfet = 0;
					}

					$stdICMS->pICMS = $this->format($i->perc_icms);
					$calcICMS = (float) $stdICMS->vBC * ((float) $stdICMS->pICMS / 100);
					$vICMSRef = (float) $i->vICMS * $fatorQtd;
					$stdICMS->vICMS = (abs($calcICMS - $vICMSRef) <= 0.05 && $vICMSRef > 0)
					    ? $this->format($vICMSRef)
					    : $this->format($calcICMS);
					$stdICMS->pRedBC = $this->format($i->pRedBC);

					if($i->modBCST > 0){
						$stdICMS->modBCST = (int)$i->modBCST;
					}
					if($i->vBCST > 0){
						$stdICMS->vBCST = $this->format($vBCSTItem);
					}
					if($i->pICMSST > 0){
						$stdICMS->pICMSST = $i->pICMSST;
					}
					if($vICMSSTItem > 0){
						$somaST += $stdICMS->vICMSST = $this->format($vICMSSTItem);
					}
					if($i->pMVAST > 0){
						$stdICMS->pMVAST = $i->pMVAST;
					}

					if($stdICMS->CST == 41 && $vBCSTRetItem > 0 || strlen($i->codigo_anp) > 0){
						$ICMS = $nfe->tagICMSST($stdICMS);
					}else{
						$ICMS = $nfe->tagICMS($stdICMS);
					}

					
				}else{

					$tempB = 100-$i->pRedBC;
					$VBC += $stdICMS->vBC = (($stdProd->vProd-$stdProd->vDesc) * ($tempB/100));
					$stdICMS->pICMS = $this->format($i->perc_icms);
					$stdICMS->vICMS = (($stdProd->vProd-$stdProd->vDesc) * ($tempB/100)) * ($stdICMS->pICMS/100);
					$stdICMS->pRedBC = $this->format($i->pRedBC);
					$ICMS = $nfe->tagICMS($stdICMS);
				}

				// $somaICMS += 0;
				// $ICMS = $nfe->tagICMS($stdICMS);
				if($i->cst_csosn != 40){
					$somaICMS += $this->format($stdICMS->vICMS);
				}


			}else{ // regime simples

				$stdICMS = new \stdClass();

				$stdICMS->item = $itemCont;
				$stdICMS->orig = $i->orig;
				$stdICMS->CSOSN = '900';
				$stdICMS->modBC = (int) ($i->modBC > 0 ? $i->modBC : 3);
				$stdICMS->vBC = $this->format($vBCItem);
				$VBC += (float) $stdICMS->vBC;
				$stdICMS->pRedBC = $this->format($i->pRedBC);
				$stdICMS->pICMS = $this->format($i->perc_icms);
				$calcICMS = (float) $vBCItem * ((float) $i->perc_icms / 100);
				$vICMSRefSN = (float) $i->vICMS * $fatorQtd;
				$stdICMS->vICMS = (abs($calcICMS - $vICMSRefSN) <= 0.05 && $vICMSRefSN > 0)
				    ? $this->format($vICMSRefSN)
				    : $this->format($calcICMS);
				$ICMS = $nfe->tagICMSSN($stdICMS);

				$somaICMS += (float) $stdICMS->vICMS;
			}

			$stdPIS = new \stdClass();//PIS
			$stdPIS->item = $itemCont; 
			$stdPIS->CST = $i->cst_pis;
			$stdPIS->vBC = $i->perc_pis > 0 ? $this->format($vbcPisItem) : 0.00;

			$stdPIS->pPIS = $this->format($i->perc_pis);
			$stdPIS->vPIS = $this->format($stdPIS->vBC * ($i->perc_pis/100));;
			// $stdPIS->qBCProd = 0.00;
			$PIS = $nfe->tagPIS($stdPIS);
			$somaPIS += (float) $stdPIS->vPIS;

			
			$stdCOFINS = new \stdClass();//COFINS
			$stdCOFINS->item = $itemCont; 
			$stdCOFINS->CST = $i->cst_cofins;
			$stdCOFINS->vBC = $i->perc_cofins > 0 ? $this->format($vbcCofinsItem) : 0.00;
			$stdCOFINS->pCOFINS = $this->format($i->perc_cofins);
			$stdCOFINS->vCOFINS = $this->format($stdCOFINS->vBC * ($i->perc_cofins/100));
			$COFINS = $nfe->tagCOFINS($stdCOFINS);
			$somaCOFINS += (float) $stdCOFINS->vCOFINS;


			$std = new \stdClass();//IPI
			$std->item = $itemCont; 
			$std->clEnq = null;
			$std->CNPJProd = null;
			$std->cSelo = null;
			$std->qSelo = null;
			$std->cEnq = '999'; //999 – para tributação normal IPI
			$std->CST = $i->cst_ipi;
			$std->vBC = $this->format($vbcIpiItem);
			$std->pIPI = $this->format($i->perc_ipi);
			$calcIPI = (float) $vbcIpiItem * ((float) $i->perc_ipi / 100);
			$vIPIRef = (float) $i->vIPI * $fatorQtd;
			$std->vIPI = (abs($calcIPI - $vIPIRef) <= 0.05 && $vIPIRef > 0)
			    ? $this->format($vIPIRef)
			    : $this->format($calcIPI);
			$somaIPI += (float) $std->vIPI;
			$std->qUnid = null;
			$std->vUnid = null;

			$nfe->tagIPI($std);

			// $std = new \stdClass();
			// $std->item = $itemCont; 
			// $std->CEST = '';
			// $nfe->tagCEST($std);

			if(strlen($i->codigo_anp) > 0){

				$stdComb = new \stdClass();
				$stdComb->item = $itemCont; 
				$stdComb->cProdANP = $i->codigo_anp;
				$stdComb->descANP = $i->descricao_anp; 

				if($i->perc_glp > 0){
					$stdComb->pGLP = $this->format($i->perc_glp);
				}

				if($i->perc_gnn > 0){
					$stdComb->pGNn = $this->format($i->perc_gnn);
				}

				if($i->perc_gni > 0){
					$stdComb->pGNi = $this->format($i->perc_gni);
				}

				$stdComb->vPart = $this->format($i->valor_partida);


				$stdComb->UFCons = $i->uf_cons;
				$nfe->tagcomb($stdComb);
			}
		}


		$stdICMSTot = new \stdClass();
		// $stdICMSTot->vBC = $this->format($somaVbc);
		$stdICMSTot->vBC = $this->format($VBC > 0 ? $VBC : $somaVbc);
		$stdICMSTot->vICMS = $this->format($somaICMS);
		$stdICMSTot->vICMSDeson = 0.00;
		$stdICMSTot->vBCST = 0.00;
		$stdICMSTot->vST = 0.00;
		$stdICMSTot->vProd = $this->format($devolucao->valor_devolvido);

		$stdICMSTot->vFrete = $this->format($devolucao->vFrete);


		$stdICMSTot->vSeg = $this->format($devolucao->vSeguro);
		$stdICMSTot->vDesc = $this->format($devolucao->vDesc);
		// $stdICMSTot->vDesc = $this->format($devolucao->vDesc);
		$stdICMSTot->vII = 0.00;
		$stdICMSTot->vIPI = $this->format($somaIPI);
		$stdICMSTot->vPIS = $this->format($somaPIS);
		$stdICMSTot->vCOFINS = $this->format($somaCOFINS);
		$stdICMSTot->vIPIDevol = 0.00;
		$stdICMSTot->vOutro = $this->format($devolucao->vOutro);

		
		$stdICMSTot->vNF = $this->format(($devolucao->valor_devolvido + $devolucao->vFrete + $somaIPI + $devolucao->vSeguro + $devolucao->vOutro) - $devolucao->vDesc);

		$stdICMSTot->vTotTrib = 0.00;
		$ICMSTot = $nfe->tagICMSTot($stdICMSTot);


		$stdTransp = new \stdClass();
		$stdTransp->modFrete = $devolucao->frete_tipo;

		$transp = $nfe->tagtransp($stdTransp);


		$stdPag = new \stdClass();
		$pag = $nfe->tagpag($stdPag);

		$stdDetPag = new \stdClass();


		$stdDetPag->tPag = '90';
		$stdDetPag->vPag = 0.00; 

		$stdDetPag->indPag = '0'; // sem pagamento 

		$detPag = $nfe->tagdetPag($stdDetPag);

		if($devolucao->transportadora_nome != ""){

			$std = new \stdClass();

			$std->xNome = $devolucao->transportadora_nome;

			$std->xEnder = $devolucao->transportadora_endereco;
			$std->xMun = $devolucao->transportadora_cidade;
			$std->UF = $devolucao->transportadora_uf;


			$cnpj_cpf = $devolucao->transportadora_cpf_cnpj;
			$cnpj_cpf = str_replace(".", "", $cnpj_cpf);
			$cnpj_cpf = str_replace("/", "", $cnpj_cpf);
			$cnpj_cpf = str_replace("-", "", $cnpj_cpf);

			if(strlen($cnpj_cpf) == 14) $std->CNPJ = $cnpj_cpf;
			else $std->CPF = $cnpj_cpf;

			$nfe->tagtransporta($std);
		}


		if($devolucao->veiculo_uf != '' && $devolucao->veiculo_placa != ''){
			$std = new \stdClass();

			$placa = str_replace("-", "", $devolucao->veiculo_placa);
			$std->placa = strtoupper($placa);
			$std->UF = $devolucao->veiculo_uf;

			$nfe->tagveicTransp($std);
		}

		if($devolucao->frete_peso_bruto > 0 && $devolucao->frete_peso_liquido > 0){
			$stdVol = new \stdClass();
			$stdVol->item = 1;
			$stdVol->qVol = $devolucao->frete_quantidade;
			$stdVol->esp = $devolucao->frete_especie;

			$stdVol->nVol = $devolucao->frete_numero;
			$stdVol->pesoL = $devolucao->frete_peso_liquido;
			$stdVol->pesoB = $devolucao->frete_peso_bruto;
			$vol = $nfe->tagvol($stdVol);
		}

		$stdInfoAdic = new \stdClass();
		$stdInfoAdic->infCpl = $this->retiraAcentos($devolucao->observacao);

		$infoAdic = $nfe->taginfAdic($stdInfoAdic);


		$respCnpj = preg_replace('/[^0-9]/', '', (string) (env('RESP_CNPJ') ?? getenv('RESP_CNPJ') ?: ''));
		$respNome = trim((string) (env('RESP_NOME') ?? getenv('RESP_NOME') ?: ''));
		$respEmail = trim((string) (env('RESP_EMAIL') ?? getenv('RESP_EMAIL') ?: ''));
		$respFone = preg_replace('/[^0-9]/', '', (string) (env('RESP_FONE') ?? getenv('RESP_FONE') ?: ''));
		if (strlen($respCnpj) === 14 && strlen($respNome) >= 2 && strlen($respEmail) >= 6 && preg_match('/^[0-9]{6,14}$/', $respFone)) {
			$std = new \stdClass();
			$std->CNPJ = $respCnpj; //CNPJ da pessoa jurídica responsável pelo sistema utilizado na emissão do documento fiscal eletrônico
			$std->xContato = $respNome; //Nome da pessoa a ser contatada
			$std->email = $respEmail; //E-mail da pessoa jurídica a ser contatada
			$std->fone = $respFone; //Telefone da pessoa jurídica/física a ser contatada

			$nfe->taginfRespTec($std);
		} else {
			\Log::warning('infRespTec omitido (Devolucao): RESP_* ausente ou inválido', compact('respCnpj', 'respNome', 'respEmail', 'respFone'));
		}

		$aut_xml = preg_replace('/[^0-9]/', '', $config->aut_xml);
		if(strlen($aut_xml) > 10){

			$std = new \stdClass();
			$std->CNPJ = $aut_xml;
			$nfe->tagautXML($std);
		}

		try{

			$nfe->montaNFe();
			$chave = $nfe->getChave();
			$devolucao->chave_gerada = $chave;
			$devolucao->numero_gerado = $stdIde->nNF;
			$devolucao->save();
			
			$arr = [
				'chave' => $chave,
				'xml' => $nfe->getXML(),
				'nNf' => $stdIde->nNF
			];
			return $arr;
		}catch(\Exception $e){
			return [
				'xml_erros' => $nfe->getErrors()
			];
		}

	}

	private function validate_EAN13Barcode($ean)
	{

		$sumEvenIndexes = 0;
		$sumOddIndexes  = 0;

		$eanAsArray = array_map('intval', str_split($ean));

		if(strlen($ean) == 14){
			return true;
		}

		if (!$this->has13Numbers($eanAsArray) ) {
			return false;
		};

		for ($i = 0; $i < count($eanAsArray)-1; $i++) {
			if ($i % 2 === 0) {
				$sumOddIndexes  += $eanAsArray[$i];
			} else {
				$sumEvenIndexes += $eanAsArray[$i];
			}
		}

		$rest = ($sumOddIndexes + (3 * $sumEvenIndexes)) % 10;

		if ($rest !== 0) {
			$rest = 10 - $rest;
		}

		return $rest === $eanAsArray[12];
	}

	private function has13Numbers(array $ean)
	{
		return count($ean) === 13 || count($ean) === 14;
	}

	private function retiraAcentos($texto){
		return preg_replace(array("/(á|à|ã|â|ä)/","/(Á|À|Ã|Â|Ä)/","/(é|è|ê|ë)/","/(É|È|Ê|Ë)/","/(í|ì|î|ï)/","/(Í|Ì|Î|Ï)/","/(ó|ò|õ|ô|ö)/","/(Ó|Ò|Õ|Ô|Ö)/","/(ú|ù|û|ü)/","/(Ú|Ù|Û|Ü)/","/(ñ)/","/(Ñ)/", "/(ç)/", "/(Ç)/", "/(°)/"),explode(" ","a A e E i I o O u U n N c C o"),$texto);
	}

	public function sign($xml){
		return $this->tools->signNFe($xml);
	}

	public function transmitir($signXml, $devolucao){
		try{
			if (!is_object($devolucao) || !$devolucao->business) {
				throw new \Exception('Objeto de devolução inválido ou sem relação com business');
			}
			
			$cnpj = preg_replace('/\D/', '', $devolucao->business->cnpj);
			$chave = $devolucao->chave_gerada;
			$idLote = substr(str_replace(',', '', number_format(microtime(true) * 1000000, 0)), 0, 15);
			$indSinc = 1; // síncrono p/ lote único
			$compactar = false;
			$xmls = [];
			Log::info('DevolucaoService transmitir init', ['idLote' => $idLote, 'indSinc' => $indSinc, 'chave' => $chave]);
			$resp = $this->tools->sefazEnviaLote([$signXml], $idLote, $indSinc, $compactar, $xmls);
			$st = new Standardize();
			$std = $st->toStd($resp);
			sleep(2);
			Log::info('DevolucaoService transmitir resp', ['cStat' => isset($std->cStat) ? (string)$std->cStat : null, 'xMotivo' => isset($std->xMotivo) ? (string)$std->xMotivo : null]);

			if (isset($std->cStat) && $std->cStat == 104) {
				$infProtStat = isset($std->protNFe->infProt->cStat) ? (string)$std->protNFe->infProt->cStat : '';
				$infProtMotivo = isset($std->protNFe->infProt->xMotivo) ? (string)$std->protNFe->infProt->xMotivo : '';

				if ($infProtStat === '100' || $infProtStat === '150') {
					try {
						$xml = Complements::toAuthorize($signXml, $resp);
						header('Content-type: text/xml; charset=UTF-8');
						if(!is_dir(public_path('xml_devolucao/'.$cnpj))){
							mkdir(public_path('xml_devolucao/'.$cnpj), 0777, true);
						}
						file_put_contents(public_path('xml_devolucao/'.$cnpj.'/'.$chave.'.xml'), $xml);
						return isset($std->protNFe->infProt->nProt) ? (string)$std->protNFe->infProt->nProt : '';
					} catch (\Exception $e) {
						return ['erro' => true, 'protocolo' => $st->toJson($resp), 'status' => 401];
					}
				}

				if ($infProtStat === '776' || stripos($infProtMotivo, 'síncrona para UF que não disponibiliza') !== false) {
					$idLote = substr(str_replace(',', '', number_format(microtime(true) * 1000000, 0)), 0, 15);
					$indSinc = 0;
					Log::warning('DevolucaoService fallback asincrono 776', ['novoIdLote' => $idLote, 'indSinc' => $indSinc, 'chave' => $chave]);
					$resp = $this->tools->sefazEnviaLote([$signXml], $idLote, $indSinc, $compactar, $xmls);
					$std = $st->toStd($resp);
					sleep(2);
					if ($std->cStat != 103) {
						Log::error('DevolucaoService fallback 776 sem recibo', ['cStat' => isset($std->cStat) ? (string)$std->cStat : null, 'xMotivo' => isset($std->xMotivo) ? (string)$std->xMotivo : null]);
						return ['erro' => true, 'protocolo' => "[{$std->cStat}] - {$std->xMotivo}", 'status' => 402];
					}
					$recibo = $std->infRec->nRec; 
					$protocolo = $this->tools->sefazConsultaRecibo($recibo);
					sleep(2);
					try {
						$xml = Complements::toAuthorize($signXml, $protocolo);
						header('Content-type: text/xml; charset=UTF-8');
						if(!is_dir(public_path('xml_devolucao/'.$cnpj))){
							mkdir(public_path('xml_devolucao/'.$cnpj), 0777, true);
						}
						file_put_contents(public_path('xml_devolucao/'.$cnpj.'/'.$chave.'.xml'), $xml);
						Log::info('DevolucaoService autorizado', ['modo' => 'assincrono_fallback', 'recibo' => $recibo]);
						return $recibo;
					} catch (\Exception $e) {
						return ['erro' => true, 'protocolo' => $st->toJson($protocolo), 'status' => 401];
					}
				}

				return ['erro' => true, 'protocolo' => "[{$infProtStat}] - {$infProtMotivo}", 'status' => 402];
			}

			if (isset($std->cStat) && $std->cStat == 103) {
				$recibo = $std->infRec->nRec; 
				$protocolo = $this->tools->sefazConsultaRecibo($recibo);
				sleep(2);
				try {
					$xml = Complements::toAuthorize($signXml, $protocolo);
					header('Content-type: text/xml; charset=UTF-8');
					if(!is_dir(public_path('xml_devolucao/'.$cnpj))){
						mkdir(public_path('xml_devolucao/'.$cnpj), 0777, true);
					}
					file_put_contents(public_path('xml_devolucao/'.$cnpj.'/'.$chave.'.xml'), $xml);
					Log::info('DevolucaoService autorizado', ['modo' => 'assincrono', 'recibo' => $recibo]);
					return $recibo;
				} catch (\Exception $e) {
					return ['erro' => true, 'protocolo' => $st->toJson($protocolo), 'status' => 401];
				}
			}

			if (isset($std->xMotivo) && stripos($std->xMotivo, 'síncrona para UF que não disponibiliza') !== false) {
				$idLote = substr(str_replace(',', '', number_format(microtime(true) * 1000000, 0)), 0, 15);
				$indSinc = 0;
				Log::warning('DevolucaoService fallback asincrono xMotivo', ['novoIdLote' => $idLote, 'indSinc' => $indSinc, 'chave' => $chave]);
				$resp = $this->tools->sefazEnviaLote([$signXml], $idLote, $indSinc, $compactar, $xmls);
				$std = $st->toStd($resp);
				sleep(2);
				if ($std->cStat != 103) {
					Log::error('DevolucaoService fallback xMotivo sem recibo', ['cStat' => isset($std->cStat) ? (string)$std->cStat : null, 'xMotivo' => isset($std->xMotivo) ? (string)$std->xMotivo : null]);
					return ['erro' => true, 'protocolo' => "[{$std->cStat}] - {$std->xMotivo}", 'status' => 402];
				}
				$recibo = $std->infRec->nRec; 
				$protocolo = $this->tools->sefazConsultaRecibo($recibo);
				sleep(2);
				try {
					$xml = Complements::toAuthorize($signXml, $protocolo);
					header('Content-type: text/xml; charset=UTF-8');
					if(!is_dir(public_path('xml_devolucao/'.$cnpj))){
						mkdir(public_path('xml_devolucao/'.$cnpj), 0777, true);
					}
					file_put_contents(public_path('xml_devolucao/'.$cnpj.'/'.$chave.'.xml'), $xml);
					Log::info('DevolucaoService autorizado', ['modo' => 'assincrono_fallback_xMotivo', 'recibo' => $recibo]);
					return $recibo;
				} catch (\Exception $e) {
					return ['erro' => true, 'protocolo' => $st->toJson($protocolo), 'status' => 401];
				}
			}

			return ['erro' => true, 'protocolo' => (isset($std->xMotivo) ? (string)$std->xMotivo : 'Falha no envio'), 'status' => 402];

		} catch(\Exception $e){
			Log::error('DevolucaoService transmitir exception', ['message' => $e->getMessage()]);
			return ['erro' => true, 'protocolo' => $e->getMessage(), 'status' => 401];
		}
	}

	public function format($number, $dec = 2){
		return number_format((float) $number, $dec, ".", "");
	}

	public function cancelar($devolucao, $justificativa, $cnpj){
		try {
			// Validar se $devolucao é um objeto válido
			if (!is_object($devolucao) || empty($devolucao->chave_gerada)) {
				throw new \Exception('Objeto de devolução inválido para cancelamento');
			}

			$chave = $devolucao->chave_gerada;
			$response = $this->tools->sefazConsultaChave($chave);
			$stdCl = new Standardize($response);
			$arr = $stdCl->toArray();
			sleep(1);
				// return $arr;
			$xJust = $justificativa;


			$nProt = $arr['protNFe']['infProt']['nProt'];

			$response = $this->tools->sefazCancela($chave, $xJust, $nProt);
			sleep(2);
			$stdCl = new Standardize($response);
			$std = $stdCl->toStd();
			$arr = $stdCl->toArray();
			$json = $stdCl->toJson();

			if ($std->cStat != 128) {
        //TRATAR
			} else {
				$cStat = $std->retEvento->infEvento->cStat;
				$public = getenv('SERVIDOR_WEB') ? 'public/' : '';
				if ($cStat == '101' || $cStat == '135' || $cStat == '155' ) {
            //SUCESSO PROTOCOLAR A SOLICITAÇÂO ANTES DE GUARDAR
					$xml = Complements::toAuthorize($this->tools->lastRequest, $response);
					if(!is_dir(public_path('xml_devolucao_cancelado/'.$cnpj))){
						mkdir(public_path('xml_devolucao_cancelado/'.$cnpj), 0777, true);
					}
					file_put_contents(public_path('xml_devolucao_cancelado/'.$cnpj.'/'.$chave.'.xml'), $xml);
					return $arr;
				} else {
					return ['erro' => true, 'data' => $arr, 'status' => 402];	
				}
			}    
		} catch (\Exception $e) {
			return $e->getMessage();
			return ['erro' => true, 'data' => $e->getMessage(), 'status' => 402];
    //TRATAR
		}
	}

	public function cartaCorrecao($devolucao, $correcao, $cnpj){
		try {

			$chave = $devolucao->chave_gerada;
			$xCorrecao = $correcao;
			$nSeqEvento = $devolucao->sequencia_cce+1;
			$response = $this->tools->sefazCCe($chave, $xCorrecao, $nSeqEvento);
			sleep(2);

			$stdCl = new Standardize($response);

			$std = $stdCl->toStd();

			$arr = $stdCl->toArray();

			$json = $stdCl->toJson();

			if ($std->cStat != 128) {
        //TRATAR
			} else {
				$cStat = $std->retEvento->infEvento->cStat;
				if ($cStat == '135' || $cStat == '136') {
					$public = getenv('SERVIDOR_WEB') ? 'public/' : '';
            //SUCESSO PROTOCOLAR A SOLICITAÇÂO ANTES DE GUARDAR
					$xml = Complements::toAuthorize($this->tools->lastRequest, $response);

					if(!is_dir(public_path('xml_devolucao_correcao/'.$cnpj))){
						mkdir(public_path('xml_devolucao_correcao/'.$cnpj), 0777, true);
					}
					file_put_contents(public_path('xml_devolucao_correcao/'.$cnpj.'/'.$chave.'.xml'), $xml);

					$devolucao->sequencia_cce = $devolucao->sequencia_cce + 1;
					$devolucao->save();
					return $arr;

				} else {
					return ['erro' => true, 'data' => $arr, 'status' => 402];	

				}
			}    
		} catch (\Exception $e) {
			return $e->getMessage();
		}
	}

}