<?php
namespace App\Services;

use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Common\Standardize;
use App\Models\Business;
use App\Models\Transaction;
use NFePHP\NFe\Complements;
use NFePHP\DA\NFe\Danfe;
use NFePHP\DA\Legacy\FilesFolders;
use NFePHP\Common\Soap\SoapCurl;
use App\Models\Tributacao;
use App\Models\Ibpt;
use App\Models\Contigencia;
use NFePHP\NFe\Factories\Contingency;
use Illuminate\Support\Facades\Log;

error_reporting(E_ALL);
ini_set('display_errors', 'On');

class NFCeService{

	private $config; 
	private $tools;

	public function __construct($config, $certificado){

		$this->config = $config;
		$this->tools = new Tools(json_encode($config), Certificate::readPfx($certificado->certificado, base64_decode($certificado->senha_certificado)));
		$soapCurl = new SoapCurl();
		$soapCurl->httpVersion('1.1');
		$this->tools->loadSoapClass($soapCurl);

		$contigencia = $this->getContigencia();
		if($contigencia != null){
			$contingency = new Contingency($contigencia->status_retorno);
			$this->tools->contingency = $contingency;
		}
		$this->tools->model('65');
		
	}

	private function getContigencia(){
		$business_id = request()->session()->get('user.business_id');

		$active = Contigencia::
		where('business_id', $business_id)
		->where('status', 1)
		->where('documento', 'NFCe')
		->first();
		return $active;
	}

	public function gerarNFCe($venda){
		date_default_timezone_set('America/Belem');
		$business_id = request()->session()->get('user.business_id');
		// $config = Business::find($business_id);
		$config = Business::getConfig($business_id, $venda);

		$nfe = new Make();
		$stdInNFe = new \stdClass();
		$stdInNFe->versao = '4.00'; //versão do layout
		$stdInNFe->Id = null; //se o Id de 44 digitos não for passado será gerado automaticamente
		$stdInNFe->pk_nItem = ''; //deixe essa variavel sempre como NULL

		$infNFe = $nfe->taginfNFe($stdInNFe);

		//IDE
		$stdIde = new \stdClass();
		$stdIde->cUF = $config->getcUF($config->cidade->uf);
		$stdIde->cNF = rand(11111111, 99999999);
		$stdIde->natOp = 'Venda de produto do estabelecimento';
		if($config->natureza_operacao_pdv != null){
			$stdIde->natOp = $config->natureza_operacao_pdv;
		}
		// $stdIde->indPag = 1; //NÃO EXISTE MAIS NA VERSÃO 4.00 // forma de pagamento

		$vendaLast = $venda->lastNFCe($venda);
		$lastNumero = $vendaLast;

		$stdIde->mod = 65;
		$stdIde->serie = $config->numero_serie_nfce;
		$stdIde->nNF = (int)$lastNumero+1; //******=========p=p=p=p=p
		$stdIde->dhEmi = date("Y-m-d\TH:i:sP");
		$stdIde->dhSaiEnt = date("Y-m-d\TH:i:sP");
		$stdIde->tpNF = 1;
		$stdIde->idDest = 1;
		$stdIde->cMunFG = $config->cidade->codigo;
		$stdIde->tpImp = 4;
		$stdIde->tpEmis = 1;
		$stdIde->cDV = 0;
		$stdIde->tpAmb = $config->ambiente;
		$stdIde->finNFe = 1;
		$stdIde->indFinal = 1;
		$stdIde->indPres = 1;
		$stdIde->indIntermed = 0;
		$stdIde->procEmi = '0';
		$stdIde->verProc = '3.10.31';
		//

		$tagide = $nfe->tagide($stdIde);

		$stdEmit = new \stdClass();
		$stdEmit->xNome = $config->razao_social;
		$stdEmit->xFant = $config->name;

		$ie = preg_replace('/[^0-9]/', '', $config->ie);
		$stdEmit->IE = $ie;
		$stdEmit->CRT = $config->regime;

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

		$cep = preg_replace('/[^0-9]/', '', $config->cep);
		$stdEnderEmit->CEP = $cep;
		$stdEnderEmit->cPais = '1058';
		$stdEnderEmit->xPais = 'BRASIL';

		$enderEmit = $nfe->tagenderEmit($stdEnderEmit);

		// DESTINATARIO
		
		if(strlen($venda->cpf_nota) >= 11){
			$stdDest = new \stdClass();
			$cpf = $venda->cpf_nota;
			$cpf = preg_replace('/[^0-9]/', '', $cpf);

			$stdDest->indIEDest = "9";

			if(strlen($cpf) == 11){
				$stdDest->CPF = $cpf;
			}else{
				$stdDest->CNPJ = $cpf;
			}
			$dest = $nfe->tagdest($stdDest);
		}

		if($venda->contact->cpf_cnpj){

			$stdDest = new \stdClass();
			$stdDest->xNome = $venda->contact->name;
			if($venda->contact->contribuinte){
				if($venda->contact->ie_rg == 'ISENTO' || $venda->contact->ie_rg == NULL){
					$stdDest->indIEDest = "2";
				}else{
					$stdDest->indIEDest = "1";
				}

			}else{
				$stdDest->indIEDest = "9";
			}

			$cnpj_cpf = preg_replace('/[^0-9]/', '', $venda->contact->cpf_cnpj);

			if(strlen($cnpj_cpf) == 14){
				$stdDest->CNPJ = $cnpj_cpf;
			}else{
				$stdDest->CPF = $cnpj_cpf;
			}
			$dest = $nfe->tagdest($stdDest);

			$stdEnderDest = new \stdClass();
			$stdEnderDest->xLgr = $venda->contact->rua;
			$stdEnderDest->nro = $venda->contact->numero;
			$stdEnderDest->xCpl = $venda->contact->complement;
			$stdEnderDest->xBairro = $venda->contact->bairro;
			$stdEnderDest->fone = $venda->contact->mobile;

			if($venda->contact->cod_pais == 1058){

				$stdEnderDest->cMun = $venda->contact->cidade->codigo;
				$stdEnderDest->xMun = strtoupper($venda->contact->cidade->nome);
				$stdEnderDest->UF = $venda->contact->cidade->uf;

				$cep = str_replace("-", "", $venda->contact->cep);
				$cep = str_replace(".", "", $cep);
				$stdEnderDest->CEP = $cep;
				$stdEnderDest->cPais = "1058";
				$stdEnderDest->xPais = "BRASIL";
			}else{
				$stdEnderDest->cMun = 9999999;
				$stdEnderDest->xMun = "EXTERIOR";
				$stdEnderDest->UF = "EX";
				$stdEnderDest->cPais = $venda->contact->cod_pais;
				$stdEnderDest->xPais = $venda->contact->getPais();
			}
		}

		$somaProdutos = 0;
		$somaICMS = 0;
		//PRODUTOS
		$itemCont = 0;
		$totalItens = count($venda->sell_lines);
		$somaAcrescimo = 0;
		$somaDesconto = 0;
		$somaFederal = 0;
		$somaEstadual = 0;
		$somaMunicipal = 0;

		$vOutro = $venda->shipping_charges;
		$totalDesconto = $venda->discount_amount;
		$sumTotal = $venda->sumItens();
		if($venda->discount_type == 'percentage'){
			$totalDesconto = $sumTotal*($venda->discount_amount/100);
		}
		// echo $sumTotal;
		// die;
		$vbc = 0;

		foreach($venda->sell_lines as $i){
			$itemCont++;

			$ncm = $i->product->ncm;
			$ncm = str_replace(".", "", $ncm);

			$ibpt = Ibpt::getIBPT($config->cidade->uf, $ncm);

			$stdProd = new \stdClass();
			$stdProd->item = $itemCont;

			$cod = $this->validate_EAN13Barcode($i->product->codigo_barras);
			$stdProd->cEAN = $cod ? $i->product->codigo_barras : 'SEM GTIN';
			$stdProd->cEANTrib = $cod ? $i->product->codigo_barras : 'SEM GTIN';
			// $stdProd->cEAN = $i->product->codigo_barras != '' ? $i->product->codigo_barras : 'SEM GTIN';
			// $stdProd->cEANTrib = $i->product->codigo_barras != '' ? $i->product->codigo_barras : 'SEM GTIN';
			// $stdProd->cEANTrib = strlen($i->product->sku) < 7 ? 'SEM GTIN' : $i->product->sku;
			$stdProd->cProd = $i->product->id;
			$stdProd->xProd = $i->product->name;

			// if($i->variations){
			// 	$stdProd->xProd .= " " . $i->variations->name;
			// }

			if($i->variations){
				$stdProd->xProd .= " " . ($i->variations->name != "DUMMY" ? $i->variations->name : '');
			}
			$stdProd->xProd = \App\Helpers\FiscalHelper::truncate($stdProd->xProd, 120);
			$ncm = $i->product->ncm;
			$ncm = str_replace(".", "", $ncm);
			$stdProd->NCM = $ncm;

			$stdProd->CFOP = $i->product->cfop_interno;
			// $cest = $i->product->cest != null ? $i->product->cest : '';
			// $cest = str_replace(".", "", $cest);
			// $stdProd->CEST = $cest;
			$stdProd->uCom = $i->product->unit->short_name;
			$stdProd->qCom = $i->quantity;
			$stdProd->vUnCom = $this->format($i->unit_price);
			$stdProd->vProd = $this->format(($i->quantity * $i->unit_price));

			if($i->product->unidade_tributavel == ''){
				$stdProd->uTrib = $i->product->unit->short_name;
			}else{
				$stdProd->uTrib = $i->product->unidade_tributavel;
			}

			if($i->product->quantidade_tributavel == 0){
				$stdProd->qTrib = $i->quantity;
			}else{
				$stdProd->qTrib = $i->product->quantidade_tributavel * $i->quantity;
			}

			$stdProd->vUnTrib = $this->format($i->unit_price);

			if($i->product->quantidade_tributavel > 0){
				$stdProd->vUnTrib = $stdProd->vProd/$stdProd->qTrib;
			}
			$stdProd->indTot = 1;
			// fim calculo

			if($vOutro > 0){
				if($itemCont < sizeof($venda->sell_lines)){
					$totalVenda = $venda->valor_total;

					$media = (((($stdProd->vProd-$totalVenda)/$totalVenda))*100);
					$media = 100 - ($media * -1);

					$tempAcrescimo = ($vOutro*$media)/100;
					$somaAcrescimo+=$tempAcrescimo;
					if($tempAcrescimo > 0.1)
						$stdProd->vOutro = $this->format($tempAcrescimo);
				}else{
					if($vOutro - $somaAcrescimo > 0.1)
						$stdProd->vOutro = $this->format($vOutro - $somaAcrescimo);
				}
			}

			if($i->product->codigo_anp != ''){
				$stdComb = new \stdClass();
				$stdComb->item = $itemCont; 
				$stdComb->cProdANP = $i->product->codigo_anp;
				$stdComb->descANP = $i->product->getDescricaoAnp(); 
				$stdComb->pGLP = $this->format($i->product->perc_glp);
				$stdComb->pGNn = $this->format($i->product->perc_gnn);
				$stdComb->pGNi = $this->format($i->product->perc_gni);
				$stdComb->vPart = $this->format($i->product->valor_partida);

				$stdComb->UFCons = $config->cidade->uf;
				if($i->product->pBio > 0){
					$stdComb->pBio = $i->produto->pBio;
				}
				$nfe->tagcomb($stdComb);
			}
			
			$cest = $i->product->cest;
			$cest = str_replace(".", "", $cest);
			$stdProd->CEST = $cest;
			if(strlen($cest) > 2){
				$std = new \stdClass();
				$std->item = $itemCont; 
				$std->CEST = $cest;
				$nfe->tagCEST($std);
			}

			$vDesc = 0;
			if($totalDesconto >= 0.1){
				if($itemCont < sizeof($venda->sell_lines)){
					$totalVenda = $venda->final_total;

					$media = (((($stdProd->vProd - $totalVenda)/$totalVenda))*100);
					$media = 100 - ($media * -1);

					$tempDesc = ($totalDesconto*$media)/100;
					$somaDesconto += $tempDesc;

					$stdProd->vDesc = $this->format($tempDesc);
				}else{
					$stdProd->vDesc = $this->format($totalDesconto - $somaDesconto);
				}
			}

			$somaProdutos += $i->quantity * $i->unit_price;


			$prod = $nfe->tagprod($stdProd);

			$stdImposto = new \stdClass();
			$stdImposto->item = $itemCont;

			if($ibpt != null){
				// $vProd = $stdProd->vProd;
				// $somaFederal = ($vProd*($ibpt->nacional_federal/100));
				// $somaEstadual += ($vProd*($ibpt->estadual/100));
				// $somaMunicipal += ($vProd*($ibpt->municipal/100));
				// $soma = $somaFederal + $somaEstadual + $somaMunicipal;
				// $stdImposto->vTotTrib = $soma;

				$vProd = $stdProd->vProd;

				$federal = ($vProd*($ibpt->nacional_federal/100));
				$somaFederal += $federal;

				$estadual = ($vProd*($ibpt->estadual/100));
				$somaEstadual += $estadual;

				$municipal = ($vProd*($ibpt->municipal/100));
				$somaMunicipal += $municipal;
				$soma = $federal + $estadual + $municipal;

				$stdImposto->vTotTrib = $soma;
			}

			$imposto = $nfe->tagimposto($stdImposto);


			if($config->regime == 3){ 

				//$venda->produto->CST  CST

				$stdICMS = new \stdClass();
				$stdICMS->item = $itemCont; 
				$stdICMS->orig = 0;
				$stdICMS->CST = $i->product->cst_csosn;
				$stdICMS->modBC = 0;
				$stdICMS->vBC = $this->format($i->unit_price * $i->quantity);
				$stdICMS->pICMS = $this->format($i->product->perc_icms);
				$stdICMS->vICMS = $stdICMS->vBC * ($stdICMS->pICMS/100);

				
				$ICMS = $nfe->tagICMS($stdICMS);

				if($i->product->cst_csosn != 60){
					$vbc += $stdICMS->vBC;
					$somaICMS += (($i->unit_price * $i->quantity) 
						* ($stdICMS->pICMS/100));
				}


					// regime simples
			}else{ 

				//$venda->produto->CST CSOSN

				$stdICMS = new \stdClass();

				$stdICMS->item = $itemCont; 
				$stdICMS->orig = 0;
				$stdICMS->CSOSN = $i->product->cst_csosn;

				if($i->product->cst_csosn == '500'){
					$stdICMS->vBCSTRet = 0.00;
					$stdICMS->pST = 0.00;
					$stdICMS->vICMSSTRet = 0.00;
				}

				$stdICMS->pCredSN = $this->format($i->product->perc_icms);
				$stdICMS->vCredICMSSN = $this->format($i->product->perc_icms);

				if($i->product->cst_csosn == '61'){
					$stdICMS->CST = $i->product->cst_csosn;
					$stdICMS->qBCMonoRet = $this->format($stdProd->qTrib);
					$stdICMS->adRemICMSRet = $this->format($i->product->adRemICMSRet, 4);
					$stdICMS->vICMSMonoRet = $this->format($i->product->adRemICMSRet*$stdProd->qTrib, 4);
					$ICMS = $nfe->tagICMS($stdICMS);
				}else{
					$ICMS = $nfe->tagICMSSN($stdICMS);
				}
				$somaICMS = 0;

			}



			$stdPIS = new \stdClass();
			$stdPIS->item = $itemCont; 
			$stdPIS->CST = $i->product->cst_pis;
			$stdPIS->vBC = $this->format($i->product->perc_pis) > 0 ? $stdProd->vProd : 0.00;
			$stdPIS->pPIS = $this->format($i->product->perc_pis);
			$stdPIS->vPIS = $this->format(($stdProd->vProd * $i->quantity) * 
				($i->product->perc_pis/100));
			$PIS = $nfe->tagPIS($stdPIS);

				//COFINS
			$stdCOFINS = new \stdClass();
			$stdCOFINS->item = $itemCont; 
			$stdCOFINS->CST = $i->product->cst_cofins;
			$stdCOFINS->vBC = $this->format($i->product->perc_cofins) > 0 ? $stdProd->vProd : 0.00;
			$stdCOFINS->pCOFINS = $this->format($i->product->perc_cofins);
			$stdCOFINS->vCOFINS = $this->format(($stdProd->vProd * $i->quantity) * 
				($i->product->perc_cofins/100));
			$COFINS = $nfe->tagCOFINS($stdCOFINS);


		}

		//ICMS TOTAL
		$stdICMSTot = new \stdClass();
		$stdICMSTot->vBC = $vbc;
		$stdICMSTot->vICMS = $this->format($somaICMS);
		$stdICMSTot->vICMSDeson = 0.00;
		$stdICMSTot->vBCST = 0.00;
		$stdICMSTot->vST = 0.00;
		$stdICMSTot->vProd = $this->format($somaProdutos);
		
		$stdICMSTot->vFrete = 0.00;

		$stdICMSTot->vSeg = 0.00;
		$stdICMSTot->vDesc = $this->format($totalDesconto);
		$stdICMSTot->vII = 0.00;
		$stdICMSTot->vIPI = 0.00;
		$stdICMSTot->vPIS = 0.00;
		$stdICMSTot->vCOFINS = 0.00;
		$stdICMSTot->vOutro = $this->format($vOutro);
		$stdICMSTot->vNF = $this->format($somaProdutos-$totalDesconto+$vOutro);
		$stdICMSTot->vTotTrib = 0.00;
		$ICMSTot = $nfe->tagICMSTot($stdICMSTot);
		

		$stdTransp = new \stdClass();
		$stdTransp->modFrete = 9;

		$transp = $nfe->tagtransp($stdTransp);
		
		$stdPag = new \stdClass();
		$det = $venda->payment_lines[0];

		if($det->method == 'cash'){
			$stdPag->vTroco = $this->format($venda->troco); 
		}else{
			$stdPag->vTroco = 0;
		}

		$pag = $nfe->tagpag($stdPag);

		//Resp Tecnico
		$respCnpj = preg_replace('/[^0-9]/', '', (string) (env('RESP_CNPJ') ?? getenv('RESP_CNPJ') ?: ''));
		$respNome = trim((string) (env('RESP_NOME') ?? getenv('RESP_NOME') ?: ''));
		$respEmail = trim((string) (env('RESP_EMAIL') ?? getenv('RESP_EMAIL') ?: ''));
		$respFone = preg_replace('/[^0-9]/', '', (string) (env('RESP_FONE') ?? getenv('RESP_FONE') ?: ''));
		if (strlen($respCnpj) === 14 && strlen($respNome) >= 2 && strlen($respEmail) >= 6 && preg_match('/^[0-9]{6,14}$/', $respFone)) {
			$stdResp = new \stdClass();
			$stdResp->CNPJ = $respCnpj;
			$stdResp->xContato = $respNome;
			$stdResp->email = $respEmail;
			$stdResp->fone = $respFone;

			$nfe->taginfRespTec($stdResp);
		} else {
			\Log::warning('infRespTec omitido (NFCe): RESP_* ausente ou inválido', compact('respCnpj', 'respNome', 'respEmail', 'respFone'));
		}

		//DETALHE PAGAMENTO

		$stdDetPag = new \stdClass();
		$stdDetPag->indPag = 0;

		if(sizeof($venda->payment_lines) == 1){

			$det = $venda->payment_lines[0];

			if($det->method == 'cash'){
				$tipo = '01';
			}else if($det->method == 'card'){
				$tipo = '03';
				if($det->card_type == 'debit'){
					$tipo = '04';
				}
			}else if($det->method == 'debit'){
				$tipo = '04';
			}else if($det->method == 'pix'){
				$tipo = '17';
			}else if($det->method == 'vale_alimentacao'){
				$tipo = '10';
			}else if($det->method == 'vale_refeicao'){
				$tipo = '11';
			}else{
				$tipo = '99';
			}
			$stdDetPag->tPag = $tipo;

			$stdDetPag->vPag = $this->format($venda->valor_recebido); 

			if($tipo == '03' || $tipo == '04' || $tipo == '17'){
				if($det->card_transaction_number != ""){
					$stdDetPag->cAut = $det->card_transaction_number;
				}

				if($det->card_holder_name != ""){
					$stdDetPag->CNPJ = $det->card_holder_name;
				}

				$stdDetPag->tBand = $det->card_security;
				$stdDetPag->tpIntegra = 2;
				$stdDetPag->vPag = $this->format($somaProdutos-$totalDesconto); 

			}
			// $tipo = '99';
			if($tipo == '99'){
				$stdDetPag->xPag = $det->note != "" ? $det->note : "Multiplo pagamento";
			}
			$detPag = $nfe->tagdetPag($stdDetPag);


		}else{

			foreach($venda->payment_lines as $det){

				if($det->method == 'cash'){
					$tipo = '01';
				}else if($det->method == 'card'){
					// $tipo = '03';
					if($det->card_type == 'debit'){
						$tipo = '04';
					}else{
						$tipo = '03';
					}
				}else if($det->method == 'pix'){
					$tipo = '17';
				}else if($det->method == 'debit'){
					$tipo = '04';
				}else{
					$tipo = '99';
				}
				$stdDetPag->tPag = $tipo;
			// $stdDetPag->tPag = $tipo; 

				$stdDetPag->vPag = $this->format($det->amount); 

				if($tipo == '03' || $tipo == '04' || $tipo == '17'){
					// $stdDetPag->CNPJ = '12345678901234';
					$stdDetPag->tBand = '01';
					// $stdDetPag->cAut = '3333333';
					$stdDetPag->tpIntegra = 2;
				}

				if($tipo == '99'){
					$stdDetPag->xPag = $det->note != "" ? $det->note : "Multiplo pagamento";
				}
			// $std->tpIntegra = 1; //incluso na NT 2015/002
			// $std->indPag = '0'; //0= Pagamento à Vista 1= Pagamento à Prazo

				$detPag = $nfe->tagdetPag($stdDetPag);
			}
		}
			// die();

		$stdInfoAdic = new \stdClass();
		// $stdInfoAdic->infAdFisco = 'informacoes para o fisco';
		$obs = "";
		if($somaEstadual > 0 || $somaFederal > 0 || $somaMunicipal > 0){
			$obs .= " Trib. aprox. ";
			if($somaFederal > 0){
				$obs .= "R$ " . number_format($somaFederal, 2, ',', '.') ." Federal"; 
			}
			if($somaEstadual > 0){
				$obs .= ", R$ ".number_format($somaEstadual, 2, ',', '.')." Estadual"; 
			}
			if($somaMunicipal > 0){
				$obs .= ", R$ ".number_format($somaMunicipal, 2, ',', '.')." Municipal"; 
			}
			$obs .= " FONTE: " . ($ibpt->versao ?? '');
		}
		$stdInfoAdic->infCpl = $obs;
		$infoAdic = $nfe->taginfAdic($stdInfoAdic);

		//INFO ADICIONAL
		// $stdInfoAdic = new \stdClass();
		// $stdInfoAdic->infAdFisco = 'informacoes para o fisco';
		// $stdInfoAdic->infCpl = 'informacoes complementares';

		// $infoAdic = $nfe->taginfAdic($stdInfoAdic);
		try{
			$nfe->monta();
			$arr = [
				'chave' => $nfe->getChave(),
				'xml' => $nfe->getXML(),
				'nNf' => $stdIde->nNF,
				'modelo' => $nfe->getModelo()
			];
			return $arr;
		}catch(\Exception $e){
			return [
				'erros_xml' => $nfe->getErrors()
			];
		}


	}

	public function format($number, $dec = 2){
		return number_format((float) $number, $dec, ".", "");
	}

	public function sign($xml){
		return $this->tools->signNFe($xml);
	}

	public function transmitir($signXml, $chave, $cnpj){
		try{
			$idLote = substr(str_replace(',', '', number_format(microtime(true) * 1000000, 0)), 0, 15);
			Log::info('NFCeService transmitir init', ['idLote' => $idLote, 'indSinc' => 1, 'chave' => $chave, 'cnpj' => $cnpj]);

			$resp = $this->tools->sefazEnviaLote([$signXml], $idLote, 1);
			sleep(4);
			$st = new Standardize();
			$std = $st->toStd($resp);
			Log::info('NFCeService transmitir resp', ['cStat' => isset($std->cStat) ? (string)$std->cStat : null, 'xMotivo' => isset($std->xMotivo) ? (string)$std->xMotivo : null]);

			if ($std->cStat != 103 && $std->cStat != 104) {
				return ['erro' => true, 'protocolo' => "[$std->cStat] - $std->xMotivo", 'status' => 402];
			}
			sleep(1);

    		// $recibo = $std->infRec->nRec; 
    		// $protocolo = $this->tools->sefazConsultaRecibo($recibo);
    		// sleep(3);

			try {
				$xml = Complements::toAuthorize($signXml, $resp);
				header('Content-type: text/xml; charset=UTF-8');

				if(!is_dir(public_path('xml_nfce/'.$cnpj))){
					mkdir(public_path('xml_nfce/'.$cnpj), 0777, true);
				}
				file_put_contents(public_path('xml_nfce/'.$cnpj.'/'.$chave.'.xml'), $xml);
				// return $recibo;
				return ['successo' => true, 'recibo' => $std->protNFe->infProt->nProt];
				// $this->printDanfe($xml);
			} catch (\Exception $e) {
				// return "Erro: " . $st->toJson($resp);
				return ['erro' => true, 'protocolo' => $st->toJson($resp), 'status' => 401];
			}

		} catch(\Exception $e){
			// return "Erro: ".$e->getMessage() ;
			return ['erro' => true, 'protocolo' => $e->getMessage(), 'status' => 401];

		}

	}

	public function transmitirLote($signXml, $chave, $cnpj){
		try{
			$idLote = substr(str_replace(',', '', number_format(microtime(true) * 1000000, 0)), 0, 15);
			Log::info('NFCeService transmitirLote init', ['idLote' => $idLote, 'indSinc' => 1, 'chave' => $chave, 'cnpj' => $cnpj]);

			$resp = $this->tools->sefazEnviaLote($signXml, $idLote, 1);
			sleep(4);
			$st = new Standardize();
			$std = $st->toStd($resp);
			Log::info('NFCeService transmitirLote resp', ['cStat' => isset($std->cStat) ? (string)$std->cStat : null, 'xMotivo' => isset($std->xMotivo) ? (string)$std->xMotivo : null]);

			if ($std->cStat != 103 && $std->cStat != 104) {
				return ['erro' => true, 'protocolo' => "[$std->cStat] - $std->xMotivo", 'status' => 402];
			}
			sleep(1);

    		// $recibo = $std->infRec->nRec; 
    		// $protocolo = $this->tools->sefazConsultaRecibo($recibo);
    		// sleep(3);

			try {
				foreach($signXml as $signXml){
					$xml = Complements::toAuthorize($signXml, $resp);
					header('Content-type: text/xml; charset=UTF-8');

					if(!is_dir(public_path('xml_nfce/'.$cnpj))){
						mkdir(public_path('xml_nfce/'.$cnpj), 0777, true);
					}
					file_put_contents(public_path('xml_nfce/'.$cnpj.'/'.$chave.'.xml'), $xml);
				}
				// return $recibo;
				return ['successo' => true, 'recibo' => $std->protNFe->infProt->nProt];
				// $this->printDanfe($xml);
			} catch (\Exception $e) {
				// return "Erro: " . $st->toJson($resp);
				return ['erro' => true, 'protocolo' => $st->toJson($resp), 'status' => 401];
			}

		} catch(\Exception $e){
			// return "Erro: ".$e->getMessage() ;
			return ['erro' => true, 'protocolo' => $e->getMessage(), 'status' => 401];

		}

	}

	// public function transmitir($signXml, $chave, $cnpj){
	// 	try{
	// 		$idLote = str_pad(100, 15, '0', STR_PAD_LEFT);
	// 		$resp = $this->tools->sefazEnviaLote([$signXml], $idLote);
	// 		sleep(6);
	// 		$st = new Standardize();
	// 		$std = $st->toStd($resp);

	// 		if ($std->cStat != 103) {

	// 			// return "[$std->cStat] - $std->xMotivo";
	// 			return ['erro' => true, 'protocolo' => "[$std->cStat] - $std->xMotivo", 'status' => 402];
	// 		}

	// 		$recibo = $std->infRec->nRec; 
	// 		sleep(2);

	// 		$protocolo = $this->tools->sefazConsultaRecibo($recibo);

	// 		try {
	// 			$xml = Complements::toAuthorize($signXml, $protocolo);
	// 			header('Content-type: text/xml; charset=UTF-8');

	// 			if(!is_dir(public_path('xml_nfce/'.$cnpj))){
	// 				mkdir(public_path('xml_nfce/'.$cnpj), 0777, true);
	// 			}
	// 			file_put_contents(public_path('xml_nfce/'.$cnpj.'/'.$chave.'.xml'), $xml);
	// 			// return $recibo;
	// 			return ['successo' => true, 'recibo' => $recibo];

	// 			// $this->printDanfe($xml);
	// 		} catch (\Exception $e) {
	// 			return ['erro' => true, 'protocolo' => $st->toJson($protocolo), 'status' => 401];
	// 		}

	// 	} catch(\Exception $e){
	// 		// return "erro: ".$e->getMessage() ;
	// 		return ['erro' => true, 'protocolo' => $e->getMessage(), 'status' => 401];
	// 	}

	// }	

	public function cancelar($venda, $justificativa, $cnpj){
		try {

			$chave = $venda->chave;
			$response = $this->tools->sefazConsultaChave($chave);
			$stdCl = new Standardize($response);
			$arr = $stdCl->toArray();
			sleep(3);
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

			} else {
				$cStat = $std->retEvento->infEvento->cStat;
				$public = getenv('SERVIDOR_WEB') ? 'public/' : '';
				if ($cStat == '101' || $cStat == '135' || $cStat == '155' ) {

					$xml = Complements::toAuthorize($this->tools->lastRequest, $response);

					if(!is_dir(public_path('xml_nfce_cancelada/'.$cnpj))){
						mkdir(public_path('xml_nfce_cancelada/'.$cnpj), 0777, true);
					}
					file_put_contents(public_path('xml_nfce_cancelada/'.$cnpj.'/'.$chave.'.xml'), $xml);

					return $arr;
				} else {

					return ['erro' => true, 'data' => $arr, 'status' => 402];	
				}
			}    
		} catch (\Exception $e) {
			echo $e->getMessage();
    //TRATAR
		}
	}

	private function validaEan13($code){
		if(strlen($code) < 10) return true;
		$weightflag = true;
		$sum = 0;
		for ($i = strlen($code) - 1; $i >= 0; $i--) {
			$sum += (int)$code[$i] * ($weightflag?3:1);
			$weightflag = !$weightflag;
		}
		return (10 - ($sum % 10)) % 10;
	}

	public function consultar($venda){
		try {
			$chave = $venda->chave;
			$this->tools->model('65');

			$chave = $venda->chave;
			$response = $this->tools->sefazConsultaChave($chave);

			$stdCl = new Standardize($response);
			$arr = $stdCl->toArray();

			// $arr = json_decode($json);
			return $arr;

		} catch (\Exception $e) {
			echo $e->getMessage();
		}
	}

	public function consultaStatus($tpAmb, $uf){
		try{
			$response = $this->tools->sefazStatus($uf, $tpAmb);
			$stdCl = new Standardize($response);
			$arr = $stdCl->toArray();
			return $arr;
		} catch (\Exception $e) {
			echo $e->getMessage();
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


}