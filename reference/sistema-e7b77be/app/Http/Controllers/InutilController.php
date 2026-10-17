<?php

namespace App\Http\Controllers;

use App\Models\Inutil;
use App\Models\Business;
use App\Services\NFeService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InutilController extends Controller
{
    public $inutil;

    public function __construct()
    {
        $this->inutil = new Inutil();
    }

    public function index(Request $request)
    {
        $dataInicio = isset($request) ? $request->dataInicio : '';
        $dataFinal = isset($request) ? $request->dataFinal : '';

        if (!empty($dataInicio) && !empty($dataFinal)) {
            $this->inutil->where('created', '>=', $dataInicio)->where('created', '>=', $dataFinal);
        }

        return view('inutil.index',
            [
                'inutils' => $this->inutil->where('business_id',request()->user()->business_id)->selectRaw('nNFIni,nNFFin,serie,tpAmb,modelo,status,xJust,DATE_FORMAT(created_at, "%d/%m/%Y") as criado,id')->get()
            ]);


    }

    public function create()
    {
        return view('inutil.form');
    }

    public function edit($id)
    {

        $item = $this->inutil->find($id);
        if($item->business_id !== request()->user()->business_id){
            abort(403,'Não autorizado');
        }
        return view('inutil.form', compact('item'));
    }

    public function issue($id)
    {

        $item = $this->inutil->find($id);
        if($item->business_id !== request()->user()->business_id){
            abort(403,'Não autorizado');
        }
        return view('inutil.issue', compact('item'));
    }

    public function store(Request $request)
    {
        $data = [
            'business_id' => $request->user()->business_id ?? null,
            'nNFIni' => $request->nNFIni ?? null,
            'nNFFin' => $request->nNFFin ?? null,
            'serie' => $request->serie ?? null,
            'tpAmb' => $request->user()->business->ambiente,
            'modelo' => $request->modelo ?? null,
            'status' => $request->status ?? 'novo',
            'xJust' => $request->xJust ?? null,
        ];
        if($request->nNFIni>$request->nNFFin || $request->serie==0){
            $output = [
                'success' => 0,
                'msg' => __('Número de serie zerado ou numero de início maior que o fim.')
            ];
            return redirect()->route('inutilizacao.create')->with('status', $output)->withInput();
        }

        if (!in_array(null, [$data])) {
            Inutil::create($data);
            $output = [
                'success' => 1,
                'msg' => "Registro criado!"
            ];
            return redirect()->route('inutilizacao.index')->with('status', $output);
        } else {

            $output = [
                'success' => 0,
                'msg' => __('Por favor preencha todos os campos')
            ];
            return redirect()->route('inutilizacao.index')->with('status', $output);
        }


    }

    public function update(Request $request, $id)
    {


        $inutil = $this->inutil->find($id);


        $data = [];

        $data['business_id'] = $request->user()->business_id;
        $data ['nNFIni'] = $request->nNFIni;
        $data['nNFFin'] = $request->nNFFin;
        $data ['serie'] = $request->serie;
        $data ['tpAmb'] = $request->user()->business->ambiente;
        $data['modelo'] = $request->modelo;
        $data['status'] = 'novo';
        $data['xJust'] = $request->xJust;
        if($request->nNFIni>$request->nNFFin || $request->serie==0){
            $output = [
                'success' => 0,
                'msg' => __('Número de serie zerado ou numero de início maior que o fim.')
            ];
            return redirect()->route('inutilizacao.edit',$id)->with('status', $output);
        }
        if (!in_array(null, [$data])) {
            Inutil::find($id)->update($data);
            $output = [
                'success' => 1,
                'msg' => 'Salvo com sucesso!'
            ];

            return redirect()->route('inutilizacao.index')->with('status', $output);
        } else {
            $output = [
                'success' => 0,
                'msg' => __('Por favor preencha todos os campos')
            ];
            return redirect()->route('inutilizacao.index')->with('status', $output);
        }


    }

    public function issuePost(Request $request){
        try{
            $inutil = Inutil::findOrFail($request->id);

            $business_id = request()->session()->get('user.business_id');

            $config = Business::getConfig($business_id, $inutil);

            $cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

            $nfe_service = new NFeService([
                "atualizacao" => date('Y-m-d h:i:s'),
                "tpAmb" => (int)$config->ambiente,
                "razaosocial" => $config->razao_social,
                "siglaUF" => $config->cidade->uf,
                "cnpj" => $cnpj,
                "schemes" => "PL_009_V4",
                "versao" => "4.00",
                "tokenIBPT" => "AAAAAAA",
                "CSC" => $config->csc,
                "CSCid" => $config->csc_id
            ], $config);

            $result = $nfe_service->inutilizar($inutil);
            if(isset($result['infInut']['cStat'])){
                $cStat = $result['infInut']['cStat'];
                if($cStat != 102){
                    return response()->json($result, 401);
                }
                $inutil->status = 'aprovado';
                $inutil->save();
                return response()->json($result, 200);

            }else{
                return response()->json($result, 401);

            }
        }catch(\Exception $e){
            return response()->json($e->getMessage(), 401);
        }
    }
}
