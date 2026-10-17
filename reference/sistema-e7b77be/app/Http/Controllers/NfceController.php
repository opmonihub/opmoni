<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Business;
use App\Models\Contigencia;
use App\Models\BusinessLocation;
use NFePHP\DA\NFe\Danfce;
use NFePHP\DA\NFe\Cupom;
use NFePHP\DA\Legacy\FilesFolders;
use NFePHP\DA\NFe\Daevento;
use App\Services\NFCeService;
use Dompdf\Dompdf;

class NfceController extends Controller
{

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

		$ncfe_service = new NFCeService([
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

			$nfe = $ncfe_service->gerarNFCe($transaction);

			// return response()->json($signed, 200);
			if(!isset($nfe['erros_xml'])){

				$signed = $ncfe_service->sign($nfe['xml']);
				if($this->getContigencia()){
					if(!is_dir(public_path('xml_nfce_contigencia'))){
						mkdir(public_path('xml_nfce_contigencia'), 0777, true);
					}
					$transaction->chave = $nfe['chave'];
					$transaction->numero_nfce = $nfe['nNf'];
					$transaction->estado = 'APROVADO';
					$transaction->contigencia = 1;
					$transaction->save();
					file_put_contents(public_path('xml_nfce_contigencia/').$nfe['chave'].'.xml', $signed);

					return response()->json('OFFL', 200);

				}else{

					$resultado = $ncfe_service->transmitir($signed, $nfe['chave'], $cnpj);

					if(isset($resultado['successo'])){
						$transaction->chave = $nfe['chave'];
						$transaction->numero_nfce = $nfe['nNf'];
						$transaction->estado = 'APROVADO';
						$transaction->recibo = $resultado['recibo'];
						$transaction->save();
						return response()->json($resultado['recibo'], 200);

					}else{
						$transaction->estado = 'REJEITADO';
						$transaction->save();
						return response()->json($resultado, 401);		
					}
				}
			}else{
				return response()->json($nfe['erros_xml'][0], 407);

			}

		}else{
			return response()->json("Esta NFCe já esta aprovada", 200);
		}

		// return response()->json($xml, 200);

	}

	public function gerar($id){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->first();

		// $business = Business::find($business_id);
		$business = Business::getConfig($business_id, $transaction);

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}

		if($transaction->numero_nfce > 0){
			return redirect('/nfce/ver/'.$transaction->id);
		}

		$erros = [];

		if($business->cnpj == '00.000.000/0000-00'){
			$msg = 'Informe a configuração do emitente';
			array_push($erros, $msg);
		}

		if(sizeof($erros) > 0){
			return view('nfe.erros')
			->with(compact('erros'));
		}

		return view('nfce.gerar')
		->with(compact('transaction', 'business'));
	}

	private function getContigencia(){

		$business_id = request()->session()->get('user.business_id');

		$active = Contigencia::
		where('business_id', $business_id)
		->where('status', 1)
		->where('documento', 'NFCe')
		->first();
		return $active != null ? 1 : 0;
	}

	public function gerarXml($id){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->first();

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}

		// $config = Business::find($business_id);
		$config = Business::getConfig($business_id, $transaction);

		if($config->certificado == null){
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Certificado não encontrado!!'
			]);
		}

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		$nfce_service = new NFCeService([
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

		$nfe = $nfce_service->gerarNFCe($transaction);
		if(!isset($nfe['erros_xml'])){
			$signed = $nfce_service->sign($nfe['xml']);

			$xml = $signed;

			return response($xml)
			->header('Content-Type', 'application/xml');
		}else{
			foreach($nfe['erros_xml'] as $e){
				echo $e . "<br>";
			}
		}
	}

	public function renderizarDanfce($id){

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

		$nfce_service = new NFCeService([
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

		$nfe = $nfce_service->gerarNFCe($transaction);
		// print_r($nfe);

		try {
			$xml = $nfe['xml'];

		// echo public_path('uploads/business_logos/' . $config->logo);

			$danfe = new Danfce($xml);
			// $id = $danfe->monta();
			$pdf = $danfe->render();
			return response($pdf)
			->header('Content-Type', 'application/pdf');
		} catch (InvalidArgumentException $e) {
			echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
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
			$logo = 'data://text/plain;base64,'. base64_encode(file_get_contents(
				public_path('uploads/business_logos/' . $business->logo)));
		}

		if($transaction->contigencia){
			if(file_exists(public_path('xml_nfce_contigencia/'.$transaction->chave.'.xml'))){
				$xml = file_get_contents(public_path('xml_nfce_contigencia/'.$transaction->chave.'.xml'));

				$danfe = new Danfce($xml);
				// $id = $danfe->monta($logo);
				$pdf = $danfe->render($logo);
				return response($pdf)
				->header('Content-Type', 'application/pdf');
			}else{
				return redirect()->back()
				->with('status', [
					'success' => 0,
					'msg' => 'Arquivo não encontrado!!'
				]);
			}
		}else{
			try {
				if(file_exists(public_path('xml_nfce/'.$cnpj.'/'.$transaction->chave.'.xml'))){
					$xml = file_get_contents(public_path('xml_nfce/'.$cnpj.'/'.$transaction->chave.'.xml'));

					$danfe = new Danfce($xml);
				// $id = $danfe->monta($logo);
					$pdf = $danfe->render($logo);
					return response($pdf)
					->header('Content-Type', 'application/pdf');
				}else{
					return redirect()->back()
					->with('status', [
						'success' => 0,
						'msg' => 'Arquivo não encontrado!!'
					]);
				}


			} catch (InvalidArgumentException $e) {
				echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
			}  
		}  

	}

	public function imprimirNaoFiscal($id){

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
			$logo = public_path('uploads/business_logos/' . $business->logo);
		}

		try {

			// $danfe = new Cupom($transaction);
			// $id = $danfe->monta($logo);
			// $pdf = $danfe->render();
			// return response($pdf)
			// ->header('Content-Type', 'application/pdf');

			$cupom = new Cupom($transaction, $logo, $business);
			$cupom->monta();
			$pdf = $cupom->render();

			return response($pdf)
			->header('Content-Type', 'application/pdf');


		} catch (InvalidArgumentException $e) {
			echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
		}  

	}

	public function ver($id){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $id)
		->first();

		// $business = Business::find($business_id);
		$business = Business::getConfig($business_id, $transaction);

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}

		if($transaction->numero_nfce == 0){
			return redirect('/nfce/gerar/'.$transaction->id);
		}

		return view('nfce.ver')
		->with(compact('transaction', 'business'));
	}

	public function baixarXml($id){

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

		if(file_exists(public_path('xml_nfce/'.$cnpj.'/'.$transaction->chave.'.xml'))){
			return response()->download(public_path('xml_nfce/'.$cnpj.'/'.$transaction->chave.'.xml'));
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

		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);

		if(!$transaction){
			abort(403, 'Unauthorized action.');
		}

		if(file_exists(public_path('xml_nfce_cancelada/'.$cnpj.'/'.$transaction->chave.'.xml'))){
			return response()->download(public_path('xml_nfce_cancelada/'.$cnpj.'/'.$transaction->chave.'.xml'));
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	}

	public function cancelar(Request $request){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $request->id)
		->first();

		// $config = Business::find($business_id);
		$config = Business::getConfig($business_id, $transaction);

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		$nfce_service = new NFCeService([
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


		$nfe = $nfce_service->cancelar($transaction, $request->justificativa, $cnpj);
		if(!isset($nfe['erro'])){

			$transaction->estado = 'CANCELADO';
			$transaction->save();
			return response()->json($nfe, 200);


		}else{
			return response()->json($nfe, $nfe['status']);
		}
	}

	public function lista(){

		$business_id = request()->session()->get('user.business_id');
		$notasAprovadas = [];
		$notasCanceladas = [];
		$business = Business::find($business_id);
		// $business = Business::getConfig($business_id, $transaction);

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

		return view('nfce.lista')
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
		->where('numero_nfce', '>', 0)
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
		->where('numero_nfce', '>', 0)
		->where('estado', 'CANCELADO')
		->orderBy('id', 'desc');

		if($select_location_id){
			$notasCanceladas->where('location_id', $select_location_id);
		}
		$notasCanceladas = $notasCanceladas->get();

		$msg = [];

		if($select_location_id){
			$business = BusinessLocation::findOrFail($select_location_id);
		}else{
			$business = Business::findOrFail($business_id);
		}
		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);

		if(sizeof($notasAprovadas) > 0){

			try{
				$zip_file = public_path('xml_nfce/'.$cnpj.'/'.'xml.zip');

				$zip = new \ZipArchive();
				$zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

				foreach($notasAprovadas as $n){

					if(file_exists(public_path('xml_nfce/'.$cnpj.'/'.$n->chave.'.xml'))){
						$zip->addFile(public_path('xml_nfce/'.$cnpj.'/'.$n->chave.'.xml'), $n->chave . '.xml');
					}

				}

				$this->print($data_inicio, $data_final, $notasAprovadas);
				$zip->addFile(public_path("print_xml/")."nfce_$cnpj.pdf", "nfce_$cnpj.pdf");
				$zip->close();
			}catch(\Exception $e){
				array_push($msg, "Erro ao gerar arquivo de XML!!");
			}

		}

		if(sizeof($notasCanceladas) > 0){

			try{
				$zip_file = public_path('xml_nfce_cancelada/'.$cnpj.'/'.'xml_cancelado.zip');
				$zip = new \ZipArchive();
				$zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

				foreach($notasCanceladas as $n){

					if(file_exists(public_path('xml_nfce_cancelada/'.$cnpj.'/'.$n->chave.'.xml'))){
						$zip->addFile(public_path('xml_nfce_cancelada/'.$cnpj.'/'.$n->chave.'.xml'), $n->chave . '.xml');
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

		return view('nfce.lista')
		->with(compact('notasCanceladas', 'notasAprovadas', 'business', 'data_inicio', 'data_final', 'msg'))
		->with('bl_attributes' , $bl_attributes)
		->with('default_location' , $default_location)
		->with('select_location_id' , $select_location_id)
		->with('business_locations' , $business_locations);
	}

	private function print($data_inicio, $data_final, $notasAprovadas){

		$business_id = request()->session()->get('user.business_id');
		$business = Business::findOrFail($business_id);
		if(!is_dir(public_path('print_xml'))){
			mkdir(public_path('print_xml'), 0777, true);
		}
		$title = 'Relatório NFCe ' . \Carbon\Carbon::parse($data_inicio)->format('d/m/Y') . ' - ' .
		\Carbon\Carbon::parse($data_final)->format('d/m/Y');
		$p = view('nfce.print', compact('notasAprovadas', 'data_inicio', 'data_final', 'business', 'title'));
		$domPdf = new Dompdf(["enable_remote" => true]);
		$domPdf->loadHtml($p);

		$pdf = ob_get_clean();

		$domPdf->setPaper("A4", "landscape");
		$domPdf->render();
		// $domPdf->stream("Somatório de vendas.pdf", array("Attachment" => false));
		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);
		$output = $domPdf->output();
		file_put_contents(public_path("print_xml/")."nfce_$cnpj.pdf", $output);
	}

	public function downloadPrint(){
		$business_id = request()->session()->get('user.business_id');
		$business = Business::findOrFail($business_id);
		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);
		if(file_exists(public_path("print_xml/")."nfce_$cnpj.pdf")){
			return response()->download(public_path("print_xml/")."nfce_$cnpj.pdf");
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
		
		if(file_exists(public_path('xml_nfce/'.$cnpj.'/'.'xml.zip'))){
			return response()->download(public_path('xml_nfce/'.$cnpj.'/'.'xml.zip'));
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
		if(file_exists(public_path('xml_nfce_cancelada/'.$cnpj.'/'.'xml_cancelado.zip'))){
			return response()->download(public_path('xml_nfce_cancelada/'.$cnpj.'/'.'xml_cancelado.zip'));
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	}

	public function consultar(Request $request){

		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $request->id)
		->first();

		$config = Business::find($business_id);

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		$nfce_service = new NFCeService([
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
			$res = $nfce_service->consultar($transaction);
			return response()->json($res, 200);
		}catch(\Exception $e){
			return response()->json($e->getMessage(), 401);

		}
	}

	public function consultaStatusSefaz(){
		$busines_id = request()->session()->get('user.business_id');

		$business = Business::findOrFail($busines_id);

		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);

		$nfe_service = new NFCeService([
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

	public function transmitirContigencia(Request $request){
		$business_id = request()->session()->get('user.business_id');
		$transaction = Transaction::where('business_id', $business_id)
		->where('id', $request->id)
		->first();
		if(file_exists(public_path('xml_nfce_contigencia/'.$transaction->chave.'.xml'))){
			$xml = file_get_contents(public_path('xml_nfce_contigencia/'.$transaction->chave.'.xml'));

			$config = Business::getConfig($business_id, $transaction);

			$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

			$ncfe_service = new NFCeService([
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

			$resultado = $ncfe_service->transmitir($xml, $transaction->chave, $cnpj);

			if(isset($resultado['successo'])){
				$transaction->estado = 'APROVADO';
				$transaction->recibo = $resultado['recibo'];
				$transaction->reenvio_contigencia = 1;
				$transaction->save();
				return response()->json($resultado['recibo'], 200);

			}else{
				$transaction->estado = 'REJEITADO';
				$transaction->save();
				return response()->json($resultado, 401);		
			}
		}else{
			return response()->json("arquivo não existe", 402);
		}
	}

	public function transmitirContigenciaLote(Request $request){
		$business_id = request()->session()->get('user.business_id');
		$selecionados = $request->selecionados;
		$transactions = [];
		foreach($selecionados as $s){

			$transaction = Transaction::where('business_id', $business_id)
			->where('id', $s)
			->first();

			array_push($transactions, $transaction);
		}

		$files = [];
		if(file_exists(public_path('xml_nfce_contigencia/'.$transaction->chave.'.xml'))){
			$xml = file_get_contents(public_path('xml_nfce_contigencia/'.$transaction->chave.'.xml'));
			array_push($files, $xml);
		}else{
			return response()->json("arquivo(s) não existe", 402);
		}

		$config = Business::getConfig($business_id, $transactions[0]);

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		$ncfe_service = new NFCeService([
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

		$resultado = $ncfe_service->transmitirLote($files, $transaction->chave, $cnpj);

		if(isset($resultado['successo'])){
			$transaction->estado = 'APROVADO';
			$transaction->recibo = $resultado['recibo'];
			$transaction->reenvio_contigencia = 1;
			$transaction->save();
			return response()->json($resultado['recibo'], 200);

		}else{
			$transaction->estado = 'REJEITADO';
			$transaction->save();
			return response()->json($resultado, 401);		
		}
		
	}

}
