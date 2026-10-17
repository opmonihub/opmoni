<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transportadora;
use App\Models\System;
use App\Models\City;
use App\Models\BusinessLocation;
use App\Models\Contact;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\Facades\DataTables;
use App\Utils\ModuleUtil;

class TransportadoraController extends Controller
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
			$naturezas = Transportadora::where('business_id', $business_id)
			->join('cities', 'cities.id' , '=', 'transportadoras.cidade_id')
			->select(['transportadoras.id', 'razao_social', 'cities.nome as cidade', 'cities.uf as uf', 'cnpj_cpf', 'logradouro']);


			return Datatables::of($naturezas)

			->addColumn(
				'action',
				'<a href="/transportadoras/edit/{{$id}}" class="btn btn-xs btn-primary"><i class="glyphicon glyphicon-edit"></i> @lang("messages.edit")</a>
				&nbsp;<a href="/transportadoras/delete/{{$id}}" class="btn btn-xs btn-danger delete_user_button"><i class="glyphicon glyphicon-trash"></i> @lang("messages.delete")</a>'
			)

			->addColumn(
				'teste', '{{$cidade}} ({{$uf}})')

			->removeColumn('id')
			->rawColumns(['action'])
			->make(true);

		}
		return view('transportadoras.list');

	}

	public function new(){
		// return view('naturezas.register');

		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		$business_id = request()->session()->get('user.business_id');

        //Check if subscribed or not, then check for users quota
		if (!$this->moduleUtil->isSubscribed($business_id)) {
			return $this->moduleUtil->expiredResponse();
		} elseif (!$this->moduleUtil->isQuotaAvailable('transportadoras', $business_id)) {
			return $this->moduleUtil->quotaExpiredResponse('transportadoras', $business_id, action('TransportadoraController@index'));
		}

		$roles  = $this->getRolesArray($business_id);
		$username_ext = $this->getUsernameExtension();
		$contacts = Contact::contactDropdown($business_id, true, false);
		$locations = BusinessLocation::where('business_id', $business_id)
		->Active()
		->get();

        //Get user form part from modules
		$form_partials = $this->moduleUtil->getModuleData('moduleViewPartials', ['view' => 'transportadoras.register']);

		return view('transportadoras.register')
		->with('cities', $this->prepareCities())
		->with(compact('roles', 'username_ext', 'contacts', 'locations', 'form_partials'));
	}

	private function prepareCities(){
		$cities = City::all();
		$temp = [];
		foreach($cities as $c){
			// array_push($temp, $c->id => $c->nome);
			$temp[$c->id] = $c->nome;
		}
		return $temp;
	}

	public function save(Request $request){
		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		try {
			$transp = $request->only(['razao_social', 'cnpj_cpf', 'cidade_id',
				'logradouro']);



			$business_id = $request->session()->get('user.business_id');
			$transp['business_id'] = $business_id;


			Transportadora::create($transp);


			$output = [
				'success' => 1,
				'msg' => 'Sucesso cadastrado!'
			];
		} catch (\Exception $e) {
			\Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

			$output = [
				'success' => 0,
				'msg' => __("messages.something_went_wrong")
			];

			print_r($e->getMessage());

		}

		return redirect('transportadoras')->with('status', $output);
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

	public function edit($id){
		// return view('naturezas.register');

		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		$business_id = request()->session()->get('user.business_id');

        //Check if subscribed or not, then check for users quota
		if (!$this->moduleUtil->isSubscribed($business_id)) {
			return $this->moduleUtil->expiredResponse();
		} elseif (!$this->moduleUtil->isQuotaAvailable('transportadoras', $business_id)) {
			return $this->moduleUtil->quotaExpiredResponse('transportadoras', $business_id, action('TransportadoraController@index'));
		}

		$roles  = $this->getRolesArray($business_id);
		$username_ext = $this->getUsernameExtension();
		$contacts = Contact::contactDropdown($business_id, true, false);
		$locations = BusinessLocation::where('business_id', $business_id)
		->Active()
		->get();

		$transportadora = Transportadora::find($id);

        //Get user form part from modules
		$form_partials = $this->moduleUtil->getModuleData('moduleViewPartials', ['view' => 'transportadoras.register']);

		return view('transportadoras.edit')
		->with('cities', $this->prepareCities())
		->with(compact('roles', 'transportadora', 'username_ext', 'contacts', 'locations', 'form_partials'));
	}

	public function update(Request $request){
		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		try {
			$transportadora = $request->only(['razao_social', 'cnpj_cpf', 'cidade_id', 'logradouro']);

			$transp = Transportadora::find($request->id);
			
			$transp['cidade_id'] = $request->cidade_id;
			
			$transp->update($transportadora);

			$output = [
				'success' => 1,
				'msg' => 'Editado com sucesso!'
			];
		} catch (\Exception $e) {
			\Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

			$output = [
				'success' => 0,
				'msg' => __("messages.something_went_wrong")
			];

		}

		return redirect('transportadoras')->with('status', $output);
	}

	public function delete($id){

		if (!auth()->user()->can('user.delete')) {
			abort(403, 'Unauthorized action.');
		}

		try {
			$business_id = request()->session()->get('user.business_id');

			Transportadora::where('business_id', $business_id)
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

		return redirect('transportadoras')->with('status', $output);

	}

}
