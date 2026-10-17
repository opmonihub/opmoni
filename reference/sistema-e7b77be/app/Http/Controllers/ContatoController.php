<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ContatoEcommerce;
use Yajra\DataTables\Facades\DataTables;

class ContatoController extends Controller
{
    public function index(){
		if (!auth()->user()->can('user.view') && !auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		if (request()->ajax()) {
			$business_id = request()->session()->get('user.business_id');
			$user_id = request()->session()->get('user.id');
			$contatos = ContatoEcommerce::where('business_id', $business_id)
			->select(['id', 'nome', 'email', 'texto']);


			return Datatables::of($contatos)

			->addColumn(
				'action',
				'<button onclick="verTexto(\'{{$texto}}\')" class="btn btn-xs btn-primary"><i class="glyphicon glyphicon-comment"></i></button>
				&nbsp;'
			)

			->removeColumn('id')
			->rawColumns(['action'])
			->make(true);

		}
		return view('contatos.list');

	}
}
