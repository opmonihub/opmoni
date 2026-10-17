<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\Facades\DataTables;
use App\Utils\ModuleUtil;
use App\Models\Bank;
use App\Models\Test;
use App\Models\City;

class BankController extends Controller
{
    public function __construct(ModuleUtil $moduleUtil)
    {
        $this->moduleUtil = $moduleUtil;
    }

    public function index(){

        if (!auth()->user()->can('revenues.access')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $banks = Bank::where('business_id', $business_id)
            ->select(['id', 'banco', 'agencia', 'conta', 'titular']);


            return Datatables::of($banks)
            ->addColumn(
                'action',
                function ($row) {
                    $html = '<a href="'.action('BankController@edit', [$row->id]).'" class="btn btn-xs btn-primary"><i class="glyphicon glyphicon-edit"></i> Editar </a>';
                    $html .= '&nbsp;<button data-href="'.action('BankController@destroy', [$row->id]).'" class="btn btn-xs btn-danger delete_button"><i class="glyphicon glyphicon-trash"></i> Excluir</button>';
                    return $html;
                }
            )

            ->removeColumn('id')
            ->rawColumns(['action'])
            ->make(true);

        }
        return view('banks.list');

    }

    public function create(){
        $cities = $this->prepareCities();
        return view('banks.register', compact('cities'));
    }

    public function edit($id){
        $cities = $this->prepareCities();
        $item = Bank::findOrFail($id);
        return view('banks.edit', compact('cities', 'item'));
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
        $this->_validate($request);
        try {
            $business_id = $request->session()->get('user.business_id');
            $request->merge(['business_id' => $business_id]);

            Bank::create($request->all());
            $output = [
                'success' => 1,
                'msg' => 'Conta Bancária cadastrada!'
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __("messages.something_went_wrong")
            ];

        }
        return redirect('/bank')->with('status', $output);
    }

    public function update(Request $request, $id){
        $this->_validate($request);
        try {
            $business_id = $request->session()->get('user.business_id');

            $item = Bank::findOrFail($id);
            $item->fill($request->all())->save();
            
            $output = [
                'success' => 1,
                'msg' => 'Conta Bancária atualizada!'
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __("messages.something_went_wrong")
            ];

        }
        return redirect('/bank')->with('status', $output);
    }

    private function _validate($request){
        $validator = $request->validate(
            [
                'agencia' => 'required|max:10',
                'conta' => 'required|max:15',
                'conta' => 'required|max:15',
                'titular' => 'required|max:45',
                'cep' => 'required',
                'cnpj' => 'required',
                'cidade_id' => 'required',
                'bairro' => 'required|max:30',
                'endereco' => 'required|max:50',
                'carteira' => 'required|max:10',
                'convenio' => 'required|max:20',
                'juros' => 'required',
                'multa' => 'required',
                'juros_apos' => 'required',
            ],
            [
                'agencia.required' => 'Campo obrigatório',
                'conta.required' => 'Campo obrigatório',
                'agencia.max' => 'Máximo de 10 caracteres',
                'conta.max' => 'Máximo de 15 caracteres',
                'titular.required' => 'Campo obrigatório',
                'titular.max' => 'Máximo de 45 caracteres',
                'cep.required' => 'Campo obrigatório',
                'bairro.required' => 'Campo obrigatório',
                'bairro.max' => 'Máximo de 30 caracteres',
                'endereco.required' => 'Campo obrigatório',
                'endereco.max' => 'Máximo de 50 caracteres',
                'cnpj.required' => 'Campo obrigatório',
                'cidade_id.required' => 'Campo obrigatório',
                'carteira.required' => 'Campo obrigatório',
                'carteira.max' => 'Máximo de 10 caracteres',
                'convenio.required' => 'Campo obrigatório',
                'convenio.max' => 'Máximo de 20 caracteres',
                'juros.required' => 'Campo obrigatório',
                'multa.required' => 'Campo obrigatório',
                'juros_apos.required' => 'Campo obrigatório',
            ]
        );
    }

    public function destroy($id)
    {

        if (request()->ajax()) {
            try {
                $business_id = request()->user()->business_id;

                $bank = Bank::where('business_id', $business_id)->findOrFail($id);
                $bank->delete();
                $output = [
                    'success' => true,
                    'msg' => __("contact.deleted_success")
                ];

            } catch (\Exception $e) {
                \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

                $output = [
                    'success' => false,
                    'msg' => 'Aldo ocorreu errado.'
                ];
            }

            return $output;
        }
    }

    public function find($id){
        try{
            $bank = Bank::findOrFail($id);
            return response()->json($bank);
        } catch (\Exception $e) {
            return response()->json($e->getMessage(), 401);
        }
    }

}
