<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Manifesto;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\Facades\DataTables;
use App\Utils\ModuleUtil;
use App\Models\Business;
use App\Services\DFeService;
use NFePHP\NFe\Common\Standardize;
use NFePHP\DA\NFe\Danfe;
use App\Models\City;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ItemDfe;
use App\Models\PurchaseLine;
use App\Models\BusinessLocation;
use App\Models\Unit;
use App\Models\ProductVariation;
use App\Models\Variation;
use App\Models\Transaction;
use App\Models\VariationLocationDetails;
use App\Models\ManifestoLimite;

class ManifestoController extends Controller
{	
	protected $user_id = 0;
	protected $business_id = 0;

	public function index(){
		if (!auth()->user()->can('user.view') && !auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}


		if (request()->ajax()) {
			$business_id = request()->session()->get('user.business_id');
			$user_id = request()->session()->get('user.id');
			$naturezas = Manifesto::where('business_id', $business_id)
			->select(['id', 'nome', 'location_id', 'documento', 'valor', 'data_emissao', 
				'num_prot', 'chave', 'tipo'])
			->orderBy('location_id')
			->orderBy('data_emissao', 'desc');

			return Datatables::of($naturezas)
			->addColumn(
				'action',
				'<a href="/naturezas/edit/{{$id}}" class="btn btn-xs btn-primary"><i class="glyphicon glyphicon-edit"></i> @lang("messages.edit")</a>
				&nbsp;<a href="/naturezas/delete/{{$id}}" class="btn btn-xs btn-danger delete_user_button"><i class="glyphicon glyphicon-trash"></i> @lang("messages.delete")</a>'
			)

			->editColumn('data_emissao', function ($row) {
				return \Carbon\Carbon::parse($row->data_emissao)->format('d/m/Y H:i:s');
			})

			->editColumn('tipo', function ($row) {
				return $row->estado();
			})

			->editColumn('location_id', function ($row) {
				$b = BusinessLocation::find($row->location_id);
				if($b){
					return $b->name . " [$b->location_id]";
				}else{
					return "--";
				}
			})

			->addColumn('action', function ($row) {
				$html = '';

				if($row->tipo == 1 || $row->tipo == 2){
					
					$html = '<a style="width: 100%" target="_blank" class="btn btn-xs btn-success" href="/manifesto/download/'.$row->chave.'">Completa</a>';
					$html .= '&nbsp;<a target="_blank" style="width: 100%" href="/manifesto/imprimirDanfe/'.$row->chave.'" class="btn btn-xs btn-info delete_user_button">Imprimir</a>';
				}
				else if($row->tipo == 3){
					$html = '<a class="btn btn-xs btn-danger">Desconhecida</a>';
				}

				else if($row->tipo == 4){
					$html = '<a class="btn btn-xs btn-danger">Não realizada</a>';	
				}
				else{
					$html = '<button onclick="openModal('.$row->id.')" class="btn btn-xs btn-primary">Manifestar</button>';
				}

				return $html;
			})

			->removeColumn('id')
			->rawColumns(['action'])
			->make(true);

		}
		$business_id = request()->session()->get('user.business_id');
		
		$business_locations = BusinessLocation::forDropdown($business_id, false, true);
		$bl_attributes = $business_locations['attributes'];
		$business_locations = $business_locations['locations'];

		$default_location = null;
		if (count($business_locations) == 1) {
			foreach ($business_locations as $id => $name) {
				$default_location = BusinessLocation::findOrFail($id);
			}
		}
		
		return view('manifesto.list')
		->with('bl_attributes' , $bl_attributes)
		->with('default_location' , $default_location)
		->with('business_locations' , $business_locations);
	}

	public function getByLocation($location_id){
		if (request()->ajax()) {

			$business_id = request()->session()->get('user.business_id');
			$user_id = request()->session()->get('user.id');
			$naturezas = Manifesto::where('business_id', $business_id)
			->select(['id', 'nome', 'location_id', 'documento', 'valor', 'data_emissao', 
				'num_prot', 'chave', 'tipo'])
			->where('location_id', $location_id)
			->orderBy('location_id')
			->orderBy('data_emissao', 'desc');

			return Datatables::of($naturezas)
			->addColumn(
				'action',
				'<a href="/naturezas/edit/{{$id}}" class="btn btn-xs btn-primary"><i class="glyphicon glyphicon-edit"></i> @lang("messages.edit")</a>
				&nbsp;<a href="/naturezas/delete/{{$id}}" class="btn btn-xs btn-danger delete_user_button"><i class="glyphicon glyphicon-trash"></i> @lang("messages.delete")</a>'
			)

			->editColumn('data_emissao', function ($row) {
				return \Carbon\Carbon::parse($row->data_emissao)->format('d/m/Y H:i:s');
			})

			->editColumn('tipo', function ($row) {
				return $row->estado();
			})

			->editColumn('location_id', function ($row) {
				$b = BusinessLocation::find($row->location_id);
				return $b->name . " [$b->location_id]";
			})

			->addColumn('action', function ($row) {
				$html = '';

				if($row->tipo == 1 || $row->tipo == 2){

					$html = '<a style="width: 100%" target="_blank" class="btn btn-xs btn-success" href="/manifesto/download/'.$row->chave.'">Completa</a>';
					$html .= '&nbsp;<a target="_blank" style="width: 100%" href="/manifesto/imprimirDanfe/'.$row->chave.'" class="btn btn-xs btn-info delete_user_button">Imprimir</a>';
				}
				else if($row->tipo == 3){
					$html = '<a class="btn btn-xs btn-danger">Desconhecida</a>';
				}

				else if($row->tipo == 4){
					$html = '<a class="btn btn-xs btn-danger">Não realizada</a>';	
				}
				else{
					$html = '<button onclick="openModal('.$row->id.')" class="btn btn-xs btn-primary">Manifestar</button>';
				}

				return $html;
			})

			->removeColumn('id')
			->rawColumns(['action'])
			->make(true);
		}
	}

	public function buscarNovosDocumentos(){

		if (!auth()->user()->can('user.view') && !auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}
		$business_id = request()->session()->get('user.business_id');

		$d1 = date("Y-m-d");
		$d2 = date('Y-m-d', strtotime('+1 day'));
		$maximoConsultaDia = getenv("CONSULTAS_MANIFESTO_DIA");
		$consultas = ManifestoLimite::
		whereBetween('created_at', [$d1, 
			$d2])
		->where('business_id', $business_id)
		->get();
		if(sizeof($consultas) < $maximoConsultaDia){

			$business_locations = BusinessLocation::forDropdown($business_id, false, true);
			$bl_attributes = $business_locations['attributes'];
			$business_locations = $business_locations['locations'];

			$default_location = null;
			if (count($business_locations) == 1) {
				foreach ($business_locations as $id => $name) {
					$default_location = BusinessLocation::findOrFail($id);
				}
			}
			return view('manifesto.novos_docs')
			->with('bl_attributes' , $bl_attributes)
			->with('default_location' , $default_location)
			->with('select_location_id' , null)
			->with('business_locations' , $business_locations);
		}else{
			$output = [
				'success' => 0,
				'msg' => 'Maximo de consultas diárias atingidas: ' . $maximoConsultaDia
			];
			return redirect()->back()->with('status', $output);
		}
	}

	public function getDocumentosNovosLocation(Request $request){
		try{
			$location_id = $request->location;

			$business_id = request()->session()->get('user.business_id');

			$fistLocation = BusinessLocation::where('business_id', $business_id)->first();
			if($location_id != $fistLocation->id){
				$config = BusinessLocation::where('id', $location_id)->first(); 
			} else {
				$config = Business::find($business_id);
			} 
			// $config = Business::find($business_id);

			$cnpj = str_replace(".", "", $config->cnpj);
			$cnpj = str_replace("/", "", $cnpj);
			$cnpj = str_replace("-", "", $cnpj);
			$cnpj = str_replace(" ", "", $cnpj);


			$dfe_service = new DFeService([
				"atualizacao" => date('Y-m-d h:i:s'),
				"tpAmb" => 1,
				"razaosocial" => $config->razao_social,
				"siglaUF" => $config->cidade->uf,
				"cnpj" => $cnpj,
				"schemes" => "PL_009_V4",
				"versao" => "4.00",
				"tokenIBPT" => "AAAAAAA",
				"CSC" => $config->csc,
				"CSCid" => $config->csc_id
			], $config);

			$manifesto = Manifesto::
			where('business_id', $business_id)
			->where('location_id', $location_id)
			->orderBy('nsu', 'desc')->first();

			if($manifesto == null) $nsu = 0;
			else $nsu = $manifesto->nsu;
			$docs = $dfe_service->novaConsulta($nsu, $location_id);
			$novos = [];
			foreach($docs as $d) {
				if($this->validaNaoInserido($d['chave'])){
					if($d['valor'] > 0 && $d['nome']){
						Manifesto::create($d);
						array_push($novos, $d);
					}
				}
			}

			ManifestoLimite::create(
				[
					'business_id' => $business_id
				]
			);
			return response()->json($novos, 200);

		}catch(Exception $e){
			return response()->json($e->getMessage(), 403);
		}

	}

	public function getDocumentosNovos(){
		try{
			$business_id = request()->session()->get('user.business_id');

			$config = Business::find($business_id);

			$cnpj = str_replace(".", "", $config->cnpj);
			$cnpj = str_replace("/", "", $cnpj);
			$cnpj = str_replace("-", "", $cnpj);
			$cnpj = str_replace(" ", "", $cnpj);


			$dfe_service = new DFeService([
				"atualizacao" => date('Y-m-d h:i:s'),
				"tpAmb" => 1,
				"razaosocial" => $config->razao_social,
				"siglaUF" => $config->cidade->uf,
				"cnpj" => $cnpj,
				"schemes" => "PL_009_V4",
				"versao" => "4.00",
				"tokenIBPT" => "AAAAAAA",
				"CSC" => $config->csc,
				"CSCid" => $config->csc_id
			], $config);

			$manifesto = Manifesto::
			where('business_id', $business_id)
			->orderBy('nsu', 'desc')->first();

			if($manifesto == null) $nsu = 0;
			else $nsu = $manifesto->nsu;

			$docs = $dfe_service->novaConsulta($nsu, $config->location_id);

			$novos = [];
			foreach($docs as $d) {
				if($this->validaNaoInserido($d['chave'])){
					if($d['valor'] > 0 && $d['nome']){
						Manifesto::create($d);
						array_push($novos, $d);
					}
				}
			}

			ManifestoLimite::create(
				[
					'business_id' => $business_id
				]
			);
			return response()->json($novos, 200);

		}catch(Exception $e){
			return response()->json($e->getMessage(), 403);
		}

	}

	private function validaNaoInserido($chave){
		$m = Manifesto::where('chave', $chave)
		->first();
		if($m == null) return true;
		else return false;
	}

	public function manifestar(Request $request){

		try{
			$business_id = request()->session()->get('user.business_id');
			$mTemp = Manifesto::find($request->id);

			$location_id = $mTemp->location_id;

			$fistLocation = BusinessLocation::where('business_id', $business_id)->first();
			if($location_id != $fistLocation->id && $location_id != null){
				$config = BusinessLocation::where('id', $location_id)->first(); 
			} else {
				$config = Business::find($business_id);
			} 

			// $config = Business::find($business_id);

			$cnpj = str_replace(".", "", $config->cnpj);
			$cnpj = str_replace("/", "", $cnpj);
			$cnpj = str_replace("-", "", $cnpj);
			$cnpj = str_replace(" ", "", $cnpj);


			$dfe_service = new DFeService([
				"atualizacao" => date('Y-m-d h:i:s'),
				"tpAmb" => 1,
				"razaosocial" => $config->razao_social,
				"siglaUF" => $config->cidade->uf,
				"cnpj" => $cnpj,
				"schemes" => "PL_009_V4",
				"versao" => "4.00",
				"tokenIBPT" => "AAAAAAA",
				"CSC" => $config->csc,
				"CSCid" => $config->csc_id
			], $config);

			$evento = $request->evento;

			$manifestaAnterior = $this->verificaAnterior($request->chave);

			if($evento == 1){
				$res = $dfe_service->manifesta($mTemp->chave,	 
					$manifestaAnterior != null ? ($manifestaAnterior->sequencia_evento + 1) : 1);
			}else if($evento == 2){
				$res = $dfe_service->confirmacao($mTemp->chave,	 
					$manifestaAnterior != null ? ($manifestaAnterior->sequencia_evento + 1) : 1);
			}else if($evento == 3){
				$res = $dfe_service->desconhecimento($mTemp->chave,	 
					$manifestaAnterior != null ? ($manifestaAnterior->sequencia_evento + 1) : 1, $request->justificativa);
			}else if($evento == 4){
				$res = $dfe_service->operacaoNaoRealizada($mTemp->chave,	 
					$manifestaAnterior != null ? ($manifestaAnterior->sequencia_evento + 1) : 1, $request->justificativa);
			}



			if($res['retEvento']['infEvento']['cStat'] == '135'){ 
				//suesso
				$manifesto = Manifesto::where('chave', $mTemp->chave)
				->first();
				$manifesto->tipo = $evento;
				$manifesto->save();

				$output = [
					'success' => 1,
					'msg' => 'XML ' . $request->chave . ' manifestado!'
				];

				return redirect('manifesto')->with('status', $output);
			}else{

				$manifesto = Manifesto::where('chave', $mTemp->chave)
				->first();
				$manifesto->tipo = $evento;
				$manifesto->save();

				$output = [
					'success' => 0,
					'msg' => 'Já esta manifestado a chave ' . $request->chave
				];
				return redirect('manifesto')->with('status', $output);
				
			}

		}catch(Exception $e){
			echo "erro: " . $e->getMessage();
		}
	}

	private function verificaAnterior($chave){
		return Manifesto::where('chave', $chave)->first();
	}

	public function imprimirDanfe($chave){
		try{

			$business_id = request()->session()->get('user.business_id');

			// $config = Business::find($business_id);
			$mTemp = Manifesto::where('chave', $chave)->first();

			$location_id = $mTemp->location_id;

			$fistLocation = BusinessLocation::where('business_id', $business_id)->first();
			if($location_id != $fistLocation->id && $location_id != null){
				$config = BusinessLocation::where('id', $location_id)->first(); 
			} else {
				$config = Business::find($business_id);
			} 

			$cnpj = str_replace(".", "", $config->cnpj);
			$cnpj = str_replace("/", "", $cnpj);
			$cnpj = str_replace("-", "", $cnpj);
			$cnpj = str_replace(" ", "", $cnpj);


			$dfe_service = new DFeService([
				"atualizacao" => date('Y-m-d h:i:s'),
				"tpAmb" => 1,
				"razaosocial" => $config->razao_social,
				"siglaUF" => $config->cidade->uf,
				"cnpj" => $cnpj,
				"schemes" => "PL_009_V4",
				"versao" => "4.00",
				"tokenIBPT" => "AAAAAAA",
				"CSC" => $config->csc,
				"CSCid" => $config->csc_id
			], $config);

			$response = $dfe_service->download($chave);
		// print_r($response);
			try {
				$stz = new Standardize($response);
				$std = $stz->toStd();
				if ($std->cStat != 138) {
					echo "Documento não retornado. [$std->cStat] $std->xMotivo" . ", aguarde alguns instantes e atualize a pagina!";  
					die;
				}    
				$zip = $std->loteDistDFeInt->docZip;
				$xml = gzdecode(base64_decode($zip));

				$public = getenv('SERVIDOR_WEB') ? 'public/' : '';

				try{
					$danfe = new Danfe($xml);
					// $danfe->monta();
					$pdf = $danfe->render();
					return response($pdf)
					->header('Content-Type', 'application/pdf');
				}catch (\Exception $e) {
					echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
				}  
				
			} catch (InvalidArgumentException $e) {
				echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
			}  

		}catch(Exception $e){
			echo "erro: " . $e->getMessage();
		}
	}


	// Download


	public function download($chave){

		$this->business_id = $business_id = request()->session()->get('user.business_id');
		$this->user_id = $user_id = request()->session()->get('user.id');

		$mTemp = Manifesto::where('chave', $chave)->first();;

		$location_id = $mTemp->location_id;

		$fistLocation = BusinessLocation::where('business_id', $business_id)->first();
		if($location_id != $fistLocation->id && $location_id != null){
			$config = BusinessLocation::where('id', $location_id)->first(); 
		} else {
			$config = Business::find($business_id);
		} 

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		$dfe_service = new DFeService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => 1,
			"razaosocial" => $config->razao_social,
			"siglaUF" => $config->cidade->uf,
			"cnpj" => $cnpj,
			"schemes" => "PL_009_V4",
			"versao" => "4.00",
			"tokenIBPT" => "AAAAAAA",
			"CSC" => $config->csc,
			"CSCid" => $config->csc_id
		], $config);
		// try{


		if(!file_exists(public_path('xml_dfe/'.$chave.'.xml'))){
			$response = $dfe_service->download($chave);
		// print_r($response);
			$stz = new Standardize($response);
			$std = $stz->toStd();
			if ($std->cStat != 138) {
				echo "Documento não retornado. [$std->cStat] $std->xMotivo" . ", aguarde alguns instantes e atualize a pagina!";  
				die();
			}    
			$zip = $std->loteDistDFeInt->docZip;
			$xml = gzdecode(base64_decode($zip));
			file_put_contents(public_path('xml_dfe/'.$chave.'.xml'), $xml);

		}else{
			$xml = file_get_contents(public_path('xml_dfe/'.$chave.'.xml'));
		}
		$xml = simplexml_load_string($xml);
		if(!$xml) {
			echo "Erro ao ler XML";
		}else{

			if(!isset($xml->NFe->infNFe->emit)){
				echo "Isso não é uma NFe";
				die;
			}

			$cidade = City::getCidadeCod($xml->NFe->infNFe->emit->enderEmit->cMun);
			if($cidade == null){
				echo "Cadastre a cidade com código ".$xml->NFe->infNFe->emit->enderEmit->cMun . " na base de dados";
				die();
			}
			$contact = [
				'business_id' => $business_id,
				'city_id' => $cidade->id,
				'cpf_cnpj' => $xml->NFe->infNFe->emit->CNPJ ? 
				$this->formataCnpj($xml->NFe->infNFe->emit->CNPJ) : 
				$this->formataCpf($xml->NFe->infNFe->emit->CPF),
				'ie_rg' => $xml->NFe->infNFe->emit->IE,
				'consumidor_final' => 1,
				'contribuinte' => 1,
				'rua' => $xml->NFe->infNFe->emit->enderEmit->xLgr,
				'numero' => $xml->NFe->infNFe->emit->enderEmit->nro,
				'bairro' => $xml->NFe->infNFe->emit->enderEmit->xBairro,
				'cep' => $xml->NFe->infNFe->emit->enderEmit->CEP,
				'type' => 'supplier',
				'name' => $xml->NFe->infNFe->emit->xNome,
				'mobile' => '',
				'created_by' => $user_id
			];

			$cnpj = $contact['cpf_cnpj'];
			$fornecedorNovo = Contact::where('cpf_cnpj', $cnpj)
			->where('type', 'supplier')
			->first();

				// $resFornecedor = $this->validaFornecedorCadastrado($contact);

			$itens = [];
			$contSemRegistro = 0;
			foreach($xml->NFe->infNFe->det as $item) {

				$produto = $this->validaProdutoCadastrado($item->prod->cEAN,
					$item->prod->xProd);

				$produtoNovo = $produto == null ? true : false;

				if($produtoNovo) $contSemRegistro++;

				if($produto != null){
					$tp = ItemDfe::
					where('produto_id', $produto->id)
					->where('numero_nfe', $xml->NFe->infNFe->ide->nNF)
					->first();
				}else{
					$tp = null;
				}

				$item = [
					'codigo' => $item->prod->cProd,
					'xProd' => $item->prod->xProd,
					'NCM' => $item->prod->NCM,
					'CFOP' => $item->prod->CFOP,
					'uCom' => $item->prod->uCom,
					'vUnCom' => $item->prod->vUnCom,
					'qCom' => $item->prod->qCom,
					'codBarras' => $item->prod->cEAN,
					'produtoNovo' => $produtoNovo,
					'produtoId' => $produtoNovo ? '0' : $produto->id,
					'tp' => $tp == null ? false : true
				];
				array_push($itens, $item);
			}

			$chave = substr($xml->NFe->infNFe->attributes()->Id, 3, 44);

			$vFrete = number_format((double) $xml->NFe->infNFe->total->ICMSTot->vFrete, 
				2, ",", ".");

			$vDesc = $xml->NFe->infNFe->total->ICMSTot->vDesc;

			$dadosNf = [
				'chave' => $chave,
				'vProd' => $xml->NFe->infNFe->total->ICMSTot->vProd,
				'indPag' => $xml->NFe->infNFe->ide->indPag,
				'nNf' => $xml->NFe->infNFe->ide->nNF,
				'vFrete' => $vFrete,
				'vDesc' => $vDesc,
				'vFinal' => (float)$xml->NFe->infNFe->total->ICMSTot->vProd - (float)$vDesc,
				'novoFornecedor' => $fornecedorNovo == null ? true : false
			];

			$fatura = [];
			if (!empty($xml->NFe->infNFe->cobr->dup))
			{
				foreach($xml->NFe->infNFe->cobr->dup as $dup) {
					$titulo = $dup->nDup;
					$vencimento = $dup->dVenc;
					$vencimento = explode('-', $vencimento);
					$vencimento = $vencimento[2]."/".$vencimento[1]."/".$vencimento[0];
					$vlr_parcela = number_format((double) $dup->vDup, 2, ",", ".");	

					$parcela = [
						'numero' => $titulo,
						'vencimento' => $vencimento,
						'valor_parcela' => $vlr_parcela
					];
					array_push($fatura, $parcela);
				}
			}

			$business_id = request()->session()->get('user.business_id');

			$business = Business::find($business_id);
			$cnpj = $business->cnpj;

			$cnpj = str_replace(".", "", $cnpj);
			$cnpj = str_replace("/", "", $cnpj);
			$cnpj = str_replace("-", "", $cnpj);
			$cnpj = str_replace(" ", "", $cnpj);


			$business_locations = BusinessLocation::forDropdown($business_id);

				// $categorias = Categoria::all();
				// $unidadesDeMedida = Produto::unidadesMedida();

				// $listaCSTCSOSN = Produto::listaCSTCSOSN();
				// $listaCST_PIS_COFINS = Produto::listaCST_PIS_COFINS();
				// $listaCST_IPI = Produto::listaCST_IPI();
				// $config = ConfigNota::first();

			$manifesto = Manifesto::where('chave', $chave)->first();

				// $compra = Compra::
				// where('chave', $chave)
				// ->first();

			$faturaAnterior = Transaction::where('ref_no', 'NOTA_'.$xml->NFe->infNFe->ide->nNF)
			->first();
			
			$default_location = null;
			if (count($business_locations) == 1) {
				foreach ($business_locations as $id => $name) {
					$default_location = BusinessLocation::findOrFail($id);
				}
			}

			$salvoEmCompras = Transaction::where('numero_nfe_entrada', $dadosNf['nNf'][0])
			->first();

			return view('manifesto/view')
			->with('contact' , $contact)
			->with('fornecedor' , $fornecedorNovo)
			->with('itens' , $itens)
			->with('cidade' , $cidade)
			->with('faturaAnterior' , $faturaAnterior)
			->with('fatura' , $fatura)
			->with('chave' , $chave)
			->with('contact' , $contact)
			->with('salvoEmCompras' , $salvoEmCompras)
			->with('default_location' , $default_location)
			->with('lucro' , $business->default_profit_percent)

			->with('business_locations' , $business_locations)
			->with('location_id' , $location_id)
			->with('dadosNf' , $dadosNf);
		}
		// }catch(\Exception $e){

		// 	echo $e->getMessage();
		// }
	}

	private function validaProdutoCadastrado($ean, $nome){
		$result = Product::
		where('sku', $ean)
		->where('sku', '!=', 'SEM GTIN')
		->first();

		if($result == null){
			$result = Product::
			where('name', $nome)
			->first();
		}

		//verifica por codBarras e nome o PROD

		return $result;
	}

	private function formataCnpj($cnpj){
		$temp = substr($cnpj, 0, 2);
		$temp .= ".".substr($cnpj, 2, 3);
		$temp .= ".".substr($cnpj, 5, 3);
		$temp .= "/".substr($cnpj, 8, 4);
		$temp .= "-".substr($cnpj, 12, 2);
		return $temp;
	}

	private function formataCpf($cpf){
		$temp = substr($cpf, 0, 3);
		$temp .= ".".substr($cpf, 3, 3);
		$temp .= ".".substr($cpf, 6, 3);
		$temp .= "-".substr($cpf, 9, 2);

		return $temp;
	}

	public function baixarXml($chave){
		try{
			return response()->download(public_path('xml_dfe/'.$chave.'.xml'));
		}catch(\Exception $e){
			echo $e->getMessage();
		}
	}

	public function cadProd(Request $request){
		// try{

		if(!$request->location_id){
			$output = [
				'success' => 0,
				'msg' => "Informe a localização!"
			];
			return redirect()->back()->with('status', $output);
		}
		$business_id = request()->session()->get('user.business_id');
		$business = Business::find($business_id);
		$user_id = request()->session()->get('user.id');

		$unidade = $this->validaUnidadeCadastrada($request->unidade, $user_id);

		$sku = $request->codBarras != 'SEM GTIN' ? $request->codBarras : $this->lastCodeProduct();
		$lastCfop = substr($request->cfop, 1, 3);

		$perc_venda = $request->perc;
		$valorCompra = $request->valor;

		$produtoData = [
			'name' => $request->nome,
			'business_id' => $business_id,
			'unit_id' => $unidade->id,
			'tax_type' => 'inclusive',
			'barcode_type' => 2,
			'sku' => $sku,
			'created_by' => $user_id,
			'perc_icms' => 0,
			'perc_pis' => 0,
			'perc_cofins' => 0,
			'perc_ipi' => 0,
			'ncm' => $request->ncm,
			'cfop_interno' => '5'.$lastCfop,
			'cfop_externo' => '6'.$lastCfop,
			'type' => 'single',
			'enable_stock' => 1,

			'cst_csosn' => $business->cst_csosn_padrao,
			'cst_pis' => $business->cst_cofins_padrao,
			'cst_cofins' => $business->cst_pis_padrao,
			'cst_ipi' => $business->cst_ipi_padrao,

		];

		// print_r($produtoData);
		$prod = Product::create($produtoData);

		\DB::table('product_locations')->insert(
			[
				'product_id' => $prod->id,
				'location_id' => $request->location_id
			]
		);

		$dataProductVariation = [
			'product_id' => $prod->id,
			'name' => 'DUMMY'
		];

		$variacao = ProductVariation::where('product_id', $prod->id)->where('name', 'DUMMY')->first();
		$produtoVariacao = null;
		if($variacao == null){
			$produtoVariacao = ProductVariation::create($dataProductVariation);
		}else{
			$produtoVariacao = $variacao;
		}

		$dataVariation = [
			'name' => 'DUMMY',
			'product_id' => $prod->id,
			'sub_sku' => $sku,
			'default_purchase_price' => $valorCompra,
			'dpp_inc_tax' => $valorCompra,
			'product_variation_id' => $produtoVariacao->id,
			'profit_percent' => $perc_venda,
			'default_sell_price' => $valorCompra + (($valorCompra * $perc_venda)/100),
			'sell_price_inc_tax' => $valorCompra + (($valorCompra * $perc_venda)/100)
		];

		$var = Variation::where('product_id', $prod->id)->where('name', 'DUMMY')
		->where('product_variation_id', $produtoVariacao->id)->first();
		$variacao = null;
		if($var == null){
			$variacao = Variation::create($dataVariation);
		}else{
			$variacao = $var;
		}
		$quantidade = $request->quantidade;
		$taxaConversao = $request->tx_conv;
		$quantidade *= $taxaConversao;

		$dataItemPurchase = [
			'transaction_id' => null,
			'product_id'=> $prod->id,
			'variation_id' => $variacao->id,
			'quantity' => $quantidade,
			'pp_without_discount' => $valorCompra,
			'purchase_price' => $valorCompra,
			'purchase_price_inc_tax' => $valorCompra
		];

		$item = PurchaseLine::create($dataItemPurchase);

		$this->openStock($business_id, $prod, $valorCompra, $quantidade, $user_id, $request->location_id, 
			$variacao->id, $produtoVariacao->id);

		ItemDfe::create(
			[
				'numero_nfe' => $request->numero_nfe,
				'produto_id' => $prod->id,
				'business_id' => $business_id
			]
		);

		$output = [
			'success' => 1,
			'msg' => 'Produto cadastrado, e atribuido estoque!!'
		];
		return redirect('/manifesto/download/'.$request->chave)
		->with('status', $output);
		// }catch(\Exception $e){
		// 	$output = [
		// 		'success' => 0,
		// 		'msg' => 'Não foi possível cadastrar produto!!'
		// 	];
		// 	return redirect()->back()->with('status', $output);
		// }
	}

	private function validaUnidadeCadastrada($nome, $user_id){
		$business_id = request()->session()->get('user.business_id');
		$unidade = Unit::where('short_name', $nome)
		->where('business_id', $business_id)
		->first();

		if($unidade != null){
			return $unidade;
		}

		//vai inserir
		$data = [
			'business_id' => $business_id,
			'actual_name' => $nome,
			'short_name' => $nome,
			'allow_decimal' => 1,
			'created_by' => $user_id
		];

		$u = Unit::create($data);
		$unidade = Unit::find($u->id);

		return $unidade;

	}

	private function lastCodeProduct(){
		$prod = Product::orderBy('id', 'desc')->first();
		if($prod == null){
			return '0001';
		}else{
			$v = (int) $prod->sku;
			if($v<10) return '000' . ($v+1);
			elseif($v<100) return '00' . ($v+1);
			elseif($v<1000) return '0'.($v+1);
			else return $v+1;
		}
	}

	private function openStock($business_id, $produto, $valorUnit, $quantidade, $user_id, $location_id, 
		$variacao_id, $product_variation_id){

		$transaction = Transaction::create(
			[
				'type' => 'opening_stock',
				'opening_stock_product_id' => $produto->id,
				'status' => 'received',
				'business_id' => $business_id,
				'transaction_date' => date('Y-m-d H:i:s'),
				'total_before_tax' => $valorUnit * $quantidade,
				'location_id' => $location_id,
				'final_total' => $valorUnit * $quantidade,
				'payment_status' => 'paid',
				'created_by' => $user_id
			]
		);

		VariationLocationDetails::create([
			'product_id' => $produto->id,
			'location_id' => $location_id,
			'variation_id' => $variacao_id,
			'product_variation_id' => $product_variation_id,
			'qty_available' => $quantidade
		]);

	}

	public function atribuirEstoque(Request $request){
		try{
			$produto = $this->validaProdutoCadastrado($request->codBarras,
				$request->nome);
			$business_id = request()->session()->get('user.business_id');
			$business = Business::find($business_id);
			$user_id = request()->session()->get('user.id');

			$valorCompra = $request->valor;
			$quantidade = $request->quantidade;
			$perc_venda = $request->perc;
			$taxaConversao = $request->tx_conv;

			$dataProductVariation = [
				'product_id' => $produto->id,
				'name' => 'DUMMY'
			];

			$variacao = ProductVariation::where('product_id', $produto->id)->where('name', 'DUMMY')->first();
			if($variacao == null){
				$produtoVariacao = ProductVariation::create($dataProductVariation);
			}else{
				$produtoVariacao = $variacao;
			}

			$dataVariation = [
				'name' => 'DUMMY',
				'product_id' => $produto->id,
				'sub_sku' => $produto->sku,
				'default_purchase_price' => $valorCompra,
				'dpp_inc_tax' => $valorCompra,
				'product_variation_id' => $produtoVariacao->id,
				'profit_percent' => $perc_venda,
				'default_sell_price' => $valorCompra + (($valorCompra * $perc_venda)/100),
				'sell_price_inc_tax' => $valorCompra + (($valorCompra * $perc_venda)/100)
			];

			$var = Variation::where('product_id', $produto->id)->where('name', 'DUMMY')
			->where('product_variation_id', $produtoVariacao->id)->first();
			$variacao = null;
			if($var == null){
				$variacao = Variation::create($dataVariation);
			}else{
				$variacao = $var;
			}

			$current_stock = VariationLocationDetails::
			where('product_id', $produto->id)
			->where('location_id', $request->location_id)
			->first();

			$quantidade *= $taxaConversao;
				// ->value('qty_available');
			if($current_stock == null){
				$this->openStock($business_id, $produto, $valorCompra, $quantidade, $user_id, 
					$request->location_id, $variacao->id, $produtoVariacao->id);

			}else{

				$current_stock->qty_available += $quantidade;
				$current_stock->save();
			}

			ItemDfe::create(
				[
					'numero_nfe' => $request->numero_nfe,
					'produto_id' => $produto->id,
					'business_id' => $business_id
				]
			);

			$output = [
				'success' => 1,
				'msg' => 'Estoque incluido '.$produto->name.'!!'
			];
			return redirect()->back()->with('status', $output);

		}catch(\Exception $e){
			$output = [
				'success' => 0,
				'msg' => 'Não foi possível cadastrar estoque!!'
			];
			return redirect()->back()->with('status', $output);
		}
	}

	public function salvarFornecedor(Request $request){
		try{
			$contato = $request->contato;
			$contato = json_decode($contato, true);

			// $ct = [
			// 	'business_id' => $contato['business_id'],
			// 	'city_id' => $contato['city_id'],
			// 	'cpf_cnpj' => $contato['cpf_cnpj'],
			// 	'ie_rg' => $contato['ie_rg'][0],
			// 	'consumidor_final' => 1,
			// 	'contribuinte' => 1,
			// 	'rua' => $contato['rua'][0],
			// 	'numero' => $contato['numero'][0],
			// 	'bairro' => $contato['bairro'][0],
			// 	'cep' => $contato['cep'][0],
			// 	'type' => 'supplier',
			// 	'name' => $contato['name'][0],
			// 	'mobile' => '',
			// 	'created_by' => $contato['created_by']
			// ];

			// $contact = Contact::create($ct);
			// $output = [
			// 	'success' => 1,
			// 	'msg' => 'Fornecedor cadastrado!!'
			// ];

			$dataCompra = [
				'business_id' => $business_id,
				'type' => 'purchase',
				'status' => 'received',
				'payment_status' => 'due',
				'contact_id' => $contact->id,
				'transaction_date' => date('Y-m-d H:i:s'),
				'created_by' => $user_id,
				'numero_nfe_entrada' => $dadosNf['nNf'][0],
				'chave_entrada' => $dadosNf['chave'],
				'estado' => 'APROVADO',
				'location_id' => $request->location_id,
				'final_total' => $dadosNf['vFinal'],
				'total_before_tax' => $dadosNf['vFinal'],
				'discount_amount' => $dadosNf['vDesc'][0],
				'discount_type' => $dadosNf['vDesc'][0] > 0 ? 'fixed' : NULL
			];

			$purchase = Transaction::create($dataCompra);

			return redirect()->back()->with('status', $output);
		}catch(\Exception $e){
			$output = [
				'success' => 0,
				'msg' => 'Não foi possível cadastrar fornecedor!!'
			];
			return redirect()->back()->with('status', $output);
		}

	}

	public function salvarFatura(Request $request){
		$fatura = $request->fatura;
		$fatura = json_decode($fatura, true);

		$business_id = request()->session()->get('user.business_id');
		$user_id = request()->session()->get('user.id');
		$desconto = str_replace(",", ".", $request->desconto);
		$desconto = (float)$desconto;
		$tamanhoFat = sizeof($fatura);
		foreach($fatura as $f){
			$vencimento = $f['vencimento'];
			$vencimento = str_replace("/", "-", $vencimento);
			$vencimento = \Carbon\Carbon::parse($vencimento)->format('Y-m-d H:i:s');

			$valor = $f['valor_parcela'];
			$valor = str_replace(",", ".", $valor);
			$desconto = $desconto / $tamanhoFat;

			$dataFatura = [
				'business_id' => $business_id,
				'type' => 'expense',
				'status' => 'final',
				'payment_status' => 'due',
				'contact_id' => $request->fornecedor_id,
				'transaction_date' => $vencimento,
				'created_by' => $user_id,
				'numero_nfe_entrada' => '',
				'chave' => '',
				'estado' => '',
				'location_id' => $request->location_id,
				'ref_no' => 'NOTA_' . $request->numero_nfe,
				'final_total' => $valor,
				'total_before_tax' => $valor,
				'discount_amount' => $desconto,
				'discount_type' => $desconto > 0 ? 'fixed' : NULL
			];

			$fatura = Transaction::create($dataFatura);

		}

		$output = [
			'success' => 1,
			'msg' => 'Fatura salva!!'
		];
		return redirect()->back()->with('status', $output);
	}

	public function save(Request $request){
		try{

			$business_id = request()->session()->get('user.business_id');
			$business = Business::find($business_id);

			$contact = json_decode($request->contact, true);
			$itens = json_decode($request->itens, true);

			// print_r($itens);
			// die;
			$fatura = json_decode($request->fatura, true);
			$dadosNf = json_decode($request->dadosNf, true);
			$perc_venda = $request->perc_venda;
			$conversao = $request->conversao;
			$conversao = explode(",", $conversao);

			$data = [
				'business_id' => $contact['business_id'],
				'city_id' => $contact['city_id'],
				'cpf_cnpj' => $contact['cpf_cnpj'],
				'ie_rg' => $contact['ie_rg'][0],
				'consumidor_final' => 1,
				'contribuinte' => 1,
				'rua' => $contact['rua'][0],
				'numero' => $contact['numero'][0],
				'bairro' => $contact['bairro'][0],
				'cep' => $contact['cep'][0],
				'type' => 'supplier',
				'name' => $contact['name'][0],
				'mobile' => '',
				'created_by' => $contact['created_by']
			];

			$user_id = $request->session()->get('user.id');

			$contact = $this->validaFornecedorCadastrado($data);

			$dataCompra = [
				'business_id' => $business_id,
				'type' => 'purchase',
				'status' => 'received',
				'payment_status' => 'due',
				'contact_id' => $contact->id,
				'transaction_date' => date('Y-m-d H:i:s'),
				'created_by' => $user_id,
				'numero_nfe_entrada' => $dadosNf['nNf'][0],
				'chave_entrada' => $dadosNf['chave'],
				'estado' => 'APROVADO',
				'location_id' => $request->location_id,
				'final_total' => $dadosNf['vFinal'],
				'total_before_tax' => $dadosNf['vFinal'],
				'discount_amount' => $dadosNf['vDesc'][0],
				'discount_type' => $dadosNf['vDesc'][0] > 0 ? 'fixed' : NULL
			];

			$purchase = Transaction::create($dataCompra);
			
			foreach($itens as $key => $i){

				$taxa = (int)$conversao[$key] ?? 1;
				$quantidade = (float)$i['qCom'][0];
				$quantidade = $quantidade * $taxa;

				$valorCompra = $i['vUnCom'][0];
				if($taxa > 1){
					$valorCompra = $valorCompra/$taxa;
				}

				$unidade = $this->validaUnidadeCadastrada($i['uCom'][0], $user_id);

				if($taxa > 1){
					$unidade = Unit::where('business_id', $business_id)->where('short_name', 'UNID')->first();
					if($unidade == null){
						$unidade = Unit::where('business_id', $business_id)->where('short_name', 'UN')->first();
					}
				}
				
				$sku = $i['codBarras'] ? ($i['codBarras'][0] != 'SEM GTIN' ? $i['codBarras'][0] : $this->lastCodeProduct()) : '';

				$cfop = $i['CFOP'][0];
				$lastCfop = substr($cfop, 1, 3);
				$produtoData = [
					'name' => $i['xProd'][0],
					'business_id' => $business_id,
					'unit_id' => $unidade->id,
					'tax_type' => 'inclusive',
					'barcode_type' => 2,
					'codigo_barras' => $sku,
					'sku' => $i['codigo'][0],
					'created_by' => $user_id,
					'perc_icms' => 0,
					'perc_pis' => 0,
					'perc_cofins' => 0,
					'perc_ipi' => 0,
					'ncm' => $i['NCM'][0],
					'cfop_interno' => '5'.$lastCfop,
					'cfop_externo' => '6'.$lastCfop,
					'type' => 'single',
					'enable_stock' => 1,

					'cst_csosn' => $business->cst_csosn_padrao,
					'cst_pis' => $business->cst_cofins_padrao,
					'cst_cofins' => $business->cst_pis_padrao,
					'cst_ipi' => $business->cst_ipi_padrao,

				];

				// print_r($produtoData);
				$prodNovo = $this->validaProdutoCadastrado($i['xProd'][0], $i['codBarras'] ? $i['codBarras'][0] : 'SEM GTIN');
				$prod = null;
				if($prodNovo == null){
					$prod = Product::create($produtoData);
				}else{
					$prod = $prodNovo;
				}


				//criar variação de produto

				//verfica variacao

				$dataProductVariation = [
					'product_id' => $prod->id,
					'name' => 'DUMMY'
				];

				$variacao = ProductVariation::where('product_id', $prod->id)->where('name', 'DUMMY')->first();
				$produtoVariacao = null;
				if($variacao == null){
					$produtoVariacao = ProductVariation::create($dataProductVariation);
				}else{
					$produtoVariacao = $variacao;
				}

				// criar variação

				$dataVariation = [
					'name' => 'DUMMY',
					'product_id' => $prod->id,
					'sub_sku' => $sku,
					'default_purchase_price' => $valorCompra,
					'dpp_inc_tax' => $valorCompra,
					'product_variation_id' => $produtoVariacao->id,
					'profit_percent' => $perc_venda,
					'default_sell_price' => $valorCompra + (($valorCompra * $perc_venda)/100),
					'sell_price_inc_tax' => $valorCompra + (($valorCompra * $perc_venda)/100)
				];

				$var = Variation::where('product_id', $prod->id)->where('name', 'DUMMY')
				->where('product_variation_id', $produtoVariacao->id)->first();
				$variacao = null;
				if($var == null){
					$variacao = Variation::create($dataVariation);
				}else{
					$variacao = $var;
				}

			//criar item compra
				$dataItemPurchase = [
					'transaction_id' => $purchase->id,
					'product_id'=> $prod->id,
					'variation_id' => $variacao->id,
					'quantity' => $quantidade,
					'pp_without_discount' => $valorCompra,
					'purchase_price' => $valorCompra,
					'purchase_price_inc_tax' => $valorCompra
				];

				$item = PurchaseLine::create($dataItemPurchase);

				\DB::table('product_locations')->insert(
					[
						'product_id' => $prod->id,
						'location_id' => $request->location_id
					]
				);

			//verificaStock

				if($prodNovo == null){
				//criar stock
					$this->openStock($business_id, $prod, $valorCompra, $quantidade, $user_id, $request->location_id, 
						$variacao->id, $produtoVariacao->id);
				//add estoque

				}else{

					$current_stock = VariationLocationDetails::
					where('product_id', $prod->id)
					->where('location_id', $request->location_id)
					->first();
				// ->value('qty_available');

					if($current_stock == null){
						$this->openStock($business_id, $prod, $valorCompra, $quantidade, $user_id, 
							$request->location_id, $variacao->id, $produtoVariacao->id);

					}else{
						$current_stock->qty_available += $quantidade;
						$current_stock->save();
					}
				}

			}

		//salvar fatura
			if(sizeof($fatura) > 0){
				$len = sizeof($fatura);

				foreach($fatura as $f){
					$vencimento = $f['vencimento'];
					$vencimento = str_replace("/", "-", $vencimento);
					$vencimento = \Carbon\Carbon::parse($vencimento)->format('Y-m-d H:i:s');


					$valor = $f['valor_parcela'];
					$valor = str_replace(",", ".", $valor);
					$desconto = (float)$dadosNf['vDesc'][0]/$len;
					$dataFatura = [
						'business_id' => $business_id,
						'type' => 'expense',
						'status' => 'final',
						'payment_status' => 'due',
						'contact_id' => $contact->id,
						'transaction_date' => $vencimento,
						'created_by' => $user_id,
						'numero_nfe_entrada' => '',
						'chave' => '',
						'estado' => '',
						'location_id' => $request->location_id,
						'ref_no' => 'NOTA_' . $dadosNf['nNf'][0],
						'final_total' => $valor,
						'total_before_tax' => $valor,
						'discount_amount' => $desconto,
						'discount_type' => $desconto > 0 ? 'fixed' : NULL
					];

					$fatura = Transaction::create($dataFatura);
				}

			}
			$output = [
				'success' => 1,
				'msg' => 'Compra com xml salva com sucesso!!'
			];
		}catch(\Exception $e){
			echo $e->getMessage() . "<br>";
			echo $e->getLine() . "<br>";
			die;
			$output = [
				'success' => 0,
				'msg' => $e->getMessage()
				// 'msg' => __('messages.something_went_wrong')
			];

		}

		return redirect()->back()->with('status', $output);

	}

	private function validaFornecedorCadastrado($data){
		$cnpj = $data['cpf_cnpj'];
		$fornecedor = Contact::where('cpf_cnpj', $cnpj)
		->where('type', 'supplier')
		->first();

		if($fornecedor == null){
			$contact = Contact::create($data);

			$fornecedor = Contact::find($contact->id);
		}

		return $fornecedor;

	}
}
