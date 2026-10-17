<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\City;
use App\Models\System;

use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\Facades\DataTables;
use App\Utils\ModuleUtil;

class CityController extends Controller
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
			$cities = City::
			select(['id', 'codigo', 'nome', 'uf']);


			return Datatables::of($cities)

			->addColumn(
				'action',
				function ($row) {

					return '<a href="'.action('CityController@edit', [$row->id]).'" class="btn btn-xs btn-primary"><i class="glyphicon glyphicon-edit"></i> Editar</a>';	
				})

			->removeColumn('id')
			->rawColumns(['action'])
			->make(true);

		}
		return view('cities.index');

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
		} elseif (!$this->moduleUtil->isQuotaAvailable('cities', $business_id)) {
			return $this->moduleUtil->quotaExpiredResponse('cities', $business_id, action('CityController@index'));
		}

		$roles  = $this->getRolesArray($business_id);
		$username_ext = $this->getUsernameExtension();

		$city = City::find($id);

        //Get user form part from modules
		$form_partials = $this->moduleUtil->getModuleData('moduleViewPartials', ['view' => 'naturezas.register']);

		return view('cities.edit')
		->with(compact('roles', 'city', 'username_ext', 'form_partials'));
	}

	public function update(Request $request){
		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		try {
			$city = $request->only(['nome', 'codigo', 'uf']);

			$c = City::find($request->id);
			
			$c->update($city);

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

		return redirect('cities')->with('status', $output);
	}

	public function create(){
		// return view('naturezas.register');

		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		$business_id = request()->session()->get('user.business_id');

        //Check if subscribed or not, then check for users quota
		if (!$this->moduleUtil->isSubscribed($business_id)) {
			return $this->moduleUtil->expiredResponse();
		} elseif (!$this->moduleUtil->isQuotaAvailable('cities', $business_id)) {
			return $this->moduleUtil->quotaExpiredResponse('cities', $business_id, action('CityController@index'));
		}

		$roles  = $this->getRolesArray($business_id);
		$username_ext = $this->getUsernameExtension();

        //Get user form part from modules
		$form_partials = $this->moduleUtil->getModuleData('moduleViewPartials', ['view' => 'naturezas.register']);

		return view('cities.create')
		->with(compact('roles', 'username_ext', 'form_partials'));
	}

	public function store(Request $request){
		if (!auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		try {
			$city = $request->only(['nome', 'codigo', 'uf']);


			$c = City::create($city);

			$output = [
				'success' => 1,
				'msg' => 'Sucesso'
			];
		} catch (\Exception $e) {
			\Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

			$output = [
				'success' => 0,
				'msg' => __("messages.something_went_wrong")
			];

		}

		return redirect('cities')->with('status', $output);
	}

}
