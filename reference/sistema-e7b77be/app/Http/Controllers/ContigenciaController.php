<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Contigencia;
use App\Models\Transaction;
use App\Models\Business;
use NFePHP\NFe\Factories\Contingency;

class ContigenciaController extends Controller
{
    public function index(){
        $business_id = request()->session()->get('user.business_id');

        $data = Contigencia::
        where('business_id', $business_id)
        ->orderBy('id', 'desc')
        ->get();

        return view('contigencia.index', compact('data'));
    }

    public function create(){
        return view('contigencia.create');
    }
    public function store(Request $request){

        $business_id = request()->session()->get('user.business_id');

        $active = Contigencia::
        where('business_id', $business_id)
        ->where('status', 1)
        ->where('documento', $request->documento)
        ->first();
        if($active){
            session()->flash('mensagem_erro', "Já existe uma contigência para $request->documento ativada!");
            return redirect()->back();
        }
        try{
            $item = Contigencia::create([
                'business_id' => $business_id,
                'status' => 1,
                'tipo' => $request->tipo,
                'documento' => $request->documento,
                'motivo' => $request->motivo,
                'status_retorno' => ''
            ]);

            $config = Business::findOrFail($business_id);

            $contingency = new Contingency();

            $acronym = $config->cidade->uf;
            $motive = $request->motivo;
            $type = $request->tipo;

            $status_retorno = $contingency->activate($acronym, $motive, $type);
            $item->status_retorno = $status_retorno;
            $item->save();

            $output = [
                'success' => true,
                'msg' => "Contigencia ativada!"
            ];
        }catch(\Exception $e){
            $output = [
                'success' => false,
                'msg' => "Algo deu errado: " . $e->getMessage()
            ];
        }
        return redirect()->route('contigencia.index')->with('status', $output);
    }

    private function _validate(Request $request){
        $rules = [
            'motivo' => 'required|max:255|min:15'
        ];

        $messages = [
            'motivo.required' => 'O campo nome é obrigatório.',
            'motivo.max' => '255 caracteres maximos permitidos.',
            'motivo.min' => '15 caracteres minímos permitidos.'
        ];
        $this->validate($request, $rules, $messages);
    }

    public function desactive($id){
        $item = Contigencia::findOrFail($id);
        $item->status = 0;

        $contingency = new Contingency($item->status_retorno);
        $status = $contingency->deactivate();

        $item->save();
        session()->flash("mensagem_sucesso", "Contigencia ddesativada!");
        return redirect()->back();

    }

    public function xml($id){
        $transaction = Transaction::findOrFail($id);
        if(file_exists(public_path('xml_nfce_contigencia/'.$transaction->chave.'.xml'))){
            $xml = file_get_contents(public_path('xml_nfce_contigencia/'.$transaction->chave.'.xml'));
            return response($xml)
            ->header('Content-Type', 'application/xml');
        }else{
            return redirect()->back()
            ->with('status', [
                'success' => 0,
                'msg' => 'Arquivo não encontrado!!'
            ]);
        }
    }
}
