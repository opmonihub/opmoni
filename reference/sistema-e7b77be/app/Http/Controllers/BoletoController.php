<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Revenue;
use App\Models\Bank;
use App\Models\Boleto;
use App\Helpers\BoletoHelper;

class BoletoController extends Controller
{
    public function create($conta_id){

        $business_id = request()->session()->get('user.business_id');

        $revenue = Revenue::findorfail($conta_id);
        $banks = Bank::where('business_id', $business_id)->get();

        $padrao = Bank::where('business_id', $business_id)
        ->where('padrao', 1)
        ->first();

        return view('boletos/create', compact('revenue', 'banks', 'padrao'));
    }

    public function store(Request $request){
        $this->_validate($request);

        $data = [
            'bank_id' => $request->banco,
            'revenue_id' => $request->revenue_id,
            'numero' => $request->numero,
            'numero_documento' => $request->numero_documento,
            'carteira' => $request->carteira,
            'convenio' => $request->convenio,
            'linha_digitavel' => '',
            'nome_arquivo' => '',
            'juros' => $request->juros ?? 0,
            'multa' => $request->multa ?? 0,
            'juros_apos' => $request->juros_apos ?? 0,
            'instrucoes' => $request->instrucoes ?? "",
            'logo' => $request->logo ? true : false,
            'tipo' => $request->tipo,
            'codigo_cliente' => $request->codigo_cliente ?? '',
            'posto' => $request->posto ?? ''
        ];

        $boleto = Boleto::create($data);

        $boletoHelper = new BoletoHelper();
        $result = $boletoHelper->gerar($boleto);

        if(!isset($result['erro'])){

            if(!is_dir(public_path('boletos'))){
                mkdir(public_path('boletos'), 0777, true);
            }

            $link = "/boletos/$result";

            $output = [
                'success' => 1,
                'msg' => 'Boleto gerado.'
            ];

            $outputBoleto = [
                'url' => "/boletos/ver/" . $request->revenue_id,
                'msg' => 'Boleto gerado deseja fazer o download?'
            ];

            return redirect('revenues')
            ->with('status', $output)
            ->with('link', $outputBoleto);
        }else{

            $boleto->delete();

            $output = [
                'success' => 0,
                'msg' => $result['mensagem']
            ];

            return redirect('revenues')->with('status', $output);
        }

    }

    private function _validate(Request $request){
        $contaBancaria = bank::find($request->banco);

        $rules = [
            'banco' => 'required',
            'numero' => 'required',
            'numero_documento' => 'required',
            'carteira' => 'required',
            'convenio' => 'required|min:4|max:7',
            'posto' => ($contaBancaria != null && $contaBancaria->banco == '748') ? 'required' : '',
            'codigo_cliente' => ($contaBancaria != null && $contaBancaria->banco == '748' && $contaBancaria->banco == '104') ? 'required' : '',
        ];

        $messages = [
            'banco.required' => 'O campo banco é obrigatório.',
            'numero.required' => 'O campo número é obrigatório.',
            'numero_documento.required' => 'O campo número do documento é obrigatório.',
            'carteira.required' => 'O campo carteira é obrigatório.',
            'convenio.required' => 'O campo convênio é obrigatório.',

            'posto.required' => 'O campo posto é obrigatório.',
            'codigo_cliente.required' => 'O campo posto é obrigatório.',

            'convenio.min' => 'O código do convênio precisa ter 4, 6 ou 7 dígitos!',
            'convenio.max' => 'O código do convênio precisa ter 4, 6 ou 7 dígitos!',

        ];

        $this->validate($request, $rules, $messages);
    }

    public function ver($conta_id){
        $revenue = Revenue::findorfail($conta_id);
        $file = public_path('boletos')."/".$revenue->boleto->nome_arquivo.".pdf";

        if(file_exists($file)){
            // return redirect($file);
            return response()->download($file);
        }else{

            $output = [
                'success' => 0,
                'msg' => 'Arquivo não encontrado!'
            ];
            return redirect('revenues')->with('status', $output);

        }
    }

    public function gerarRemessa($conta_id){
        $revenue = Revenue::findorfail($conta_id);
        $boleto = $revenue->boleto;

        if($boleto != null){
            $boletoHelper = new BoletoHelper();
            $boleto = $boletoHelper->gerarRemessa($boleto);
        }else{
            $output = [
                'success' => 0,
                'msg' => 'Gere o boleto.'
            ];
            return redirect('revenues')->with('status', $output);

        }
    }

    public function gerarMultiplos($ids){

        $ids = explode(",", $ids);

        $contas = [];
        foreach($ids as $c){

            $conta = Revenue::findorfail($c);
            if($conta->boleto){
                $output = [
                    'success' => 0,
                    'msg' => 'Gere o boleto.'
                ];
                return redirect('revenues')->with('status', $output);
            }

            array_push($contas, $conta);

        }

        $arrJson = [];

        foreach($contas as $key => $t){
            $a = [
                'cont' => $key+1,
                'id' => $t->id,
                'numero_documento' => '',
                'numero_boleto' => '',
                'juros' => 0,
                'multa' => 0,
                'juros_apos' => 0,
            ];
            array_push($arrJson, $a);
        }

        $business_id = request()->session()->get('user.business_id');

        $banks = Bank::where('business_id', $business_id)->get();

        $padrao = Bank::where('business_id', $business_id)
        ->where('padrao', 1)
        ->first();

        return view('boletos/create_multi', compact('contas', 'banks', 'padrao', 'arrJson'));


    }

    public function storeMulti(Request $request){

        // echo "<pre>";

        // print_r($request->all());
        // echo "</pre>";
        //  die;

        if(!is_dir(public_path('boletos'))){
            mkdir(public_path('boletos'), 0777, true);
        }

        $error_msg = "";
        foreach($request->payment as $pay){
            $data = [
                'bank_id' => $request->banco,
                'revenue_id' => $pay['id'],
                'numero' => $pay['numero'],
                'numero_documento' => $pay['numero_documento'],
                'carteira' => $request->carteira,
                'convenio' => $request->convenio,
                'linha_digitavel' => '',
                'nome_arquivo' => '',
                'juros' => $pay['juros'],
                'multa' => $pay['multa'],
                'juros_apos' => $pay['juros_apos'],
                'instrucoes' => $request->instrucoes ?? "",
                'logo' => $request->logo ? true : false,
                'tipo' => $request->tipo,
                'codigo_cliente' => $request->codigo_cliente ?? '',
                'posto' => $request->posto ?? ''
            ];

            $boleto = Boleto::create($data);

            $boletoHelper = new BoletoHelper();
            $result = $boletoHelper->gerar($boleto);

            if(!isset($result['erro'])){
                $link = "/boletos/$result";
            }else{
                $boleto->delete();
                $error_msg = " " . $result['mensagem'];
            }


        }

        if($error_msg != ""){
            $output = [
                'success' => 0,
                'msg' => $result['mensagem']
            ];
        }else{
            $output = [
                'success' => 1,
                'msg' => 'Boletos gerados.'
            ];
        }
        return redirect('revenues')->with('status', $output);

        
    }
}
