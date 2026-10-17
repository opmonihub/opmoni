<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Ibpt;
use App\Models\ItemIbpt;

use App\Utils\ModuleUtil;
use App\Models\System;

class IbptController extends Controller
{

	public function __construct(ModuleUtil $moduleUtil)
	{
		$this->moduleUtil = $moduleUtil;
	}

	public function index(){
		$tabelas = Ibpt::all();
		
		return view('ibpt/index')
		->with('tabelas', $tabelas);
	}

	public function create(){

		$todos = Ibpt::estados();
		$estados = [];
		foreach($todos as $uf){
			$res = Ibpt::where('uf', $uf)->first();
			if($res == null){
				$estados[$uf] = $uf;
			}
		}

		return view('ibpt/create')
		->with('estados', $estados);
	}

	public function edit($id){
		$ibpt = Ibpt::find($id);
		
		return view('ibpt/create')
		->with('ibpt', $ibpt);
	}

	public function store(Request $request){
		if ($request->hasFile('file')){


			$file = $request->file;
			$handle = fopen($file, "r");
			$row = 0;
			$linhas = [];

			if($request->ibpt_id == 0){
				$result = Ibpt::create(
					[
						'uf' => $request->uf,
						'versao' => $request->versao,
					]
				);
			}else{
				$result = Ibpt::find($request->ibpt_id);
				$result->versao = $request->versao;
				$result->save();
				ItemIbpt::where('ibte_id', $request->ibpt_id)->delete();
			}

			while ($line = fgetcsv($handle, 1000, ";")) {
				if ($row++ == 0) {
					continue;
				}
				
				$data = [
					'ibte_id' => $result->id,
					'codigo' => $line[0],
					'descricao' => $line[3],
					'nacional_federal' => $line[4],
					'importado_federal' => $line[5],
					'estadual' => $line[6],
					'municipal' => $line[7] 
				];
				ItemIbpt::create($data);

			}
			if($request->ibpt_id > 0){
				$output = [
					'success' => 1,
					'msg' => 'Importação atualizada para '.$request->uf
				];
			}else{
				
				$output = [
					'success' => 1,
					'msg' => 'Importação concluída para '.$request->uf
				];
			}
			return redirect("/ibpt");


		}else{
			if($request->ibpt_id > 0){
				$result = Ibpt::find($request->ibpt_id);
				$result->versao = $request->versao;
				$output = [
					'success' => 1,
					'msg' => 'Versão atualizada!'
				];
				$result->save();
			}else{
				session()->flash('mensagem_erro', 'Arquivo inválido!');
				$output = [
					'success' => 1,
					'msg' => 'Arquivo inválido!'
				];
			}
			return redirect("/ibpt")->with('status', $output);
		}
	}

	public function destroy($id){
		try {
			Ibpt::
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

		return redirect('ibpt')->with('status', $output);
	}

	public function list($id){
		$ibpt = Ibpt::find($id);
		$itens = ItemIbpt::where('ibte_id', $id)->paginate(100);

		return view('ibpt/view')
		->with('ibpt', $ibpt)
		->with('itens', $itens)
		->with('links', true);
	}

}
