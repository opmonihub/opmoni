<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Business;
use NFePHP\DA\NFe\Daevento;
use NFePHP\DA\NFe\Danfe;
use App\Models\NaturezaOperacao;
use App\Services\NFeEntradaService;

class NfeEntradaController extends Controller
{
	public function novo($id){
		$business_id = request()->session()->get('user.business_id');
		$business = Business::find($business_id);

		$purchase = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->where('type', 'purchase')
		->first();

		if($purchase == null){
			return redirect('/purchases')
			->with('status', [
				'success' => 0,
				'msg' => 'Não autorizado!!'
			]);
		}

		if($purchase->numero_nfe > 0){
			return redirect('/nfeEntrada/ver/'.$id)
			->with('status', [
				'success' => 0,
				'msg' => 'Já esta emitida NFe de entrada para esta compra!!'
			]);
		}

		
		return view('nfe_entrada.novo')
		->with('business', $business)
		->with('naturezas', $this->prepareNaturezas())
		->with('tiposPagamento', $this->tiposPagamento())
		->with('purchase', $purchase);
	}

	private function prepareNaturezas(){
		$naturezas = NaturezaOperacao::all();
		$temp = [];
		foreach($naturezas as $n){
			$temp[$n->id] = $n->natureza . " ($n->cfop_entrada_estadual/$n->cfop_entrada_inter_estadual)";
		}

		return $temp;
	}

	public static function tiposPagamento(){
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

	public function gerarXml(Request $request){

		$natureza = NaturezaOperacao::find($request->natureza);
		if($natureza == null){
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Informe a natureza de operação!!'
			]);
		}
		$tipoPagamento = $request->tipo_pagamento;
		if($tipoPagamento == null){
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Informe o tipo de pagamento!!'
			]);
		}

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $request->purchase_id)
		->first();

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}

		$config = Business::find($business_id);

		$cnpj = str_replace(".", "", $config->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);

		$nfe_entrada_service = new NFeEntradaService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$config->ambiente,
			"razaosocial" => $config->razao_social,
			"siglaUF" => $config->cidade->uf,
			"cnpj" => $cnpj,
			"schemes" => "PL_009_V4",
			"versao" => "4.00",
			"tokenIBPT" => "AAAAAAA",
			"CSC" => $config->csc,
			"CSCid" => $config->csc_id
		]);

		
		$nfe = $nfe_entrada_service->gerarNFe($transaction, $natureza, $tipoPagamento);
		$xml = $nfe['xml'];

		return response($xml)
		->header('Content-Type', 'application/xml');

	}

	public function renderizarDanfe(Request $request){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $request->purchase_id)
		->first();

		$natureza = NaturezaOperacao::find($request->natureza);
		if($natureza == null){
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Informe a natureza de operação!!'
			]);
		}

		$tipoPagamento = $request->tipo_pagamento;
		if($tipoPagamento == null){
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Informe o tipo de pagamento!!'
			]);
		}

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}

		$config = Business::find($business_id);

		$cnpj = str_replace(".", "", $config->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);

		$nfe_entrada_service = new NFeEntradaService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$config->ambiente,
			"razaosocial" => $config->razao_social,
			"siglaUF" => $config->cidade->uf,
			"cnpj" => $cnpj,
			"schemes" => "PL_009_V4",
			"versao" => "4.00",
			"tokenIBPT" => "AAAAAAA",
			"CSC" => $config->csc,
			"CSCid" => $config->csc_id
		]);

		$nfe = $nfe_entrada_service->gerarNFe($transaction, $natureza, $tipoPagamento);

		$xml = $nfe['xml'];

		try {
			$danfe = new Danfe($xml);
			// $id = $danfe->monta();
			$pdf = $danfe->render();

			return response($pdf)
			->header('Content-Type', 'application/pdf');
		} catch (InvalidArgumentException $e) {
			echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
		}  
		
	}

	public function imprimir($id){
		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->first();

		$business = Business::find($business_id);
		$cnpj = str_replace(".", "", $business->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}

		$logo = '';
		if($business->logo){
			$logo = 'data://text/plain;base64,'. base64_encode(file_get_contents(
				public_path('uploads/business_logos/' . $business->logo)));
		}

		$xml = file_get_contents(public_path('xml_nfe_entrada/'.$cnpj.'/'.$transaction->chave.'.xml'));

		try {
			$danfe = new Danfe($xml);
			// $id = $danfe->monta($logo);
			$pdf = $danfe->render($logo);
			return response($pdf)
			->header('Content-Type', 'application/pdf');
		} catch (InvalidArgumentException $e) {
			echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
		}  

	}

	public function transmitir(Request $request){

		if (!auth()->user()->can('user.create')) {
			return response()->json('erro', 401);
		}

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $request->purchase_id)
		->first();

		if(!$transaction){
			return response()->json('erro', 403);
		}

		$config = Business::find($business_id);

		$cnpj = str_replace(".", "", $config->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);

		$nfe_service_entrada = new NFeEntradaService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$config->ambiente,
			"razaosocial" => $config->razao_social,
			"siglaUF" => $config->cidade->uf,
			"cnpj" => $cnpj,
			"schemes" => "PL_009_V4",
			"versao" => "4.00",
			"tokenIBPT" => "AAAAAAA",
			"CSC" => $config->csc,
			"CSCid" => $config->csc_id
		]);

		if($transaction->estado == 'REJEITADO' || $transaction->estado == 'NOVO'){
			header('Content-type: text/html; charset=UTF-8');

			$natureza = NaturezaOperacao::find($request->natureza);
			$tipoPagamento = $request->tipo_pagamento;

			$nfe = $nfe_service_entrada->gerarNFe($transaction, $natureza, $tipoPagamento);

			$signed = $nfe_service_entrada->sign($nfe['xml']);

			$resultado = $nfe_service_entrada->transmitir($signed, $nfe['chave'], $cnpj);

			if(!isset($resultado['erro'])){
				$transaction->chave = $nfe['chave'];
				$transaction->numero_nfe= $nfe['nNf'];
				$transaction->estado = 'APROVADO';
				$transaction->natureza_id = $natureza->id;
				$transaction->save();
				return response()->json($resultado, 200);

			}else{
				$transaction->estado = 'REJEITADO';
				$transaction->save();
				return response()->json($resultado['protocolo'], $resultado['status']);
			}

		}else{
			return response()->json("Esta NFe já esta aprovada", 200);
		}

		return response()->json($xml, 200);

	}

	public function ver($id){
		$business_id = request()->session()->get('user.business_id');
		$business = Business::find($business_id);

		$purchase = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->where('type', 'purchase')
		->first();


		if($purchase == null){
			return redirect('/purchases')
			->with('status', [
				'success' => 0,
				'msg' => 'Não autorizado!!'
			]);
		}

		if($purchase->numero_nfe == 0){
			return redirect('/purchases')
			->with('status', [
				'success' => 0,
				'msg' => 'Nada encontrado!!'
			]);
		}

		return view('nfe_entrada.ver')
		->with('business', $business)
		->with('transaction', $purchase);
	}

	public function baixarXml($id){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->first();

		$business = Business::find($business_id);
		$cnpj = str_replace(".", "", $business->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}
		if(file_exists(public_path('xml_nfe_entrada/'.$cnpj.'/'.$transaction->chave.'.xml'))){
			return response()->download(public_path('xml_nfe_entrada/'.$cnpj.'/'.$transaction->chave.'.xml'));
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	}

	public function cancelar(Request $request){

		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $request->id)
		->first();

		$config = Business::find($business_id);
		$cnpj = str_replace(".", "", $config->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);

		$nfe_service_entrada = new NFeEntradaService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$config->ambiente,
			"razaosocial" => $config->razao_social,
			"siglaUF" => $config->cidade->uf,
			"cnpj" => $cnpj,
			"schemes" => "PL_009_V4",
			"versao" => "4.00",
			"tokenIBPT" => "AAAAAAA",
			"CSC" => $config->csc,
			"CSCid" => $config->csc_id
		]);

		$nfe = $nfe_service_entrada->cancelar($transaction, $request->justificativa, $cnpj);
		if(!isset($nfe['erro'])){

			$transaction->estado = 'CANCELADO';
			$transaction->save();
			return response()->json($nfe, 200);

		}else{
			return response()->json($nfe, $nfe['status']);
		}
	}

	public function imprimirCancelamento($id){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->first();

		$business = Business::find($business_id);
		$cnpj = str_replace(".", "", $business->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}

		$logo = '';
		if($business->logo){
			$logo = 'data://text/plain;base64,'. base64_encode(file_get_contents(
				public_path('uploads/business_logos/' . $business->logo)));
		}

		if(file_exists(public_path('xml_nfe_entrada_cancelada/'.$cnpj.'/'.$transaction->chave.'.xml'))){
			$xml = file_get_contents(public_path('xml_nfe_entrada_cancelada/'.$cnpj.'/'.$transaction->chave.'.xml'));

			try {
				$dadosEmitente = $this->getEmitente($business);

				$daevento = new Daevento($xml, $dadosEmitente);
				$daevento->debugMode(true);
				$pdf = $daevento->render($logo);
				return response($pdf)
				->header('Content-Type', 'application/pdf');
			} catch (InvalidArgumentException $e) {
				echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
			}  
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}

	}

	private function getEmitente($config){

		return [
			'razao' => $config->razao_social,
			'logradouro' => $config->rua,
			'numero' => $config->numero,
			'complemento' => '',
			'bairro' => $config->bairro,
			'CEP' => $config->cep,
			'municipio' => $config->cidade->nome,
			'UF' => $config->cidade->uf,
			'telefone' => '',
			'email' => ''
		];
	}


}
