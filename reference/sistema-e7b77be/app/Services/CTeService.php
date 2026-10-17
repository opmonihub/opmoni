<?php

namespace App\Services;
use NFePHP\CTe\Make;
use NFePHP\CTe\Tools;
use NFePHP\CTe\Complements;
use NFePHP\CTe\Common\Standardize;
use NFePHP\Common\Certificate;
use NFePHP\Common\Soap\SoapCurl;
use App\Models\ConfigNota;
use App\Models\Cte;
use App\Models\Tributacao;
use App\Models\Certificado;
use App\Models\Business;
use NFePHP\CTe\MakeCTe;
use Illuminate\Support\Facades\Log;

error_reporting(E_ALL);
ini_set('display_errors', 'On');
class CTeService{

	private $config; 
	private $tools;

	public function __construct($config, $certificado){

		$this->config = $config;
		$this->tools = new Tools(json_encode($config), Certificate::readPfx($certificado->certificado, base64_decode($certificado->senha_certificado)));
		$soapCurl = new SoapCurl();
		$soapCurl->httpVersion('1.1');
		$this->tools->loadSoapClass($soapCurl);
		$this->tools->model('57');
		
	}

	public function gerarCTe($cteEmit){
		date_default_timezone_set('America/Belem');
		
		$business_id = request()->session()->get('user.business_id');
		$config = Business::getConfigCte($business_id, $cteEmit);

		$cte = new MakeCTe();
		$dhEmi = date("Y-m-d\TH:i:sP");

		$numeroCTE = $cteEmit->lastCTe($cteEmit);
		$numeroCTE++;

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		$chave = $this->montaChave(
			$config->cUF, date('y', strtotime($dhEmi)), date('m', strtotime($dhEmi)), $cnpj, $this->tools->model(), '1', $numeroCTE, '1', '10'
		);
		$infCte = new \stdClass();
		$infCte->Id = "";
		$infCte->versao = "4.00";
		$cte->taginfCTe($infCte);

		$cDV = substr($chave, -1);      
		$ide = new \stdClass();

		$ide->cUF = $config->getcUF($config->cidade->uf); 
		$ide->cCT = rand(11111111, 99999999); 

		// CFOP do CTe é definido pela relação UFIni x UFFim (início x fim do transporte), não pelo estado das partes
		// Rejeição SEFAZ 524: UF emitente != UF início (e início != EX) exige 5932/6932
		// em qualquer finalidade (Normal, Complemento, Substituto)
		$ufEmitente = $config->cidade->uf;
		$ufIniCfop = $cteEmit->municipioInicio->uf;
		if($ufEmitente != $ufIniCfop && $ufIniCfop != 'EX'){
			$ide->CFOP = $ufIniCfop != $cteEmit->municipioFim->uf ? '6932' : '5932';
		}else{
			$ide->CFOP = $cteEmit->municipioInicio->uf != $cteEmit->municipioFim->uf ?
			$cteEmit->natureza->cfop_saida_inter_estadual : $cteEmit->natureza->cfop_saida_estadual;
		}

		$ide->natOp = $cteEmit->natureza->natureza;
		$ide->mod = '57'; 
		$ide->serie = $config->numero_serie_cte; 
		$nCte = $ide->nCT = $numeroCTE; 
		$ide->dhEmi = $dhEmi; 
		$ide->tpImp = '1'; 
		$ide->tpEmis = '1'; 
		$ide->cDV = $cDV; 
		$ide->tpAmb = (int)$config->ambiente; 
		$tpCTe = isset($cteEmit->tp_cte) ? (int) $cteEmit->tp_cte : 0;
		if (!in_array($tpCTe, [0, 1, 3], true)) {
			$tpCTe = 0;
		}
		$ide->tpCTe = (string) $tpCTe; 

		$ide->procEmi = '0'; 
		$ide->verProc = '4.0'; 
		// $ide->indGlobalizado = '';
		$ide->indGlobalizado = $cteEmit->globalizado == 1 ? '1' : '';
		
		$ide->cMunEnv = $cteEmit->municipioEnvio->codigo; 
		$ide->xMunEnv = strtoupper($cteEmit->municipioEnvio->nome); 
		$ide->UFEnv = $cteEmit->municipioEnvio->uf; 
		$ide->modal = $cteEmit->modal; 
		$ide->tpServ = $cteEmit->tipo_servico ?? '0'; 

		$ide->cMunIni = $cteEmit->municipioInicio->codigo; 
		$ide->xMunIni = strtoupper($cteEmit->municipioInicio->nome); 
		$ide->UFIni = $cteEmit->municipioInicio->uf; 
		$ide->cMunFim = $cteEmit->municipioFim->codigo; 
		$ide->xMunFim = strtoupper($cteEmit->municipioFim->nome); 
		$ide->UFFim = $cteEmit->municipioFim->uf;
		$ide->retira = $cteEmit->retira ? 0 : 1;
		$ide->xDetRetira = $cteEmit->detalhes_retira;

		if($cteEmit->tomador == 0){
			if($cteEmit->remetente->contribuinte){
				if($cteEmit->remetente->ie_rg == 'ISENTO'){
					$ide->indIEToma = '2';
				}else{
					$ide->indIEToma = '1';
				}
			}else{
				$ide->indIEToma = '9';
			}
		}else if($cteEmit->tomador == 1){
			if($cteEmit->expedidor && $cteEmit->expedidor->contribuinte){
				if($cteEmit->expedidor->ie_rg == 'ISENTO'){
					$ide->indIEToma = '2';
				}else{
					$ide->indIEToma = '1';
				}
			}else{
				$ide->indIEToma = '9';
			}
		}else if($cteEmit->tomador == 2){
			if($cteEmit->recebedor && $cteEmit->recebedor->contribuinte){
				if($cteEmit->recebedor->ie_rg == 'ISENTO'){
					$ide->indIEToma = '2';
				}else{
					$ide->indIEToma = '1';
				}
			}else{
				$ide->indIEToma = '9';
			}
		}else if($cteEmit->tomador == 3){
			if($cteEmit->destinatario->contribuinte){
				if($cteEmit->destinatario->ie_rg == 'ISENTO'){
					$ide->indIEToma = '2';
				}else{
					$ide->indIEToma = '1';
				}
			}else{
				$ide->indIEToma = '9';
			}
		}else if($cteEmit->tomador == 4){
			// Tomador Outros
			if($cteEmit->tomadorOutros && $cteEmit->tomadorOutros->contribuinte){
				if($cteEmit->tomadorOutros->ie_rg == 'ISENTO'){
					$ide->indIEToma = '2';
				}else{
					$ide->indIEToma = '1';
				}
			}else{
				$ide->indIEToma = '9';
			}
		}

		// $ide->indIEToma = $cteEmit->destinatario;
		$ide->dhCont = ''; 
		$ide->xJust = '';

		$cte->tagide($ide);

		// Indica o "papel" do tomador: 0-Remetente; 1-Expedidor; 2-Recebedor; 3-Destinatário; 4-Outros
		if($cteEmit->tomador == 4){
			// Tomador Outros - usar toma4
			$toma4 = new \stdClass();
			$toma4->toma = '4';
			
			$cnpjTomador = preg_replace('/[^0-9]/', '', $cteEmit->tomadorOutros->cpf_cnpj);
			if(strlen($cnpjTomador) == 14){
				$toma4->CNPJ = $cnpjTomador;
			}else{
				$toma4->CPF = $cnpjTomador;
			}
			
			$toma4->IE = $cteEmit->tomadorOutros->ie_rg ?? '';
			$toma4->xNome = $cteEmit->tomadorOutros->name;
			$toma4->xFant = $cteEmit->tomadorOutros->nome_fantasia ?? '';
			$toma4->fone = '';
			$toma4->email = $cteEmit->tomadorOutros->email ?? '';
			$cte->tagtoma4($toma4);
		}else{
			// Tomador 0-3 - usar toma3
			$toma3 = new \stdClass();
			$toma3->toma = $cteEmit->tomador;
			$cte->tagtoma3($toma3);
		}

		$enderToma = new \stdClass();
		$enderToma->xLgr = $cteEmit->logradouro_tomador;
		$enderToma->nro = $cteEmit->numero_tomador; 
		$enderToma->xCpl = ''; 
		$enderToma->xBairro = $cteEmit->bairro_tomador; 
		$enderToma->cMun = $cteEmit->municipioTomador->codigo; 
		$enderToma->xMun = $cteEmit->municipioTomador->nome; 
		$enderToma->CEP = \App\Helpers\FiscalHelper::cep($cteEmit->cep_tomador);
		$enderToma->UF = $cteEmit->municipioTomador->uf; 
		$enderToma->cPais = '1058'; 
		$enderToma->xPais = 'Brasil';                   
		$cte->tagenderToma($enderToma);   

		$emit = new \stdClass();

		$emit->CNPJ = $cnpj;

		$ie = preg_replace('/[^0-9]/', '', $config->ie);

		$emit->IE = $ie; 
		$emit->IEST = "";
		$emit->xNome = $config->razao_social; 
		$emit->xFant = $config->name; 
		$emit->CRT = $config->regime;

		$cte->tagemit($emit); 

		$enderEmit = new \stdClass();
		$enderEmit->xLgr = $config->rua; 
		$enderEmit->nro = $config->numero; 
		$enderEmit->xCpl = '';
		$enderEmit->xBairro = $config->bairro; 
		$enderEmit->cMun = $config->cidade->codigo;
		$enderEmit->xMun = $config->cidade->nome; 

		$enderEmit->CEP = \App\Helpers\FiscalHelper::cep($config->cep);
		$enderEmit->UF = $config->cidade->uf; 

		$fone = str_replace(" ", "", $config->fone);
		$fone = str_replace("-", "", $fone);
		$enderEmit->fone = $fone; 
		$cte->tagenderEmit($enderEmit);

		$rem = new \stdClass();

		$cnpjRemente = preg_replace('/[^0-9]/', '', $cteEmit->remetente->cpf_cnpj);

		if(strlen($cnpjRemente) == 14){
			$rem->CNPJ = $cnpjRemente;
		}
		else{
			$rem->CPF = $cnpjRemente;
		}
		// IE é obrigatória quando indIEToma=1 (Contribuinte), inclusive para produtor rural com CPF
		if(!empty($cteEmit->remetente->ie_rg) && $cteEmit->remetente->ie_rg != 'ISENTO'){
			$rem->IE = preg_replace('/[^0-9]/', '', $cteEmit->remetente->ie_rg);
		}
		// echo $cteEmit->remetente;
		// die;
		$rem->xNome = $cteEmit->remetente->name;
		// if($cteEmit->remetente->nome_fantasia) $rem->xFant = $cteEmit->remetente->nome_fantasia;
		$rem->fone = ''; 
		$rem->email = ''; 
		$cte->tagrem($rem);

		$enderReme = new \stdClass();
		$enderReme->xLgr = $cteEmit->remetente->rua; 
		$enderReme->nro = $cteEmit->remetente->numero; 
		$enderReme->xCpl = ''; 
		$enderReme->xBairro = $cteEmit->remetente->bairro; 
		$enderReme->cMun = $cteEmit->remetente->cidade->codigo; 
		$enderReme->xMun = strtoupper($cteEmit->remetente->cidade->nome); 
		$enderReme->CEP = \App\Helpers\FiscalHelper::cep($cteEmit->remetente->cep);
		$enderReme->UF = $cteEmit->remetente->cidade->uf; 
		$enderReme->cPais = '1058'; 
		$enderReme->xPais = 'Brasil'; 
		$cte->tagenderReme($enderReme);

		$dest = new \stdClass();

		$cnpjDestinatario = preg_replace('/[^0-9]/', '', $cteEmit->destinatario->cpf_cnpj);

		if(strlen($cnpjDestinatario) == 14){
			$dest->CNPJ = $cnpjDestinatario;
		}
		else{
			$dest->CPF = $cnpjDestinatario;
		}
		if(!empty($cteEmit->destinatario->ie_rg) && $cteEmit->destinatario->ie_rg != 'ISENTO'){
			$dest->IE = preg_replace('/[^0-9]/', '', $cteEmit->destinatario->ie_rg);
		}
		
		$dest->xNome = $cteEmit->destinatario->name;
		$dest->fone = ''; 
		$dest->ISUF = ''; 
		$dest->email = ''; 
		$cte->tagdest($dest);

		$enderDest = new \stdClass();
		$enderDest->xLgr = $cteEmit->destinatario->rua; 
		$enderDest->nro = $cteEmit->destinatario->numero; 
		$enderDest->xCpl = ''; 
		$enderDest->xBairro = $cteEmit->destinatario->bairro; 
		$enderDest->cMun = $cteEmit->destinatario->cidade->codigo; 
		$enderDest->xMun = strtoupper($cteEmit->destinatario->cidade->nome); 

		$enderDest->CEP = \App\Helpers\FiscalHelper::cep($cteEmit->destinatario->cep);
		$enderDest->UF = $cteEmit->destinatario->cidade->uf; 
		$enderDest->cPais = '1058'; 
		$enderDest->xPais = 'Brasil'; 
		$cte->tagenderDest($enderDest);

		if($cteEmit->expedidor){

			$exped = new \stdClass();
			$cnpjDestinatario = preg_replace('/[^0-9]/', '', $cteEmit->expedidor->cpf_cnpj);

			if(strlen($cnpjDestinatario) == 14){
				$exped->CNPJ = $cnpjDestinatario;
			}
			else{
				$exped->CPF = $cnpjDestinatario;
			}
			if(!empty($cteEmit->expedidor->ie_rg) && $cteEmit->expedidor->ie_rg != 'ISENTO'){
				$exped->IE = preg_replace('/[^0-9]/', '', $cteEmit->expedidor->ie_rg);
			}

			$exped->xNome = $cteEmit->expedidor->name;
			$exped->fone = ''; 
			$exped->email = ''; 
			$cte->tagexped($exped);

			$enderExped = new \stdClass();
			$enderExped->xLgr = $cteEmit->expedidor->rua; 
			$enderExped->nro = $cteEmit->expedidor->numero; 
			$enderExped->xCpl = ''; 
			$enderExped->xBairro = $cteEmit->expedidor->bairro; 
			$enderExped->cMun = $cteEmit->expedidor->cidade->codigo; 
			$enderExped->xMun = strtoupper($cteEmit->expedidor->cidade->nome); 

			$enderExped->CEP = \App\Helpers\FiscalHelper::cep($cteEmit->expedidor->cep);
			$enderExped->UF = $cteEmit->expedidor->cidade->uf; 
			$enderExped->cPais = '1058'; 
			$enderExped->xPais = 'Brasil'; 
			$cte->tagenderExped($enderExped);
		}

		if($cteEmit->recebedor){

			$receb = new \stdClass();
			$cnpjDestinatario = preg_replace('/[^0-9]/', '', $cteEmit->recebedor->cpf_cnpj);

			if(strlen($cnpjDestinatario) == 14){
				$receb->CNPJ = $cnpjDestinatario;
			}
			else{
				$receb->CPF = $cnpjDestinatario;
			}
			if(!empty($cteEmit->recebedor->ie_rg) && $cteEmit->recebedor->ie_rg != 'ISENTO'){
				$receb->IE = preg_replace('/[^0-9]/', '', $cteEmit->recebedor->ie_rg);
			}

			$receb->xNome = $cteEmit->recebedor->name;
			$receb->fone = ''; 
			$receb->email = ''; 
			$cte->tagreceb($receb);

			$enderReceb = new \stdClass();
			$enderReceb->xLgr = $cteEmit->recebedor->rua; 
			$enderReceb->nro = $cteEmit->recebedor->numero; 
			$enderReceb->xCpl = ''; 
			$enderReceb->xBairro = $cteEmit->recebedor->bairro; 
			$enderReceb->cMun = $cteEmit->recebedor->cidade->codigo; 
			$enderReceb->xMun = strtoupper($cteEmit->recebedor->cidade->nome); 

			$enderReceb->CEP = \App\Helpers\FiscalHelper::cep($cteEmit->recebedor->cep);
			$enderReceb->UF = $cteEmit->recebedor->cidade->uf; 
			$enderReceb->cPais = '1058'; 
			$enderReceb->xPais = 'Brasil'; 
			$cte->tagenderReceb($enderReceb);
		}

		$vPrest = new \stdClass();
		$vPrest->vTPrest = $this->format($cteEmit->valor_transporte); 
		$vPrest->vRec = $this->format($cteEmit->valor_receber);      
		$cte->tagvPrest($vPrest);

		$somaVBC = 0;

		foreach($cteEmit->componentes as $c){
			$comp = new \stdClass();
			$comp->xNome = substr($c->nome, 0, 15); 
			$comp->vComp = $this->format($c->valor);  
			$cte->tagComp($comp);

			if($cteEmit->perc_icms > 0){
				$somaVBC += $c->valor;
			}
		}

		$icms = new \stdClass();
		$icms->cst = $cteEmit->cst;
		$icms->pRedBC = ''; 
		$icms->vBC = $this->format($somaVBC); 
		$icms->pICMS = $this->format($cteEmit->perc_icms);
		if($somaVBC > 0){ 
			$icms->vICMS = $this->format($somaVBC * ($cteEmit->perc_icms/100)); 
		}else{
			$icms->vICMS = 0;
		}
		//novos
		$icms->vBCUFFim = 0.00; 
		$icms->pFCPUFFim = 0.00; 
		$icms->pICMSUFFim = 0.00; 
		$icms->pICMSInter = 0.00; 
		$icms->vFCPUFFim = 0.00; 

		$icms->vBCSTRet = ''; 
		$icms->vICMSSTRet = ''; 
		$icms->pICMSSTRet = ''; 
		$icms->vCred = ''; 
		$icms->vTotTrib = 0.00; 
		$icms->outraUF = false;    
		$icms->vICMSUFIni = 0;  
		$icms->vICMSUFFim = 0;
		$icms->infAdFisco = '';
		$cte->tagicms($icms);

		if ($tpCTe === 1) {
			$chComp = isset($cteEmit->chave_complementada) ? preg_replace('/[^0-9]/', '', (string) $cteEmit->chave_complementada) : '';
			if ($chComp !== '') {
				$infCteComp = new \stdClass();
				$infCteComp->chCTe = $chComp;
				$cte->taginfCTeComp($infCteComp);
			}
		} else {
			$cte->taginfCTeNorm();

		// Documentos de Transporte Anterior (obrigatório para Subcontratação, Redespacho, Redespacho Intermediário)
		if(in_array($cteEmit->tipo_servico, ['1', '2', '3']) && $cteEmit->documentosAnteriores && count($cteEmit->documentosAnteriores) > 0){
			$cte->tagdocAnt();
			foreach($cteEmit->documentosAnteriores as $docAnt){
				$emiDocAnt = new \stdClass();
				$emiDocAnt->CNPJ = $docAnt->cnpj_emitente;
				$emiDocAnt->IE = $docAnt->ie_emitente ?? '';
				$emiDocAnt->UF = $docAnt->uf_emitente;
				$emiDocAnt->xNome = $docAnt->nome_emitente ?? 'TRANSPORTADORA';
				$cte->tagemiDocAnt($emiDocAnt);
				
				$cte->tagidDocAnt();
				
				$idDocAntEle = new \stdClass();
				$idDocAntEle->chCTe = $docAnt->chave_cte;
				$cte->tagidDocAntEle($idDocAntEle);
			}
		}
		
		$infCarga = new \stdClass();
		$infCarga->vCarga = $this->format($cteEmit->valor_carga);
		$infCarga->proPred = $cteEmit->produto_predominante; 
		$infCarga->xOutCat = 0.00; 
		// $infCarga->vCargaAverb = 1.99;
		$cte->taginfCarga($infCarga);

		foreach($cteEmit->medidas as $m){
			$infQ = new \stdClass();
			$infQ->cUnid = $m->cod_unidade; 
// Código da Unidade de Medida: ( 00-M3; 01-KG; 02-TON; 03-UNIDADE; 04-LITROS; 05-MMBTU
			$infQ->tpMed = $m->tipo_medida; 
// Tipo de Medida
// ( PESO BRUTO; PESO DECLARADO; PESO CUBADO; PESO AFORADO; PESO AFERIDO; LITRAGEM; CAIXAS e etc)
			$infQ->qCarga = $m->quantidade_carga;  
// Quantidade (15 posições; sendo 11 inteiras e 4 decimais.)
			$cte->taginfQ($infQ);
		}

		if(strlen($cteEmit->chave_nfe) > 0){
			$chaves = explode(";", $cteEmit->chave_nfe);
			foreach($chaves as $chave){
				$infNFe = new \stdClass();
				$infNFe->chave = $chave; 
				$infNFe->PIN = ''; 
				$infNFe->dPrev = $cteEmit->data_previsata_entrega;                                       
				$cte->taginfNFe($infNFe);
			}
		}else{

			$infOut = new \stdClass();

			$infOut->tpDoc = $cteEmit->tpDoc;     
			$infOut->descOutros = $cteEmit->descOutros;     
			$infOut->nDoc = $cteEmit->nDoc;     
			$infOut->dEmi = date('Y-m-d');     
			$infOut->vDocFisc = $this->format($cteEmit->vDocFisc);     
			$infOut->dPrev = $cteEmit->data_previsata_entrega;     
			$cte->taginfOutros($infOut);

		}


		$infModal = new \stdClass();
		$infModal->versaoModal = '4.00';
		$cte->taginfModal($infModal);

		$rodo = new \stdClass();
		$rodo->RNTRC = $cteEmit->veiculo->rntrc;
		$cte->tagrodo($rodo);

		$aereo = new \stdClass();
		$aereo->nMinu = '123'; 
		$aereo->nOCA = '';
 // Número Operacional do Conhecimento Aéreo
		$aereo->dPrevAereo = date('Y-m-d');
		$aereo->natCarga_xDime = ''; 
		$aereo->natCarga_cInfManu = [  ]; 
		$aereo->tarifa_CL = 'G';
		$aereo->tarifa_cTar = ''; 
		$aereo->tarifa_vTar = 100.00; 
		$cte->tagaereo($aereo);

			if ($tpCTe === 3) {
				$chSub = isset($cteEmit->chave_original) ? preg_replace('/[^0-9]/', '', (string) $cteEmit->chave_original) : '';
				if ($chSub !== '') {
					$infCteSub = new \stdClass();
					$infCteSub->chCte = $chSub;
					if (!empty($cteEmit->ind_altera_toma)) {
						$infCteSub->indAlteraToma = '1';
					}
					$cte->taginfCteSub($infCteSub);
				}
			}
		}

		$stdCompl = new \stdClass();
		$stdCompl->xObs = $cteEmit->observacao;
		$cte->tagcompl($stdCompl);


		try{
			$cte->montaCTe();
			$chave = $cte->chCTe;
			$xml = $cte->getXML();
			$arr = [
				'chave' => $chave,
				'xml' => $xml,
				'nCte' => $nCte
			];
			return $arr;
		}catch(\Exception $e){
			return [
				'erros_xml' => $cte->getErrors()
			];
		}
	}

	public function sign($xml){
		return $this->tools->signCTe($xml);
	}

	public function transmitir($signXml, $chave, $cnpj){
		try {
			if(!is_dir(public_path('xml_cte/'.$cnpj))){
				mkdir(public_path('xml_cte/'.$cnpj), 0777, true);
			}
			$idLote = substr(str_replace(',', '', number_format(microtime(true) * 1000000, 0)), 0, 15);
			// $resp = $this->tools->sefazEnviaLote([$signXml], $idLote);
			$resp = $this->tools->sefazEnviaCTe($signXml);
			$st = new Standardize($resp);
			sleep(4);

			$std = $st->toStd();

			if ($std->cStat != 100) {
				// erro
				// return "[$std->cStat] - $std->xMotivo";
				return [
					'erro' => 1,
					'error' => "[$std->cStat] - $std->xMotivo"
				];
			}

			$recibo = $std->protCTe->infProt->nProt;

			try {
				// $xml = Complements::toAuthorize($signXml, $protocolo);
				$xml = Complements::toAuthorize($signXml, $resp);

				// file_put_contents(public_path('xml_cte/') . $chave . '.xml', $signXml);
				file_put_contents(public_path('xml_cte/'.$cnpj.'/'.$chave.'.xml'), $xml);
				
				// return $recibo;
				return [
					'erro' => 0,
					'success' => $recibo
				];
				// $this->printDanfe($xml);
			} catch (\Exception $e) {
				return [
					'erro' => 1,
					'error' => $e->getMessage()
				];
			}
		} catch (\Exception $e) {
			Log::error('CTeService transmitir exception', [
				'chave' => $chave,
				'cnpj' => $cnpj,
				'message' => $e->getMessage(),
				'last_request' => substr($this->tools->lastRequest ?? '', 0, 3000),
				'last_response' => substr($this->tools->lastResponse ?? '', 0, 3000),
			]);
			return [
				'erro' => 1,
				'error' => $e->getMessage()
			];
		}

	}	

	private function format($number, $dec = 2){
		return number_format((float) $number, $dec, ".", "");
	}

	private function montaChave($cUF, $ano, $mes, $cnpj, $mod, $serie, 
		$numero, $tpEmis, $codigo = ''){
		if ($codigo == '') {
			$codigo = $numero;
		}
		$forma = "%02d%02d%02d%s%02d%03d%09d%01d%08d";
		$chave = sprintf(
			$forma, $cUF, $ano, $mes, $cnpj, $mod, $serie, $numero, $tpEmis, $codigo
		);
		return $chave . $this->calculaDV($chave);
	}

	private function calculaDV($chave43){
		$multiplicadores = array(2, 3, 4, 5, 6, 7, 8, 9);
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


	public function cancelar($cte, $justificativa, $cnpj){

		try {
			
			$chave = $cte->chave;
			$response = $this->tools->sefazConsultaChave($chave);
			$stdCl = new Standardize($response);
				$arr = $stdCl->toArray();
				$js = $stdCl->toJson();
				sleep(1);
				$xJust = $justificativa;

				if(!isset($arr['protCTe']['infProt']['nProt'])){
					$motivo = $arr['xMotivo'] ?? 'Não foi possível obter o protocolo do CT-e para cancelamento.';
					$cStat = $arr['cStat'] ?? null;
					return [
						'erro' => true,
						'data' => ($cStat ? "[$cStat] " : '') . $motivo,
						'status' => 402
					];
				}

				$nProt = $arr['protCTe']['infProt']['nProt'];


			$response = $this->tools->sefazCancela($chave, $xJust, $nProt);

			$stdCl = new Standardize($response);
			$std = $stdCl->toStd();
			$arr = $stdCl->toArray();
			$json = $stdCl->toJson();
			// return $json;
			$cStat = $std->infEvento->cStat;

			$public = getenv('SERVIDOR_WEB') ? 'public/' : '';
			if ($cStat == '101' || $cStat == '135' || $cStat == '155') {
				// header('Content-type: text/xml; charset=UTF-8');

				$xml = Complements::toAuthorize($this->tools->lastRequest, $response);

				if(!is_dir(public_path('xml_cte_cancelada/'.$cnpj))){
					mkdir(public_path('xml_cte_cancelada/'.$cnpj), 0777, true);
				}
				file_put_contents(public_path('xml_cte_cancelada/'.$cnpj.'/'.$chave.'.xml'), $xml);

				return $arr;
			}else{
				return ['erro' => true, 'data' => $arr, 'status' => 402];
			}

		} catch (\Exception $e) {
			return ['erro' => true, 'data' => $e->getMessage(), 'status' => 402];
		}
	}

	public function consultaChave($chave){
		try {
			$response = $this->tools->sefazConsultaChave($chave);
			if(!$response){
				return ['erro' => true, 'data' => 'SEFAZ sem resposta na consulta da chave.', 'status' => 422];
			}
			$stdCl = new Standardize($response);
			$arr = $stdCl->toArray();
			if(!is_array($arr)){
				return ['erro' => true, 'data' => 'SEFAZ retornou resposta inválida na consulta da chave.', 'status' => 422];
			}
			return $arr;
		} catch (\Exception $e) {
			return ['erro' => true, 'data' => $e->getMessage(), 'status' => 422];
		}
	}

	public function consultar($cte){
		try {

			$chave = $cte->chave;
			$response = $this->tools->sefazConsultaChave($chave);

			$stdCl = new Standardize($response);
			$arr = $stdCl->toArray();

			// $arr = json_decode($json);
			return $arr;

		} catch (\Exception $e) {
			return ['erro' => true, 'data' => $e->getMessage(), 'status' => 402];
		}
	}

	public function inutilizar($nInicio, $nFinal, $justificativa){
		try{

			$nSerie = '1';
			$nIni = $nInicio;
			$nFin = $nFinal;
			$xJust = $justificativa;
			$tpAmb = 2;
			$response = $this->tools->sefazInutiliza($nSerie, $nIni, $nFin, $xJust, $tpAmb);

			$stdCl = new Standardize($response);

			$std = $stdCl->toStd();

			$arr = $stdCl->toArray();

			$json = $stdCl->toJson();

			return $arr;

		} catch (\Exception $e) {
			echo $e->getMessage();
		}
	}

	public function cartaCorrecao($cte, array $infCorrecao, $cnpj){
		try {
			if(!$cte || empty($cte->chave)){
				return ['erro' => true, 'data' => 'CT-e inválido para carta de correção.', 'status' => 402];
			}

			if(empty($infCorrecao)){
				return ['erro' => true, 'data' => 'Informe ao menos uma correção.', 'status' => 402];
			}

			$correcoes = [];
			foreach($infCorrecao as $info){
				if(empty($info['grupoAlterado']) || empty($info['campoAlterado']) || !isset($info['valorAlterado']) || trim((string) $info['valorAlterado']) === ''){
					return ['erro' => true, 'data' => 'Grupo, campo e valor da correção são obrigatórios.', 'status' => 402];
				}

				$correcao = [
					'grupoAlterado' => htmlspecialchars((string) $info['grupoAlterado'], ENT_XML1 | ENT_COMPAT, 'UTF-8', false),
					'campoAlterado' => htmlspecialchars((string) $info['campoAlterado'], ENT_XML1 | ENT_COMPAT, 'UTF-8', false),
					'valorAlterado' => htmlspecialchars((string) $info['valorAlterado'], ENT_XML1 | ENT_COMPAT, 'UTF-8', false)
				];

				if(isset($info['nroItemAlterado']) && $info['nroItemAlterado'] !== ''){
					$correcao['nroItemAlterado'] = htmlspecialchars((string) $info['nroItemAlterado'], ENT_XML1 | ENT_COMPAT, 'UTF-8', false);
				}

				$correcoes[] = $correcao;
			}

			$chave = preg_replace('/[^0-9]/', '', $cte->chave);
			$nSeqEvento = $cte->sequencia_cce + 1;
			$response = $this->tools->sefazCCe($chave, $correcoes, $nSeqEvento);

			$stdCl = new Standardize($response);
			$std = $stdCl->toStd();
			$arr = $stdCl->toArray();
			$cStat = isset($std->infEvento->cStat) ? (string) $std->infEvento->cStat : '';

			if (in_array($cStat, ['135', '136'])) {
				$xml = Complements::toAuthorize($this->tools->lastRequest, $response);
				$dir = public_path('xml_cte_correcao/'.$cnpj);

				if(!is_dir($dir)){
					mkdir($dir, 0777, true);
				}

				if(file_put_contents($dir.'/'.$chave.'.xml', $xml) === false){
					return ['erro' => true, 'data' => 'Não foi possível salvar o XML da carta de correção.', 'status' => 402];
				}

				$cte->sequencia_cce = $cte->sequencia_cce + 1;
				$cte->save();
				return $arr;
			}

			return ['erro' => true, 'data' => $arr, 'status' => 402];

		} catch (\Exception $e) {
			return ['erro' => true, 'data' => $e->getMessage(), 'status' => 402];
		}
	}

}
