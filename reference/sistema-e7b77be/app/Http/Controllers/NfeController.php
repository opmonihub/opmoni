<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Business;
use App\Models\Contigencia;
use App\Models\BusinessLocation;
use Dompdf\Dompdf;
use App\Models\City;
use NFePHP\DA\NFe\Danfe;
use NFePHP\DA\Legacy\FilesFolders;
use NFePHP\DA\NFe\Daevento;
use App\Services\NFeService;
use Mail;
use App\Utils\TransactionUtil;

class NfeController extends Controller
{

	protected $transactionUtil;


	public function __construct(TransactionUtil $transactionUtil)
	{
		$this->transactionUtil = $transactionUtil;
	}

	private function getContigencia(){
        $business_id = request()->session()->get('user.business_id');

        $active = Contigencia::
        where('business_id', $business_id)
        ->where('status', 1)
        ->where('documento', 'NFe')
        ->first();
        return $active;
    }

	public function novo($id){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->first();
		// $business = Business::find($business_id);
		$business = Business::getConfig($business_id, $transaction);

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}

		if($transaction->numero_nfe > 0){
			return redirect('/nfe/ver/'.$transaction->id);
		}

		$erros = [];
		if($transaction->contact->cpf_cnpj == null){
			$msg = 'Não é possivel emitir NFe para cliente sem CNPJ ou CPF';
			array_push($erros, $msg);
		}

		if($business->cnpj == '00.000.000/0000-00'){
			$msg = 'Informe a configuração do emitente';
			array_push($erros, $msg);
		}

		if(sizeof($erros) > 0){
			return view('nfe.erros')
			->with(compact('erros'));
		}

		$payment_types = $this->transactionUtil->payment_types();
		$methods = array_unique($transaction->payment_lines->pluck('method')->toArray());
		$count = count($methods);
		$payment_method = '';
		if ($count == 1) {
			$payment_method = $payment_types[$methods[0]];
		} elseif ($count > 1) {
			$payment_method = 'Pagamento multiplo';
		}

		$contigencia = $this->getContigencia();
		return view('nfe.novo')
		->with(compact('transaction', 'business', 'payment_method', 'contigencia'));

	}

	public function renderizarDanfe($id){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->first();

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}
		// $config = Business::find($business_id);
		$config = Business::getConfig($business_id, $transaction);


		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		$logo = '';
		if($config->logo){
			$logo = public_path('uploads/business_logos/') . $config->logo;
		}
		$nfe_service = new NFeService([
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
		], $config);

		$nfe = $nfe_service->gerarNFe($transaction);
		if(!isset($nfe['xml_erros'])){
			$xml = $nfe['xml'];

			try {
				$danfe = new Danfe($xml);
				// $id = $danfe->monta();
				$pdf = $danfe->render($logo);

				return response($pdf)
				->header('Content-Type', 'application/pdf');

			} catch (InvalidArgumentException $e) {
				echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
			}  
		}else{
			foreach($nfe['xml_erros'] as $e){
				echo $e . "<br>";
			}
		}
		
	}


	public function gerarXml($id){
		
		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->first();

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}

		$config = Business::getConfig($business_id, $transaction);

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		$nfe_service = new NFeService([
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
		], $config);

		$nfe = $nfe_service->gerarNFe($transaction);
		if(!isset($nfe['xml_erros'])){
			$xml = $nfe['xml'];

			return response($xml)
			->header('Content-Type', 'application/xml');
		}else{
			foreach($nfe['xml_erros'] as $e){
				echo $e . "<br>";
			}
		}

	}

	public function transmtir(Request $request){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $request->id)
		->first();

		if(!$transaction){
			return response()->json('erro', 403);
		}

		// $config = Business::find($business_id);
		$config = Business::getConfig($business_id, $transaction);

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		$nfe_service = new NFeService([
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
		], $config);

		if($transaction->estado == 'REJEITADO' || $transaction->estado == 'NOVO'){
			header('Content-type: text/html; charset=UTF-8');

			$nfe = $nfe_service->gerarNFe($transaction);
			if(!isset($nfe['xml_erros'])){
			// return response()->json($signed, 200);

				$signed = $nfe_service->sign($nfe['xml']);
			// return response()->json($signed, 200);
				$resultado = $nfe_service->transmitir($signed, $nfe['chave'], $cnpj);

				if(isset($resultado['successo'])){
					$transaction->chave = $nfe['chave'];
					$transaction->numero_nfe = $nfe['nNf'];
					$transaction->recibo = $resultado['recibo'];
					$transaction->estado = 'APROVADO';

					$transaction->contigencia = $this->getContigencia() != null ? 1 : 0;
					$transaction->reenvio_contigencia = 0;

					$transaction->save();
					return response()->json($resultado, 200);

				}else{
					$transaction->estado = 'REJEITADO';
					$transaction->save();
					if(isset($resultado['protocolo'])){
						return response()->json($resultado['protocolo'], $resultado['status']);
					}else{
						return response()->json($resultado, 404);

					}
				}
			}else{
				return response()->json($nfe['xml_erros'][0], 407);

			}


		}else{
			return response()->json("Esta NFe já esta aprovada", 403);
		}

		return response()->json($xml, 200);

	}

	public function ver($id){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->first();

		// $business = Business::find($business_id);
		$business = Business::getConfig($business_id, $transaction);


		if($transaction->numero_nfe == 0){
			return redirect('/nfe/novo/'.$transaction->id);
		}

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}

		return view('nfe.ver')
		->with(compact('transaction', 'business'));
	}

	public function baixarXml($id){
		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->first();

		// $business = Business::find($business_id);
		$business = Business::getConfig($business_id, $transaction);

		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}
		if(file_exists(public_path('xml_nfe/'.$cnpj.'/'.$transaction->chave.'.xml'))){
			return response()->download(public_path('xml_nfe/'.$cnpj.'/'.$transaction->chave.'.xml'));
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	}

	public function baixarXmlCancelado($id){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->first();

		// $business = Business::find($business_id);
		$business = Business::getConfig($business_id, $transaction);

		$cnpj = str_replace(".", "", $business->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}
		if(file_exists(public_path('xml_nfe_cancelada/'.$cnpj.'/'.$transaction->chave.'.xml'))){
			return response()->download(public_path('xml_nfe_cancelada/'.$cnpj.'/'.$transaction->chave.'.xml'));
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	}

	public function imprimir($id){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->first();

		// $business = Business::find($business_id);
		$business = Business::getConfig($business_id, $transaction);

		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}

		$logo = '';
		if($business->logo){
			$logo = public_path('uploads/business_logos/') . $business->logo;
		}

		try {
			if(file_exists(public_path('xml_nfe/'.$cnpj.'/'.$transaction->chave.'.xml'))){
				$xml = file_get_contents(public_path('xml_nfe/'.$cnpj.'/'.$transaction->chave.'.xml'));

				$danfe = new Danfe($xml);
				// $id = $danfe->monta($logo);
				$pdf = $danfe->render($logo);

				return response($pdf)
				->header('Content-Type', 'application/pdf');
			}else{
				return redirect('/sells')
				->with('status', [
					'success' => 0,
					'msg' => 'Arquivo não encontrado!!'
				]);
			}
		} catch (InvalidArgumentException $e) {
			echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
		}  

	}

	public function imprimirCorrecao($id){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->first();

		// $business = Business::find($business_id);
		$business = Business::getConfig($business_id, $transaction);

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


		try {

			if(file_exists(public_path('xml_nfe_correcao/'.$cnpj.'/'.$transaction->chave.'.xml'))){

				$xml = file_get_contents(public_path('xml_nfe_correcao/'.$cnpj.'/'.$transaction->chave.'.xml'));

				$dadosEmitente = $this->getEmitente($business);

				$daevento = new Daevento($xml, $dadosEmitente);
				$daevento->debugMode(true);
				$pdf = $daevento->render($logo);
				
				return response($pdf)
				->header('Content-Type', 'application/pdf');
			}else{
				return redirect('/sells')
				->with('status', [
					'success' => 0,
					'msg' => 'Arquivo não encontrado!!'
				]);
			}
		} catch (InvalidArgumentException $e) {
			echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
		}  

	}

	public function imprimirCancelamento($id){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->first();

		// $business = Business::find($business_id);
		$business = Business::getConfig($business_id, $transaction);

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
		try {

			if(file_exists(public_path('xml_nfe_cancelada/'.$cnpj.'/'.$transaction->chave.'.xml'))){

				$xml = file_get_contents(public_path('xml_nfe_cancelada/'.$cnpj.'/'.$transaction->chave.'.xml'));

				$dadosEmitente = $this->getEmitente($business);

				$daevento = new Daevento($xml, $dadosEmitente);
				$daevento->debugMode(true);
				$pdf = $daevento->render($logo);
				return response($pdf)
				->header('Content-Type', 'application/pdf');

			}else{
				return redirect('/sells')
				->with('status', [
					'success' => 0,
					'msg' => 'Arquivo não encontrado!!'
				]);
			}
		} catch (InvalidArgumentException $e) {
			echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
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

	public function cancelar(Request $request){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $request->id)
		->first();

		// $config = Business::find($business_id);
		$config = Business::getConfig($business_id, $transaction);

		$cnpj = str_replace(".", "", $config->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);


		$nfe_service = new NFeService([
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
		], $config);


		$nfe = $nfe_service->cancelar($transaction, $request->justificativa, $cnpj);
		if(!isset($nfe['erro'])){

			$transaction->estado = 'CANCELADO';
			$transaction->save();
			return response()->json($nfe, 200);

		}else{
			return response()->json($nfe, $nfe['status']);
		}
	}

	public function corrigir(Request $request){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $request->id)
		->first();

		// $config = Business::find($business_id);
		$config = Business::getConfig($business_id, $transaction);

		$cnpj = str_replace(".", "", $config->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);


		$nfe_service = new NFeService([
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
		], $config);


		$nfe = $nfe_service->cartaCorrecao($transaction, $request->justificativa, $cnpj);
		if(!isset($nfe['erro'])){
			return response()->json($nfe, 200);

		}else{
			return response()->json($nfe, $nfe['status']);
		}

	}

	public function lista(){

		$business_id = request()->session()->get('user.business_id');
		$notasAprovadas = [];
		$notasCanceladas = [];

		$business_locations = BusinessLocation::forDropdown($business_id, false, true);

		$bl_attributes = $business_locations['attributes'];

		$business_locations = $business_locations['locations'];

		$default_location = null;
		if (count($business_locations) == 1) {
			foreach ($business_locations as $id => $name) {
				$default_location = BusinessLocation::findOrFail($id);
			}
		}


		$business = Business::find($business_id);
		return view('nfe.lista')
		->with(compact('notasCanceladas', 'notasAprovadas', 'business'))
		->with('bl_attributes' , $bl_attributes)
		->with('default_location' , $default_location)
		->with('select_location_id' , null)
		->with('business_locations' , $business_locations);
	}

	public function filtro(Request $request){

		$data_inicio = str_replace("/", "-", $request->data_inicio);
		$data_final = str_replace("/", "-", $request->data_final);
		$select_location_id = $request->select_location_id;

		$data_inicio_convert =  \Carbon\Carbon::parse($data_inicio)->format('Y-m-d');
		$data_final_convert =  \Carbon\Carbon::parse($data_final)->format('Y-m-d');
		$data_final_convert = date('Y-m-d', strtotime($data_final_convert. ' + 1 days'));

		$business_id = request()->session()->get('user.business_id');
		$notasAprovadas = Transaction::where('business_id', $business_id)
		->whereBetween('created_at', [
			$data_inicio_convert, 
			$data_final_convert])
		->where('numero_nfe', '>', 0)
		->where('estado', 'APROVADO')
		->orderBy('id', 'desc');

		if($select_location_id){
			$notasAprovadas->where('location_id', $select_location_id);
		}
		$notasAprovadas = $notasAprovadas->get();

		$notasCanceladas = Transaction::where('business_id', $business_id)
		->whereBetween('created_at', [
			$data_inicio_convert, 
			$data_final_convert])
		->where('numero_nfe', '>', 0)
		->where('estado', 'CANCELADO')

		->orderBy('id', 'desc');

		if($select_location_id){
			$notasCanceladas->where('location_id', $select_location_id);
		}
		$notasCanceladas = $notasCanceladas->get();

		$business = Business::find($business_id);

		if($select_location_id){
			$config = BusinessLocation::findOrFail($select_location_id);
			if($config->cnpj != '00.000.000/0000-00' && $config->cnpj != '00000000000000'){
				$business = $config;
			}
		}

		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);

		$msg = [];

		if(sizeof($notasAprovadas) > 0){
			try{
				$zip_file = public_path('xml_nfe/'.$cnpj.'/'.'xml.zip');
				$zip = new \ZipArchive();
				$zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

				foreach($notasAprovadas as $n){

					if(file_exists(public_path('xml_nfe/'.$cnpj.'/'.$n->chave.'.xml'))){
						$zip->addFile(public_path('xml_nfe/'.$cnpj.'/'.$n->chave.'.xml'), $n->chave . '.xml');
					}

				}
				$this->print($data_inicio, $data_final, $notasAprovadas);
				$zip->addFile(public_path("print_xml/")."nfe_$cnpj.pdf", "nfe_$cnpj.pdf");
				$zip->close();
			}catch(\Exception $e){
				array_push($msg, "Erro ao gerar arquivo de XML!!");
			}

		}

		if(sizeof($notasCanceladas) > 0){

			try{
				$zip_file = public_path('xml_nfe_cancelada/'.$cnpj.'/'.'xml_cancelado.zip');
				$zip = new \ZipArchive();
				$zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

				foreach($notasCanceladas as $n){

					if(file_exists(public_path('xml_nfe_cancelada/'.$cnpj.'/'.$n->chave.'.xml'))){
						$zip->addFile(public_path('xml_nfe_cancelada/'.$cnpj.'/'.$n->chave.'.xml'), $n->chave . '.xml');
					}

				}

				$zip->close();
			}catch(\Exception $e){
				array_push($msg, "Erro ao gerar arquivo de XML de Cancelamento!!");
			}

		}

		$business_locations = BusinessLocation::forDropdown($business_id, false, true);

		$bl_attributes = $business_locations['attributes'];

		$business_locations = $business_locations['locations'];
		// array_push($business_locations, "");

		$default_location = null;
		if (count($business_locations) == 1) {
			foreach ($business_locations as $id => $name) {
				$default_location = BusinessLocation::findOrFail($id);
			}
		}
		if(!$select_location_id){
			$select_location_id = $default_location->id;
		}
		return view('nfe.lista')
		->with(compact('notasCanceladas', 'notasAprovadas', 'business', 'data_inicio', 'data_final', 'msg'))
		->with('bl_attributes' , $bl_attributes)
		->with('select_location_id' , $select_location_id)
		->with('default_location' , $default_location)
		->with('business_locations' , $business_locations);
	}

	private function print($data_inicio, $data_final, $notasAprovadas){

		$business_id = request()->session()->get('user.business_id');
		$business = Business::findOrFail($business_id);
		if(!is_dir(public_path('print_xml'))){
			mkdir(public_path('print_xml'), 0777, true);
		}
		$title = 'Relatório NFe ' . \Carbon\Carbon::parse($data_inicio)->format('d/m/Y') . ' - ' .
		\Carbon\Carbon::parse($data_final)->format('d/m/Y');
		$p = view('nfe.print', compact('notasAprovadas', 'data_inicio', 'data_final', 'business', 'title'));

		$domPdf = new Dompdf(["enable_remote" => true]);
		$domPdf->loadHtml($p);

		$pdf = ob_get_clean();

		$domPdf->setPaper("A4", "landscape");
		$domPdf->render();
		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);
		$output = $domPdf->output();
		file_put_contents(public_path("print_xml/")."nfe_$cnpj.pdf", $output);
	}

	public function downloadPrint(){
		$business_id = request()->session()->get('user.business_id');
		$business = Business::findOrFail($business_id);
		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);
		if(file_exists(public_path("print_xml/")."nfe_$cnpj.pdf")){
			
			return response()->download(public_path("print_xml/")."nfe_$cnpj.pdf");
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	}

	public function baixarZipXmlAprovado($location_id){

		$business_id = request()->session()->get('user.business_id');
		$business = Business::find($business_id);
		if($location_id){
			$config = BusinessLocation::findOrFail($location_id);

			if($config->cnpj != '00.000.000/0000-00' && $config->cnpj != '00000000000000'){
				$business = $config;
			}
		}
		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);
		
		if(file_exists(public_path('xml_nfe/'.$cnpj.'/'.'xml.zip'))){
			return response()->download(public_path('xml_nfe/'.$cnpj.'/'.'xml.zip'));
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	}

	public function baixarZipXmlReprovado($location_id){
		$business_id = request()->session()->get('user.business_id');
		$business = Business::find($business_id);
		if($location_id){
			$config = BusinessLocation::findOrFail($location_id);

			if($config->cnpj != '00.000.000/0000-00' && $config->cnpj != '00000000000000'){
				$business = $config;
			}
		}
		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);
		
		if(file_exists(public_path('xml_nfe_cancelada/'.$cnpj.'/'.'xml_cancelado.zip'))){
			return response()->download(public_path('xml_nfe_cancelada/'.$cnpj.'/'.'xml_cancelado.zip'));
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	}

	public function consultaCadastro(Request $request){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $request->id)
		->first();

		$config = Business::find($business_id);

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		if(!$config->certificado){
			return response()->json('Configure o certificado para consultar', 403);
		}

		$nfe_service = new NFeService([
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
		], $config);

		$cnpj = str_replace(".", "", $request->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);
		$uf = $request->uf;

		$nfe_service->consultaCadastro($cnpj, $uf);

	}

	public function findCidade(Request $request){
		$cidade = City::
		where('nome', $request->nome)
		->first();

		return response()->json($cidade);
	}

	public function consultar(Request $request){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $request->id)
		->first();

		$config = Business::find($business_id);
		$cnpj = str_replace(".", "", $config->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);


		$nfe_service = new NFeService([
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
		], $config);

		try{
			$res = $nfe_service->consultar($transaction);
			return response()->json($res, 200);
		}catch(\Exception $e){
			return response()->json($e->getMessage(), 401);

		}
	}

	public function enviarEmail($id){
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

		$email = $transaction->contact->email;
		if(file_exists(public_path('xml_nfe/'.$cnpj.'/'.$transaction->chave.'.xml'))){
			$xml = public_path('xml_nfe/'.$cnpj.'/'.$transaction->chave.'.xml');
			$this->criarPdfParaEnvio($transaction);
			$pdf = public_path('temp/'.$cnpj.'/'.$transaction->chave.'.pdf');

			try{
				Mail::send('mail.nfe', ['transaction' => $transaction, 'saudacao' => $this->saudacao(), 
					'business' => $business], function($m) use ($transaction, $email, $xml, $pdf){

						$emailEnvio = getenv("MAIL_USERNAME");
						$nomeEmpresa = getenv("SlymSoftware");
						$m->from($emailEnvio, $nomeEmpresa);
						$m->subject('Envio de XML NFe ' . $transaction->numero_nfe);

						$emails = explode(";", $email);
						$m->attach($xml);
						$m->attach($pdf);
						$m->to($emails);
					});
				return response()->json("Email enviado", 200);
			}catch(\Exception $e){
				return response()->json($e->getMessage(), 401);
			}
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	}

	private function criarPdfParaEnvio($transaction){
		$business_id = request()->session()->get('user.business_id');

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}

		// $business = Business::find($business_id);
		$business = Business::getConfig($business_id, $transaction);

		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);


		$nfe_service = new NFeService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$business->ambiente,
			"razaosocial" => $business->razao_social,
			"siglaUF" => $business->cidade->uf,
			"cnpj" => $cnpj,
			"schemes" => "PL_009_V4",
			"versao" => "4.00",
			"tokenIBPT" => "AAAAAAA",
			"CSC" => $business->csc,
			"CSCid" => $business->csc_id
		], $business);
		$logo = '';
		if($business->logo){
			$logo = 'data://text/plain;base64,'. base64_encode(file_get_contents(
				public_path('uploads/business_logos/' . $business->logo)));
		}

		try {
			if(file_exists(public_path('xml_nfe/'.$cnpj.'/'.$transaction->chave.'.xml'))){
				$xml = file_get_contents(public_path('xml_nfe/'.$cnpj.'/'.$transaction->chave.'.xml'));

				$danfe = new Danfe($xml);
				// $id = $danfe->monta($logo);
				$pdf = $danfe->render($logo);

				if(!is_dir(public_path('temp/'.$cnpj))){
					mkdir(public_path('temp/'.$cnpj), 0777, true);
				}
				$chave = $transaction->chave;
				file_put_contents(public_path('temp/'.$cnpj.'/'.$chave.'.pdf'), $pdf);
			}else{
				return redirect('/sells')
				->with('status', [
					'success' => 0,
					'msg' => 'Arquivo não encontrado!!'
				]);
			}
		} catch (InvalidArgumentException $e) {
			return "Ocorreu um erro durante o processamento :" . $e->getMessage();
		}  
	}

	private function saudacao() {		
		date_default_timezone_set('America/Sao_Paulo');		
		$hora = date('H');		
		if( $hora >= 6 && $hora <= 12)			
			return 'Bom dia';		
		else if ( $hora > 12 && $hora <= 18  )			
			return 'Boa tarde';		
		else			
			return 'Boa noite';	
	}

	public function consultaStatusSefaz(){
		$busines_id = request()->session()->get('user.business_id');

		$business = Business::findOrFail($busines_id);

		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);

		$nfe_service = new NFeService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$business->ambiente,
			"razaosocial" => $business->razao_social,
			"siglaUF" => $business->cidade->uf,
			"cnpj" => $cnpj,
			"schemes" => "PL_009_V4",
			"versao" => "4.00",
			"tokenIBPT" => "AAAAAAA",
			"CSC" => $business->csc,
			"CSCid" => $business->csc_id
		], $business);
		$consulta = $nfe_service->consultaStatus((int)$business->ambiente, $business->cidade->uf);
		return response()->json($consulta, 200);
	}

}
