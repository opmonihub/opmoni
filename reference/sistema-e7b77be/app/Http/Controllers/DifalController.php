<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Difal;

class DifalController extends Controller
{
    //
    public function index(Request $request){

        $business_id = request()->session()->get('user.business_id');
        $data = Difal::where('business_id', $business_id)->get();
        return view('difal/index', compact('data'));
    }

    public function create(){

        $todosEstados = Difal::estados();
        $estados = [];
        foreach($todosEstados as $uf){
            $res = Difal::where('uf', $uf)->first();
            if($res == null){
                $estados[$uf] = $uf;
            }
        }

        return view('difal/create', compact('estados'));
    }

    public function edit($id){
        $item = Difal::findOrFail($id);
        // $todosEstados = Difal::estados();
        // $estados = [];
        // foreach($todosEstados as $uf){
        //     $res = Difal::where('uf', $uf)->first();
        //     if($res == null){
        //         $estados[$uf] = $uf;
        //     }
        // }
        return view('difal/create', compact('item'));
    }

    public function store(Request $request){
        if($request->difal_id == 0){
            $business_id = request()->session()->get('user.business_id');
            
            $result = Difal::create(
                [
                    'business_id' => $business_id,
                    'uf' => $request->uf,
                    'cfop' => $request->cfop,
                    'pICMSUFDest' => str_replace(",", ".", $request->pICMSUFDest),
                    'pICMSInter' => str_replace(",", ".", $request->pICMSInter),
                    'pICMSInterPart' => str_replace(",", ".", $request->pICMSInterPart),
                    'pFCPUFDest' => str_replace(",", ".", $request->pFCPUFDest),
                ]
            );
            $output = [
                'success' => 1,
                'msg' => 'Registro criado'
            ];
        }else{
            $item = Difal::find($request->difal_id);
            $item->pICMSUFDest = $request->pICMSUFDest;
            $item->cfop = $request->cfop;
            $item->pICMSInter = $request->pICMSInter;
            $item->pICMSInterPart = $request->pICMSInterPart;
            $item->pFCPUFDest = $request->pFCPUFDest;
            $item->save();

            $output = [
                'success' => 1,
                'msg' => 'Registro atualizado'
            ];
        }

        return redirect()->route('difal.index')->with('status', $output);

    }

    public function destroy($id){
        try {
            Difal::
            where('id', $id)->delete();

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

        return redirect()->route('difal.index')->with('status', $output);
    }
}
