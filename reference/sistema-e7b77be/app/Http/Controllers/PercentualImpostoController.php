<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PercentualImpostoEstado;
use App\Models\Product;

class PercentualImpostoController extends Controller
{
    public function index(Request $request){

        $business_id = request()->session()->get('user.business_id');
        $data = PercentualImpostoEstado::where('business_id', $business_id)
        ->select('percentual_imposto_estados.uf', 'percentual_imposto_estados.id')
        ->join('products', 'products.id', '=', 'percentual_imposto_estados.product_id')
        ->groupBy('percentual_imposto_estados.uf')
        ->get();
        return view('percentual_imposto/index', compact('data'));
    }

    public function create(){
        $todosEstados = PercentualImpostoEstado::estados();
        $estados = [];
        foreach($todosEstados as $uf){
            $res = PercentualImpostoEstado::where('uf', $uf)->first();
            if($res == null){
                $estados[$uf] = $uf;
            }
        }
        return view('percentual_imposto/create', compact('estados'));

    }

    public function store(Request $request){
        $this->_validate($request);
        try{

            $business_id = request()->session()->get('user.business_id');

            $produtos = Product::
            where('business_id', $business_id)
            ->get();

            foreach($produtos as $p){
                PercentualImpostoEstado::where('product_id', $p->id)
                ->where('uf', $request->uf)
                ->delete();

                PercentualImpostoEstado::create([
                    'product_id' => $p->id,
                    'uf' => $request->uf,
                    'percentual_icms' => str_replace(',', '.',$request->percentual_icms),
                    'percentual_pis' => str_replace(',', '.', $request->percentual_pis),
                    'percentual_cofins' => str_replace(',', '.', $request->percentual_cofins),
                    'percentual_ipi' => str_replace(',', '.', $request->percentual_ipi),
                    'percentual_red_bc' => str_replace(',', '.', $request->percentual_red_bc),
                    'percentual_fcp' => str_replace(',', '.', $request->percentual_fcp),
                    'percentual_icms_interno' => str_replace(',', '.', $request->percentual_icms_interno)
                ]);
            }
            $output = [
                'success' => 1,
                'msg' => 'Percentual atribuído nos produtos UF ' . $request->uf
            ];

        }catch(\Exception $e){
            // session()->flash('mensagem_erro', 'Erro: ' . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => $e->getMessage()
            ];
            
        }
        return redirect()->route('percentual-imposto-estado.index')->with('status', $output);

    }

    private function _validate(Request $request){
        $rules = [
            'percentual_icms' => 'required',
            'percentual_red_bc' => 'required',
            'percentual_fcp' => 'required',
            'percentual_icms_interno' => 'required',
        ];

        $messages = [
            'percentual_icms.required' => 'Campo obrigatório.',
            'percentual_red_bc.required' => 'Campo obrigatório.',
            'percentual_fcp.required' => 'Campo obrigatório.',
            'percentual_icms_interno.required' => 'Campo obrigatório.',
        ];
        $this->validate($request, $rules, $messages);
    }

    public function show($id){
        $item = PercentualImpostoEstado::findOrFail($id);

        $data = PercentualImpostoEstado::where('uf', $item->uf)->get();

        return view('percentual_imposto/show', compact('item', 'data'));

    }

    public function updateItem(Request $request){
        $item = PercentualImpostoEstado::findOrFail($request->item_id);
        try{

            $item->percentual_icms = $request->percentual_icms;
            $item->percentual_pis = $request->percentual_pis;
            $item->percentual_cofins = $request->percentual_cofins;
            $item->percentual_ipi = $request->percentual_ipi;
            $item->percentual_fcp = $request->percentual_fcp;
            $item->percentual_icms_interno = $request->percentual_icms_interno;
            $item->percentual_red_bc = $request->percentual_red_bc;

            $item->save();

            $output = [
                'success' => 1,
                'msg' => 'Percentual editado!'
            ];

        }catch(\Exception $e){

            $output = [
                'success' => false,
                'msg' => $e->getMessage()
            ];
            
        }
        return redirect()->back()->with('status', $output);
    }

    public function destroyItem($id){

        try {
            PercentualImpostoEstado::
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

        return redirect()->back()->with('status', $output);
    }

}
