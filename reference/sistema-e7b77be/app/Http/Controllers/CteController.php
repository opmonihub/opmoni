<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Cte;
use App\Models\Veiculo;
use App\Models\Contact;
use App\Models\City;
use App\Models\MedidaCte;
use App\Models\ComponenteCte;
use App\Models\NaturezaOperacao;
use App\Models\CteDocumentoAnterior;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\Facades\DataTables;
use App\Utils\ModuleUtil;
use App\Utils\CteListQuery;
use App\Utils\CteListPresenter;
use App\Utils\CtePreviousDocumentValidator;
use App\Models\System;
use App\Models\BusinessLocation;

use NFePHP\DA\CTe\Dacte;
use NFePHP\DA\CTe\Daevento;
use App\Services\CTeService;
use App\Models\Business;

class CteController extends Controller
{
	public function __construct(ModuleUtil $moduleUtil)
	{
		$this->moduleUtil = $moduleUtil;
	}

	public function index(){
		if (!auth()->user()->can('user.view') && !auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		if (request()->ajax()) {
			$business_id = request()->session()->get('user.business_id');
			$list = new CteListQuery($business_id);
			$ctes = $list->query();
			$table = $list->configure(Datatables::of($ctes));

			return $table
			->editColumn('data_previsata_entrega', function ($row) {
				return \Carbon\Carbon::parse($row->data_previsata_entrega)->format('d/m/Y');
			})

			->editColumn('created_at', function ($row) {
				return \Carbon\Carbon::parse($row->created_at)->format('d/m/Y H:i');
			})

			->editColumn('valor_carga', function ($row) {
				return number_format($row->valor_carga, 2, ',', '');
			})

			->editColumn('valor_transporte', function ($row) {
				return number_format($row->valor_transporte, 2, ',', '');
			})

			->editColumn('valor_receber', function ($row) {
				return number_format($row->valor_receber, 2, ',', '');
			})

			->addColumn('remetente', function ($row) {
				return $row->remetente_nome ?? '—';
			})

			->addColumn('destinatario', function ($row) {
				return $row->destinatario_nome ?? '—';
			})

			->addColumn('documentos', function ($row) {
				return CteListPresenter::documents($row, $row->documentosAnteriores);
			})

			->addColumn('cidade_destino', function ($row) {
				return CteListPresenter::city($row->cidade_nome, $row->cidade_uf);
			})

			->addColumn('peso_kg', function ($row) {
				return CteListPresenter::weight($row->weight_status, $row->peso_valor);
			})

			->addColumn('tomador_servico', function ($row) {
				return CteListPresenter::taker($row->tomador, $row->tomador_nome);
			})

			->addColumn('numero_cte', function ($row) {
				return $row->cte_numero > 0 ? $row->cte_numero : '--';
			})

			->addColumn('action', function ($row) {
				$html = '';
				$html = '<form id="cte'.$row['id'].'" method="POST" action="'. route('cte.destroy', $row['id']) .'">';

				if($row->cte_numero > 0){
					$html .= '<a class="btn btn-xs btn-info" href="'. route('cte.ver', $row['id']) .'">Ver CTe</a>';
				}else{
					$html .= '<a class="btn btn-xs btn-primary" href="'. route('cte.gerar', $row['id']) .'">Gerar CTe</a>';
					$html .= '&nbsp;<a class="btn btn-xs btn-warning" href="'. route('cte.edit', $row['id']) .'">Editar</a>';
				}

				$html .= '&nbsp;<button type="button" class="btn btn-xs btn-danger btn-delete"> Excluir</button>
				'. method_field('DELETE') .'
				'. csrf_field() .'
				</form>';
				return $html;
			})


			->only(['cte_numero', 'valor_transporte', 'valor_receber', 'valor_carga',
				'produto_predominante', 'data_previsata_entrega', 'estado', 'created_at',
				'remetente', 'destinatario', 'numero_cte', 'documentos', 'cidade_destino',
				'peso_kg', 'tomador_servico', 'action'])
			// Envelopes são texto estruturado; escapar apenas as células HTML legadas.
			->escapeColumns(['cte_numero', 'valor_transporte', 'valor_receber', 'valor_carga',
				'produto_predominante', 'data_previsata_entrega', 'estado', 'created_at',
				'remetente', 'destinatario', 'numero_cte'])
			->rawColumns(['action'])
			->make(true);

		}
		return view('cte.index');
	}

	public function create(){

		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		$business_id = request()->session()->get('user.business_id');

		$tipos = Veiculo::tipos();
		$tiposRodado = Veiculo::tiposRodado();
		$tiposCarroceria = Veiculo::tiposCarroceria();
		$tiposProprietario = Veiculo::tiposProprietario();
		$ufs = Veiculo::cUF();

        //Check if subscribed or not, then check for users quota
		if (!$this->moduleUtil->isSubscribed($business_id)) {
			return $this->moduleUtil->expiredResponse();
		} elseif (!$this->moduleUtil->isQuotaAvailable('naturezas', $business_id)) {
			return $this->moduleUtil->quotaExpiredResponse('naturezas', $business_id, action('NaturezaController@index'));
		}

		$roles  = $this->getRolesArray($business_id);
		$username_ext = $this->getUsernameExtension();
		
        //Get user form part from modules

		$lastCte = Cte::lastCTeAux($business_id);
		$unidadesMedida = Cte::unidadesMedida();
		$tiposMedida = Cte::tiposMedida();
		$tiposTomador = Cte::tiposTomador();
		$tiposServico = Cte::tiposServico();
		$naturezas = $this->prepareNaturezas();
		$modals = Cte::modals();
		$veiculos = $this->prepareVeiculos();
		$clientesAux = Contact::where('business_id', $business_id)->get();

		foreach($clientesAux as $c){
			$c->cidade;
		}
		
		$clientes = $this->prepareClientes();
		$cidades = $this->prepareCities();

		$business_locations = BusinessLocation::forDropdown($business_id, false, true);
		$bl_attributes = $business_locations['attributes'];
		$business_locations = $business_locations['locations'];

		$default_location = null;
		if (count($business_locations) == 1) {
			foreach ($business_locations as $id => $name) {
				$default_location = BusinessLocation::findOrFail($id);
			}
		}

		return view('cte.create')
		->with(compact('roles', 'username_ext', 'cidades', 'clientesAux', 'clientes', 'lastCte', 'unidadesMedida', 'tiposMedida', 'tiposTomador', 'tiposServico', 'naturezas', 'modals', 'veiculos'))
		->with('bl_attributes' , $bl_attributes)
		->with('default_location' , $default_location)
		->with('business_locations' , $business_locations);
	}

	public function edit($id){

		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}
		$cte = Cte::find($id);

		$business_id = request()->session()->get('user.business_id');

		$tipos = Veiculo::tipos();
		$tiposRodado = Veiculo::tiposRodado();
		$tiposCarroceria = Veiculo::tiposCarroceria();
		$tiposProprietario = Veiculo::tiposProprietario();
		$ufs = Veiculo::cUF();

        //Check if subscribed or not, then check for users quota
		if (!$this->moduleUtil->isSubscribed($business_id)) {
			return $this->moduleUtil->expiredResponse();
		} elseif (!$this->moduleUtil->isQuotaAvailable('naturezas', $business_id)) {
			return $this->moduleUtil->quotaExpiredResponse('naturezas', $business_id, action('NaturezaController@index'));
		}

		$roles  = $this->getRolesArray($business_id);
		$username_ext = $this->getUsernameExtension();
		
        //Get user form part from modules

		$lastCte = Cte::lastCTeAux($business_id);
		$unidadesMedida = Cte::unidadesMedida();
		$tiposMedida = Cte::tiposMedida();
		$tiposTomador = Cte::tiposTomador();
		$tiposServico = Cte::tiposServico();
		$naturezas = $this->prepareNaturezas();
		$modals = Cte::modals();
		$veiculos = $this->prepareVeiculos();
		$clientesAux = Contact::where('business_id', $business_id)->get();

		foreach($clientesAux as $c){
			$c->cidade;
		}
		
		$clientes =  $this->prepareClientes();
		$cidades =  $this->prepareCities();

		$business_locations = BusinessLocation::forDropdown($business_id, false, true);
		$bl_attributes = $business_locations['attributes'];
		$business_locations = $business_locations['locations'];

		$default_location = null;
		if (count($business_locations) == 1) {
			foreach ($business_locations as $id => $name) {
				$default_location = BusinessLocation::findOrFail($id);
			}
		}

		return view('cte.edit')
		->with(compact('roles', 'username_ext', 'cidades', 'clientesAux', 'clientes', 'lastCte', 'unidadesMedida', 'tiposMedida', 'tiposTomador', 'tiposServico', 'naturezas', 'modals', 'veiculos'))
		->with('bl_attributes' , $bl_attributes)
		->with('cte' , $cte)
		->with('default_location' , $default_location)
		->with('business_locations' , $business_locations);
	}

	private function prepareCities(){
		$cities = City::all();
		$temp = [];
		foreach($cities as $c){
            // array_push($temp, $c->id => $c->nome);
			$temp[$c->id] = $c->nome . " ($c->uf)";
		}
		return $temp;
	}

	private function prepareNaturezas(){
		$business_id = request()->session()->get('user.business_id');

		$naturezas = NaturezaOperacao::
		where('business_id', $business_id)
		->get();
		
		$temp = [];
		foreach($naturezas as $c){
			$temp[$c->id] = $c->natureza;
		}
		return $temp;
	}

	private function prepareVeiculos(){
		$business_id = request()->session()->get('user.business_id');

		$veiculos = Veiculo::
		where('business_id', $business_id)
		->get();
		$temp = [];
		foreach($veiculos as $v){
			$temp[$v->id] = "$v->placa - $v->modelo";
		}
		return $temp;
	}

	private function prepareClientes(){
		$business_id = request()->session()->get('user.business_id');

		$clientes = Contact::
		where('business_id', $business_id)
		->orderBy('name')
		->get();

		$temp = [];
		foreach($clientes as $c){
			if($c->name != 'Cliente padrão')
				$temp[$c->id] = $c->name . " ($c->cpf_cnpj)";
		}
		return $temp;
	}


	private function getRolesArray($business_id)
	{
		$roles_array = Role::where('business_id', $business_id)->get()->pluck('name', 'id');
		$roles = [];

		$is_admin = $this->moduleUtil->is_admin(auth()->user(), $business_id);

		foreach ($roles_array as $key => $value) {
			if (!$is_admin && $value == 'Admin#' . $business_id) {
				continue;
			}
			$roles[$key] = str_replace('#' . $business_id, '', $value);
		}
		return $roles;
	}

	private function getUsernameExtension()
	{
		$extension = !empty(System::getProperty('enable_business_based_username')) ? '-' .str_pad(session()->get('business.id'), 2, 0, STR_PAD_LEFT) : null;
		return $extension;
	}

	private function resolveVinculoSubstituicao(Request $request, $business_id, $excludeId = null){
		$tp_cte = (int)($request->tp_cte ?? 0);
		if(!in_array($tp_cte, [0, 1, 3], true)){
			$tp_cte = 0;
		}

		if($tp_cte == 0){
			return ['tp_cte' => 0, 'chave_original' => null, 'chave_complementada' => null, 'ind_altera_toma' => null, 'cte_original_id' => null];
		}

		$manual = $tp_cte == 3 ? $request->chave_original : $request->chave_complementada;
		$manual = $manual ? preg_replace('/\D/', '', $manual) : '';
		$chave = ($manual != '' ? $manual : null);
		$cte_original_id = null;

		if($chave){
			$queryVinculado = Cte::where('business_id', $business_id)->where('chave', $chave);
			if(!empty($excludeId)){
				$queryVinculado->where('id', '!=', $excludeId);
			}
			$vinculado = $queryVinculado->first();
			if($vinculado) $cte_original_id = $vinculado->id;
		}

		if(!$cte_original_id && $request->cte_original_id){
			$queryOriginal = Cte::where('business_id', $business_id)->where('id', $request->cte_original_id);
			if(!empty($excludeId)){
				$queryOriginal->where('id', '!=', $excludeId);
			}
			$original = $queryOriginal->first();
			if($original){
				$cte_original_id = $original->id;
				if(!$chave) $chave = $original->chave;
			}
		}

		if($tp_cte == 3){
			return ['tp_cte' => 3, 'chave_original' => $chave, 'chave_complementada' => null, 'ind_altera_toma' => !empty($request->ind_altera_toma) ? 1 : null, 'cte_original_id' => $cte_original_id];
		}

		return ['tp_cte' => 1, 'chave_original' => null, 'chave_complementada' => $chave, 'ind_altera_toma' => null, 'cte_original_id' => $cte_original_id];
	}

	private function docDoTomadorCte($cte){
		$toma = (int)($cte->tomador ?? 0);
		if(!in_array($toma, [0, 1, 2, 3, 4], true)){
			$toma = 0;
		}
		if($toma === 4){
			$contato = $cte->tomadorOutros;
			$doc = $contato ? preg_replace('/\D/', '', (string)$contato->cpf_cnpj) : '';
			return ['toma' => 4, 'doc' => $doc];
		}
		$mapa = [0 => 'remetente', 1 => 'expedidor', 2 => 'recebedor', 3 => 'destinatario'];
		$relacao = $mapa[$toma];
		$contato = $cte->$relacao;
		$doc = $contato ? preg_replace('/\D/', '', (string)$contato->cpf_cnpj) : '';
		return ['toma' => $toma, 'doc' => $doc];
	}

	private function derivarIndAlteraToma($novo, $original){
		if(!$original){
			return false;
		}
		$origem = $this->docDoTomadorCte($original);
		if($novo['toma'] !== $origem['toma']){
			return 1;
		}
		if($novo['doc'] !== '' && $origem['doc'] !== ''){
			return $novo['doc'] !== $origem['doc'] ? 1 : null;
		}
		return false;
	}

	private function aplicarDerivacaoToma($cte, $business_id){
		if((int)($cte->tp_cte ?? 0) !== 3){
			return;
		}
		$original = null;
		if(!empty($cte->cte_original_id)){
			$original = Cte::where('business_id', $business_id)->where('id', $cte->cte_original_id)->first();
		}
		if(!$original){
			$chave = $cte->chave_original ? preg_replace('/\D/', '', (string)$cte->chave_original) : '';
			if($chave !== ''){
				$query = Cte::where('business_id', $business_id)->where('chave', $chave);
				if(!empty($cte->id)){
					$query->where('id', '!=', $cte->id);
				}
				$original = $query->first();
			}
		}
		if(!$original || (!empty($cte->id) && $original->id == $cte->id)){
			return;
		}
		$derivado = $this->derivarIndAlteraToma($this->docDoTomadorCte($cte), $original);
		if($derivado !== false){
			$cte->ind_altera_toma = $derivado;
		}
	}

	private function validarVinculoSintatico($cte, $tpCte, $chave, $cnpj){
		$rotulo = $tpCte == 3 ? 'substituto (chave do CT-e original)' : 'complemento (chave do CT-e complementado)';
		$chave = $chave ? preg_replace('/\D/', '', (string) $chave) : '';
		if($chave === ''){
			return 'Informe a chave de acesso do CT-e de origem para emitir CT-e ' . $rotulo . '.';
		}
		if(strlen($chave) != 44 || !ctype_digit($chave)){
			return 'Chave de origem inválida para CT-e ' . $rotulo . ': a chave deve ter 44 dígitos numéricos. Confira a chave e tente novamente.';
		}
		$propria = preg_replace('/\D/', '', (string) ($cte->chave ?? ''));
		if($propria !== '' && $chave === $propria){
			return 'A chave de origem não pode ser a mesma chave deste CT-e. Informe a chave do CT-e de origem.';
		}
		if(substr($chave, 6, 14) !== $cnpj){
			return 'A chave de origem pertence a outro CNPJ emitente. O CT-e de substituição/complemento só pode referenciar CT-e emitido pelo mesmo CNPJ.';
		}
		return null;
	}

	private function validarVinculoSefaz($chave, $cte_service){
		$chave = $chave ? preg_replace('/\D/', '', (string) $chave) : '';
		$consulta = $cte_service->consultaChave($chave);
		if(!is_array($consulta) || !empty($consulta['erro'])){
			$detalhe = '';
			if(is_array($consulta) && isset($consulta['data']) && is_string($consulta['data']) && trim($consulta['data']) !== ''){
				$detalhe = ' Detalhe: ' . trim($consulta['data']);
			}
			return 'Não foi possível validar o CT-e de origem na SEFAZ. Tente novamente.' . $detalhe;
		}
		$dados = $consulta;
		if(isset($consulta['retConsSitCTe']) && is_array($consulta['retConsSitCTe'])){
			$dados = $consulta['retConsSitCTe'];
		}
		$cStat = isset($dados['cStat']) ? (string) $dados['cStat'] : '';
		$infProt = isset($dados['protCTe']['infProt']) && is_array($dados['protCTe']['infProt']) ? $dados['protCTe']['infProt'] : null;
		$nProt = $infProt !== null && isset($infProt['nProt']) ? (string) $infProt['nProt'] : '';
		if($cStat !== '100' || $nProt === ''){
			$motivo = isset($dados['xMotivo']) ? (string) $dados['xMotivo'] : 'situação não autorizada';
			$prefixo = $cStat !== '' ? '[' . $cStat . '] ' . $motivo : $motivo;
			return 'O CT-e de origem não está autorizado na SEFAZ (' . $prefixo . '). Só é possível emitir CT-e substituto/complemento de um CT-e autorizado.';
		}
		return null;
	}

	private function validarListasCte(Request $request){
		return [
			CtePreviousDocumentValidator::validate($request->documentos_anteriores, $request->tipo_servico ?? '0', $request->tp_cte ?? '0'),
			CtePreviousDocumentValidator::decodeList($request->medidas, 'medidas', ['unidade_medida', 'tipo_medida', 'quantidade']),
			CtePreviousDocumentValidator::decodeList($request->componentes, 'componentes', ['nome', 'valor']),
		];
	}

	public function update(Request $request, $id){
		$business_id = request()->session()->get('user.business_id');
		$user_id = request()->session()->get('user.id');
		try {
			[$docsAnteriores, $medidas, $componentes] = $this->validarListasCte($request);
		} catch (\InvalidArgumentException $e) {
			return redirect()->back()->withInput()->with('status', ['success' => 0, 'msg' => $e->getMessage()]);
		}
		try{

			$chaves = $request->chaves_nfe ?? '';
			if($chaves != ''){
				$chaves = str_replace(",", ";", $chaves);
			} 

			$cte = Cte::find($id);
			$config = Business::find($business_id);

			$cte->chave_nfe = $chaves;
			$cte->remetente_id = $request->remetente_id;
			$cte->destinatario_id = $request->destinatario_id;
			$cte->recebedor_id = $request->recebedor_id ?? null;
			$cte->expedidor_id = $request->expedidor_id ?? null;
			$cte->usuario_id = $user_id;
			$cte->natureza_id = $request->natureza_id;
			$cte->tomador = $request->tomador;
			$cte->tipo_servico = $request->tipo_servico ?? '0';
			$cte->tomador_outros_id = $request->tomador == '4' ? $request->tomador_outros_id : null;
			$cte->municipio_envio = $request->cidade_envio;
			$cte->municipio_inicio = $request->cidade_inicio;
			$cte->municipio_fim = $request->cidade_fim;
			$cte->logradouro_tomador = $request->rua_tomador;
			$cte->numero_tomador = $request->numero_tomador;
			$cte->bairro_tomador = $request->bairro_tomador;
			$cte->cep_tomador = $request->cep_tomador;
			$cte->municipio_tomador = $request->cidade_tomador;
			$cte->observacao = $request->observacao ?? '';
			$cte->data_previsata_entrega = $this->parseDate($request->data_prevista_entrega);
			$cte->produto_predominante = $request->prod_predominante;
			$cte->cte_numero = 0;
			$cte->sequencia_cce = 0;
			$cte->chave = '';
			$cte->path_xml = '';
			$cte->estado = 'DISPONIVEL';

			$cte->valor_transporte = str_replace(",", ".", $request->valor_transporte);
			$cte->valor_receber = str_replace(",", ".", $request->valor_receber);
			$cte->valor_carga = str_replace(",", ".", $request->valor_carga);

			$cte->retira = $request->retira;
			$cte->detalhes_retira = $request->detalhes_retira ?? '';
			$cte->modal = $request->modal_transp;
			$cte->veiculo_id = $request->veiculo_id;
			$cte->tpDoc = $request->tpDoc ?? '';
			$cte->descOutros = $request->descOutros ?? '';
			$cte->nDoc = $request->nDoc ?? 0;
			$cte->vDocFisc = $request->vDocFisc ? str_replace(",", ".", $request->vDocFisc) : 0;
			$cte->globalizado = $request->globalizado ?? 0;
			$cte->cst = $request->cst;
			$cte->perc_icms = str_replace(",", ".", $request->perc_icms);
			$cte->location_id = $request->select_location_id ?? $config->locations[0]->id;

			$vinculo = $this->resolveVinculoSubstituicao($request, $business_id, $id);
			$cte->tp_cte = $vinculo['tp_cte'];
			$cte->chave_original = $vinculo['chave_original'];
			$cte->chave_complementada = $vinculo['chave_complementada'];
			$cte->ind_altera_toma = $vinculo['ind_altera_toma'];
			$cte->cte_original_id = $vinculo['cte_original_id'];
		$this->aplicarDerivacaoToma($cte, $business_id);

			$cte->save();

			foreach($cte->medidas as $m){
				$m->delete();
			}
		// print_r($medidas);
			foreach($medidas as $m){
				$medida = MedidaCte::create([
					'cod_unidade' => $m->unidade_medida,
					'tipo_medida'=> $m->tipo_medida,
					'quantidade_carga' => str_replace(",", ".", $m->quantidade),
					'cte_id' => $cte->id
				]);

			}

			foreach($cte->componentes as $c){
				$c->delete();
			}
		// print_r($medidas);
			foreach($componentes as $c){
				$componente = ComponenteCte::create([
					'nome' => $c->nome,
					'valor' => str_replace(",", ".", $c->valor),
					'cte_id' => $cte->id
				]);

			}

			// Salvar documentos anteriores (para subcontratação/redespacho)
			foreach($cte->documentosAnteriores as $d){
				$d->delete();
			}
			foreach($docsAnteriores as $doc){
				CteDocumentoAnterior::create([
					'cte_id' => $cte->id,
					'chave_cte' => $doc['chave'],
					'cnpj_emitente' => $doc['cnpj'],
					'ie_emitente' => $doc['ie'],
					'uf_emitente' => $doc['uf']
				]);
			}

			$output = [
				'success' => 1,
				'msg' => "Cte atualizada!!"
			];

		}catch(\Exception $e){
			$output = [
				'success' => false,
				'msg' => "Erro ao criar CTe"
			];

			echo $e->getMessage();
			die;
		}

		return redirect('cte')->with('status', $output);
	}

	public function store(Request $request){
		$business_id = request()->session()->get('user.business_id');
		$user_id = request()->session()->get('user.id');
		try {
			[$docsAnteriores, $medidas, $componentes] = $this->validarListasCte($request);
		} catch (\InvalidArgumentException $e) {
			// A importação é retornada por POST; voltar à URL anterior tentaria GET nessa rota.
			$retorno = $request->_cte_xml_import === '1' ? redirect('cte/create') : redirect()->back();
			return $retorno->withInput()->with('status', ['success' => 0, 'msg' => $e->getMessage()]);
		}
		try{

			$chaves = $request->chaves_nfe ?? '';
			if($chaves != ''){
				$chaves = str_replace(",", ";", $chaves);
			} 

			$config = Business::find($business_id);

			$data = [
				'business_id' => $business_id,
				'chave_nfe' => $chaves,
				'remetente_id' => $request->remetente_id,
				'destinatario_id' => $request->destinatario_id,
				'recebedor_id' => $request->recebedor_id ?? null,
				'expedidor_id' => $request->expedidor_id ?? null,
				'usuario_id' => $user_id,
				'natureza_id' => $request->natureza_id,
				'tomador' => $request->tomador,
				'tipo_servico' => $request->tipo_servico ?? '0',
				'tomador_outros_id' => $request->tomador == '4' ? $request->tomador_outros_id : null,
				'municipio_envio' => $request->cidade_envio,
				'municipio_inicio' => $request->cidade_inicio,
				'municipio_fim' => $request->cidade_fim,
				'logradouro_tomador' => $request->rua_tomador,
				'numero_tomador' => $request->numero_tomador,
				'bairro_tomador' => $request->bairro_tomador,
				'cep_tomador' => $request->cep_tomador,
				'municipio_tomador' => $request->cidade_tomador,
				'observacao' => $request->observacao ?? '',
				'data_previsata_entrega' => $this->parseDate($request->data_prevista_entrega),
				'produto_predominante' => $request->prod_predominante,
				'cte_numero' => 0,
				'sequencia_cce' => 0,
				'chave' => '',
				'path_xml' => '',
				'estado' => 'DISPONIVEL',

				'valor_transporte' => str_replace(",", ".", $request->valor_transporte),
				'valor_receber' => str_replace(",", ".", $request->valor_receber),
				'valor_carga' => str_replace(",", ".", $request->valor_carga),

				'retira' => $request->retira,
				'detalhes_retira' => $request->detalhes_retira ?? '',
				'modal' => $request->modal_transp,
				'veiculo_id' => $request->veiculo_id,
				'tpDoc' => $request->tpDoc ?? '',
				'descOutros' => $request->descOutros ?? '',
				'nDoc' => $request->nDoc ?? 0,
				'vDocFisc' => $request->vDocFisc ? str_replace(",", ".", $request->vDocFisc) : 0,
				'globalizado' => $request->globalizado ?? 0,
				'cst' => $request->cst,
				'perc_icms' => str_replace(",", ".", $request->perc_icms),
				'location_id' => $request->select_location_id ?? $config->locations[0]->id
			];

			$data = array_merge($data, $this->resolveVinculoSubstituicao($request, $business_id));

			$tmp = new Cte($data);
			$this->aplicarDerivacaoToma($tmp, $business_id);
			$data['ind_altera_toma'] = $tmp->ind_altera_toma;

			$result = Cte::create($data);

		// print_r($medidas);
			foreach($medidas as $m){
				$medida = MedidaCte::create([
					'cod_unidade' => $m->unidade_medida,
					'tipo_medida'=> $m->tipo_medida,
					'quantidade_carga' => str_replace(",", ".", $m->quantidade),
					'cte_id' => $result->id
				]);

			}

		// print_r($medidas);
			foreach($componentes as $c){
				$componente = ComponenteCte::create([
					'nome' => $c->nome,
					'valor' => str_replace(",", ".", $c->valor),
					'cte_id' => $result->id
				]);

			}

			// Salvar documentos anteriores (para subcontratação/redespacho)
			foreach($docsAnteriores as $doc){
				CteDocumentoAnterior::create([
					'cte_id' => $result->id,
					'chave_cte' => $doc['chave'],
					'cnpj_emitente' => $doc['cnpj'],
					'ie_emitente' => $doc['ie'],
					'uf_emitente' => $doc['uf']
				]);
			}

			$output = [
				'success' => 1,
				'msg' => "Cte Gerada!!"
			];
		}catch(\Exception $e){
			$output = [
				'success' => false,
				'msg' => "Erro ao gerar CTe"
			];
		}

		return redirect('cte')->with('status', $output);
	}

	private function parseDate($date, $plusDay = false){
		if($plusDay == false)
			return date('Y-m-d', strtotime(str_replace("/", "-", $date)));
		else
			return date('Y-m-d', strtotime("+1 day",strtotime(str_replace("/", "-", $date))));
	}

	public function destroy($id){
		if (!auth()->user()->can('user.delete')) {
			abort(403, 'Unauthorized action.');
		}

		try {
			$business_id = request()->session()->get('user.business_id');

			$cte = Cte::where('business_id', $business_id)
			->where('id', $id)->delete();

			$output = [
				'success' => true,
				'msg' => 'Registro removido'
			];
		} catch (\Exception $e) {
			\Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

			$output = [
				'success' => false,
				'msg' => __("messages.something_went_wrong")
			];
		}

		return redirect('cte')->with('status', $output);
	}

	public function gerar($id){
		$business_id = request()->session()->get('user.business_id');

		$cte = Cte::where('business_id', $business_id)
		->where('id', $id)
		->first();

		if(!$cte){
			abort(403, 'Unauthorized action.');
		}


		if($cte->cte_numero > 0){
			return redirect('/cte/ver/'.$cte->id);
		}

		$cte->load(['original', 'substitutos']);

		return view('cte.gerar')
		->with(compact('cte'));

	}

	public function renderizar($id){
		$business_id = request()->session()->get('user.business_id');

		$cte = Cte::where('business_id', $business_id)
		->where('id', $id)
		->first();

		if(!$cte){
			abort(403, 'Unauthorized action.');
		}

		// $config = Business::find($business_id);
		$config = Business::getConfigCte($business_id, $cte);

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		if($config->certificado == ""){
			return redirect('/cte')
			->with('status', [
				'success' => 0,
				'msg' => 'Cadastre o certificado do emitente.'
			]);
		}
		$cte_service = new CTeService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$config->ambiente,
			"razaosocial" => $config->razao_social,
			"siglaUF" => $config->cidade->uf,
			"cnpj" => $cnpj,
			"schemes" => "PL_CTe_400",
			"versao" => '4.00',
			"proxyConf" => [
				"proxyIp" => "",
				"proxyPort" => "",
				"proxyUser" => "",
				"proxyPass" => ""
			]
		], $config);

		try{
			$doc = $cte_service->gerarCTe($cte);
			if(!isset($doc['erros_xml'])){
				$xml = $doc['xml'];
				$dacte = new Dacte($xml);
				// $dacte->monta();
				$pdf = $dacte->render();
				return response($pdf)
				->header('Content-Type', 'application/pdf');

			}else{
				foreach($doc['erros_xml'] as $e){
					echo $e . "<br>";
				}
			}

		}catch(\Excption $e){
			echo $e->getMessage();
		}
	}

	public function gerarXml($id){
		$business_id = request()->session()->get('user.business_id');

		$cte = Cte::where('business_id', $business_id)
		->where('id', $id)
		->first();

		if(!$cte){
			abort(403, 'Unauthorized action.');
		}

		// $config = Business::find($business_id);
		$config = Business::getConfigCte($business_id, $cte);

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		if($config->certificado == ""){
			return redirect('/cte')
			->with('status', [
				'success' => 0,
				'msg' => 'Cadastre o certificado do emitente.'
			]);
		}

		$cte_service = new CTeService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$config->ambiente,
			"razaosocial" => $config->razao_social,
			"siglaUF" => $config->cidade->uf,
			"cnpj" => $cnpj,
			"schemes" => "PL_CTe_400",
			"versao" => '4.00',
			"proxyConf" => [
				"proxyIp" => "",
				"proxyPort" => "",
				"proxyUser" => "",
				"proxyPass" => ""
			]
		], $config);

		try{
			$doc = $cte_service->gerarCTe($cte);
			if(!isset($doc['erros_xml'])){
				$xml = $doc['xml'];
				// $dacte = new Dacte($xml);
				// $dacte->monta();
				// $pdf = $dacte->render();
				return response($xml)
				->header('Content-Type', 'application/xml');

			}else{
				foreach($doc['erros_xml'] as $e){
					echo $e . "<br>";
				}
			}

		}catch(\Excption $e){
			echo $e->getMessage();
		}
	}

	public function transmitir(Request $request){
		$business_id = request()->session()->get('user.business_id');

		$cte = Cte::where('business_id', $business_id)
		->where('id', $request->id)
		->first();

		if(!$cte){
			return response()->json('CT-e não encontrado para esta empresa/sessão. Atualize a página e tente novamente.', 403);
		}

		$tpCte = (int)($cte->tp_cte ?? 0);
		if(!in_array($tpCte, [0, 1, 3], true)){
			$tpCte = 0;
		}

		if($tpCte == 0){
			$chavesNfe = preg_split('/[;,]/', $cte->chave_nfe ?? '', -1, PREG_SPLIT_NO_EMPTY);
			foreach($chavesNfe as $chaveNfe){
				$chaveNfe = trim($chaveNfe);
				if(strlen($chaveNfe) != 44){
					continue;
				}

				$cteDuplicado = Cte::where('business_id', $business_id)
				->where('id', '!=', $cte->id)
				->where('estado', 'aprovado')
				->where('cte_numero', '>', 0)
				->where('chave_nfe', 'like', '%' . $chaveNfe . '%')
				->first();

				if($cteDuplicado){
					return response()->json(
						'Esta NF-e já está vinculada ao CT-e autorizado número ' . $cteDuplicado->cte_numero . '. Chave NF-e: ' . $chaveNfe,
						402
					);
				}
			}
		}

		// $config = Business::find($business_id);
		$config = Business::getConfigCte($business_id, $cte);

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		$chaveVinculo = $tpCte == 3 ? $cte->chave_original : ($tpCte == 1 ? $cte->chave_complementada : null);
		if($tpCte != 0){
			$erroVinculo = $this->validarVinculoSintatico($cte, $tpCte, $chaveVinculo, $cnpj);
			if($erroVinculo){
				return response()->json($erroVinculo, 422);
			}
		}

		if($tpCte == 3){
			$original559 = null;
			if(!empty($cte->cte_original_id)){
				$original559 = Cte::where('business_id', $business_id)->where('id', $cte->cte_original_id)->first();
			}
			if(!$original559){
				$chave559 = $cte->chave_original ? preg_replace('/\D/', '', (string)$cte->chave_original) : '';
				if($chave559 !== ''){
					$q559 = Cte::where('business_id', $business_id)->where('chave', $chave559);
					if(!empty($cte->id)){
						$q559->where('id', '!=', $cte->id);
					}
					$original559 = $q559->first();
				}
			}
			if($original559){
				$ufIniNovo = $cte->municipioInicio ? $cte->municipioInicio->uf : null;
				$ufIniOrig = $original559->municipioInicio ? $original559->municipioInicio->uf : null;
				if($ufIniNovo && $ufIniOrig && $ufIniNovo != $ufIniOrig){
					return response()->json('UF de início do substituto (' . $ufIniNovo . ') difere da UF de início do CT-e original (' . $ufIniOrig . '). A SEFAZ exige UFs iguais (rejeição 559).', 422);
				}
			}
		}

		$this->aplicarDerivacaoToma($cte, $business_id);
		if($cte->isDirty('ind_altera_toma')){
			$cte->save();
		}

		$cte_service = new CTeService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$config->ambiente,
			"razaosocial" => $config->razao_social,
			"siglaUF" => $config->cidade->uf,
			"cnpj" => $cnpj,
			"schemes" => "PL_CTe_400",
			"versao" => '4.00',
			"proxyConf" => [
				"proxyIp" => "",
				"proxyPort" => "",
				"proxyUser" => "",
				"proxyPass" => ""
			]
		], $config);


		if($tpCte != 0){
			$erroVinculo = $this->validarVinculoSefaz($chaveVinculo, $cte_service);
			if($erroVinculo){
				return response()->json($erroVinculo, 422);
			}
		}

		$doc = $cte_service->gerarCTe($cte);
		if (!isset($doc['erros_xml'])) {
			// return response()->json($signed, 200);

			$signed = $cte_service->sign($doc['xml']);
			// return response()->json($signed, 200);
			$resultado = $cte_service->transmitir($signed, $doc['chave'], $cnpj);
			
			if ($resultado['erro'] == 0) {
				$cte->chave = $doc['chave'];
				$cte->estado = 'aprovado';

				if ($config->ambiente == 2) {
					$config->ultimo_numero_cte = $doc['nCte'];
				} else {
					$config->ultimo_numero_cte = $doc['nCte'];
				}
				$cte->cte_numero = $doc['nCte'];
				$cte->recibo = $resultado['success'];
				if($tpCte != 0){
					$chaveOrigem = $chaveVinculo ? preg_replace('/\D/', '', (string) $chaveVinculo) : '';
					$original = null;
					if($chaveOrigem !== ''){
						$original = Cte::where('business_id', $business_id)
						->where('chave', $chaveOrigem)
						->where('id', '!=', $cte->id)
						->first();
					}
					if(!$original && $cte->cte_original_id){
						$original = Cte::where('business_id', $business_id)
						->where('id', $cte->cte_original_id)
						->where('id', '!=', $cte->id)
						->first();
					}
					if($original){
						$cte->cte_original_id = $original->id;
						if($tpCte == 3 && !$cte->chave_original && $original->chave){
							$cte->chave_original = $original->chave;
						}
						if($tpCte == 1 && !$cte->chave_complementada && $original->chave){
							$cte->chave_complementada = $original->chave;
						}
					}
				}
				$cte->save();
				$config->save();
				$data = [
					'recibo' => $resultado['success'],
					'chave' => $cte->chave
				];
				return response()->json($data, 200);
			} else {
				$error = $resultado['error'];

				if (isset($error['protCTe'])) {
					$motivo = $error['protCTe']['infProt']['xMotivo'];
					$cStat = $error['protCTe']['infProt']['cStat'];
					$cte->motivo_rejeicao = substr("[$cStat] $motivo", 0, 200);
				}
				$cte->chave = $doc['chave'];
				$cte->estado = 'rejeitado';
				$cte->save();

					if (isset($error['protCTe'])) {
						return response()->json("[$cStat] $motivo", 402);
					} else {
						return response()->json($error, 402);
					}
				}
			} else {
				return response()->json($doc['erros_xml'][0], 407);
			}
	}

	public function imprimir($id){

		$business_id = request()->session()->get('user.business_id');
		$cte = Cte::where('business_id', $business_id)
		->where('id', $id)
		->first();

		// $business = Business::find($business_id);
		$business = Business::getConfigCte($business_id, $cte);
		$cnpj = str_replace(".", "", $business->cnpj);
		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);

		if(!$cte){
			abort(403, 'Unauthorized action.');
		}

		$logo = '';
		if($business->logo){
			$logo = 'data://text/plain;base64,'. base64_encode(file_get_contents(
				public_path('uploads/business_logos/' . $business->logo)));
		}

		try {
			if(file_exists(public_path('xml_cte/'.$cnpj.'/'.$cte->chave.'.xml'))){
				$xml = file_get_contents(public_path('xml_cte/'.$cnpj.'/'.$cte->chave.'.xml'));

				$dacte = new Dacte($xml, $cte->estado);

				// $dacte->creditsIntegratorFooter('WEBNFe Sistemas - http://www.webenf.com.br');
				// $dacte->monta();
				$pdf = $dacte->render($logo);

				return response($pdf)
				->header('Content-Type', 'application/pdf');
			}else{
				return redirect('/cte')
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
		$cte = Cte::where('business_id', $business_id)
		->where('id', $id)
		->first();

		// $business = Business::find($business_id);
		$business = Business::getConfigCte($business_id, $cte);

		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);

		if(!$cte){
			abort(403, 'Unauthorized action.');
		}

		$logo = '';
		if($business->logo){
			$logo = 'data://text/plain;base64,'. base64_encode(file_get_contents(
				public_path('uploads/business_logos/' . $business->logo)));
		}

		try {
			if(file_exists(public_path('xml_cte_cancelada/'.$cnpj.'/'.$cte->chave.'.xml'))){
				$xml = file_get_contents(public_path('xml_cte_cancelada/'.$cnpj.'/'.$cte->chave.'.xml'));

				$dadosEmitente = $this->getEmitente($business);


				$dacte = new Daevento($xml, $dadosEmitente);

				$daevento = new Daevento($xml, $dadosEmitente);
				$daevento->debugMode(true);
				$pdf = $daevento->render($logo);

				return response($pdf)
				->header('Content-Type', 'application/pdf');
			}else{
				return redirect('/cte')
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
		$cte = Cte::where('business_id', $business_id)
		->where('id', $id)
		->first();

		if(!$cte){
			abort(403, 'Unauthorized action.');
		}

		$business = Business::getConfigCte($business_id, $cte);
		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);
		$chave = preg_replace('/[^0-9]/', '', $cte->chave);

		$logo = '';
		if($business->logo){
			$logo = 'data://text/plain;base64,'. base64_encode(file_get_contents(
				public_path('uploads/business_logos/' . $business->logo)));
		}

		try {
			if(file_exists(public_path('xml_cte_correcao/'.$cnpj.'/'.$chave.'.xml'))){
				$xml = file_get_contents(public_path('xml_cte_correcao/'.$cnpj.'/'.$chave.'.xml'));

				$dadosEmitente = $this->getEmitente($business);

				$daevento = new Daevento($xml, $dadosEmitente);
				$daevento->debugMode(true);
				$pdf = $daevento->render($logo);

				return response($pdf)
				->header('Content-Type', 'application/pdf');
			}else{
				return redirect('/cte')
				->with('status', [
					'success' => 0,
					'msg' => 'Arquivo não encontrado!!'
				]);
			}
		} catch (\InvalidArgumentException $e) {
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


	public function ver($id){

		$business_id = request()->session()->get('user.business_id');
		$cte = Cte::where('business_id', $business_id)
		->where('id', $id)
		->first();

		if(!$cte){
			abort(403, 'Unauthorized action.');
		}

		// $business = Business::find($business_id);
		$business = Business::getConfigCte($business_id, $cte);

		if($cte->cte_numero == 0){
			return redirect('/cte/gerar/'.$cte->id);
		}

		$cte->load(['original', 'substitutos']);

		return view('cte.ver')
		->with(compact('cte', 'business'));
	}

	public function buscarOriginal(Request $request){
		$business_id = request()->session()->get('user.business_id');
		$q = trim((string) $request->get('q', ''));
		$exclude = (int) $request->get('exclude_id', 0);
		$digitos = preg_replace('/\D/', '', $q);

		$ctes = Cte::where('business_id', $business_id)
		->where('cte_numero', '>', 0)
		->where('estado', 'aprovado')
		->whereNotNull('chave')
		->where('chave', '!=', '');

		if($exclude > 0){
			$ctes->where('id', '!=', $exclude);
		}

		if($digitos !== ''){
			$ctes->where(function($w) use ($digitos){
				$w->where('chave', 'like', '%' . $digitos . '%');
				if(ctype_digit($digitos) && strlen($digitos) <= 9){
					$w->orWhere('cte_numero', (int) $digitos);
				}
			});
		}

		$ctes = $ctes->with('destinatario')
		->orderBy('id', 'desc')
		->limit(20)
		->get();

		$lista = [];
		foreach($ctes as $c){
			$lista[] = [
				'id' => $c->id,
				'numero' => $c->cte_numero,
				'chave' => $c->chave,
				'destinatario' => $c->destinatario ? $c->destinatario->name : '',
				'data' => $c->created_at ? $c->created_at->format('d/m/Y') : '',
			];
		}

		return response()->json($lista);
	}

	public function dadosOriginal($id){
		$business_id = request()->session()->get('user.business_id');
		$c = Cte::where('business_id', $business_id)
		->where('id', $id)
		->with(['medidas', 'componentes', 'documentosAnteriores'])
		->first();
		if(!$c){
			\Log::warning('cte.dadosOriginal 404', ['business_id' => $business_id, 'id' => $id]);
			return response()->json('CT-e original não encontrado para esta empresa/sessão.', 404);
		}
		$medidas = [];
		foreach($c->medidas as $m){
			$medidas[] = [
				'unidade_medida' => (string)$m->cod_unidade,
				'tipo_medida' => (string)$m->tipo_medida,
				'quantidade' => (string)$m->quantidade_carga,
			];
		}
		$componentes = [];
		foreach($c->componentes as $comp){
			$componentes[] = ['nome' => (string)$comp->nome, 'valor' => (string)$comp->valor];
		}
		$anteriores = [];
		foreach($c->documentosAnteriores as $d){
			$anteriores[] = [
				'chave' => $d->chave_cte,
				'cnpj' => $d->cnpj_emitente,
				'ie' => $d->ie_emitente,
				'uf' => $d->uf_emitente,
			];
		}
		$dataEntrega = '';
		if(!empty($c->data_previsata_entrega)){
			try{
				$dataEntrega = \Carbon\Carbon::parse($c->data_previsata_entrega)->format('d/m/Y');
			}catch(\Exception $e){
				$dataEntrega = '';
			}
		}
		return response()->json([
			'id' => $c->id,
			'chave' => $c->chave,
			'remetente_id' => $c->remetente_id,
			'destinatario_id' => $c->destinatario_id,
			'recebedor_id' => $c->recebedor_id,
			'expedidor_id' => $c->expedidor_id,
			'natureza_id' => $c->natureza_id,
			'globalizado' => $c->globalizado,
			'cst' => $c->cst,
			'perc_icms' => (string)$c->perc_icms,
			'tomador' => $c->tomador,
			'tipo_servico' => $c->tipo_servico,
			'tomador_outros_id' => $c->tomador_outros_id,
			'cidade_envio' => $c->municipio_envio,
			'cidade_inicio' => $c->municipio_inicio,
			'cidade_fim' => $c->municipio_fim,
			'rua_tomador' => $c->logradouro_tomador,
			'numero_tomador' => $c->numero_tomador,
			'bairro_tomador' => $c->bairro_tomador,
			'cep_tomador' => $c->cep_tomador,
			'cidade_tomador' => $c->municipio_tomador,
			'valor_transporte' => (string)$c->valor_transporte,
			'valor_receber' => (string)$c->valor_receber,
			'valor_carga' => (string)$c->valor_carga,
			'prod_predominante' => $c->produto_predominante,
			'observacao' => $c->observacao,
			'data_previsata_entrega' => $dataEntrega,
			'retira' => $c->retira,
			'detalhes_retira' => $c->detalhes_retira,
			'modal_transp' => $c->modal,
			'veiculo_id' => $c->veiculo_id,
			'tpDoc' => $c->tpDoc,
			'descOutros' => $c->descOutros,
			'nDoc' => (string)$c->nDoc,
			'vDocFisc' => (string)$c->vDocFisc,
			'chave_nfe' => $c->chave_nfe,
			'medidas' => $medidas,
			'componentes' => $componentes,
			'documentos_anteriores' => $anteriores,
		]);
	}

	public function baixarXml($id){
		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		$business_id = request()->session()->get('user.business_id');
		$cte = Cte::where('business_id', $business_id)
		->where('id', $id)
		->first();

		// $business = Business::find($business_id);
		$business = Business::getConfigCte($business_id, $cte);

		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);

		if(!$cte){
			abort(403, 'Unauthorized action.');
		}
		if(file_exists(public_path('xml_cte/'.$cnpj.'/'.$cte->chave.'.xml'))){
			return response()->download(public_path('xml_cte/'.$cnpj.'/'.$cte->chave.'.xml'));
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	}

	public function baixarXmlCancelado($id){
		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		$business_id = request()->session()->get('user.business_id');
		$cte = Cte::where('business_id', $business_id)
		->where('id', $id)
		->first();

		// $business = Business::find($business_id);
		$business = Business::getConfigCte($business_id, $cte);

		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);

		if(!$cte){
			abort(403, 'Unauthorized action.');
		}
		
		if(file_exists(public_path('xml_cte_cancelada/'.$cnpj.'/'.$cte->chave.'.xml'))){
			return response()->download(public_path('xml_cte_cancelada/'.$cnpj.'/'.$cte->chave.'.xml'));
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	}

	public function baixarXmlCorrecao($id){
		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		$business_id = request()->session()->get('user.business_id');
		$cte = Cte::where('business_id', $business_id)
		->where('id', $id)
		->first();

		if(!$cte){
			abort(403, 'Unauthorized action.');
		}

		$business = Business::getConfigCte($business_id, $cte);
		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);
		$chave = preg_replace('/[^0-9]/', '', $cte->chave);

		if(file_exists(public_path('xml_cte_correcao/'.$cnpj.'/'.$chave.'.xml'))){
			return response()->download(public_path('xml_cte_correcao/'.$cnpj.'/'.$chave.'.xml'));
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	}

	public function corrigir(Request $request){

		$dados = $request->validate([
			'id' => 'required|integer',
			'justificativa' => 'required|string|max:500',
		]);

		$business_id = request()->session()->get('user.business_id');
		$cte = Cte::where('business_id', $business_id)
		->where('id', $dados['id'])
		->first();

		if(!$cte){
			return response()->json([
				'erro' => true,
				'data' => 'CT-e não encontrado para esta empresa/sessão. Atualize a página e tente novamente.',
				'status' => 403
			], 403);
		}

		if(strtolower((string) $cte->estado) != 'aprovado'){
			return response()->json([
				'erro' => true,
				'data' => 'A carta de correção só pode ser emitida para CT-e aprovado.',
				'status' => 422
			], 422);
		}

		$chave = preg_replace('/[^0-9]/', '', (string) $cte->chave);
		if((int) $cte->cte_numero <= 0 || strlen($chave) != 44){
			return response()->json([
				'erro' => true,
				'data' => 'CT-e sem número ou chave válida para correção.',
				'status' => 422
			], 422);
		}

		$cte->chave = $chave;

		$business = Business::getConfigCte($business_id, $cte);

		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);

		$cte_service = new CTeService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$business->ambiente,
			"razaosocial" => $business->razao_social,
			"siglaUF" => $business->cidade->uf,
			"cnpj" => $cnpj,
			"schemes" => "PL_CTe_400",
			"versao" => '4.00',
			"proxyConf" => [
				"proxyIp" => "",
				"proxyPort" => "",
				"proxyUser" => "",
				"proxyPass" => ""
			]
		], $business);

		$correcao = [
			'grupoAlterado' => 'compl',
			'campoAlterado' => 'xObs',
			'valorAlterado' => trim($dados['justificativa']),
		];

		$doc = $cte_service->cartaCorrecao($cte, [$correcao], $cnpj);
		if(!is_array($doc)){
			$doc = [
				'erro' => true,
				'data' => $doc,
				'status' => 402
			];
		}

		if(!isset($doc['erro'])){
			return response()->json($doc, 200);

		}else{
			return response()->json($doc, $doc['status'] ?? 402);
		}

	}

	public function cancelar(Request $request){

		$business_id = request()->session()->get('user.business_id');
		$cte = Cte::where('business_id', $business_id)
		->where('id', $request->id)
		->first();

		if(!$cte){
			return response()->json('CT-e não encontrado para esta empresa/sessão. Atualize a página e tente novamente.', 403);
		}

		// $config = Business::find($business_id);
		$config = Business::getConfigCte($business_id, $cte);

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		$cte_service = new CTeService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$config->ambiente,
			"razaosocial" => $config->razao_social,
			"siglaUF" => $config->cidade->uf,
			"cnpj" => $cnpj,
			"schemes" => "PL_CTe_400",
			"versao" => '4.00',
			"proxyConf" => [
				"proxyIp" => "",
				"proxyPort" => "",
				"proxyUser" => "",
				"proxyPass" => ""
			]
		], $config);


		$doc = $cte_service->cancelar($cte, $request->justificativa, $cnpj);
		if(!isset($doc['erro'])){

			$cte->estado = 'cancelado';
			$cte->save();
			return response()->json($doc, 200);

		}else{
			return response()->json($doc, $doc['status']);
		}

	}


	//Arquivs XML

	public function xmls(){
		$business_id = request()->session()->get('user.business_id');
		$aprovadas = [];
		$canceladas = [];

		$business = Business::find($business_id);

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

		return view('cte.lista')
		->with(compact('canceladas', 'aprovadas', 'business'))
		->with('bl_attributes' , $bl_attributes)
		->with('default_location' , $default_location)
		->with('select_location_id' , null)
		->with('business_locations' , $business_locations);
	}

	public function filtroXml(Request $request){
		$data_inicio = str_replace("/", "-", $request->data_inicio);
		$data_final = str_replace("/", "-", $request->data_final);
		$select_location_id = $request->select_location_id;

		$data_inicio_convert =  \Carbon\Carbon::parse($data_inicio)->format('Y-m-d');
		$data_final_convert =  \Carbon\Carbon::parse($data_final)->format('Y-m-d');
		$data_final_convert = date('Y-m-d', strtotime($data_final_convert. ' + 1 days'));

		$business_id = request()->session()->get('user.business_id');

		$aprovadas = Cte::where('business_id', $business_id)
		->whereBetween('created_at', [
			$data_inicio_convert, 
			$data_final_convert])
		->where('cte_numero', '>', 0)
		->where('estado', 'aprovado')
		->orderBy('id', 'desc');

		if($select_location_id){
			$aprovadas->where('location_id', $select_location_id);
		}
		$aprovadas = $aprovadas->get();
		$aprovadas->load('substitutos');

		$canceladas = Cte::where('business_id', $business_id)
		->whereBetween('created_at', [
			$data_inicio_convert, 
			$data_final_convert])
		->where('cte_numero', '>', 0)
		->where('estado', 'cancelado')
		->orderBy('id', 'desc');

		if($select_location_id){
			$canceladas->where('location_id', $select_location_id);
		}
		$canceladas = $canceladas->get();

		$business = Business::find($business_id);
		if($select_location_id){
			$config = BusinessLocation::findOrFail($select_location_id);
			if($config->cnpj != '00.000.000/0000-00' && $config->cnpj != '00000000000000'){
				$business = $config;
			}
		}

		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);

		$msg = [];

		if(sizeof($aprovadas) > 0){
			try{
				$zip_file = public_path('xml_cte/'.$cnpj.'/'.'xml.zip');
				$zip = new \ZipArchive();
				$zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
				$substituidosIgnorados = 0;
				foreach($aprovadas as $n){

					if($n->substitutos && $n->substitutos->where('estado', 'aprovado')->isNotEmpty()){
						$substituidosIgnorados++;
						continue;
					}
					if(file_exists(public_path('xml_cte/'.$cnpj.'/'.$n->chave.'.xml'))){
						$zip->addFile(public_path('xml_cte/'.$cnpj.'/'.$n->chave.'.xml'), $n->chave . '.xml');
					}

				}
				$zip->close();
				if($substituidosIgnorados > 0){
					array_push($msg, $substituidosIgnorados . " CT-e(s) substituído(s) excluído(s) do ZIP (vale o substituto).");
				}
			}catch(\Exception $e){
				array_push($msg, "Erro ao gerar arquivo de XML!!");
			}

		}

		if(sizeof($canceladas) > 0){

			try{
				$zip_file = public_path('xml_cte_cancelada/'.$cnpj.'/'.'xml_cancelado.zip');
				$zip = new \ZipArchive();
				$zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

				foreach($canceladas as $n){

					if(file_exists(public_path('xml_cte_cancelada/'.$cnpj.'/'.$n->chave.'.xml'))){
						$zip->addFile(public_path('xml_cte_cancelada/'.$cnpj.'/'.$n->chave.'.xml'), $n->chave . '.xml');
					}

				}
				$zip->close();
			}catch(\Exception $e){
				array_push($msg, "Erro ao gerar arquivo de XML de Cancelamento!!");
			}

		}

		$business = Business::find($business_id);

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

		return view('cte.lista')
		->with(compact('canceladas', 'aprovadas', 'business', 'data_inicio', 'data_final', 'msg'))
		->with('bl_attributes' , $bl_attributes)
		->with('default_location' , $default_location)
		->with('select_location_id' , $select_location_id)
		->with('business_locations' , $business_locations);
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

		if(file_exists(public_path('xml_cte/'.$cnpj.'/'.'xml.zip'))){
			return response()->download(public_path('xml_cte/'.$cnpj.'/'.'xml.zip'));
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	}

	public function baixarZipXmlReprovado(){
		$business_id = request()->session()->get('user.business_id');
		$business = Business::find($business_id);
		if($location_id){
			$config = BusinessLocation::findOrFail($location_id);

			if($config->cnpj != '00.000.000/0000-00' && $config->cnpj != '00000000000000'){
				$business = $config;
			}
		}
		$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);
		if(file_exists(public_path('xml_cte_cancelada/'.$cnpj.'/'.'xml_cancelado.zip'))){
			return response()->download(public_path('xml_cte_cancelada/'.$cnpj.'/'.'xml_cancelado.zip'));
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
		$cte = Cte::where('business_id', $business_id)
		->where('id', $request->id)
		->first();

		if(!$cte){
			return response()->json('CT-e não encontrado para esta empresa/sessão. Atualize a página e tente novamente.', 403);
		}

		// $config = Business::find($business_id);
		$config = Business::getConfigCte($business_id, $cte);

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		$cte_service = new CTeService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$config->ambiente,
			"razaosocial" => $config->razao_social,
			"siglaUF" => $config->cidade->uf,
			"cnpj" => $cnpj,
			"schemes" => "PL_CTe_400",
			"versao" => '4.00',
			"proxyConf" => [
				"proxyIp" => "",
				"proxyPort" => "",
				"proxyUser" => "",
				"proxyPass" => ""
			]
		], $config);


			try{
				$res = $cte_service->consultar($cte);
				if(isset($res['erro'])){
					return response()->json($res, $res['status']);
				}
				return response()->json($res, 200);
			}catch(\Exception $e){
				return response()->json($e->getMessage(), 402);

			}
	}

	public function importarXml(Request $request){

		if ($request->hasFile('file')){
			$arquivo = $request->hasFile('file');
			$xml = simplexml_load_file($request->file);

			// NFe pode vir com wrapper <nfeProc> (assinada/processada) ou como <NFe> direto (não assinada)
			if ($xml && $xml->NFe && $xml->NFe->infNFe) {
				$infNFe = $xml->NFe->infNFe;
			} elseif ($xml && $xml->getName() === 'NFe' && $xml->infNFe) {
				$infNFe = $xml->infNFe;
			} else {
				$output = [
					'success' => 0,
					'msg' => 'Não foi possível ler este XML!! Verifique se o arquivo é uma NFe válida.'
				];
				return redirect()->back()->with('status', $output);
			}

			$business_id = request()->session()->get('user.business_id');

			$cidade = City::getCidadeCod($infNFe->emit->enderEmit->cMun);

			$dadosEmitente = [
				'type' => 'customer',
				'supplier_business_name' => '',
				'cpf_cnpj' => $infNFe->emit->CPF ?? $infNFe->emit->CNPJ,
				'ie_rg' => $infNFe->emit->IE,
				'contribuinte' => 1,
				'consumidor_final' => 1,
				'rua' => $infNFe->emit->enderEmit->xLgr,
				'numero' => $infNFe->emit->enderEmit->nro,
				'bairro' => $infNFe->emit->enderEmit->xBairro,
				'cep' => $infNFe->emit->enderEmit->CEP,
				'name' => $infNFe->emit->xNome,
				'tax_number' => '',
				'pay_term_number' => '',
				'pay_term_type' => '',
				'mobile' => '',
				'landline' => '',
				'alternate_number' => '',
				'city' => '',
				'state' => '',
				'country' =>  '',
				'landmark' => '',
				'customer_group_id' => '',
				'custom_field1' => '',
				'custom_field2' => '',
				'custom_field3' => '',
				'custom_field4' => '',
				'email' => '',
				'city_id' => $cidade->id,
				'cod_pais' => '1058',
				'id_estrangeiro' => '',
				'business_id' => $business_id,
				'created_by' => $request->session()->get('user.id')
			];

			$emitente = $this->verificaClienteCadastrado($dadosEmitente);

			$cidade = City::getCidadeCod($infNFe->dest->enderDest->cMun);

			$dadosDestinatario = [
				'type' => 'customer',
				'supplier_business_name' => '',
				'cpf_cnpj' => $infNFe->dest->CPF ?? $infNFe->dest->CNPJ,
				'ie_rg' => $infNFe->dest->IE,
				'contribuinte' => 1,
				'consumidor_final' => 1,
				'rua' => $infNFe->dest->enderDest->xLgr,
				'numero' => $infNFe->dest->enderDest->nro,
				'bairro' => $infNFe->dest->enderDest->xBairro,
				'cep' => $infNFe->dest->enderDest->CEP,
				'name' => $infNFe->dest->xNome,
				'tax_number' => '',
				'pay_term_number' => '',
				'pay_term_type' => '',
				'mobile' => '',
				'landline' => '',
				'alternate_number' => '',
				'city' => '',
				'state' => '',
				'country' =>  '',
				'landmark' => '',
				'customer_group_id' => '',
				'custom_field1' => '',
				'custom_field2' => '',
				'custom_field3' => '',
				'custom_field4' => '',
				'email' => '',
				'city_id' => $cidade->id,
				'cod_pais' => '1058',
				'id_estrangeiro' => '',
				'business_id' => $business_id,
				'created_by' => $request->session()->get('user.id')
			];

			$destinatario = $this->verificaClienteCadastrado($dadosDestinatario);

			$chave = substr($infNFe->attributes()->Id, 3, 44);

			$somaQuantidade = 0;
			foreach($infNFe->det as $item) {
				$somaQuantidade += $item->prod->qCom;
			}

			$unidade = $infNFe->det[0]->prod->uCom;
			if($unidade == 'M2'){
				$unidade = '04';
			}else if($unidade == 'M3'){
				$unidade = '00';
			}else if($unidade == 'KG'){
				$unidade = '01';
			}else if($unidade == 'UNID'){
				$unidade = '03';
			}else if($unidade == 'TON'){
				$unidade = '02';
			}

			$dadosDaNFe = [
				'remetente' => $emitente->id,
				'destinatario' => $destinatario->id,
				'chave' => $chave,
				'produto_predominante' => $infNFe->det[0]->prod->xProd,
				'unidade' => $unidade,
				'valor_carga' => $infNFe->total->ICMSTot->vProd,
				'munipio_envio' => $emitente->cidade->id . " - " . $emitente->cidade->nome . "(" .$emitente->cidade->uf . ")",
				'munipio_final' => $destinatario->cidade->id . " - " . $destinatario->cidade->nome . "(" .$destinatario->cidade->uf . ")",
				'componente' => 'Transporte',
				'valor_frete' => $infNFe->total->ICMSTot->vFrete,
				'quantidade' => number_format($somaQuantidade, 4),
				'data_entrega' => date('d/m/Y')
			];

			// echo "<pre>";
			// print_r($dadosDaNFe);
			// echo "</pre>";

			$roles  = $this->getRolesArray($business_id);
			$username_ext = $this->getUsernameExtension();

			$lastCte = Cte::lastCTeAux($business_id);
			$unidadesMedida = Cte::unidadesMedida();
			$tiposMedida = Cte::tiposMedida();
			$tiposTomador = Cte::tiposTomador();
			$tiposServico = Cte::tiposServico();
			$naturezas = $this->prepareNaturezas();
			$modals = Cte::modals();
			$veiculos = $this->prepareVeiculos();
			$clientesAux = Contact::where('business_id', $business_id)->get();

			foreach($clientesAux as $c){
				$c->cidade;
			}

			$clientes =  $this->prepareClientes();
			$cidades =  $this->prepareCities();

			$business_locations = BusinessLocation::forDropdown($business_id, false, true);
			$bl_attributes = $business_locations['attributes'];
			$business_locations = $business_locations['locations'];

			$default_location = null;
			if (count($business_locations) == 1) {
				foreach ($business_locations as $id => $name) {
					$default_location = BusinessLocation::findOrFail($id);
				}
			}

			$chave = substr($infNFe->attributes()->Id, 3, 44);

			$unidade = $infNFe->det[0]->prod->uCom;
			if($unidade == 'M2'){
				$unidade = '04';
			}else if($unidade == 'M3'){
				$unidade = '00';
			}else if($unidade == 'KG'){
				$unidade = '01';
			}else if($unidade == 'UNID'){
				$unidade = '03';
			}else if($unidade == 'TON'){
				$unidade = '02';
			}
			
			$dadosDaNFe = [
				'remetente' => $emitente->id,
				'destinatario' => $destinatario->id,
				'chave' => $chave,
				'produto_predominante' => $infNFe->det[0]->prod->xProd,
				'unidade' => $unidade,
				'valor_carga' => $infNFe->total->ICMSTot->vProd,
				'munipio_envio' => $emitente->cidade->id,
				'munipio_final' => $destinatario->cidade->id,
				'componente' => 'Transporte',
				'valor_frete' => $infNFe->total->ICMSTot->vFrete,
				'quantidade' => number_format($somaQuantidade, 4),
				'data_entrega' => date('d/m/Y')
			];

			return view('cte.register_xml')
			->with(compact('roles', 'username_ext', 'cidades', 'clientesAux', 'clientes', 'lastCte', 'unidadesMedida', 'tiposMedida', 'tiposTomador', 'tiposServico', 'naturezas', 'modals', 'veiculos', 'dadosDaNFe'))
			->with('bl_attributes' , $bl_attributes)
			->with('default_location' , $default_location)
			->with('business_locations' , $business_locations);

		}

	}

	private function verificaClienteCadastrado($cliente){

		$query = Contact::where('business_id', $cliente['business_id'])
			->where('cpf_cnpj', $cliente['cpf_cnpj']);

		// Mesmo CPF/CNPJ pode ter IEs diferentes em UFs distintas (produtor rural com fazendas em estados diferentes)
		if(!empty($cliente['ie_rg'])){
			$query->where('ie_rg', $cliente['ie_rg']);
		}

		$cli = $query->first();

		if($cli == null){
			return Contact::create($cliente);
		}
		return $cli;
	}

}
