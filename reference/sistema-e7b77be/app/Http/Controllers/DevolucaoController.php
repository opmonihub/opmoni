<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Devolucao;
use App\Models\ItemDevolucao;
use App\Models\Business;
use App\Models\Contact;
use App\Models\City;
use App\Models\Product;
use App\Models\Unit;
use App\Models\BusinessLocation;
use App\Models\NaturezaOperacao;
use NFePHP\DA\NFe\Danfe;
use NFePHP\DA\Legacy\FilesFolders;
use NFePHP\DA\NFe\Daevento;
use App\Services\DevolucaoService;
use Illuminate\Support\Facades\DB;

class DevolucaoController extends Controller
{
	public function index(){
		$business_id = request()->session()->get('user.business_id');
		$business = Business::find($business_id);
		$erros = [];
		if($business->cnpj == '00.000.000/0000-00'){
			$msg = 'Informe a configuração do emitente';
			array_push($erros, $msg);
		}

		if(sizeof($erros) > 0){
			return view('nfe.erros')
			->with(compact('erros'));
		}

		if (!auth()->user()->can('user.view') && !auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		return view('devolucao.index');
	}

	public function verXml(Request $request){
		try{
			if ($request->hasFile('file')){

				$arquivo = $request->hasFile('file');
				$xml = simplexml_load_file($request->file);

				$msgImport = "";
				if(!$xml->NFe->infNFe){
					$output = [
						'success' => 0,
						'msg' => 'Não foi possível ler este XML!!'
					];
					return redirect()->back()->with('status', $output);
				}

				if($msgImport == ""){
					$user_id = $request->session()->get('user.id');
					$business_id = request()->session()->get('user.business_id');

					$cidade = City::getCidadeCod($xml->NFe->infNFe->emit->enderEmit->cMun);
					$contact = [
						'business_id' => $business_id,
						'city_id' => $cidade->id,
						'cpf_cnpj' => $xml->NFe->infNFe->emit->CNPJ ? 
						$this->formataCnpj($xml->NFe->infNFe->emit->CNPJ) : 
						$this->formataCpf($xml->NFe->infNFe->emit->CPF),
						'ie_rg' => $xml->NFe->infNFe->emit->IE,
						'consumidor_final' => 1,
						'contribuinte' => 1,
						'rua' => $xml->NFe->infNFe->emit->enderEmit->xLgr,
						'numero' => $xml->NFe->infNFe->emit->enderEmit->nro,
						'bairro' => $xml->NFe->infNFe->emit->enderEmit->xBairro,
						'cep' => $xml->NFe->infNFe->emit->enderEmit->CEP,
						'type' => 'supplier',
						'name' => $xml->NFe->infNFe->emit->xNome,
						'mobile' => '',
						'created_by' => $user_id
					];

					$cnpj = $contact['cpf_cnpj'];
					$fornecedorNovo = Contact::where('cpf_cnpj', $cnpj)
					// ->where('type', 'supplier')
					->first();

					$resFornecedor = $this->validaFornecedorCadastrado($contact);

					$contact['id'] = $resFornecedor->id;
					$itens = [];
					$contSemRegistro = 0;

					$fornecedores = Contact::where('business_id', $business_id)
					// ->where('type', 'supplier')
					->get();

					foreach($xml->NFe->infNFe->det as $item) {

						$produto = $this->validaProdutoCadastrado($item->prod->cEAN,
							$item->prod->xProd);

						$produtoNovo = $produto == null ? true : false;

						if($produtoNovo) $contSemRegistro++;

						$trib = Devolucao::getTrib($item->imposto);

						$cod = str_replace("/", "_", $item->prod->cProd);
						$cod = str_replace(".", "_", $cod);

						$tagComb = null;
						if($item->prod->comb){
							$tagComb = $item->prod->comb;
						}
						$item = [
							'codigo' => $cod,
							'xProd' => $item->prod->xProd,
							'NCM' => $item->prod->NCM,
							'CEST' => isset($item->prod->CEST) ? (string)$item->prod->CEST : '',
							'CFOP' => Devolucao::cfopDevolucao((string)$item->prod->CFOP),
							'CFOP_origem' => (string)$item->prod->CFOP,
							'uCom' => $item->prod->uCom,
							'vUnCom' => $item->prod->vUnCom,
							'vUnTrib' => isset($item->prod->vUnTrib) ? (float)$item->prod->vUnTrib : (float)$item->prod->vUnCom,
							'qCom' => $item->prod->qCom,
							'codBarras' => $item->prod->cEAN,
							'produtoNovo' => $produtoNovo,
							'produtoId' => $produtoNovo ? '0' : $produto->id,
							'cst_csosn'=> $trib['cst_csosn'],
							'cst_pis'=> $trib['cst_pis'],
							'cst_cofins'=> $trib['cst_cofins'],
							'cst_ipi'=> $trib['cst_ipi'],
							'perc_icms' => $trib['pICMS'],
							'perc_pis' => $trib['pPIS'],
							'perc_cofins' => $trib['pCOFINS'],
							'perc_ipi' => $trib['pIPI'],
							'pRedBC' => $trib['pRedBC'],
							'modBC' => $trib['modBC'],
							'vBC' => $trib['vBC'],
							'vICMS' => $trib['vICMS'],
							'vIPI' => $trib['vIPI'],
							'cEnq' => $trib['cEnq'],

							'unidade_tributavel' => (string)$item->prod->uTrib,
							'quantidade_tributavel' => (float)$item->prod->qTrib,
							'codigo_anp' => $tagComb != null ? (string)$tagComb->cProdANP : '',
							'descricao_anp' => $tagComb != null ? (string)$tagComb->descANP : '',
							'uf_cons' => $tagComb != null ? (string)$tagComb->UFCons : '',
							'perc_glp' => $tagComb != null ? (float)$tagComb->pGLP : 0,
							'perc_gnn' => $tagComb != null ? (float)$tagComb->pGNn : 0,
							'perc_gni' => $tagComb != null ? (float)$tagComb->pGNi : 0,
							'valor_partida' => $tagComb != null ? (float)$tagComb->vPart : 0,

							'modBCST' => $trib['modBCST'],
							'vBCST' => $trib['vBCST'],
							'pICMSST' => $trib['pICMSST'],
							'vICMSST' => $trib['vICMSST'],
							'vBCSTRet' => $trib['vBCSTRet'],
							'pMVAST' => $trib['pMVAST'],
							'pST' => $trib['pST'],
							'vICMSSubstituto' => $trib['vICMSSubstituto'],
							'vICMSSTRet' => $trib['vICMSSTRet'],
							'orig' => $trib['orig'],

							'vbcPis' => $trib['vbcPis'],
							'vbcCofins' => $trib['vbcCofins'],
							'vbcIpi' => $trib['vbcIpi'],

						];
						array_push($itens, $item);
						
					}
					// echo "<pre>";
					// print_r($itens);
					// echo "</pre>";
					// die();

					$chave = substr($xml->NFe->infNFe->attributes()->Id, 3, 44);
					$vFrete = number_format((double) $xml->NFe->infNFe->total->ICMSTot->vFrete, 2, ",", ".");
					$vSeguro = number_format((double) $xml->NFe->infNFe->total->ICMSTot->vSeguro, 2, ",", ".");
					$vOutro = number_format((double) $xml->NFe->infNFe->total->ICMSTot->vOutro, 2, ",", ".");

					$vDesc = $xml->NFe->infNFe->total->ICMSTot->vDesc;

					$dadosNf = [
						'chave' => $chave,
						'vProd' => $xml->NFe->infNFe->total->ICMSTot->vProd,
						'indPag' => $xml->NFe->infNFe->ide->indPag,
						'nNf' => $xml->NFe->infNFe->ide->nNF,
						'vFrete' => $vFrete,
						'vDesc' => $vDesc,
						'vSeguro' => $vSeguro,
						'vOutro' => $vOutro,
						'novoFornecedor' => $fornecedorNovo == null ? true : false
					];

					$fatura = [];
					if (!empty($xml->NFe->infNFe->cobr->dup))
					{
						foreach($xml->NFe->infNFe->cobr->dup as $dup) {
							$titulo = $dup->nDup;
							$vencimento = $dup->dVenc;
							$vencimento = explode('-', $vencimento);
							$vencimento = $vencimento[2]."/".$vencimento[1]."/".$vencimento[0];
							$vlr_parcela = number_format((double) $dup->vDup, 2, ",", ".");	

							$parcela = [
								'numero' => $titulo,
								'vencimento' => $vencimento,
								'valor_parcela' => $vlr_parcela
							];
							array_push($fatura, $parcela);
						}
					}

					$infoFrete = null;

					if($xml->NFe->infNFe->transp->transporta){
						$transp = $xml->NFe->infNFe->transp->transporta;
						$transportadoraDoc = (int)$transp->CNPJ;

						$vol = $xml->NFe->infNFe->transp->vol;
						$modFrete = $xml->NFe->infNFe->transp->modFrete;

						$infoFrete = [
							'transportadora_nome' => (string)$transp->xNome,
							'transportadora_cidade' => (string)$transp->xMun,
							'transportadora_uf' => (string)$transp->UF,
							'transportadora_cpf_cnpj' => (string)$transp->CNPJ,
							'transportadora_ie' => (int)$transp->IE,
							'transportadora_endereco' => (string)$transp->xEnder,
							'frete_quantidade' => (float)$vol->qVol,
							'frete_especie' => (string)$vol->esp,
							'frete_marca' => '',
							'frete_numero' => 0,
							'frete_tipo' => (int)$modFrete,
							'veiculo_placa' => '',
							'veiculo_uf' => '',
							'frete_peso_bruto' => (float)$vol->pesoB, 
							'frete_peso_liquido' => (float)$vol->pesoL,
							'despesa_acessorias' => (float)$xml->NFe->infNFe->total->ICMSTot->vOutro
						];
					}

					$business_id = request()->session()->get('user.business_id');

					$business = Business::find($business_id);
					$cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);

					$file = $request->file;
					$file_name = $chave . ".xml" ;


					if(!is_dir(public_path('xml_entrada/'.$cnpj))){
						mkdir(public_path('xml_entrada/'.$cnpj), 0777, true);
					}

					$pathXml = $file->move(public_path('xml_entrada/'.$cnpj), $file_name);

					$business_locations = BusinessLocation::forDropdown($business_id, false, true);
					$bl_attributes = $business_locations['attributes'];
					$business_locations = $business_locations['locations'];

					$default_location = null;
					if (count($business_locations) == 1) {
						foreach ($business_locations as $id => $name) {
							$default_location = BusinessLocation::findOrFail($id);
						}
					}

					return view('devolucao.view_xml')
					->with('naturezas', $this->prepareNaturezas())
					->with('contact' , $contact)
					->with('itens' , $itens)
					->with('cidade' , $cidade)
					->with('casas_decimais' , $business->casas_decimais_valor)
					->with('infoFrete' , $infoFrete)
					->with('fatura' , $fatura)
					->with('fornecedores' , $fornecedores)
					->with('estados', $this->prepareEstados())
					->with('tiposFrete', $this->prepareTiposFrete())
					->with('bl_attributes' , $bl_attributes)
					->with('default_location' , $default_location)
					->with('business_locations' , $business_locations)
					->with('dadosNf' , $dadosNf);

				}else{
					$output = [
						'success' => 0,
						'msg' => 'XML já importado na base de dados!!'
					];

					return back()->with('status', $output);
				}

			}else{

			}

		}catch(\Exception $e){
			echo $e->getMessage();
		}
	}

	private function prepareEstados(){

		return [
			'AC' => 'AC',
			'AL' => 'AL',
			'AM' => 'AM',
			'AP' => 'AP',
			'BA' => 'BA',
			'CE' => 'CE',
			'DF' => 'DF',
			'ES' => 'ES',
			'GO' => 'GO',
			'MA' => 'MA',
			'MG' => 'MG',
			'MS' => 'MS',
			'MT' => 'MT',
			'PA' => 'PA',
			'PB' => 'PB',
			'PE' => 'PE',
			'PI' => 'PI',
			'PR' => 'PR',
			'RJ' => 'RJ',
			'RN' => 'RN',
			'RO' => 'RO',
			'RR' => 'RR',
			'RS' => 'RS',
			'SC' => 'SC',
			'SP' => 'SP',
			'SE' => 'SE',
			'TO' => 'TO'
		];

	}

	public function editFiscal($id){

		$item = Devolucao::findorfail($id);
		return view('devolucao.edit_fiscal', compact('item'));
	}

	public function edit($id){

		$item = Devolucao::findorfail($id);
		$business_id = request()->session()->get('user.business_id');

		$business_locations = BusinessLocation::forDropdown($business_id, false, true);
		$bl_attributes = $business_locations['attributes'];
		$business_locations = $business_locations['locations'];

		$default_location = null;
		if (count($business_locations) == 1) {
			foreach ($business_locations as $id => $name) {
				$default_location = BusinessLocation::findOrFail($id);
			}
		}

		return view('devolucao.edit', compact('item', 'business_locations', 'default_location', 'bl_attributes'))
		->with('naturezas', $this->prepareNaturezas())
		->with('tiposFrete', $this->prepareTiposFrete())
		->with('estados', $this->prepareEstados());
	}

	private function prepareTiposFrete(){
		return [
			'0' => 'Emitente',
			'1' => 'Destinatário',
			'2' => 'Terceiros',
			'9' => 'Sem frete',
		];
	}

	private function prepareNaturezas(){
		$business_id = request()->session()->get('user.business_id');

		$naturezas = NaturezaOperacao::
		where('business_id', $business_id)
		->get();
		$temp = [];
		foreach($naturezas as $n){
			$temp[$n->id] = $n->natureza . " ($n->cfop_saida_estadual/$n->cfop_saida_inter_estadual)";
		}

		return $temp;
	}

	private function validaFornecedorCadastrado($data){
		$cnpj = $data['cpf_cnpj'];
		$fornecedor = Contact::where('cpf_cnpj', $cnpj)
		->where('type', 'supplier')
		->first();

		if($fornecedor == null){
			$contact = Contact::create($data);
			$fornecedor = Contact::find($contact->id);
		}

		return $fornecedor;

	}

	private function validaProdutoCadastrado($nome, $ean){
		$result = Product::
		where('sku', $ean)
		->where('sku', '!=', 'SEM GTIN')
		->first();

		if($result == null){
			$result = Product::
			where('name', $nome)
			->first();
		}

		//verifica por codBarras e nome o PROD

		return $result;
	}

	private function formataCnpj($cnpj){
		$temp = substr($cnpj, 0, 2);
		$temp .= ".".substr($cnpj, 2, 3);
		$temp .= ".".substr($cnpj, 5, 3);
		$temp .= "/".substr($cnpj, 8, 4);
		$temp .= "-".substr($cnpj, 12, 2);
		return $temp;
	}

	private function formataCpf($cpf){
		$temp = substr($cpf, 0, 3);
		$temp .= ".".substr($cpf, 3, 3);
		$temp .= ".".substr($cpf, 6, 3);
		$temp .= "-".substr($cpf, 9, 2);

		return $temp;
	}

	private function validaUnidadeCadastrada($nome, $user_id){
		$business_id = request()->session()->get('user.business_id');
		$unidade = Unit::where('short_name', $nome)
		->first();

		if($unidade != null){
			return $unidade;
		}

		//vai inserir
		$data = [
			'business_id' => $business_id,
			'actual_name' => $nome,
			'short_name' => $nome,
			'allow_decimal' => 0,
			'created_by' => $user_id
		];

		$u = Unit::create($data);
		$unidade = Unit::find($u->id);

		return $unidade;

	}

	public function save(Request $request){

		try{

			DB::transaction(function ()  use ($request) {

				$select_location_id = $request->select_location_id;
				$business_id = request()->session()->get('user.business_id');
				$business = Business::find($business_id);

				$contact = json_decode($request->contact, true);

				$itens = json_decode($request->itens, true);
				$fatura = json_decode($request->fatura, true);
				$dadosNf = json_decode($request->dadosNf, true);
				if($contact['id'] != $request->novo_fornecedor_id){
					$novo = Contact::findOrFail($request->novo_fornecedor_id);
					$data = [
						'business_id' => $contact['business_id'],
						'city_id' => $novo->city_id,
						'cpf_cnpj' => $novo->cpf_cnpj,
						'ie_rg' => $novo->ie_rg,
						'consumidor_final' => 1,
						'contribuinte' => 1,
						'rua' => $novo->rua,
						'numero' => $novo->numero,
						'bairro' => $novo->bairro,
						'cep' => $novo->cep,
						'type' => 'supplier',
						'name' => $novo->name,
						'mobile' => '',
						'created_by' => $contact['created_by'],
					];
					// dd($data);
					// die;
				}else{
					$data = [
						'business_id' => $contact['business_id'],
						'city_id' => $contact['city_id'],
						'cpf_cnpj' => $contact['cpf_cnpj'],
						'ie_rg' => $contact['ie_rg'][0],
						'consumidor_final' => 1,
						'contribuinte' => 1,
						'rua' => $contact['rua'][0],
						'numero' => $contact['numero'][0],
						'bairro' => $contact['bairro'][0],
						'cep' => $contact['cep'][0],
						'type' => 'supplier',
						'name' => $contact['name'][0],
						'mobile' => '',
						'created_by' => $contact['created_by'],
					];
				}

				$user_id = $request->session()->get('user.id');
				$contact = $this->validaFornecedorCadastrado($data);

				// dd($contact->name);
				$valorDevolvido = $this->somaItens($request);

				$dataDevolucao = [
					'business_id' => $business_id,
					'contact_id' => $contact->id,
					'natureza_id' => $request->natureza_id,
					'tipo' => $request->tipo,
					'valor_integral' => $dadosNf['vProd'][0],
					'valor_devolvido' => $valorDevolvido,
					'motivo' => $request->motivo,
					'observacao' => $request->observacao ?? '',
					'estado' => 0,
					'devolucao_parcial' => ($dadosNf['vProd'][0] > $valorDevolvido || 
						$dadosNf['vProd'][0] < $valorDevolvido) ? 1 : 0,
					'chave_nf_entrada' => $dadosNf['chave'],
					'nNf' => $dadosNf['nNf'][0],
					'vFrete' => str_replace(",", ".", $request->valor_frete),
					'vDesc' => str_replace(",", ".", $request->desconto),
					'chave_gerada' => '',
					'numero_gerado' => 0,
					'location_id' => $select_location_id,
					'vSeguro' => str_replace(",", ".", $request->vSeguro),
					'vOutro' => str_replace(",", ".", $request->vOutro),

					'transportadora_nome' => $request->transportadora_nome ?? '',
					'transportadora_cidade' => $request->transportadora_cidade ?? '',
					'transportadora_uf' => $request->transportadora_uf ?? '',
					'transportadora_cpf_cnpj' => $request->transportadora_cpf_cnpj ?? '',
					'transportadora_ie' => $request->transportadora_ie ?? '',
					'transportadora_endereco' => $request->transportadora_endereco ?? '',
					'frete_quantidade' => $request->frete_quantidade ? str_replace(",", ".", $request->frete_quantidade) : 0,
					'frete_especie' => $request->frete_especie ?? '',
					'frete_marca' => $request->frete_marca ?? '',
					'frete_numero' => $request->frete_numero ?? '',
					'frete_tipo' => $request->frete_tipo ?? '',
					'veiculo_placa' => $request->veiculo_placa ?? '',
					'veiculo_uf' => $request->veiculo_uf ?? '',
					'frete_peso_bruto' => $request->frete_peso_bruto ? str_replace(",", ".", $request->frete_peso_bruto) : 0, 
					'frete_peso_liquido' => $request->frete_peso_liquido ? str_replace(",", ".", $request->frete_peso_liquido) : 0
				];

				$devolucao = Devolucao::create($dataDevolucao);

				for ($i = 0; $i < count($request->qtd); $i++) {
					$dataItem = [
						'devolucao_id' => $devolucao->id,
						'cod' => substr($request->codigo[$i], 0, 10),
						'nome' => $request->nome[$i],
						'ncm' => $request->ncm[$i],
						'cfop' => $request->cfop[$i],
						'codBarras' => $request->codBarras[$i] ?? '',
						'valor_unit' => $this->parseNumberDatabase($request->value_unit[$i]),
						'quantidade' => $this->parseNumberDatabase($request->qtd[$i]),
						'item_parcial' => 0,
						'unidade_medida' => $request->uCom[$i],
						'cst_csosn' => $request->cst_csosn[$i],
						'cst_pis' => $request->cst_pis[$i],
						'cst_cofins' => $request->cst_cofins[$i],
						'cst_ipi' => $request->cst_ipi[$i],
						'perc_icms' => $this->parseNumberDatabase($request->perc_icms[$i]),
						'perc_pis' => $this->parseNumberDatabase($request->perc_pis[$i]),
						'perc_cofins' => $this->parseNumberDatabase($request->perc_cofins[$i]),
						'perc_ipi' => $this->parseNumberDatabase($request->perc_ipi[$i]),
						'pRedBC' => $this->parseNumberDatabase($request->pRedBC[$i]),
						'vBC' => $this->parseNumberDatabase($request->vBC[$i]),
						'vICMS' => $request->vICMS[$i],
						'modBC' => (int) ($request->modBC[$i] ?? 3),
						'cest' => $request->cest[$i] ?? '',
						'vUnTrib' => $this->parseNumberDatabase($request->vUnTrib[$i] ?? $request->value_unit[$i]),
						'vIPI' => $this->parseNumberDatabase($request->vIPI[$i] ?? 0),

						'codigo_anp' => $request->codigo_anp[$i] ?? '',
						'descricao_anp' => $request->descricao_anp[$i] ?? '',
						'uf_cons' => $request->uf_cons[$i] ?? '',
						'valor_partida' => $request->valor_partida[$i] ?? 0,
						'perc_glp' => $request->perc_glp[$i],
						'perc_gnn' => $request->perc_gnn[$i],
						'perc_gni' => $request->perc_gni[$i],

						'unidade_tributavel' => $request->unidade_tributavel[$i],
						'quantidade_tributavel' => ($request->unidade_tributavel[$i] == "" || $request->unidade_tributavel[$i] == $request->uCom[$i]) ? $this->parseNumberDatabase($request->qtd[$i]) : $request->quantidade_tributavel[$i],
						'modBCST' => $request->modBCST[$i],
						'vBCST' => $request->vBCST[$i],
						'pICMSST' => $request->pICMSST[$i],
						'vICMSST' => $request->vICMSST[$i],
						'pMVAST' => $request->pMVAST[$i],
						'vBCSTRet' => $request->vBCSTRet[$i],
						'pST' => $request->pST[$i],
						'vICMSSubstituto' => $request->vICMSSubstituto[$i],
						'vICMSSTRet' => $request->vICMSSTRet[$i],
						'orig' => $request->orig[$i],

						'vbcPis' => $this->parseNumberDatabase($request->vbcPis[$i]),
						'vbcCofins' => $this->parseNumberDatabase($request->vbcCofins[$i]),
						'vbcIpi' => $this->parseNumberDatabase($request->vbcIpi[$i]),
					];

					$item = ItemDevolucao::create($dataItem);

				}

			});
$output = [
	'success' => 1,
	'msg' => 'Devolução salva com sucesso!!'
];
}catch(\Exception $e){
	
	$output = [
		'success' => 0,
		'msg' => "algo deu errado: " . $e->getMessage()
	];

}

return redirect('/devolucao/lista')->with('status', $output);

}

public function update(Request $request, $id){

	try{

		DB::transaction(function ()  use ($request, $id) {

			$item = Devolucao::findorfail($id);

			$select_location_id = $request->select_location_id;
			$business_id = request()->session()->get('user.business_id');
			$business = Business::find($business_id);

			$user_id = $request->session()->get('user.id');
			$valorDevolvido = $this->somaItens($request);

			$item->natureza_id = $request->natureza_id;
			$item->tipo = $request->tipo;
			$item->valor_devolvido = $valorDevolvido;
			$item->motivo = $request->motivo;
			$item->observacao = $request->observacao ?? '';

			$item->vFrete = str_replace(",", ".", $request->valor_frete);
			$item->vDesc = str_replace(",", ".", $request->desconto);
			$item->location_id = $select_location_id;
			$item->vSeguro = str_replace(",", ".", $request->vSeguro);
			$item->vOutro = str_replace(",", ".", $request->vOutro);

			$item->transportadora_nome = $request->transportadora_nome ?? '';
			$item->transportadora_cidade = $request->transportadora_cidade ?? '';
			$item->transportadora_uf = $request->transportadora_uf ?? '';
			$item->transportadora_cpf_cnpj = $request->transportadora_cpf_cnpj ?? '';
			$item->transportadora_ie = $request->transportadora_ie ?? '';
			$item->transportadora_endereco = $request->transportadora_endereco ?? '';
			$item->frete_quantidade = $request->frete_quantidade ? str_replace(",", ".", $request->frete_quantidade) : 0;
			$item->frete_especie = $request->frete_especie ?? '';
			$item->frete_marca = $request->frete_marca ?? '';
			$item->frete_numero = $request->frete_numero ?? '';
			$item->frete_tipo = $request->frete_tipo ?? '';
			$item->veiculo_placa = $request->veiculo_placa ?? '';
			$item->veiculo_uf = $request->veiculo_uf ?? '';
			$item->frete_peso_bruto = $request->frete_peso_bruto ? str_replace(",", ".", $request->frete_peso_bruto) : 0;
			$item->frete_peso_liquido = $request->frete_peso_liquido ? str_replace(",", ".", $request->frete_peso_liquido) : 0;

			$item->save();

			$item->itens()->delete();

			for ($i = 0; $i < count($request->qtd); $i++) {
				$dataItem = [
					'devolucao_id' => $item->id,
					'cod' => substr($request->codigo[$i], 0, 10),
					'nome' => $request->nome[$i],
					'ncm' => $request->ncm[$i],
					'cfop' => $request->cfop[$i],
					'codBarras' => $request->codBarras[$i],
					'valor_unit' => $this->parseNumberDatabase($request->value_unit[$i]),
					'quantidade' => $this->parseNumberDatabase($request->qtd[$i]),
					'item_parcial' => 0,
					'unidade_medida' => $request->uCom[$i],
					'cst_csosn' => $request->cst_csosn[$i],
					'cst_pis' => $request->cst_pis[$i],
					'cst_cofins' => $request->cst_cofins[$i],
					'cst_ipi' => $request->cst_ipi[$i],
					'perc_icms' => $this->parseNumberDatabase($request->perc_icms[$i]),
					'perc_pis' => $this->parseNumberDatabase($request->perc_pis[$i]),
					'perc_cofins' => $this->parseNumberDatabase($request->perc_cofins[$i]),
					'perc_ipi' => $this->parseNumberDatabase($request->perc_ipi[$i]),
					'pRedBC' => $this->parseNumberDatabase($request->pRedBC[$i]),
					'vBC' => $this->parseNumberDatabase($request->vBC[$i]),
					'vICMS' => $request->vICMS[$i],
					'modBC' => (int) ($request->modBC[$i] ?? 3),
					'cest' => $request->cest[$i] ?? '',
					'vUnTrib' => $this->parseNumberDatabase($request->vUnTrib[$i] ?? $request->value_unit[$i]),
					'vIPI' => $this->parseNumberDatabase($request->vIPI[$i] ?? 0),

					'codigo_anp' => $request->codigo_anp[$i] ?? '',
					'descricao_anp' => $request->descricao_anp[$i] ?? '',
					'uf_cons' => $request->uf_cons[$i] ?? '',
					'valor_partida' => $request->valor_partida[$i] ?? 0,
					'perc_glp' => $request->perc_glp[$i],
					'perc_gnn' => $request->perc_gnn[$i],
					'perc_gni' => $request->perc_gni[$i],

					'unidade_tributavel' => $request->unidade_tributavel[$i],
					'quantidade_tributavel' => ($request->unidade_tributavel[$i] == "" || $request->unidade_tributavel[$i] == $request->uCom[$i]) ? $this->parseNumberDatabase($request->qtd[$i]) : $request->quantidade_tributavel[$i],
					'modBCST' => $request->modBCST[$i],
					'vBCST' => $request->vBCST[$i],
					'pICMSST' => $request->pICMSST[$i],
					'vICMSST' => $request->vICMSST[$i],
					'pMVAST' => $request->pMVAST[$i],
					'vBCSTRet' => $request->vBCSTRet[$i],
					'pST' => $request->pST[$i],
					'vICMSSubstituto' => $request->vICMSSubstituto[$i],
					'vICMSSTRet' => $request->vICMSSTRet[$i],
					'orig' => $request->orig[$i],

					'vbcPis' => $this->parseNumberDatabase($request->vbcPis[$i]),
					'vbcCofins' => $this->parseNumberDatabase($request->vbcCofins[$i]),
					'vbcIpi' => $this->parseNumberDatabase($request->vbcIpi[$i]),
				];



				$dev = ItemDevolucao::create($dataItem);

			}


		});
$output = [
	'success' => 1,
	'msg' => 'Devolução atualizada com sucesso!!'
];
}catch(\Exception $e){
	echo $e->getMessage();
	die;
	$output = [
		'success' => 0,
		'msg' => __('messages.something_went_wrong')
	];

}

return redirect('/devolucao/lista')->with('status', $output);

}

private function parseNumberDatabase($value){
	return (float)str_replace(",", ".", $value);
}

private function somaItens($request){
	$soma = 0;

	for ($i = 0; $i < count($request->qtd); $i++) {

		$qtd = str_replace(",", ".", $request->qtd[$i]);
		$vUnit = str_replace(",", ".", $request->value_unit[$i]);
		$sub_total = str_replace(",", ".", $request->sub_total[$i]);

		// $vUnit = number_format($vUnit, 2, '.', '');
		// $soma += $vUnit * $qtd;
		$soma += $sub_total;
	}
	// echo $soma;
	// die;
	return $soma;

}

private function lastCodeProduct(){
	$prod = Product::orderBy('id', 'desc')->first();
	if($prod == null){
		return '0001';
	}else{
		$v = (int) $prod->sku;
		if($v<10) return '000' . ($v+1);
		elseif($v<100) return '00' . ($v+1);
		elseif($v<1000) return '0'.($v+1);
		else return $v+1;
	}
}

public function lista(){
	if (!auth()->user()->can('user.create')) {
		abort(403, 'Unauthorized action.');
	}

	$business_id = request()->session()->get('user.business_id');


	$devolucoes = Devolucao::where('business_id', $business_id)
	->orderBy('id', 'desc')
	->get();

	$business_locations = BusinessLocation::forDropdown($business_id, false, true);

	$bl_attributes = $business_locations['attributes'];

	$business_locations = $business_locations['locations'];

	$default_location = null;
	if (count($business_locations) == 1) {
		foreach ($business_locations as $id => $name) {
			$default_location = BusinessLocation::findOrFail($id);
		}
	}

	return view('devolucao.lista')
	->with('devolucoes', $devolucoes)
	->with('bl_attributes' , $bl_attributes)
	->with('default_location' , $default_location)
	->with('select_location_id' , null)
	->with('business_locations' , $business_locations);
}

public function filtro(Request $request){
	if (!auth()->user()->can('user.create')) {
		abort(403, 'Unauthorized action.');
	}

	$data_inicio = str_replace("/", "-", $request->data_inicio);
	$data_final = str_replace("/", "-", $request->data_final);
	$select_location_id = $request->select_location_id;


	$data_inicio_convert =  \Carbon\Carbon::parse($data_inicio)->format('Y-m-d');
	$data_final_convert =  \Carbon\Carbon::parse($data_final)->format('Y-m-d');
	$data_final_convert = date('Y-m-d', strtotime($data_final_convert. ' + 1 days'));

	$business_id = request()->session()->get('user.business_id');

	$devolucoes = Devolucao::where('business_id', $business_id)
	->whereBetween('created_at', [
		$data_inicio_convert, 
		$data_final_convert])
	->orderBy('id', 'desc');

	if($select_location_id){
		$devolucoes->where('location_id', $select_location_id);
	}
	$devolucoes = $devolucoes->get();

	$business_locations = BusinessLocation::forDropdown($business_id, false, true);

	$bl_attributes = $business_locations['attributes'];

	$business_locations = $business_locations['locations'];

	$default_location = null;
	if (count($business_locations) == 1) {
		foreach ($business_locations as $id => $name) {
			$default_location = BusinessLocation::findOrFail($id);
		}
	}

	return view('devolucao.lista')
	->with('devolucoes', $devolucoes)
	->with('bl_attributes' , $bl_attributes)
	->with('default_location' , $default_location)
	->with('select_location_id' , $select_location_id)
	->with('business_locations' , $business_locations);

}

public function ver($id){
	if (!auth()->user()->can('user.create')) {
		abort(403, 'Unauthorized action.');
	}
	$business_id = request()->session()->get('user.business_id');

	$devolucao = Devolucao::where('business_id', $business_id)
	->where('id', $id)
	->first();

	return view('devolucao.ver')
	->with('devolucao', $devolucao); 

}

public function delete($id){
	if (!auth()->user()->can('user.create')) {
		abort(403, 'Unauthorized action.');
	}
	$business_id = request()->session()->get('user.business_id');

	$devolucao = Devolucao::where('business_id', $business_id)
	->where('id', $id)
	->first();

	if($devolucao->delete()){
		$output = [
			'success' => 1,
			'msg' => 'Devolução removida!!'
		];
	}else{
		$output = [
			'success' => 0,
			'msg' => 'Não foi possível remover!!'
		];
	}

	return redirect()->back()
	->with('status', $output);
}

public function renderizarDanfe($id){

	if (!auth()->user()->can('user.create')) {
		abort(403, 'Unauthorized action.');
	}

	$business_id = request()->session()->get('user.business_id');
	$devolucao = Devolucao::where('business_id', $business_id)
	->where('id', $id)
	->first();

	if(!$devolucao){
		abort(403, 'Unauthorized action.');
	}

	$config = Business::getConfig($business_id, $devolucao);

	$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

	$devolucao_service = new DevolucaoService([
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

	$nfe = $devolucao_service->gerarDevolucao($devolucao);
	if(!isset($nfe['xml_erros'])){

		$xml = $nfe['xml'];


		// echo public_path('uploads/business_logos/' . $config->logo);
		try {
			$danfe = new Danfe($xml);
			// $id = $danfe->monta();
			$pdf = $danfe->render();

			return response($pdf)
			->header('Content-Type', 'application/pdf');
		} catch (InvalidArgumentException $e) {
			echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
		}
	}else{
		dd($nfe['xml_erros']);
	}

}

public function gerarXml($id){

	if (!auth()->user()->can('user.create')) {
		abort(403, 'Unauthorized action.');
	}

	$business_id = request()->session()->get('user.business_id');
	$devolucao = Devolucao::where('business_id', $business_id)
	->where('id', $id)
	->first();

	if(!$devolucao){
		abort(403, 'Unauthorized action.');
	}

		// $config = Business::find($business_id);
	$config = Business::getConfig($business_id, $devolucao);

	$cnpj = str_replace(".", "", $config->cnpj);
	$cnpj = str_replace("/", "", $cnpj);
	$cnpj = str_replace("-", "", $cnpj);
	$cnpj = str_replace(" ", "", $cnpj);

	$devolucao_service = new DevolucaoService([
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

	$nfe = $devolucao_service->gerarDevolucao($devolucao);
	if(!isset($nfe['xml_erros'])){
		$xml = $nfe['xml'];

		return response($xml)
		->header('Content-Type', 'application/xml'); 
	}else{
		foreach($nfe['xml_erros'] as $e){
			echo $e . "<br>";
		}
	}
}

public function transmitir(Request $request){

	$business_id = request()->session()->get('user.business_id');
	$devolucao = Devolucao::where('business_id', $business_id)
	->where('id', $request->devolucao_id)
	->with('business') // Carregar a relação business
	->first();

	if(!$devolucao){
		abort(403, 'Unauthorized action.');
	}

		// $config = Business::find($business_id);
	$config = Business::getConfig($business_id, $devolucao);

	$cnpj = str_replace(".", "", $config->cnpj);
	$cnpj = str_replace("/", "", $cnpj);
	$cnpj = str_replace("-", "", $cnpj);
	$cnpj = str_replace(" ", "", $cnpj);

	$devolucao_service = new DevolucaoService([
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

	if($devolucao->estado == 0 || $devolucao->estado == 2){
		header('Content-type: text/html; charset=UTF-8');

		$nfe = $devolucao_service->gerarDevolucao($devolucao);
			// return response()->json($signed, 200);

		$signed = $devolucao_service->sign($nfe['xml']);
			// return response()->json($signed, 200);
		$resultado = $devolucao_service->transmitir($signed, $devolucao);

		if(!isset($resultado['erro'])){
			$devolucao->estado = 1;
			$devolucao->save();
			return response()->json($resultado, 200);

		}else{
			$devolucao->estado = 2;
			$devolucao->save();
			return response()->json($resultado['protocolo'], $resultado['status']);
		}


	}else{
		return response()->json("Erro", 200);
	}

	return response()->json($xml, 200);

}

public function imprimir($id){
	if (!auth()->user()->can('user.create')) {
		abort(403, 'Unauthorized action.');
	}

	$business_id = request()->session()->get('user.business_id');
	$devolucao = Devolucao::where('business_id', $business_id)
	->where('id', $id)
	->first();

		// $business = Business::find($business_id);
	$business = Business::getConfig($business_id, $devolucao);

	$cnpj = str_replace(".", "", $business->cnpj);
	$cnpj = str_replace("/", "", $cnpj);
	$cnpj = str_replace("-", "", $cnpj);
	$cnpj = str_replace(" ", "", $cnpj);

	if(!$devolucao){
		abort(403, 'Unauthorized action.');
	}

	$logo = '';
	if($business->logo){
		$logo = 'data://text/plain;base64,'. base64_encode(file_get_contents(
			public_path('uploads/business_logos/' . $business->logo)));
	}

	try {
		\Log::info('Tentando imprimir XML', ['path' => public_path('xml_devolucao/'.$cnpj.'/'.$devolucao->chave_gerada.'.xml')]);
		if(file_exists(public_path('xml_devolucao/'.$cnpj.'/'.$devolucao->chave_gerada.'.xml'))){
			$xml = file_get_contents(public_path('xml_devolucao/'.$cnpj.'/'.$devolucao->chave_gerada.'.xml'));

			$danfe = new Danfe($xml);
				// $id = $danfe->monta($logo);
			$pdf = $danfe->render($logo);

			return response($pdf)
			->header('Content-Type', 'application/pdf');
		}else{
			return redirect()->back()
			->with('status', [
				'success' => 0,
				'msg' => 'Arquivo não encontrado!!'
			]);
		}
	} catch (InvalidArgumentException $e) {
		echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
	}  

}

public function cancelar(Request $request){

	if (!auth()->user()->can('user.create')) {
		abort(403, 'Unauthorized action.');
	}

	$business_id = request()->session()->get('user.business_id');
	$devolucao = Devolucao::where('business_id', $business_id)
	->where('id', $request->id)
	->first();

		// $config = Business::find($business_id);
	$config = Business::getConfig($business_id, $devolucao);

	$cnpj = str_replace(".", "", $config->cnpj);
	$cnpj = str_replace("/", "", $cnpj);
	$cnpj = str_replace("-", "", $cnpj);
	$cnpj = str_replace(" ", "", $cnpj);


	$devolucao_service = new DevolucaoService([
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


	$nfe = $devolucao_service->cancelar($devolucao, $request->justificativa, $cnpj);
	if(!isset($nfe['erro'])){

		$devolucao->estado = 3;
		$devolucao->save();
		return response()->json($nfe, 200);

	}else{
		return response()->json($nfe, $nfe['status']);
	}
}

public function baixarXml($id){
	if (!auth()->user()->can('user.create')) {
		abort(403, 'Unauthorized action.');
	}

	$business_id = request()->session()->get('user.business_id');
	$devolucao = Devolucao::where('business_id', $business_id)
	->where('id', $id)
	->first();

		// $business = Business::find($business_id);
	$business = Business::getConfig($business_id, $devolucao);

	$cnpj = str_replace(".", "", $business->cnpj);
	$cnpj = str_replace("/", "", $cnpj);
	$cnpj = str_replace("-", "", $cnpj);
	$cnpj = str_replace(" ", "", $cnpj);

	if(!$devolucao){
		abort(403, 'Unauthorized action.');
	}
	\Log::info('Tentando baixar XML', ['path' => public_path('xml_devolucao/'.$cnpj.'/'.$devolucao->chave_gerada.'.xml')]);
	if(file_exists(public_path('xml_devolucao/'.$cnpj.'/'.$devolucao->chave_gerada.'.xml'))){
		return response()->download(public_path('xml_devolucao/'.$cnpj.'/'.$devolucao->chave_gerada.'.xml'));
	}else{
		\Log::error('Arquivo XML não encontrado para download', ['path' => public_path('xml_devolucao/'.$cnpj.'/'.$devolucao->chave_gerada.'.xml')]);
		return redirect()->back()
		->with('status', [
			'success' => 0,
			'msg' => 'Arquivo não encontrado!!'
		]);
	}
}

public function baixarXmlCancelamento($id){
	if (!auth()->user()->can('user.create')) {
		abort(403, 'Unauthorized action.');
	}

	$business_id = request()->session()->get('user.business_id');
	$devolucao = Devolucao::where('business_id', $business_id)
	->where('id', $id)
	->first();

		// $business = Business::find($business_id);
	$business = Business::getConfig($business_id, $devolucao);

	$cnpj = str_replace(".", "", $business->cnpj);
	$cnpj = str_replace("/", "", $cnpj);
	$cnpj = str_replace("-", "", $cnpj);
	$cnpj = str_replace(" ", "", $cnpj);

	if(!$devolucao){
		abort(403, 'Unauthorized action.');
	}
	if(file_exists(public_path('xml_devolucao_cancelado/'.$cnpj.'/'.$devolucao->chave_gerada.'.xml'))){
		return response()->download(public_path('xml_devolucao_cancelado/'.$cnpj.'/'.$devolucao->chave_gerada.'.xml'));
	}else{
		return redirect()->back()
		->with('status', [
			'success' => 0,
			'msg' => 'Arquivo não encontrado!!'
		]);
	}
}

public function imprimirCancelamento($id){
	if (!auth()->user()->can('user.create')) {
		abort(403, 'Unauthorized action.');
	}

	$business_id = request()->session()->get('user.business_id');
	$devolucao = Devolucao::where('business_id', $business_id)
	->where('id', $id)
	->first();

		// $business = Business::find($business_id);
	$business = Business::getConfig($business_id, $devolucao);

	$cnpj = str_replace(".", "", $business->cnpj);
	$cnpj = str_replace("/", "", $cnpj);
	$cnpj = str_replace("-", "", $cnpj);
	$cnpj = str_replace(" ", "", $cnpj);

	if(!$devolucao){
		abort(403, 'Unauthorized action.');
	}

	$logo = '';
	if($business->logo){
		$logo = 'data://text/plain;base64,'. base64_encode(file_get_contents(
			public_path('uploads/business_logos/' . $business->logo)));
	}
	if(file_exists(public_path('xml_devolucao_cancelado/'.$cnpj.'/'.$devolucao->chave_gerada.'.xml'))){
		$xml = file_get_contents(public_path('xml_devolucao_cancelado/'.$cnpj.'/'.$devolucao->chave_gerada.'.xml'));


		$dadosEmitente = $this->getEmitente($business);

		$daevento = new Daevento($xml, $dadosEmitente);
		$daevento->debugMode(true);
		$pdf = $daevento->render($logo);

		return response($pdf)
		->header('Content-Type', 'application/pdf');
	}else{
		return redirect()->back()
		->with('status', [
			'success' => 0,
			'msg' => 'Arquivo não encontrado!!'
		]);
	}
}

public function imprimirCorrecao($id){
	if (!auth()->user()->can('user.create')) {
		abort(403, 'Unauthorized action.');
	}

	$business_id = request()->session()->get('user.business_id');
	$devolucao = Devolucao::where('business_id', $business_id)
	->where('id', $id)
	->first();

		// $business = Business::find($business_id);
	$business = Business::getConfig($business_id, $devolucao);

	$cnpj = str_replace(".", "", $business->cnpj);
	$cnpj = str_replace("/", "", $cnpj);
	$cnpj = str_replace("-", "", $cnpj);
	$cnpj = str_replace(" ", "", $cnpj);

	if(!$devolucao){
		abort(403, 'Unauthorized action.');
	}

	$logo = '';
	if($business->logo){
		$logo = 'data://text/plain;base64,'. base64_encode(file_get_contents(
			public_path('uploads/business_logos/' . $business->logo)));
	}
	if(file_exists(public_path('xml_devolucao_correcao/'.$cnpj.'/'.$devolucao->chave_gerada.'.xml'))){
		$xml = file_get_contents(public_path('xml_devolucao_correcao/'.$cnpj.'/'.$devolucao->chave_gerada.'.xml'));


		$dadosEmitente = $this->getEmitente($business);

		$daevento = new Daevento($xml, $dadosEmitente);
		$daevento->debugMode(true);
		$pdf = $daevento->render($logo);

		return response($pdf)
		->header('Content-Type', 'application/pdf');
	}else{
		die;
		return redirect()->back()
		->with('status', [
			'success' => 0,
			'msg' => 'Arquivo não encontrado!!'
		]);
	}
}

private function getEmitente($config){

	return [
		'razao' => $config->razao_social,
		'logradouro' => $config->rua,
		'numero' => $config->numero,
		'complemento' => '',
		'bairro' => $config->bairro,
		'CEP' => $config->cep,
		'municipio' => $config->cidade->nome,
		'UF' => $config->cidade->uf,
		'telefone' => '',
		'email' => ''
	];
}

public function corrigir(Request $request){

	$business_id = request()->session()->get('user.business_id');
	$devolucao = Devolucao::where('business_id', $business_id)
	->where('id', $request->id)
	->first();

		// $config = Business::find($business_id);
	$config = Business::getConfig($business_id, $devolucao);

	$cnpj = str_replace(".", "", $config->cnpj);
	$cnpj = str_replace("/", "", $cnpj);
	$cnpj = str_replace("-", "", $cnpj);
	$cnpj = str_replace(" ", "", $cnpj);


	$devolucao_service = new DevolucaoService([
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


	$nfe = $devolucao_service->cartaCorrecao($devolucao, $request->justificativa, $cnpj);
	if(!isset($nfe['erro'])){
		return response()->json($nfe, 200);
	}else{
		return response()->json($nfe, $nfe['status']);
	}

}

public function updateFiscal(Request $request, $id){
	$item = Devolucao::findorfail($id);
	try{
		$item->estado = $request->estado;
		$business_id = request()->session()->get('user.business_id');
		$config = Business::getConfig($business_id, $item);

		$cnpj = preg_replace('/[^0-9]/', '', $config->cnpj);

		if ($request->hasFile('file')){

			$xml = simplexml_load_file($request->file);
			$chave = substr($xml->NFe->infNFe->attributes()->Id, 3, 44);
			$file = $request->file;
			$file->move(public_path('xml_devolucao/'.$cnpj.'/'), $chave.'.xml');
			$item->chave_gerada = $chave;
			$item->numero_gerado = (int)$xml->NFe->infNFe->ide->nNF;

		}

		$item->save();

		return redirect()->route('devolucao.lista')
		->with('status', [
			'success' => 1,
			'msg' => 'Estado fiscal alterado!'
		]);
	}catch(\Exception $e){
		return redirect()->route('devolucao.lista')
		->with('status', [
			'success' => 0,
			'msg' => 'Algo deu errado: ' . $e->getMessage()
		]);
	}
}

}
