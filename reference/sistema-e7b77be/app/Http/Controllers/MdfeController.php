<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\Mdfe;
use App\Models\Veiculo;
use App\Utils\ModuleUtil;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\Facades\DataTables;
use App\Models\System;
use App\Models\Contact;
use App\Models\City;
use App\Models\BusinessLocation;
use App\Models\Business;
use App\Models\Percurso;
use App\Models\ValePedagio;
use App\Models\Ciot;
use App\Models\MunicipioCarregamento;
use App\Models\InfoDescarga;
use App\Models\NFeDescarga;
use App\Models\CTeDescarga;
use App\Models\LacreUnidadeCarga;
use App\Models\LacreTransporte;
use App\Models\UnidadeCarga;
use App\Services\MDFeService;
use NFePHP\DA\MDFe\Damdfe;

class MdfeController extends Controller
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
			$user_id = request()->session()->get('user.id');
			$mdfes = Mdfe::where('business_id', $business_id)
			->select(['id', 'uf_inicio', 'uf_fim', 'data_inicio_viagem', 'condutor_nome', 'valor_carga', 'estado', 'quantidade_carga', 'veiculo_tracao_id'])
			->orderBy('id', 'desc');


			return Datatables::of($mdfes)

			->addColumn('action', function ($row) {
				$t = Mdfe::find($row->id);
				$html = '';

				if($t->mdfe_numero > 0){

					$html .= '<a href="' . action('MdfeController@ver', [$row->id]) . '" class="btn btn-xs btn-info">Ver MDFe</a>
					&nbsp;';
				}else{
					// $html = '<a class="btn btn-xs btn-primary" href="/mdfe/gerar/'.$row->id.'">Gerar MDFe</a>';
					// $html .= '&nbsp;<a href="/mdfe/delete/'.$row->id.'" class="btn btn-xs btn-danger delete_user_button">Remover</a>';
					// $html .= '&nbsp;<a href="/mdfe/edit/'.$row->id.'" class="btn btn-xs btn-info delete_user_button">Editar</a>';

					$html = '<form id="mdfe'.$row['id'].'" method="POST" action="'. route('mdfe.destroy', $row['id']) .'">';
					$html .= '<a href="' . action('MdfeController@gerar', [$row->id]) . '" class="btn btn-xs btn-primary"></i>Gerar MDFe</a>
					&nbsp;';

					$html .= '<a href="' . action('MdfeController@edit', [$row->id]) . '" class="btn btn-xs btn-info">Editar</a>
					&nbsp;';

					$html .= '<button type="button" class="btn btn-xs btn-danger btn-delete">Excluir</button>
					'. method_field('DELETE') .'
					'. csrf_field() .'
					</form>';

				}

				return $html;
			})
			->editColumn('data_inicio_viagem', function ($row) {
				return \Carbon\Carbon::parse($row->data_inicio_viagem)->format('d/m/Y');
			})
			->addColumn('veiculo', function ($row) {
				return $row->veiculoTracao->placa . " - " . $row->veiculoTracao->marca;
			})
			->addColumn('valor_carga', function ($row) {
				return number_format($row->valor_carga, 2, ',', '.');
			})

			->removeColumn('id')
			->rawColumns(['action', 'veiculo'])
			->make(true);

		}
		return view('mdfe.index');
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
		$ufs = Veiculo::ufs();
        //Check if subscribed or not, then check for users quota
		if (!$this->moduleUtil->isSubscribed($business_id)) {
			return $this->moduleUtil->expiredResponse();
		} elseif (!$this->moduleUtil->isQuotaAvailable('naturezas', $business_id)) {
			return $this->moduleUtil->quotaExpiredResponse('naturezas', $business_id, action('NaturezaController@index'));
		}

		$roles  = $this->getRolesArray($business_id);
		$username_ext = $this->getUsernameExtension();
		
        //Get user form part from modules

		$lastMdfe = Mdfe::lastMDFeAux($business_id);

		$tiposUnidadeTransporte = Mdfe::tiposUnidadeTransporte();
		$veiculos = $this->prepareVeiculos();

		if(sizeof($veiculos) == 1){
			$output = [
				'success' => 0,
				'msg' => 'Cadastre um veiculo para continuar!!'
			];
			return redirect()->route('veiculos.create')
			->with('status', $output);
		}
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

		return view('mdfe.create')
		->with(compact('roles', 'username_ext', 'cidades', 'clientesAux', 'clientes', 
			'lastMdfe', 'tiposUnidadeTransporte', 'veiculos', 'ufs'))
		->with('bl_attributes' , $bl_attributes)
		->with('default_location' , $default_location)
		->with('mdfe' , null)
		->with('business_locations' , $business_locations);
	}


	public function edit($id){
		if (!auth()->user()->can('user.delete')) {
			abort(403, 'Unauthorized action.');
		}

		$business_id = request()->session()->get('user.business_id');

		$mdfe = Mdfe::where('id', $id)
		->where('business_id', $business_id)
		->first();

		if($mdfe == null){
			$output = [
				'success' => 0,
				'msg' => "Não permitido!"
			];
			return redirect('/mdfe')->with(['status', $output]);
		}

		$tipos = Veiculo::tipos();
		$tiposRodado = Veiculo::tiposRodado();
		$tiposCarroceria = Veiculo::tiposCarroceria();
		$tiposProprietario = Veiculo::tiposProprietario();
		$ufs = Veiculo::ufs();
        //Check if subscribed or not, then check for users quota
		if (!$this->moduleUtil->isSubscribed($business_id)) {
			return $this->moduleUtil->expiredResponse();
		} elseif (!$this->moduleUtil->isQuotaAvailable('naturezas', $business_id)) {
			return $this->moduleUtil->quotaExpiredResponse('naturezas', $business_id, action('NaturezaController@index'));
		}

		$roles  = $this->getRolesArray($business_id);
		$username_ext = $this->getUsernameExtension();

        //Get user form part from modules

		$lastMdfe = Mdfe::lastMDFeAux($business_id);

		$tiposUnidadeTransporte = Mdfe::tiposUnidadeTransporte();
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


		foreach($mdfe->infoDescarga as $info){
			$info->cidade;
			$info->cte;
			$info->nfe;
			$info->lacresTransp;
			$info->unidadeCarga;
			$info->lacresUnidCarga;
		}

		return view('mdfe.create')
		->with(compact('roles', 'username_ext', 'cidades', 'clientesAux', 'clientes', 
			'lastMdfe', 'tiposUnidadeTransporte', 'veiculos', 'ufs'))
		->with('bl_attributes' , $bl_attributes)
		->with('default_location' , $default_location)
		->with('mdfe' , $mdfe)
		->with('business_locations' , $business_locations);
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

	private function prepareVeiculos(){
		$business_id = request()->session()->get('user.business_id');

		$veiculos = Veiculo::
		where('business_id', $business_id)
		->get();
		// $temp = [];
		$temp[null] = "Selecione";
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

	private function prepareCities(){
		$cities = City::all();
		$temp = [];
		foreach($cities as $c){
            // array_push($temp, $c->id => $c->nome);
			$temp[$c->id] = $c->nome . " ($c->uf)";
		}
		return $temp;
	}

	public function store(Request $request){
		$business_id = request()->session()->get('user.business_id');
		$user_id = request()->session()->get('user.id');
		try {
			$descargas = $this->validarDescargasEdicao($request->input('descargas'));
			$this->validarListasAuxiliares($request);
		} catch (\Illuminate\Validation\ValidationException $e) {
			return redirect()->back()->withInput($request->except('_token'))->with('status', [
				'success' => false,
				'msg' => implode(' ', \Illuminate\Support\Arr::flatten($e->errors())) . ' Nenhum dado foi alterado.'
			]);
		}
		try{
			return DB::transaction(function () use ($request, $business_id, $user_id, $descargas) {
			$config = Business::find($business_id);

			// Validações condicionais para pagamento (NT 2025.001)
			// Nota: A validação completa de carga lotação será feita após salvar os documentos
			$request->validate([
				'responsavel_pag_nome' => 'nullable|string|max:100',
				'responsavel_pag_cpf_cnpj' => 'nullable|string|max:18',
				'valor_frete' => 'nullable|numeric|min:0',
				'valor_contrato' => 'nullable|numeric|min:0',
				'indicador_pagamento' => 'nullable|in:0,1',
				'numero_parcela' => 'nullable|string|max:3',
				'data_vencimento' => 'nullable|date',
				'valor_parcela' => 'nullable|numeric|min:0',
				'codigo_banco' => 'nullable|string|max:3',
				'codigo_agencia' => 'nullable|string|max:10',
				'cnpj_ipef' => 'nullable|string|max:18',
				'chave_pix' => 'nullable|string|max:60',
			]);

			$data = [
				'uf_inicio' => $request->uf_inicio,
				'uf_fim' => $request->uf_fim,
				'encerrado' => 0,
				'data_inicio_viagem' => $this->parseDate($request->data_inicio_viagem),
				'carga_posterior' => $request->carga_posterior,
				'veiculo_tracao_id' => $request->veiculo_tracao_id,
				'veiculo_reboque1_id' => $request->veiculo_reboque1_id ?? NULL,
				'veiculo_reboque2_id' => $request->veiculo_reboque2_id ?? NULL,
				'veiculo_reboque3_id' => $request->veiculo_reboque3_id ?? NULL,
				'estado' => 'NOVO',
				'seguradora_nome' => $request->seguradora_nome ?? '',
				'seguradora_cnpj' => $request->seguradora_cnpj ?? '',
				'numero_apolice' => $request->numero_apolice ?? '',
				'numero_averbacao' => $request->numero_averbacao ?? '',
				'valor_carga' => str_replace(",", ".", $request->valor_carga),
				'quantidade_carga' => str_replace(",", ".", $request->quantidade_carga),
				'info_complementar' => $request->info_complementar ?? '',
				'info_adicional_fisco' => $request->info_adicional_fisco ?? '',
				'cnpj_contratante' => $request->cnpj_contratante,
				'mdfe_numero' => 0,
				'condutor_nome' => $request->condutor_nome,
				'condutor_cpf' => $request->condutor_cpf,
				'tp_emit' => $request->tipo_emitente,
				'tp_transp' => $request->tipo_transportador,
				'lac_rodo' => $request->lac_rodo,
				'chave' => '',
				'protocolo' => '',
				'produto_pred_nome'=> $request->produto_pred_nome ?? "",
				'produto_pred_ncm' => $request->produto_pred_ncm ? str_replace(".", "", $request->produto_pred_ncm) : "",
				'produto_pred_cod_barras' => $request->produto_pred_cod_barras ?? "",
				'cep_carrega' => $request->cep_carrega ? str_replace("-", "", $request->cep_carrega) : "",
				'cep_descarrega' => $request->cep_descarrega ? str_replace("-", "", $request->cep_descarrega) : "",
				'tp_carga' => $request->tp_carga,
				'business_id' => $business_id,
				'location_id' => $request->select_location_id ?? $config->locations[0]->id,
				// Campos de pagamento (NT 2025.001)
				'responsavel_pag_nome' => $request->responsavel_pag_nome,
				'responsavel_pag_cpf_cnpj' => $request->responsavel_pag_cpf_cnpj,
				'valor_frete' => $request->valor_frete ? str_replace(",", ".", $request->valor_frete) : null,
				'tipo_componente' => '99', // 99 = Outros (Frete) - Valores válidos: 01, 02, 03, 99
				// Valor do contrato = valor do frete (conforme NT 2025.001)
				'valor_contrato' => $request->valor_frete ? str_replace(",", ".", $request->valor_frete) : null,
				'indicador_pagamento' => $request->indicador_pagamento,
				'numero_parcela' => $request->numero_parcela,
				'data_vencimento' => $request->data_vencimento,
				'valor_parcela' => $request->valor_parcela ? str_replace(",", ".", $request->valor_parcela) : null,
				'codigo_banco' => $request->codigo_banco,
				'codigo_agencia' => $request->codigo_agencia,
				'cnpj_ipef' => $request->cnpj_ipef,
				'chave_pix' => $request->chave_pix,
			];

			$mdfe = Mdfe::create($data);

			$municipios_descarregamentos = json_decode($request->municipios_descarregamentos);

			foreach($municipios_descarregamentos as $m){
				$medida = MunicipioCarregamento::create([
					'cidade_id' => $m,
					'mdfe_id' => $mdfe->id
				]);
			}

			$percurso = json_decode($request->percurso);
			if($percurso){
				foreach($percurso as $p){
					Percurso::create([
						'uf' => $p,
						'mdfe_id' => $mdfe->id
					]);
				}
			}

			$vales = json_decode($request->vales);
			if($vales){
				foreach($vales as $p){
					ValePedagio::create([
						'mdfe_id' => $mdfe->id,
						'cnpj_fornecedor' => $p->cnpj_fornecedor,
						'cnpj_fornecedor_pagador' => $p->doc_pagador,
						'numero_compra' => $p->numero_compra,
						'valor' => str_replace(",", ".", $p->valor) 
					]);
				}
			}

			$ciots = json_decode($request->ciots);

			if($ciots){
				foreach($ciots as $c){
					Ciot::create([
						'mdfe_id' => $mdfe->id,
						'cpf_cnpj' => $c->doc_ciot,
						'codigo' => $c->codigo

					]);
				}
			}

			foreach($descargas as $d){
				$info = InfoDescarga::create([
					'mdfe_id' => $mdfe->id,
					'tp_unid_transp' => $d->tipo_unidade_transporte,
					'id_unid_transp' => $d->id_unidade_transporte,
					'quantidade_rateio' => $d->qtd_rateio_transporte,
					'cidade_id' => $d->municipio_descarregamento
				]);

				if($d->chave_nfe || $d->segunda_nfe){
					NFeDescarga::Create([
						'info_id' => $info->id,
						'chave' => str_replace(" ", "", $d->chave_nfe),
						'seg_cod_barras' => str_replace(" ", "", $d->segunda_nfe)
					]);
				}

				if($d->chave_cte || $d->segunda_cte){
					CTeDescarga::Create([
						'info_id' => $info->id,
						'chave' => str_replace(" ", "", $d->chave_cte),
						'seg_cod_barras' => str_replace(" ", "", $d->segunda_cte)
					]);
				}

				$lacres_unidade_carga = ($d->lacres_unidade_carga);
				if($lacres_unidade_carga){
					foreach($lacres_unidade_carga as $l){
						LacreUnidadeCarga::create([
							'info_id' => $info->id,
							'numero' => $l
						]);
					}
				}

				$lacres_transporte = ($d->lacres_transporte);
				if($lacres_transporte){
					foreach($lacres_transporte as $l){
						LacreTransporte::create([
							'info_id' => $info->id,
							'numero' => $l
						]);
					}
				}

				UnidadeCarga::create([
					'info_id' => $info->id,
					'id_unidade_carga' => $d->id_unidade_carga,
					'quantidade_rateio' => $d->qtd_rateio_unidade
				]);
			}

			return redirect()->route('mdfe.index')->with('status', [
				'success' => 1,
				'msg' => "MDFe Gerada!!"
			]);
			});
		}catch(\Throwable $e){
			Log::error('mdfe.store.failed', ['business_id' => $business_id, 'exception_type' => get_class($e)]);
			$output = [
				'success' => false,
				'msg' => "Erro ao gerar MDFe"
			];
		}

		return redirect()->route('mdfe.index')->with('status', $output);
	}

	public function update(Request $request, $id){
		$business_id = request()->session()->get('user.business_id');
		$user_id = request()->session()->get('user.id');
		$mdfe = Mdfe::where('business_id', $business_id)->where('id', $id)->firstOrFail();
		try {
			$descargas = $this->validarDescargasEdicao($request->input('descargas'));
			$this->validarListasAuxiliares($request);
		} catch (\Illuminate\Validation\ValidationException $e) {
			return redirect()->back()->withInput($request->except('_token'))->with('status', [
				'success' => false,
				'msg' => implode(' ', \Illuminate\Support\Arr::flatten($e->errors())) . ' Nenhum dado foi alterado. Confira os documentos antes de salvar.'
			]);
		}
		try{
			return DB::transaction(function () use ($request, $business_id, $mdfe, $descargas) {
			$config = Business::find($business_id);

			$mdfe->uf_inicio = $request->uf_inicio;
			$mdfe->uf_fim = $request->uf_fim;
			$mdfe->encerrado = 0;
			$mdfe->data_inicio_viagem = $this->parseDate($request->data_inicio_viagem);
			$mdfe->carga_posterior = $request->carga_posterior; 
			$mdfe->veiculo_tracao_id = $request->veiculo_tracao_id;
			$mdfe->veiculo_reboque1_id = $request->veiculo_reboque1_id;
			$mdfe->veiculo_reboque2_id = $request->veiculo_reboque2_id ?? NULL;
			$mdfe->veiculo_reboque3_id = $request->veiculo_reboque3_id ?? NULL;
			$mdfe->estado = 'NOVO';
			$mdfe->seguradora_nome = $request->seguradora_nome ?? '';
			$mdfe->seguradora_cnpj = $request->seguradora_cnpj ?? '';
			$mdfe->numero_apolice = $request->numero_apolice ?? '';
			$mdfe->numero_averbacao = $request->numero_averbacao ?? '';
			$mdfe->valor_carga = str_replace(",", ".", $request->valor_carga);
			$mdfe->quantidade_carga = str_replace(",", ".", $request->quantidade_carga);
			$mdfe->info_complementar = $request->info_complementar ?? '';
			$mdfe->info_adicional_fisco = $request->info_adicional_fisco ?? '';
			$mdfe->cnpj_contratante = $request->cnpj_contratante;
			$mdfe->mdfe_numero = 0;
			$mdfe->condutor_nome = $request->condutor_nome;
			$mdfe->condutor_cpf = $request->condutor_cpf;
			$mdfe->tp_emit = $request->tipo_emitente;
			$mdfe->tp_transp = $request->tipo_transportador;
			$mdfe->lac_rodo = $request->lac_rodo;
			$mdfe->chave = '';
			$mdfe->protocolo = '';
			$mdfe->produto_pred_nome = $request->produto_pred_nome ?? ''; 
			$mdfe->produto_pred_ncm = $request->produto_pred_ncm ? str_replace(".", "", $request->produto_pred_ncm) : "";
			$mdfe->produto_pred_cod_barras = $request->produto_pred_cod_barras ?? "";
			$mdfe->cep_carrega = $request->cep_carrega ? str_replace("-", "", $request->cep_carrega) : "";
			$mdfe->cep_descarrega = $request->cep_descarrega ? str_replace("-", "", $request->cep_descarrega) : "";
			$mdfe->tp_carga = $request->tp_carga;
			$mdfe->business_id = $business_id;
			$mdfe->location_id = $request->select_location_id ?? $config->locations[0]->id;

			// Campos de pagamento (NT 2025.001)
			$mdfe->responsavel_pag_nome = $request->responsavel_pag_nome;
			$mdfe->responsavel_pag_cpf_cnpj = $request->responsavel_pag_cpf_cnpj;
			$mdfe->valor_frete = $request->valor_frete ? str_replace(",", ".", $request->valor_frete) : null;
			$mdfe->tipo_componente = '99'; // 99 = Outros (Frete) - Valores válidos: 01, 02, 03, 99
			// Valor do contrato = valor do frete (conforme NT 2025.001)
			$mdfe->valor_contrato = $request->valor_frete ? str_replace(",", ".", $request->valor_frete) : null;
			$mdfe->indicador_pagamento = $request->indicador_pagamento;
			$mdfe->numero_parcela = $request->numero_parcela;
			$mdfe->data_vencimento = $request->data_vencimento;
			$mdfe->valor_parcela = $request->valor_parcela ? str_replace(",", ".", $request->valor_parcela) : null;
			$mdfe->codigo_banco = $request->codigo_banco;
			$mdfe->codigo_agencia = $request->codigo_agencia;
			$mdfe->cnpj_ipef = $request->cnpj_ipef;
			$mdfe->chave_pix = $request->chave_pix;

			$mdfe->update();

			$municipios_descarregamentos = json_decode($request->municipios_descarregamentos);
			MunicipioCarregamento::where('mdfe_id', $mdfe->id)->delete();
			foreach($municipios_descarregamentos as $m){
				$medida = MunicipioCarregamento::create([
					'cidade_id' => $m,
					'mdfe_id' => $mdfe->id
				]);
			}

			$percurso = json_decode($request->percurso);
			Percurso::where('mdfe_id', $mdfe->id)->delete();
			if($percurso){
				foreach($percurso as $p){
					Percurso::create([
						'uf' => $p,
						'mdfe_id' => $mdfe->id
					]);
				}
			}

			$vales = json_decode($request->vales);
			ValePedagio::where('mdfe_id', $mdfe->id)->delete();
			if($vales){
				foreach($vales as $p){
					ValePedagio::create([
						'mdfe_id' => $mdfe->id,
						'cnpj_fornecedor' => $p->cnpj_fornecedor,
						'cnpj_fornecedor_pagador' => $p->doc_pagador,
						'numero_compra' => $p->numero_compra,
						'valor' => str_replace(",", ".", $p->valor) 
					]);
				}
			}

			$ciots = json_decode($request->ciots);
			Ciot::where('mdfe_id', $mdfe->id)->delete();
			if($ciots){
				foreach($ciots as $c){
					Ciot::create([
						'mdfe_id' => $mdfe->id,
						'cpf_cnpj' => $c->doc_ciot,
						'codigo' => $c->codigo

					]);
				}
			}

			// O contrato foi validado antes de qualquer escrita; a substituição é atômica.
			InfoDescarga::where('mdfe_id', $mdfe->id)->delete();
			foreach($descargas as $d){
				$info = InfoDescarga::create([
					'mdfe_id' => $mdfe->id,
					'tp_unid_transp' => $d->tipo_unidade_transporte,
					'id_unid_transp' => $d->id_unidade_transporte,
					'quantidade_rateio' => $d->qtd_rateio_transporte,
					'cidade_id' => $d->municipio_descarregamento
				]);

				if($d->chave_nfe || $d->segunda_nfe){
					NFeDescarga::Create([
						'info_id' => $info->id,
						'chave' => str_replace(" ", "", $d->chave_nfe),
						'seg_cod_barras' => str_replace(" ", "", $d->segunda_nfe)
					]);
				}

				if($d->chave_cte || $d->segunda_cte){
					CTeDescarga::Create([
						'info_id' => $info->id,
						'chave' => str_replace(" ", "", $d->chave_cte),
						'seg_cod_barras' => str_replace(" ", "", $d->segunda_cte)
					]);
				}

				$lacres_unidade_carga = ($d->lacres_unidade_carga);
				if($lacres_unidade_carga){
					foreach($lacres_unidade_carga as $l){
						LacreUnidadeCarga::create([
							'info_id' => $info->id,
							'numero' => $l
						]);
					}
				}

				$lacres_transporte = ($d->lacres_transporte);
				if($lacres_transporte){
					foreach($lacres_transporte as $l){
						LacreTransporte::create([
							'info_id' => $info->id,
							'numero' => $l
						]);
					}
				}

				UnidadeCarga::create([
					'info_id' => $info->id,
					'id_unidade_carga' => $d->id_unidade_carga,
					'quantidade_rateio' => $d->qtd_rateio_unidade
				]);
			}

			return redirect()->route('mdfe.index')->with('status', [
				'success' => true,
				'msg' => "MDFe atualizada!!"
			]);
			});
		}catch(\Throwable $e){
			Log::error('mdfe.update.failed', ['mdfe_id' => $mdfe->id, 'business_id' => $business_id, 'exception_type' => get_class($e)]);
			$output = [
				'success' => false,
				'msg' => "Erro ao atualizar MDFe"
			];
		}

		return redirect()->route('mdfe.index')->with('status', $output);
	}

	private function validarListasAuxiliares(Request $request)
	{
		foreach (['municipios_descarregamentos', 'percurso', 'ciots', 'vales'] as $campo) {
			$json = $request->input($campo);
			// Listas opcionais vazias são o formato enviado pelo formulário novo.
			if (($json === null || $json === '') && $campo !== 'municipios_descarregamentos') {
				continue;
			}
			$lista = is_string($json) ? json_decode($json) : null;
			if (!is_array($lista) || json_last_error() !== JSON_ERROR_NONE || ($campo === 'municipios_descarregamentos' && !$lista)) {
				throw \Illuminate\Validation\ValidationException::withMessages([$campo => 'Lista ausente ou inválida: ' . $campo . '.']);
			}
			foreach ($lista as $item) {
				if ($campo === 'municipios_descarregamentos') {
					Validator::make(['cidade' => $item], ['cidade' => 'required|integer|min:1'])->validate();
				} elseif ($campo === 'percurso') {
					Validator::make(['uf' => $item], ['uf' => 'required|string|in:AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO'])->validate();
				} else {
					if (!is_object($item)) {
						throw \Illuminate\Validation\ValidationException::withMessages([$campo => 'Item inválido na lista: ' . $campo . '.']);
					}
					$regras = $campo === 'ciots'
						? ['codigo' => 'required', 'doc_ciot' => 'required']
						: ['cnpj_fornecedor' => 'required', 'doc_pagador' => 'required', 'numero_compra' => 'required', 'valor' => 'required'];
					Validator::make((array) $item, $regras)->validate();
				}
			}
		}
	}

	private function validarDescargasEdicao($json)
	{
		$lista = is_string($json) ? json_decode($json) : null;
		if (!is_array($lista) || count($lista) === 0 || json_last_error() !== JSON_ERROR_NONE) {
			throw \Illuminate\Validation\ValidationException::withMessages(['descargas' => 'Lista de documentos ausente ou inválida.']);
		}
		foreach ($lista as $indice => $documento) {
			if (!is_object($documento)) {
				throw \Illuminate\Validation\ValidationException::withMessages(['descargas' => 'Documento inválido.']);
			}
			// Aceitar o decimal digitado em pt-BR sem persistir vírgulas em colunas numéricas.
			foreach (['qtd_rateio_transporte', 'qtd_rateio_unidade'] as $campo) {
				if (isset($documento->$campo) && is_string($documento->$campo)) {
					$documento->$campo = str_replace(',', '.', trim($documento->$campo));
				}
			}
			$dados = (array) $documento;
			Validator::make($dados, [
				'tipo_unidade_transporte' => 'required|integer|between:1,7',
				'id_unidade_transporte' => 'required|string|max:20',
				'qtd_rateio_transporte' => 'required|numeric|min:0|max:999.99',
				'id_unidade_carga' => 'required|string|max:20',
				'qtd_rateio_unidade' => 'required|numeric|min:0|max:999.99',
				'municipio_descarregamento' => 'required|integer|min:1',
				'chave_nfe' => 'present|nullable|string|max:44',
				'segunda_nfe' => 'present|nullable|string|max:44',
				'chave_cte' => 'present|nullable|string|max:44',
				'segunda_cte' => 'present|nullable|string|max:44',
				'lacres_transporte' => 'present|array',
				'lacres_unidade_carga' => 'present|array',
				'lacres_transporte.*' => 'required|string|max:20',
				'lacres_unidade_carga.*' => 'required|string|max:20',
			])->validate();
			foreach (['chave_nfe' => ['NF-e', '55'], 'chave_cte' => ['CT-e', '57']] as $campo => $tipo) {
				$chave = $documento->$campo ?? '';
				if ($chave === '') { continue; }
				$erro = null;
				if (!preg_match('/^[0-9]{44}$/D', $chave)) {
					$erro = 'informe exatamente 44 dígitos numéricos, sem letras ou símbolos';
				} elseif (substr($chave, 20, 2) !== $tipo[1]) {
					$erro = 'a chave não corresponde ao modelo ' . $tipo[1];
				} else {
					$soma = 0;
					$peso = 2;
					for ($i = 42; $i >= 0; --$i) {
						$soma += (int) $chave[$i] * $peso;
						$peso = $peso === 9 ? 2 : $peso + 1;
					}
					$dv = 11 - ($soma % 11);
					$dv = $dv >= 10 ? 0 : $dv;
					if ($dv !== (int) $chave[43]) { $erro = 'dígito verificador inválido; confira a chave original'; }
				}
				if ($erro !== null) {
					throw \Illuminate\Validation\ValidationException::withMessages(['descargas' => 'Documento ' . ($indice + 1) . ' (' . $tipo[0] . '): ' . $erro . '.']);
				}
			}
			if (trim(($documento->chave_nfe ?? '') . ($documento->segunda_nfe ?? '') . ($documento->chave_cte ?? '') . ($documento->segunda_cte ?? '')) === '') {
				throw \Illuminate\Validation\ValidationException::withMessages(['descargas' => 'Referencie um documento.']);
			}
		}
		return $lista;
	}

	public function destroy($id){
		if (!auth()->user()->can('user.delete')) {
			abort(403, 'Unauthorized action.');
		}

		try {
			$business_id = request()->session()->get('user.business_id');

			$cte = Mdfe::where('business_id', $business_id)
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

		return redirect()->route('mdfe.index')->with('status', $output);
	}

	public function gerar($id){
		$business_id = request()->session()->get('user.business_id');

		$mdfe = Mdfe::where('business_id', $business_id)
		->where('id', $id)
		->first();

		if(!$mdfe){
			abort(403, 'Unauthorized action.');
		}


		if($mdfe->mdfe_numero > 0){
			return redirect('/mdfe/ver/'.$mdfe->id);
		}

		return view('mdfe.gerar')
		->with(compact('mdfe'));

	}

	public function ver($id){

		$business_id = request()->session()->get('user.business_id');
		$mdfe = Mdfe::where('business_id', $business_id)
		->where('id', $id)
		->first();

		if(!$mdfe){
			abort(403, 'Unauthorized action.');
		}

		// $business = Business::find($business_id);
		$business = Business::getConfigMdfe($business_id, $mdfe);

		if($mdfe->mdfe_numero == 0){
			return redirect('/mdfe/gerar/'.$mdfe->id);
		}

		return view('mdfe.ver')
		->with(compact('mdfe', 'business'));
	}

	private function parseDate($date, $plusDay = false){
		if($plusDay == false)
			return date('Y-m-d', strtotime(str_replace("/", "-", $date)));
		else
			return date('Y-m-d', strtotime("+1 day",strtotime(str_replace("/", "-", $date))));
	}

	public function renderizar($id){
		$business_id = request()->session()->get('user.business_id');

		$mdfe = Mdfe::where('business_id', $business_id)
		->where('id', $id)
		->first();

		if(!$mdfe){
			abort(403, 'Unauthorized action.');
		}

		// $config = Business::find($business_id);
		$config = Business::getConfigMdfe($business_id, $mdfe);

		$cnpj = str_replace(".", "", $config->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);

		$mdfe_service = new MDFeService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$config->ambiente,
			"razaosocial" => $config->razao_social,
			"siglaUF" => $config->cidade->uf,
			"cnpj" => $cnpj,
			"inscricaomunicipal" => $config->inscricao_municipal,
			"codigomunicipio" => $config->cidade->codigo,
			"schemes" => "PL_MDFe_300a",
			"versao" => '3.00'
		], $config);

		$xml = $mdfe_service->gerar($mdfe);
		if(!isset($xml['erros_xml'])){
			$signed = $mdfe_service->sign($xml['xml']);
			$logo = '';
			if($config->logo){
				$logo = 'data://text/plain;base64,'. base64_encode(file_get_contents(
					public_path('uploads/business_logos/' . $config->logo)));
			}

			try {
				$damdfe = new Damdfe($signed, $mdfe);
				$damdfe->debugMode(true);
				$damdfe->creditsIntegratorFooter('WEBNFe Sistemas - http://www.webenf.com.br');
				$pdf = $damdfe->render($logo);
				header('Content-Type: application/pdf');
				return response($pdf)
				->header('Content-Type', 'application/pdf');
			} catch (Exception $e) {
				echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
			} 
		}else{
			return response()->json($xml['erros_xml'], 404);
		}

	}

	public function gerarXml($id){
		$business_id = request()->session()->get('user.business_id');

		$mdfe = Mdfe::where('business_id', $business_id)
		->where('id', $id)
		->first();

		if(!$mdfe){
			abort(403, 'Unauthorized action.');
		}

		// $config = Business::find($business_id);
		$config = Business::getConfigMdfe($business_id, $mdfe);

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		$mdfe_service = new MDFeService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$config->ambiente,
			"razaosocial" => $config->razao_social,
			"siglaUF" => $config->cidade->uf,
			"cnpj" => $cnpj,
			"inscricaomunicipal" => $config->inscricao_municipal,
			"codigomunicipio" => $config->cidade->codigo,
			"schemes" => "PL_MDFe_300a",
			"versao" => '3.00'
		], $config);

		$xml = $mdfe_service->gerar($mdfe);
		if(!isset($xml['erros_xml'])){
			$signed = $mdfe_service->sign($xml['xml']);
			$logo = '';
			// if($config->logo){
			// 	$logo = 'data://text/plain;base64,'. base64_encode(file_get_contents(
			// 		public_path('uploads/business_logos/' . $business->logo)));
			// }

			try {

				return response($signed)
				->header('Content-Type', 'application/xml');
			} catch (Exception $e) {
				echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
			} 
		}else{
			return response()->json($xml['erros_xml'], 404);
		}

	}

	public function transmitir(Request $request){

		$business_id = request()->session()->get('user.business_id');

		$mdfe = Mdfe::where('business_id', $business_id)
		->where('id', $request->id)
		->first();

		if(!$mdfe){
			abort(403, 'Unauthorized action.');
		}

		// $config = Business::find($business_id);
		$config = Business::getConfigMdfe($business_id, $mdfe);

		$cnpj = str_replace(".", "", $config->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);


		$mdfe_service = new MDFeService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$config->ambiente,
			"razaosocial" => $config->razao_social,
			"siglaUF" => $config->cidade->uf,
			"cnpj" => $cnpj,
			"inscricaomunicipal" => $config->inscricao_municipal,
			"codigomunicipio" => $config->cidade->codigo,
			"schemes" => "PL_MDFe_300a",
			"versao" => '3.00'
		], $config);

		$xml = $mdfe_service->gerar($mdfe);
		if(!isset($xml['erros_xml'])){
			$signed = $mdfe_service->sign($xml['xml']);
			$resultado = $mdfe_service->transmitir($signed, $cnpj);
			if(!isset($resultado['erro'])){
				$mdfe->chave = $resultado['chave'];
				$mdfe->protocolo = $resultado['protocolo'];

				$mdfe->estado = 'APROVADO';

				$mdfe->mdfe_numero = $xml['numero'];
				$mdfe->save();
				return response()->json($resultado, 200);
			}else{
				$mdfe->estado = 'REJEITADO';
				$mdfe->save();

				return response()->json($resultado, 403);
			}
		}else{
			return response()->json($xml['erros_xml'], 404);
		}

	}

	public function naoencerrados(){

		$business_id = request()->session()->get('user.business_id');

		$config = Business::find($business_id);

		$locations = $config->locations;
		$naoEncerrados = [];

		foreach($locations as $key => $l){

			if($key == 0){
				$config = Business::find($business_id);
			}else{
				$config = $l;
			}
			$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

			$mdfe_service = new MDFeService([
				"atualizacao" => date('Y-m-d h:i:s'),
				"tpAmb" => (int)$config->ambiente,
				"razaosocial" => $config->razao_social,
				"siglaUF" => $config->cidade->uf,
				"cnpj" => $cnpj,
				"inscricaomunicipal" => $config->inscricao_municipal,
				"codigomunicipio" => $config->cidade->codigo,
				"schemes" => "PL_MDFe_300a",
				"versao" => '3.00'
			], $config);

			$resultados = $mdfe_service->naoEncerrados();
			// echo '<pre>';
			// dd($resultados);
			// echo "</pre>";
			// die;

			// dd($resultados);

			$msg = $resultados['xMotivo'];
			if($resultados['xMotivo'] != 'Consulta não encerrados não localizou MDFe nessa situação'){
				// dd($resultados);

				if(isset($resultados['infMDFe'])){

				// if(sizeof($resultados['infMDFe']) == 2){
					if(!isset($resultados['infMDFe'][1])){
						$array = [
							'chave' => $resultados['infMDFe']['chMDFe'],
							'protocolo' => $resultados['infMDFe']['nProt'],
							'numero' => 0,
							'data' => '',
							'location_id' => $l->location_id
						];
						array_push($naoEncerrados, $array);
					}else{

						foreach($resultados['infMDFe'] as $inf){

							$array = [
								'chave' => $inf['chMDFe'],
								'protocolo' => $inf['nProt'],
								'numero' => 0,
								'location_id' => $l->location_id
							];
							array_push($naoEncerrados, $array);

						}
					}
				}
			}

			$naoEncerrados = $this->percorreDatabaseNaoEncerrados($naoEncerrados, $business_id);
		}

		return view('mdfe.nao_encerrados')
		->with('message', $msg)
		->with('naoEncerrados' , $naoEncerrados);

	}

	private function percorreDatabaseNaoEncerrados($naoEncerrados, $business_id){
		for($aux = 0; $aux < count($naoEncerrados); $aux++){
			$mdfe = Mdfe::
			where('chave', $naoEncerrados[$aux]['chave'])
			->where('business_id', $business_id)
			->first();

			if($mdfe != null){
				$naoEncerrados[$aux]['data'] = $mdfe->created_at;
				$naoEncerrados[$aux]['numero'] = $mdfe->mdfe_numero;
			}

		}
		return $naoEncerrados;
	}

	public function encerrar($chave, $protocolo, $location_id){

		$business_id = request()->session()->get('user.business_id');

		$config = Business::find($business_id);

		$locations = $config->locations;

		if(sizeof($locations) == 1 || $location_id == 'BL0001'){

			$config = Business::find($business_id);
		}else{
			$location = BusinessLocation::where('location_id', $location_id)
			->where('business_id', $business_id)
			->first();
			$config = $location;
		}

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		try{

			$mdfe_service = new MDFeService([
				"atualizacao" => date('Y-m-d h:i:s'),
				"tpAmb" => (int)$config->ambiente,
				"razaosocial" => $config->razao_social,
				"siglaUF" => $config->cidade->uf,
				"cnpj" => $cnpj,
				"inscricaomunicipal" => $config->inscricao_municipal,
				"codigomunicipio" => $config->cidade->codigo,
				"schemes" => "PL_MDFe_300a",
				"versao" => '3.00'
			], $config);

			$mdfe = Mdfe::
			where('chave', $chave)
			->where('business_id', $business_id)
			->first();

			$mdfe_service->encerrar($config, $chave, $protocolo);
			if($mdfe != null){
				$mdfe->encerrado = true;
				$mdfe->save();
			}

			$output = [
				'success' => 1,
				'msg' => "Documento $chave encerrado!!"
			];


		}catch(\Exception $e){
			echo $e->getFile();
			die;
			$output = [
				'success' => 0,
				'msg' => $e->getMessage()
			];
		}

		return redirect('mdfe')->with('status', $output);

	}

	public function consultar(Request $request){
		$mdfe = Mdfe::find($request->id);

		if($mdfe->estado == 'APROVADO' || $mdfe->estado == 'CANCELADO'){
			$business_id = request()->session()->get('user.business_id');

			$config = Business::getConfigMdfe($business_id, $mdfe);

			$cnpj = str_replace(".", "", $config->cnpj);
			$cnpj = str_replace("/", "", $cnpj);
			$cnpj = str_replace("-", "", $cnpj);
			$cnpj = str_replace(" ", "", $cnpj);

			$mdfe_service = new MDFeService([
				"atualizacao" => date('Y-m-d h:i:s'),
				"tpAmb" => (int)$config->ambiente,
				"razaosocial" => $config->razao_social,
				"siglaUF" => $config->cidade->uf,
				"cnpj" => $cnpj,
				"inscricaomunicipal" => $config->inscricao_municipal,
				"codigomunicipio" => $config->cidade->codigo,
				"schemes" => "PL_MDFe_300a",
				"versao" => '3.00'
			], $config);

			$result = $mdfe_service->consultar($mdfe->chave);

			return response()->json($result, 200);
		}else{
			return response()->json("Erro ao consultar", 404);
		}
	}


	public function imprimir($id){
		$business_id = request()->session()->get('user.business_id');

		$mdfe = Mdfe::
		where('id', $id)
		->where('business_id', $business_id)
		->first();

		if(!$mdfe){
			abort(403, 'Unauthorized action.');
		}

		$config = Business::getConfigMdfe($business_id, $mdfe);

		$cnpj = str_replace(".", "", $config->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);

		$logo = '';
		if($config->logo){
			$logo = 'data://text/plain;base64,'. base64_encode(file_get_contents(
				public_path('uploads/business_logos/' . $config->logo)));
		}


		if(file_exists(public_path('xml_mdfe/'.$cnpj.'/'.$mdfe->chave.'.xml'))){
			$xml = file_get_contents(public_path('xml_mdfe/'.$cnpj.'/'.$mdfe->chave.'.xml'));

			try {
				$damdfe = new Damdfe($xml, $mdfe);
				$damdfe->debugMode(true);
				$damdfe->creditsIntegratorFooter('WEBNFe Sistemas - http://www.webenf.com.br');
				$pdf = $damdfe->render($logo);
				header('Content-Type: application/pdf');
				return response($pdf)
				->header('Content-Type', 'application/pdf');
			} catch (Exception $e) {
				echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
			} 

		}else{
			return redirect('/mdfe')
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	}

	public function imprimirCancelamento($id){

	}

	public function baixarXml($id){
		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		$business_id = request()->session()->get('user.business_id');
		$mdfe = Mdfe::where('business_id', $business_id)
		->where('id', $id)
		->first();

		// $business = Business::find($business_id);
		$business = Business::getConfigMdfe($business_id, $mdfe);

		$cnpj = str_replace(".", "", $business->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);

		if(!$mdfe){
			abort(403, 'Unauthorized action.');
		}
		if(file_exists(public_path('xml_mdfe/'.$cnpj.'/'.$mdfe->chave.'.xml'))){
			return response()->download(public_path('xml_mdfe/'.$cnpj.'/'.$mdfe->chave.'.xml'));
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
		$mdfe = Mdfe::where('business_id', $business_id)
		->where('id', $request->id)
		->first();

		// $config = Business::find($business_id);
		$config = Business::getConfigMdfe($business_id, $mdfe);

		$cnpj = str_replace(".", "", $config->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);

		$mdfe_service = new MDFeService([
			"atualizacao" => date('Y-m-d h:i:s'),
			"tpAmb" => (int)$config->ambiente,
			"razaosocial" => $config->razao_social,
			"siglaUF" => $config->cidade->uf,
			"cnpj" => $cnpj,
			"inscricaomunicipal" => $config->inscricao_municipal,
			"codigomunicipio" => $config->cidade->codigo,
			"schemes" => "PL_MDFe_300a",
			"versao" => '3.00'
		], $config);


		$result = $mdfe_service->cancelar($mdfe->chave, $mdfe->protocolo, 
			$request->justificativa);
		if($result->infEvento->cStat == '101' || $result->infEvento->cStat == '135' || $result->infEvento->cStat == '155'){
			$mdfe->estado = 'CANCELADO';
			$mdfe->save();
			return response()->json($result, 200);

		}else{

			return response()->json($result, 401);
		}

	}

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

		return view('mdfe.xmls')
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

		$aprovadas = Mdfe::where('business_id', $business_id)
		->whereBetween('created_at', [
			$data_inicio_convert, 
			$data_final_convert])
		->where('mdfe_numero', '>', 0)
		->where('estado', 'APROVADO')
		->orderBy('id', 'desc');

		if($select_location_id){
			$aprovadas->where('location_id', $select_location_id);
		}
		$aprovadas = $aprovadas->get();

		$canceladas = Mdfe::where('business_id', $business_id)
		->whereBetween('created_at', [
			$data_inicio_convert, 
			$data_final_convert])
		->where('mdfe_numero', '>', 0)
		->where('estado', 'CANCELADO')
		->orderBy('id', 'desc');

		if($select_location_id){
			$canceladas->where('location_id', $select_location_id);
		}
		$canceladas = $canceladas->get();

		$business = Business::find($business_id);
		$cnpj = str_replace(".", "", $business->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);

		$msg = [];

		if(sizeof($aprovadas) > 0){
			try{
				$zip_file = public_path('xml_mdfe/'.$cnpj.'/'.'xml.zip');
				$zip = new \ZipArchive();
				$zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

				foreach($aprovadas as $n){

					if(file_exists(public_path('xml_mdfe/'.$cnpj.'/'.$n->chave.'.xml'))){
						$zip->addFile(public_path('xml_mdfe/'.$cnpj.'/'.$n->chave.'.xml'), $n->chave . '.xml');
					}

				}
				$zip->close();
			}catch(\Exception $e){
				array_push($msg, "Erro ao gerar arquivo de XML!!");
			}

		}

		if(sizeof($canceladas) > 0){

			try{
				$zip_file = public_path('xml_mdfe_cancelada/'.$cnpj.'/'.'xml_cancelado.zip');
				$zip = new \ZipArchive();
				$zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

				foreach($canceladas as $n){

					if(file_exists(public_path('xml_mdfe_cancelada/'.$cnpj.'/'.$n->chave.'.xml'))){
						$zip->addFile(public_path('xml_mdfe_cancelada/'.$cnpj.'/'.$n->chave.'.xml'), $n->chave . '.xml');
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

		return view('mdfe.xmls')
		->with(compact('canceladas', 'aprovadas', 'business', 'data_inicio', 'data_final', 'msg'))
		->with('bl_attributes' , $bl_attributes)
		->with('default_location' , $default_location)
		->with('select_location_id' , $select_location_id)
		->with('business_locations' , $business_locations);
	}

	public function baixarZipXmlAprovado(){
		$business_id = request()->session()->get('user.business_id');
		$business = Business::find($business_id);
		$cnpj = str_replace(".", "", $business->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);
		if(file_exists(public_path('xml_mdfe/'.$cnpj.'/'.'xml.zip'))){
			return response()->download(public_path('xml_mdfe/'.$cnpj.'/'.'xml.zip'));
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
		$cnpj = str_replace(".", "", $business->cnpj);
		$cnpj = str_replace("/", "", $cnpj);
		$cnpj = str_replace("-", "", $cnpj);
		$cnpj = str_replace(" ", "", $cnpj);
		if(file_exists(public_path('xml_mdfe_cancelada/'.$cnpj.'/'.'xml_cancelado.zip'))){
			return response()->download(public_path('xml_mdfe_cancelada/'.$cnpj.'/'.'xml_cancelado.zip'));
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	}

}
