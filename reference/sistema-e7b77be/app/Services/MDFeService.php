<?php

namespace App\Services;

use NFePHP\MDFe\Make;
use NFePHP\DA\Legacy\FilesFolders;
use NFePHP\Common\Soap\SoapCurl;
use App\Models\ConfigNota;
use App\Models\Mdfe;
use App\Models\Certificado;
use NFePHP\Common\Certificate;
use NFePHP\MDFe\Common\Standardize;
use NFePHP\MDFe\Tools;
use App\Models\Business;

error_reporting(E_ALL);
ini_set('display_errors', 'On');

class MDFeService{

	private $config; 
	protected $empresa_id = null;

	public function __construct($config, $certificado){

		$this->config = json_encode($config);
		$this->tools = new Tools(json_encode($config), Certificate::readPfx($certificado->certificado,
			base64_decode($certificado->senha_certificado)));
		$soapCurl = new SoapCurl();
		$soapCurl->httpVersion('1.1');
		$this->tools->loadSoapClass($soapCurl);

	}

	public function getTools(){
		return $this->tools;
	}

	public function gerar($mdfe){
		date_default_timezone_set('America/Belem');

		$mdfex = new Make();
		$mdfex->setOnlyAscii(true);

		$business_id = request()->session()->get('user.business_id');
		$config = Business::getConfigMdfe($business_id, $mdfe);

		// $emitente = ConfigNota::
		// where('empresa_id', $this->empresa_id)
		// ->first();

		$std = new \stdClass();
		$std->cUF = $config->getcUF($config->cidade->uf);
		$std->tpAmb = (int)$config->ambiente;
		$std->tpEmit = $mdfe->tp_emit; 
		
		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		$cnpjEmitente = $cnpj;

		$doc = preg_replace('/[^0-9]/', '', $mdfe->veiculoTracao->proprietario_documento);

		$mdfeLast = $mdfe->lastMDFe($mdfe);

		if($cnpjEmitente != $doc){
			$std->tpTransp = $mdfe->tp_transp; 
		}

		$std->mod = '58';
		$std->serie = $config->numero_serie_mdfe;

		$std->nMDF = $mdfeLast+1; // ver aqui
		$std->cMDF = rand(11111111, 99999999);
		$std->cDV = '0';
		$std->modal = '1';
		$std->dhEmi = date("Y-m-d\TH:i:sP");
		$std->tpEmis = '1';
		$std->procEmi = '0';
		$std->verProc = '3.0';
		$std->UFIni = $mdfe->uf_inicio;
		$std->UFFim = $mdfe->uf_fim;
		$std->dhIniViagem = $mdfe->data_inicio_viagem . 'T06:00:48-03:00';
		// $std->indCanalVerde = '1';
		// $std->indCarregaPosterior = $mdfe->carga_posterior;
		$mdfex->tagide($std);


		foreach($mdfe->municipiosCarregamento as $m){
			$infMunCarrega = new \stdClass();
			$infMunCarrega->cMunCarrega = $m->cidade->codigo;
			$infMunCarrega->xMunCarrega = $m->cidade->nome;
			$mdfex->taginfMunCarrega($infMunCarrega);
		}

		foreach($mdfe->percurso as $p){

			$infPercurso = new \stdClass();
			$infPercurso->UFPer = $p->uf;
			$mdfex->taginfPercurso($infPercurso);
		}

		$std = new \stdClass();

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);
		$doc = str_replace(" ", "", $cnpj);

		if(strlen($doc) == 11){
			$std->CPF = $doc;
		}else{
			$std->CNPJ = $doc;
		}
		$std->IE = $config->ie;
		$std->xNome = $config->razao_social;
		$std->xFant = $config->nome_fantasia;
		$mdfex->tagemit($std);

		$std = new \stdClass();
		$std->xLgr = $config->rua;
		$std->nro = $config->numero;
		$std->xBairro = $config->bairro;
		$std->cMun = $config->cidade->codigo;
		$std->xMun = $config->cidade->nome;
		$std->CEP = \App\Helpers\FiscalHelper::cep($config->cep);
		$std->UF = $config->cidade->uf;
		$std->fone = '';
		$std->email = '';
		$mdfex->tagenderEmit($std);

		/* Grupo infANTT */
		$infANTT = new \stdClass();
		$infANTT->RNTRC = $mdfe->veiculoTracao->rntrc; // pega antt do veiculo de tracao
		$mdfex->taginfANTT($infANTT);

		foreach($mdfe->ciots as $c){
			$infCIOT = new \stdClass();
			$infCIOT->CIOT = $c->codigo;

			$doc = preg_replace('/[^0-9]/', '', $c->cpf_cnpj);

			if(strlen($doc) == 11) $infCIOT->CPF = $doc;
			else $infCIOT->CNPJ = $doc;
			
			$mdfex->taginfCIOT($infCIOT);
		}

		foreach($mdfe->valesPedagio as $v){
			$valePed = new \stdClass();
			$valePed->CNPJForn = $v->cnpj_fornecedor;
			$doc = preg_replace('/[^0-9]/', '', $v->cnpj_fornecedor_pagador);

			if(strlen($doc) == 11) $valePed->CPFPg = $doc;
			else $valePed->CNPJPg = $doc;
			
			$valePed->nCompra = $v->numero_compra;
			$valePed->vValePed = $this->format($v->valor);
			$mdfex->tagdisp($valePed);
		}

		$infContratante = new \stdClass();
		$doc = preg_replace('/[^0-9]/', '', $mdfe->cnpj_contratante);

		if(strlen($doc) == 11){
			$infContratante->CPF = $doc;
		}else{
			$infContratante->CNPJ = $doc;
		}
		$mdfex->taginfContratante($infContratante);

		/* Grupo veicTracao */
		$veicTracao = new \stdClass();
		$veicTracao->cInt = '01';
		$placa = str_replace("-", "", $mdfe->veiculoTracao->placa);
		$veicTracao->placa = strtoupper($placa);
		$veicTracao->tara = $mdfe->veiculoTracao->tara;
		$veicTracao->capKG = $mdfe->veiculoTracao->capacidade;
		$veicTracao->tpRod = $mdfe->veiculoTracao->tipo_rodado;
		$veicTracao->tpCar = $mdfe->veiculoTracao->tipo_carroceira;
		$veicTracao->UF = Business::getUF($mdfe->veiculoTracao->uf);

		$condutor = new \stdClass();
		$condutor->xNome = $mdfe->condutor_nome; 
		$cpf = str_replace(".", "", $mdfe->condutor_cpf);
		$cpf = str_replace("-", "", $cpf);
		$condutor->CPF = $cpf; 
		$veicTracao->condutor = [$condutor];

		$prop = new \stdClass();

		$doc = str_replace("-", "", $mdfe->veiculoTracao->proprietario_documento);
		$doc = str_replace(".", "", $doc);
		$doc = str_replace("/", "", $doc);

		if(strlen($doc) == 11) $prop->CPF = $doc;
		else $prop->CNPJ = $doc;
		
		$prop->RNTRC = $mdfe->veiculoTracao->rntrc;
		$prop->xNome = $mdfe->veiculoTracao->proprietario_nome;
		if(strlen($mdfe->veiculoTracao->proprietario_ie) > 3){
			$prop->IE = $mdfe->veiculoTracao->proprietario_ie;
		}
		$prop->UF = Business::getUF($mdfe->veiculoTracao->proprietario_uf);
		$prop->tpProp = $mdfe->veiculoTracao->proprietario_tp;

		if($cnpjEmitente != $doc){
			$veicTracao->prop = $prop;
		}

		$mdfex->tagveicTracao($veicTracao);

		/* fim veicTracao */

		/* Grupo veicReboque */
		if($mdfe->veiculoReboque1){
			$veicReboque = new \stdClass();
			$veicReboque->cInt = '02';
			$placa = str_replace("-", "", $mdfe->veiculoReboque1->placa);

			$veicReboque->placa = strtoupper($placa);
			$veicReboque->tara = $mdfe->veiculoReboque1->tara;
			$veicReboque->capKG = $mdfe->veiculoReboque1->capacidade;
			$veicReboque->tpCar = $mdfe->veiculoReboque1->tipo_carroceira;
			$veicReboque->UF = Business::getUF($mdfe->veiculoReboque1->uf);

			$prop = new \stdClass();
			$doc = str_replace("-", "", $mdfe->veiculoReboque1->proprietario_documento);
			$doc = str_replace(".", "", $doc);
			$doc = str_replace("/", "", $doc);
			if(strlen($doc) == 11) $prop->CPF = $doc;
			else $prop->CNPJ = $doc;

			$prop->RNTRC = $mdfe->veiculoReboque1->rntrc;
			$prop->xNome = $mdfe->veiculoReboque1->proprietario_nome;
			$prop->IE = $mdfe->veiculoReboque1->proprietario_ie;
			$prop->UF = Business::getUF($mdfe->veiculoReboque1->proprietario_uf);
			$prop->tpProp = $mdfe->veiculoReboque1->proprietario_tp;
			$veicReboque->prop = $prop;
			$mdfex->tagveicReboque($veicReboque);
		}

		if($mdfe->veiculoReboque2){
			$veicReboque = new \stdClass();
			$veicReboque->cInt = '02';
			$placa = str_replace("-", "", $mdfe->veiculoReboque2->placa);

			$veicReboque->placa = strtoupper($placa);
			$veicReboque->tara = $mdfe->veiculoReboque2->tara;
			$veicReboque->capKG = $mdfe->veiculoReboque2->capacidade;
			$veicReboque->tpCar = $mdfe->veiculoReboque2->tipo_carroceira;
			$veicReboque->UF = Business::getUF($mdfe->veiculoReboque2->uf);

			$prop = new \stdClass();
			$doc = str_replace("-", "", $mdfe->veiculoReboque2->proprietario_documento);
			$doc = str_replace(".", "", $doc);
			$doc = str_replace("/", "", $doc);
			if(strlen($doc) == 11) $prop->CPF = $doc;
			else $prop->CNPJ = $doc;

			$prop->RNTRC = $mdfe->veiculoReboque2->rntrc;
			$prop->xNome = $mdfe->veiculoReboque2->proprietario_nome;
			$prop->IE = $mdfe->veiculoReboque2->proprietario_ie;
			$prop->UF = Business::getUF($mdfe->veiculoReboque2->proprietario_uf);
			$prop->tpProp = $mdfe->veiculoReboque2->proprietario_tp;
			$veicReboque->prop = $prop;
			$mdfex->tagveicReboque($veicReboque);
		}

		if($mdfe->veiculoReboque3){
			$veicReboque = new \stdClass();
			$veicReboque->cInt = '02';
			$placa = str_replace("-", "", $mdfe->veiculoReboque3->placa);

			$veicReboque->placa = strtoupper($placa);
			$veicReboque->tara = $mdfe->veiculoReboque3->tara;
			$veicReboque->capKG = $mdfe->veiculoReboque3->capacidade;
			$veicReboque->tpCar = $mdfe->veiculoReboque3->tipo_carroceira;
			$veicReboque->UF = Business::getUF($mdfe->veiculoReboque3->uf);

			$prop = new \stdClass();
			$doc = str_replace("-", "", $mdfe->veiculoReboque3->proprietario_documento);
			$doc = str_replace(".", "", $doc);
			$doc = str_replace("/", "", $doc);
			if(strlen($doc) == 11) $prop->CPF = $doc;
			else $prop->CNPJ = $doc;

			$prop->RNTRC = $mdfe->veiculoReboque3->rntrc;
			$prop->xNome = $mdfe->veiculoReboque3->proprietario_nome;
			$prop->IE = $mdfe->veiculoReboque3->proprietario_ie;
			$prop->UF = Business::getUF($mdfe->veiculoReboque3->proprietario_uf);
			$prop->tpProp = $mdfe->veiculoReboque3->proprietario_tp;
			$veicReboque->prop = $prop;
			$mdfex->tagveicReboque($veicReboque);
		}

		$lacRodo = new \stdClass();
		$lacRodo->nLacre = $mdfe->lac_rodo;//ver no banco
		$mdfex->taglacRodo($lacRodo);


		/*
		 * Grupo infDoc ( Documentos fiscais )
		 */
		$cont = 0;
		$contNFe = 0; 
		$contCTe = 0; 

		$infos = $this->unirDescarregamentoCidade($mdfe->infoDescarga);
		
		foreach($infos as $key => $info) {
			$infMunDescarga = new \stdClass();
			$infMunDescarga->cMunDescarga = $info['codigo_cidade'];
			$infMunDescarga->xMunDescarga = $info['nome_cidade'];
			$infMunDescarga->nItem = $key;
			$mdfex->taginfMunDescarga($infMunDescarga);

			/* infCTe */
			// $std = new \stdClass();
			// $std->chCTe = $info->cte->chave;
			// $std->SegCodBarra = '';
			// $std->indReentrega = '1';
			// $std->nItem = $cont;

			$chavesNfe = isset($info['chave_nfe']) ? explode(";", $info['chave_nfe']) : [];
			$chavesCte = isset($info['chave_cte']) ? explode(";", $info['chave_cte']) : [];


			if(sizeof($chavesNfe) > 1 || sizeof($chavesCte) > 1){
				foreach($chavesNfe as $ch){
					if($ch){

						$std = new \stdClass();
						$std->chNFe = $ch;
						$std->SegCodBarra = '';
						$std->indReentrega = '1';
						$std->nItem = $cont;
						$contNFe++;

						$mdfex->taginfNFe($std);
					}
				}

				foreach($chavesCte as $ch){
					if($ch){
						$std = new \stdClass();
						$std->chCTe = $ch;
						$std->SegCodBarra = '';
						$std->indReentrega = '1';
						$std->nItem = $cont;
						$contCTe++;
						$mdfex->taginfCTe($std);
					}
				}

			}else{
				
				if($info['chave_nfe'] != ""){
					$std = new \stdClass();
					$std->chNFe = $info['chave_nfe'];
					$std->SegCodBarra = '';
					$std->indReentrega = '1';
					$std->nItem = $cont;
					$contNFe++;

					$mdfex->taginfNFe($std);

				}else{
					/* infCTe */
					$std = new \stdClass();
					$std->chCTe = $info['chave_cte'];
					$std->SegCodBarra = '';
					$std->indReentrega = '1';
					$std->nItem = $cont;
					$contCTe++;
					$mdfex->taginfCTe($std);

				}
			}

			/* Informações das Unidades de Transporte (Carreta/Reboque/Vagão) */
			$stdinfUnidTransp = new \stdClass();
			$stdinfUnidTransp->tpUnidTransp = $info['tp_unid_transp'];

			$stdinfUnidTransp->idUnidTransp = strtoupper($info['id_unid_transp']);

			/* Lacres das Unidades de Transporte */

			$lacres = [];
			$lacresTemp = $info['lacresTransp'];
			array_push($lacres, $lacresTemp);

			
			$stdlacUnidTransp = new \stdClass();
			$stdlacUnidTransp->nLacre = $lacres;

			$stdinfUnidTransp->lacUnidTransp = $stdlacUnidTransp;

			/* Informações das Unidades de Carga (Containeres/ULD/Outros) */
			$stdinfUnidCarga = new \stdClass();
			$stdinfUnidCarga->tpUnidCarga = '1';

			$unidades = explode(";", $info['id_unidade_carga']);

			if(sizeof($unidades) > 1){
				$temp = [];
				foreach($unidades as $u){
					array_push($temp, $u);
				}
				$stdinfUnidCarga->idUnidCarga = $temp;

			}else{
				$stdinfUnidCarga->idUnidCarga = $info['id_unidade_carga'];
			}


			/* Lacres das Unidades de Carga */
			$lacres = [];
			$lacres = $info['lacresUnidCarga'];


			$stdlacUnidCarga = new \stdClass();
			$stdlacUnidCarga->nLacre = $lacres;


			$stdinfUnidCarga->lacUnidCarga = $stdlacUnidCarga;
			$stdinfUnidCarga->qtdRat = $info['quantidade_rateio_carga'];

			$stdinfUnidTransp->infUnidCarga = [$stdinfUnidCarga];
			$stdinfUnidTransp->qtdRat = $info['quantidade_rateio'];

			$std->infUnidTransp = [$stdinfUnidTransp];


			$cont++;

		}

		

		/* Grupo do Seguro */
		if($mdfe->seguradora_cnpj != null){
			$std = new \stdClass();
			$std->respSeg = '1';

			$cnpj = $mdfe->seguradora_cnpj;
			$cnpj = str_replace("/", "", $cnpj);
			$cnpj = str_replace(".", "", $cnpj);
			$cnpj = str_replace(" ", "", $cnpj);
			$cnpj = str_replace("-", "", $cnpj);
			/* Informações da seguradora */
			$stdinfSeg = new \stdClass();
			$stdinfSeg->xSeg = $mdfe->seguradora_nome;
			$stdinfSeg->CNPJ = $cnpj;

			$std->infSeg = $stdinfSeg;
			$std->nApol = $mdfe->numero_apolice;
			$std->nAver = [$mdfe->numero_averbacao];
			$mdfex->tagseg($std);
			/* fim grupo Seguro */
			// print_r($std);
			// die();
		}

		if($mdfe->produto_pred_nome != ''){
			$prodPred = new \stdClass();
			$prodPred->tpCarga = $mdfe->tp_carga;
			$prodPred->xProd = $mdfe->produto_pred_nome;

			if($mdfe->produto_pred_cod_barras != '' && $mdfe->produto_pred_cod_barras > 0){
				$prodPred->cEAN = $mdfe->produto_pred_cod_barras;
			}else{
				$prodPred->cEAN = null;
			}
			if($mdfe->produto_pred_ncm != '' && $mdfe->produto_pred_ncm > 0){
				$prodPred->NCM = $mdfe->produto_pred_ncm;
			}else{
				$prodPred->NCM = null;
			}

			$localCarrega = new \stdClass();
			$localCarrega->CEP = \App\Helpers\FiscalHelper::cep($mdfe->cep_carrega);
			// $localCarrega->latitude = null;
			// $localCarrega->longitude = null;
			$localCarrega->latitude = $this->preparaCordenada($mdfe->latitude_carregamento);
			$localCarrega->longitude = $this->preparaCordenada($mdfe->longitude_carregamento);

			$localDescarrega = new \stdClass();
			$localDescarrega->CEP = \App\Helpers\FiscalHelper::cep($mdfe->cep_descarrega);
			// $localDescarrega->latitude = null;
			// $localDescarrega->longitude = null;
			$localDescarrega->latitude = $this->preparaCordenada($mdfe->latitude_descarregamento);
			$localDescarrega->longitude = $this->preparaCordenada($mdfe->longitude_descarregamento);

			$lotacao = new \stdClass();
			$lotacao->infLocalCarrega = $localCarrega;
			$lotacao->infLocalDescarrega = $localDescarrega;

			$prodPred->infLotacao = $lotacao;

			// print_r($prodPred);
			// die();
			$mdfex->tagprodPred($prodPred);
		}


		/* grupo de totais */
		$std = new \stdClass();
		$std->vCarga = $this->format($mdfe->valor_carga);
		$std->cUnid = '02';
		if($contNFe > 0){
			$std->qNFe = $contNFe;
		}
		$std->qCTe = $contCTe;
		$std->qCarga = $mdfe->quantidade_carga;
		$mdfex->tagtot($std);
		/* fim grupo de totais */

		/* Grupo infPag - Informações de Pagamento (NT 2025.001) */
		// Obrigatório para carga lotação (apenas 1 documento fiscal)
		if($mdfe->requiresPaymentInfo() || !empty($mdfe->responsavel_pag_nome)) {

			// Validação: se é carga lotação, campos são obrigatórios
			if($mdfe->requiresPaymentInfo()) {
				if(empty($mdfe->responsavel_pag_nome) ||
				   empty($mdfe->responsavel_pag_cpf_cnpj) ||
				   empty($mdfe->valor_frete) ||
				   empty($mdfe->valor_contrato)) {
					throw new \Exception('Para carga lotação, as informações de pagamento são obrigatórias (NT 2025.001). Preencha: Responsável pelo Pagamento, CPF/CNPJ, Valor do Frete e Valor do Contrato.');
				}
			}

			$infPag = new \stdClass();
			$infPag->xNome = $mdfe->responsavel_pag_nome;

			// CPF ou CNPJ do responsável
			$doc = preg_replace('/[^0-9]/', '', $mdfe->responsavel_pag_cpf_cnpj);
			if(strlen($doc) == 11) {
				$infPag->CPF = $doc;
			} else {
				$infPag->CNPJ = $doc;
			}

			// Componente do pagamento (Frete)
			// Valores válidos para tpComp: 01=Vale Pedágio, 02=Impostos, 03=Despesas, 99=Outros
			$Comp = new \stdClass();
			$Comp->tpComp = $mdfe->tipo_componente ?? '99'; // 99 = Outros (Frete)
			$Comp->vComp = $this->format($mdfe->valor_frete);
			$Comp->xComp = 'Frete';

			$infPag->Comp = [$Comp];
			$infPag->vContrato = $this->format($mdfe->valor_contrato);
			$infPag->indPag = $mdfe->indicador_pagamento ?? 0; // 0=À vista, 1=A prazo

			// Se pagamento a prazo, adicionar informações da parcela
			if($mdfe->indicador_pagamento == 1 && !empty($mdfe->numero_parcela)) {
				$infPrazo = new \stdClass();
				$infPrazo->nParcela = str_pad($mdfe->numero_parcela, 3, '0', STR_PAD_LEFT);
				$infPrazo->dVenc = date('Y-m-d', strtotime($mdfe->data_vencimento));
				$infPrazo->vParcela = $this->format($mdfe->valor_parcela);

				$infPag->infPrazo = [$infPrazo];
			}

			// Dados bancários (para transportador autônomo) - NT 2025.001
			// Pode ser: codBanco + codAgencia OU CNPJIPEF OU PIX (chave PIX)
			// IMPORTANTE: infBanc é OPCIONAL segundo a NT 2025.001
			// Só adiciona se houver pelo menos um dado bancário informado

			$temDadosBancarios = false;
			$infBanc = new \stdClass();

			// Prioridade 1: Chave PIX (campo PIX)
			if(!empty($mdfe->chave_pix)) {
				$infBanc->PIX = $mdfe->chave_pix;
				$temDadosBancarios = true;
			}
			// Prioridade 2: CNPJ IPEF (Instituição de Pagamento Eletrônico)
			else if(!empty($mdfe->cnpj_ipef)) {
				$cnpj = preg_replace('/[^0-9]/', '', $mdfe->cnpj_ipef);
				// CNPJ deve ter exatamente 14 dígitos
				if(strlen($cnpj) == 14) {
					$infBanc->CNPJIPEF = $cnpj;
					$temDadosBancarios = true;
				}
			}
			// Prioridade 3: Banco e agência tradicional
			else if(!empty($mdfe->codigo_banco) && !empty($mdfe->codigo_agencia)) {
				$infBanc->codBanco = $mdfe->codigo_banco;
				$infBanc->codAgencia = $mdfe->codigo_agencia;
				$temDadosBancarios = true;
			}

			// Só adiciona infBanc se houver dados bancários
			// Se não houver, a tag <infBanc> não será gerada no XML (opcional)
			if($temDadosBancarios) {
				$infPag->infBanc = $infBanc;
			}

			$mdfex->taginfPag($infPag);
		}
		/* fim grupo infPag */

		if($config->aut_xml != ""){
			$std = new \stdClass();
			$cnpj = str_replace(".", "", $config->aut_xml);
			$cnpj = str_replace("/", "", $cnpj);
			$cnpj = str_replace("-", "", $cnpj);
			$std->CNPJ = $cnpj;
			$mdfex->tagautXML($std);
		}

		try{
			$xml = $mdfex->getXML();
			header("Content-type: text/xml");

			return [
				'xml' => $xml,
				'numero' => $mdfeLast+1
			];
		}catch(\Exception $e){
			return [
				'erros_xml' => $mdfex->getErrors()
			];
		}
	}

	private function preparaCordenada($cordenada){
		if(strlen($cordenada) == 10) return $cordenada;

		$dif = 10 - strlen($cordenada);
		$temp = $cordenada;
		for($i=0; $i<$dif; $i++){
			$temp .= "0";
		} 
		return $temp;
	}

	private function unirDescarregamentoCidade($infos){
		$arrInit = [];

		foreach($infos as $i){
			$temp = [
				'codigo_cidade' => $i->cidade->codigo,
				'nome_cidade' => $i->cidade->nome,
				'chave_cte' => $i->cte ? $i->cte->chave : '',
				'chave_nfe' => $i->nfe ? $i->nfe->chave : '',
				'tp_unid_transp' => $i->tp_unid_transp,
				'id_unid_transp' => $i->id_unid_transp,
				'lacresTransp' => $i->lacresTransp,
				'id_unidade_carga' => $i->unidadeCarga->id_unidade_carga,
				'lacresUnidCarga' => $i->lacresUnidCarga,
				'quantidade_rateio_carga' => $i->unidadeCarga->quantidade_rateio,
				'quantidade_rateio' => $i->quantidade_rateio
			];
			array_push($arrInit, $temp);
		}


		$retorno = [];
		for($i = 0; $i < sizeof($arrInit); $i++){

			$indice = $this->verificaDuplicado($retorno, $arrInit[$i]['codigo_cidade']);
			if($indice == -1){
				array_push($retorno, $arrInit[$i]);
			}else{
				// $chavesNfe = isset($info['chave_nfe']) ? explode(";", $info['chave_nfe']) : [];
				// $chavesCte = isset($info['chave_cte']) ? explode(";", $info['chave_cte']) : [];
				if(isset($arrInit[$i]['chave_nfe'])){
					$retorno[$indice]['chave_nfe'] .= ";" . $arrInit[$i]['chave_nfe'];
				}
				if(isset($arrInit[$i]['chave_cte'])){
					$retorno[$indice]['chave_cte'] .= ";" . $arrInit[$i]['chave_cte'];
				}


				$temp = $retorno[$indice]['lacresTransp'];
				$temp2 = $arrInit[$i]['lacresTransp'];
				$lacres = [];

				foreach($temp as $t){
					array_push($lacres, is_object($t) ? $t->numero : $t);
				}

				foreach($temp2 as $t){
					array_push($lacres, is_object($t) ? $t->numero : $t);
				}
				$retorno[$indice]['lacresTransp'] = $lacres;
				$retorno[$indice]['id_unidade_carga'] .= ";" . $arrInit[$i]['id_unidade_carga'];


				$temp = $retorno[$indice]['lacresUnidCarga'];
				$temp2 = $arrInit[$i]['lacresUnidCarga'];
				$lacres = [];

				foreach($temp as $t){
					array_push($lacres, is_object($t) ? $t->numero : $t);
				}

				foreach($temp2 as $t){
					array_push($lacres, is_object($t) ? $t->numero : $t);
				}

				$retorno[$indice]['lacresUnidCarga'] = $lacres;

				$retorno[$indice]['quantidade_rateio_carga'] +=  $arrInit[$i]['quantidade_rateio_carga'];
				$retorno[$indice]['quantidade_rateio'] +=  $arrInit[$i]['quantidade_rateio'];

			}

		}

		// echo "<pre>";
		// print_r($retorno);
		// echo "</pre>";

		// die();


		return $retorno;
	}

	private function verificaDuplicado($arrInit, $codMun){
		$retorno = -1;
		for($i = 0; $i < sizeof($arrInit); $i++){
			if($arrInit[$i]['codigo_cidade'] && $arrInit[$i]['codigo_cidade'] == $codMun) $retorno = $i;
		}
		return $retorno;
	}

	public function format($number, $dec = 2){
		return number_format((float) $number, $dec, ".", "");
	}

	public function sign($xml){
		return $this->tools->signMDFe($xml);
	}

	public function transmitir($signXml, $cnpj){
		try{
			$resp = $this->tools->sefazEnviaLote([$signXml], rand(1, 10000), 1);

			$st = new Standardize();
			$std = $st->toStd($resp);

			sleep(6);

			if ($std->cStat != 100) {
				return [
					'erro' => true,
					'message' => $std->xMotivo,
					'cStat' => $std->cStat
				];
			}

			// $resp = $this->tools->sefazConsultaRecibo($std->infRec->nRec);
			// $std = $st->toStd($resp);


			if(!isset($std->protMDFe)){
				return [
					'erro' => true,
					'message' => 'Tente enviar novamente em minutos!',
					'cStat' => '999'
				];
			}

			$chave = $std->protMDFe->infProt->chMDFe;
			$cStat = $std->protMDFe->infProt->cStat;

			if($cStat == '100'){

				// Adiciona o protocolo de autorização ao XML (igual NF-e/CT-e)
				try {
					$xmlProtocolado = \NFePHP\MDFe\Complements::toAuthorize($signXml, $resp);

					if(!is_dir(public_path('xml_mdfe/'.$cnpj))){
						mkdir(public_path('xml_mdfe/'.$cnpj), 0777, true);
					}

					// Salva o XML com protocolo anexado
					file_put_contents(public_path('xml_mdfe/'.$cnpj.'/'.$chave.'.xml'), $xmlProtocolado);

				} catch(\Exception $e) {
					// Se falhar ao adicionar protocolo, salva sem protocolo (fallback)
					\Log::error('Erro ao adicionar protocolo ao XML MDF-e: ' . $e->getMessage());
					file_put_contents(public_path('xml_mdfe/'.$cnpj.'/'.$chave.'.xml'), $signXml);
				}

				return [
					'chave' => $chave,
					'protocolo' => $std->protMDFe->infProt->nProt,
					'cStat' => $cStat
				];
			}else{
				return [
					'erro' => true,
					'message' => $std->protMDFe->infProt->xMotivo,
					'cStat' => $cStat
				];
			}
			return $std->protMDFe->infProt->chMDFe;

		} catch(\Exception $e){
			return [
				'erro' => true,
				'message' => $e->getMessage(),
				'cStat' => ''
			];
		}

	}


	public function naoEncerrados(){
		try {

			$resp = $this->tools->sefazConsultaNaoEncerrados();

			$st = new Standardize();
			$std = $st->toArray($resp);

			return $std;
		} catch (Exception $e) {
			echo $e->getMessage();
		}
	}

	public function encerrar($emitente, $chave, $protocolo){
		try {
			
			$chave = $chave;
			$nProt = $protocolo;
			$cUF = Business::getcUF($emitente->cidade->uf);
			$cMun = $emitente->cidade->codigo;
			$dtEnc = date('Y-m-d'); // Opcional, caso nao seja preenchido pegara HOJE
			$resp = $this->tools->sefazEncerra($chave, $nProt, $cUF, $cMun, $dtEnc);

			$st = new Standardize();
			$std = $st->toStd($resp);

			return $std;
		} catch (Exception $e) {
			return $e->getMessage();
		}
	}

	public function consultar($chave){
		try {
			
			$chave = $chave;
			$resp = $this->tools->sefazConsultaChave($chave);

			$st = new Standardize();
			$std = $st->toStd($resp);

			return $std;
		} catch (Exception $e) {
			echo $e->getMessage();
		}
	}

	public function cancelar($chave, $protocolo, $justificativa){
		try {
			$xJust = $justificativa;
			$nProt = $protocolo;
			
			$chave = $chave;
			$resp = $this->tools->sefazCancela($chave, $xJust, $nProt);
			sleep(2);
			$st = new Standardize();
			$std = $st->toStd($resp);
			return $std;
		} catch (Exception $e) {
			echo $e->getMessage();
		}
	}


}
