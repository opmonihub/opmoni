<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Remessa;
use App\Models\Boleto;
use App\Helpers\BoletoHelper;

class RemessaController extends Controller
{
    public function index(){

        $business_id = request()->session()->get('user.business_id');

        $remessas = Remessa::orderBy('id', 'desc')
        ->where('business_id', $business_id)
        ->get();

        return view('boletos/remessas', compact('remessas'));
    }

    public function download($id){
        $remessa = Remessa::findOrFail($id);
        $nameFile = $remessa->nome_arquivo;
        return response()->download(public_path('remessas'). "/$nameFile.txt");

    }

    public function destroy($id)
    {
        $item = Remessa::findOrFail($id);

        try {
            $item->delete();

            $output = [
                'success' => 1,
                'msg' => 'Remessa removida.'
            ];

        } catch (\Exception $e) {
            $output = [
                'success' => 0,
                'msg' => __("messages.something_went_wrong")
            ];
        }
        return redirect()->back()->with('status', $output);
    }

    public function boletosSemRemessa(){
        $business_id = request()->session()->get('user.business_id');

        $boletos = Boleto::
        select('boletos.*')
        ->join('revenues', 'revenues.id' , '=', 'boletos.revenue_id')
        ->orderBy('boletos.id', 'desc')
        ->where('revenues.business_id', $business_id)
        ->limit(100)
        ->get();

        $temp = [];
        foreach($boletos as $b){
            if(!$b->itemRemessa){
                array_push($temp, $b);
            }
        }

        $boletos = $temp;
        return view('boletos/sem_remessa', compact('boletos'));

    }

    public function gerarRemessaMulti($ids){
        $ids = explode(",", $ids);
        $boletos = [];

        foreach($ids as $b){
            $boleto = Boleto::findOrFail($b);
            if(!$boleto->itemRemessa){
                array_push($boletos, $boleto);
            }else{
                $output = [
                    'success' => 0,
                    'msg' => "Algum dos boletos selecionados esta com remessa gerada!"
                ];
                return redirect()->back();
            }
        }

        $bancoId = $boletos[0]->bank_id;

        foreach($boletos as $b){
            if($b->bank_id != $bancoId){
                $output = [
                    'success' => 0,
                    'msg' => "Informe os boletos para o mesmo banco para gerar a remessa!"
                ];
                return redirect()->back();
            }
        }

        $boletoHelper = new BoletoHelper();
        $result = $boletoHelper->gerarRemessaMulti($boletos);

        // $output = [
        //     'success' => 1,
        //     'msg' => "Remessa gerada."
        // ];
        // return redirect()->back();
    }
}
