<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\City;
use App\Models\Product;
use App\Models\NaturezaOperacao;
use App\Models\Transaction;
use App\Models\Contact;
use App\Models\Unit;
use App\Models\Variation;
use App\Models\ProductVariation;
use App\Models\Business;
use App\Models\BusinessLocation;
use DB;
use App\Utils\BusinessUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use App\Utils\ModuleUtil;
use File;

class ImportXmlController extends Controller
{

    protected $productUtil;
    protected $businessUtil;
    protected $transactionUtil;
    protected $moduleUtil;

    public function __construct(
        ProductUtil $productUtil,
        BusinessUtil $businessUtil,
        TransactionUtil $transactionUtil,
        ModuleUtil $moduleUtil
    ) {
        $this->productUtil = $productUtil;
        $this->businessUtil = $businessUtil;
        $this->transactionUtil = $transactionUtil;
        $this->moduleUtil = $moduleUtil;
    }

    public function index()
    {
        if (!auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = request()->session()->get('user.business_id');
        $business_locations = BusinessLocation::forDropdown($business_id, false);
        $business = Business::find($business_id);

        if($business->cnpj == '00.000.000/0000-00' || $business->cnpj == ''){
            $output = [
                'success' => 0,
                'msg' => "Informe a configuração do emitente!"
            ];
            return redirect()->route('business.getBusinessSettings')->with('status', $output);
        }
        return view('import_xml.index', compact('business_locations'));
    }

    public function preview(Request $request)
    {
        if (!auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }


        if(!is_dir(public_path('extract'))){
            mkdir(public_path('extract'), 0777, true);
        }

        $zip = new \ZipArchive();
        $zip->open($request->file);

        $destino = public_path('/extract');
        $this->limparPasta($destino);

        if($zip->extractTo($destino) == TRUE){

            $data = $this->preparaXmls($destino);

            if(sizeof($data) == 0){

                $output = [
                    'success' => 0,
                    'msg' => "Algo errado com o arquivo!"
                ];
                // echo "erro";
                // die;
                return redirect()->back()->with('status', $output);
            }

            return view('import_xml/preview')
            ->with('type', $request->type)
            ->with('location_id', $request->location_id)
            ->with('data', $data);

        }else {
            $output = [
                'success' => 0,
                'msg' => "Erro ao desconpactar arquivo"
            ];
            return redirect()->back()->with('status', $output);
        }
        $zip->close();
    }

    private function limparPasta($destino){
        $files = glob($destino."/*");
        foreach($files as $file){ 
            if(is_file($file)) unlink($file); 
        }
    }

    private function preparaXmls($destino){
        $files = glob($destino."/*");
        $data = [];
        foreach($files as $file){

            if(is_file($file)){
                $xml = simplexml_load_file($file);
                $cliente = $this->getCliente($xml);
                $produtos = $this->getProdutos($xml);
                $fatura = $this->getFatura($xml);
                // dd($fatura);
                if($produtos != null){

                    $temp = [
                        'data' => (string)$xml->NFe->infNFe->ide->dhEmi,
                        'chave' => substr($xml->NFe->infNFe->attributes()->Id, 3, 44),
                        'total' => (float)$xml->NFe->infNFe->total->ICMSTot->vProd,
                        'numero_nf' => (string)$xml->NFe->infNFe->ide->nNF,
                        'desconto' => (float)$xml->NFe->infNFe->total->ICMSTot->vDesc,
                        'cliente' => $cliente,
                        'produtos' => $produtos,
                        'fatura' => $fatura,
                        'file' => $file,
                        'natureza' => (string)$xml->NFe->infNFe->ide->natOp,
                        'observacao' => $xml->NFe->infNFe->infAdic ? (string)$xml->NFe->infNFe->infAdic->infCpl : '',
                        'tipo_pagamento' => (string)$xml->NFe->infNFe->pag->detPag->tPag,
                        'forma_pagamento' => $xml->NFe->infNFe->pag->detPag->indPag ?? 0
                    ];

                    // echo  (string)$xml->NFe->infNFe->ide->natOp . "<br>";

                    // dd($temp);
                    array_push($data, $temp);
                }
            }
        }

        return $data;
    }

    private function getCliente($xml){
        if(!isset($xml->NFe->infNFe->dest->enderDest->cMun)) return null;
        $cidade = City::getCidadeCod($xml->NFe->infNFe->dest->enderDest->cMun);
        $business_id = request()->session()->get('user.business_id');
        $dadosCliente = [
            'cpf_cnpj' => isset($xml->NFe->infNFe->dest->CNPJ) ? (string)$xml->NFe->infNFe->dest->CNPJ : (string)$xml->NFe->infNFe->dest->CPF,
            'name' => (string)$xml->NFe->infNFe->dest->xNome,
            'ie_rg' => (string)$xml->NFe->infNFe->dest->IE,
            'city_id' => $cidade != null ? $cidade->id : 1,

            'nome_fantasia' => (string)$xml->NFe->infNFe->dest->xFant,
            'rua' => (string)$xml->NFe->infNFe->dest->enderDest->xLgr,
            'numero' => (string)$xml->NFe->infNFe->dest->enderDest->nro,
            'bairro' => (string)$xml->NFe->infNFe->dest->enderDest->xBairro,
            'cep' => (string)$xml->NFe->infNFe->dest->enderDest->CEP,
            'type' => 'customer',
            'consumidor_final' => $xml->NFe->infNFe->dest->IE ? 0 : 1,
            'contribuinte' => 1,
            'created_by' => request()->session()->get('user.id'),
            'business_id' => $business_id
        ];

        return $dadosCliente;
    }

    private function validaProdutoCadastrado($nome, $ean){
        if(empty($ean)){
            $ean = 'SEM GTIN';
        }
        $result = Product::
        where('codigo_barras', $ean)
        ->where('codigo_barras', '!=', 'SEM GTIN')
        ->first();

        if($result == null){
            $result = Product::
            where('name', $nome)
            ->first();
        }

        //verifica por codBarras e nome o PROD

        return $result;
    }

    private function getProdutos($xml){
        $itens = [];
        try{

            foreach($xml->NFe->infNFe->det as $item) {
                $produto = $this->validaProdutoCadastrado($item->prod->xProd, $item->prod->cEAN);

                $objeto = $item->imposto;
                $arr = (array_values((array)$objeto->ICMS));

                $cst_csosn = $arr[0]->CST ? $arr[0]->CST : $arr[0]->CSOSN;
                $pICMS = $arr[0]->pICMS ?? 0;

                $arr = (array_values((array)$objeto->PIS));
                $cst_pis = $arr[0]->CST;
                $pPIS = $arr[0]->pPIS ?? 0;

                $arr = (array_values((array)$objeto->COFINS));
                $cst_cofins = $arr[0]->CST;
                $pCOFINS = $arr[0]->COFINS ?? 0;

                $arr = (array_values((array)$objeto->IPI));

                if(isset($arr[1])){

                    $cst_ipi = $arr[1]->CST ?? '99';
                    $pIPI = $arr[0]->IPI ?? 0;
                    if($pIPI == 0){
                        $pIPI = $arr[0]->pIPI ?? 0;
                    }

                    if(isset($arr[1]->pIPI)){
                        $pIPI = $arr[1]->pIPI ?? 0;
                    }else{
                        if(isset($arr[4]->pIPI)){
                            $cst_ipi = $arr[4]->CST;
                            $pIPI = $arr[4]->pIPI;
                        }else{
                            $pIPI = 0;
                        }
                    }

                }else{
                    $cst_ipi = '99';
                    $pIPI = 0;
                }


                $produtoNovo = !$produto ? true : false;
                $item = [
                    'codigo' => (string)$item->prod->cProd,
                    'xProd' => (string)$item->prod->xProd,
                    'NCM' => (string)$item->prod->NCM,
                    'CFOP' => (string)$item->prod->CFOP,
                    'CFOP_entrada' => $this->getCfopEntrada($item->prod->CFOP),
                    'uCom' => (string)$item->prod->uCom,
                    'vUnCom' => (string)$item->prod->vUnCom,
                    'qCom' => (string)$item->prod->qCom,
                    'codBarras' => (string)$item->prod->cEAN,
                    'produtoNovo' => $produtoNovo,
                    'produtoId' => $produtoNovo ? '0' : $produto->id,
                    'cenq_ipi' => (string)$item->imposto->IPI ? $item->imposto->IPI->cEnq : '999',
                    'cst_csosn' => (string)$cst_csosn,
                    'cst_pis' => (string)$cst_pis,
                    'cst_cofins' => (string)$cst_cofins,
                    'cst_ipi' => (string)$cst_ipi,
                    'perc_icms' => (float)$pICMS,
                    'perc_pis' => (float)$pPIS,
                    'perc_cofins' => (float)$pCOFINS,
                    'perc_ipi' => (float)$pIPI,
                ];

                // dd($item);
                array_push($itens, $item);
            }
            return $itens;
        }catch(\Exception $e){
            // echo $e->getLine();
            // die;
            return null;
        }
    }

    private function getCfopEntrada($cfop){
        $business_id = request()->session()->get('user.business_id');
        $natureza = NaturezaOperacao::
        where('business_id', $business_id)
        ->where('CFOP_saida_estadual', $cfop)
        ->first();

        if($natureza != null){
            return $natureza->CFOP_entrada_inter_estadual;
        }

        $natureza = NaturezaOperacao::
        where('business_id', $business_id)
        ->where('CFOP_saida_inter_estadual', $cfop)
        ->first();

        if($natureza != null){
            return $natureza->CFOP_entrada_inter_estadual;
        }

        $digito = substr($cfop, 0, 1);
        if($digito == '5'){
            return '1'. substr($cfop, 1, 4);

        }else{
            return '2'. substr($cfop, 1, 4);
        }
    }

    private function getCfopEstadual($cfop){
        $digito = substr($cfop, 0, 1);
        if($digito == '5'){ 
            return $cfop;
        }else{
            return '5'. substr($cfop, 1, 4);
        }
    }

    private function getCfopInterEstadual($cfop){
        $digito = substr($cfop, 0, 1);
        if($digito == '6'){ 
            return $cfop;
        }else{
            return '6'. substr($cfop, 1, 4);
        }
    }

    private function getCfopEntradaInterEstadual($cfop){
        $digito = substr($cfop, 0, 1);
        return '2'. substr($cfop, 1, 4);
    }

    private function getCfopEntradaEstadual($cfop){
        $digito = substr($cfop, 0, 1);
        return '1'. substr($cfop, 1, 4);
    }

    private function getFatura($xml){
        $fatura = [];

        try{
            if (!empty($xml->NFe->infNFe->cobr->dup))
            {
                foreach($xml->NFe->infNFe->cobr->dup as $dup) {
                    $titulo = $dup->nDup;
                    $vencimento = $dup->dVenc;
                    $vencimento = explode('-', $vencimento);
                    $vencimento = $vencimento[2]."/".$vencimento[1]."/".$vencimento[0];
                    $vlr_parcela = number_format((double) $dup->vDup, 2, ",", "."); 

                    $parcela = [
                        'numero' => (int)$titulo,
                        'vencimento' => $dup->dVenc,
                        'valor_parcela' => $vlr_parcela,
                        'rand' => rand(0, 10000)
                    ];
                    array_push($fatura, $parcela);
                }
            }else{
                $vencimento = explode('-', substr($xml->NFe->infNFe->ide->dhEmi[0], 0,10));
                $vencimento = $vencimento[2]."/".$vencimento[1]."/".$vencimento[0];
                $parcela = [
                    'numero' => 1,
                    'vencimento' => substr($xml->NFe->infNFe->ide->dhEmi[0], 0,10),
                    'valor_parcela' => (float)$xml->NFe->infNFe->total->ICMSTot->vNF[0],
                    'rand' => rand(0, 10000)
                ];
                array_push($fatura, $parcela);
            }
        }catch(\Exception $e){

        }

        return $fatura;
    }

    public function store(Request $request){

        ini_set('max_execution_time', 0);
        ini_set('memory_limit', -1);
        $type = $request->type;
        $data = json_decode($request->data);
        $business_id = request()->session()->get('user.business_id');
        $business = Business::find($business_id);

        $user_id = request()->session()->get('user.id');
        // dd($business);
        $cont = 0;
        foreach($data as $item){
            if(!$request->input('chave_'.$item->chave)){
                continue;
            }
            $cont++;
            $cli = $item->cliente;

            $contact = null;
            if($cli){
                $contact = Contact::where('business_id', $business_id)
                ->where('cpf_cnpj', $cli->cpf_cnpj)
                ->first();

                if($contact == null){
                    $contact = Contact::create((array)$cli);
                }
            }
            $sell_lines = [];

            $natureza = $this->insereNatureza($item->natureza, $item->produtos[0], $business_id);

            foreach($item->produtos as $p){
                $prod = $this->validaProdutoCadastrado($p->xProd, $p->codBarras);

                $sku = $p->codBarras ? ($p->codBarras != 'SEM GTIN' ? $p->codBarras : $this->lastCodeProduct($business_id)) : '';
                $valor_venda = $p->vUnCom;
                // echo $p->xProd;

                if($prod == null){

                    $p->business_id = $business_id;
                    $p->created_by = request()->session()->get('user.id');

                    $cfop = $p->CFOP;
                    $lastCfop = substr($cfop, 1, 3);

                    $unidade = $this->validaUnidadeCadastrada($p->uCom, $user_id);


                    $unidade = Unit::where('business_id', $business_id)->where('short_name', 'UNID')->first();
                    if($unidade == null){
                        $unidade = Unit::where('business_id', $business_id)->where('short_name', 'UN')->first();
                    }
                    $produtoData = [
                        'name' => $p->xProd,
                        'business_id' => $business_id,
                        'unit_id' => $unidade->id,
                        'tax_type' => 'inclusive',
                        'barcode_type' => 2,

                        'codigo_barras' => '',
                        'sku' => $p->codigo,
                        'created_by' => $user_id,
                        'perc_icms' => $p->perc_icms ?? 0,
                        'perc_pis' => $p->perc_pis ?? 0,
                        'perc_cofins' => $p->perc_cofins ?? 0,
                        'perc_ipi' => $p->perc_ipi ?? 0,
                        'ncm' => $p->NCM,
                        'cfop_interno' => '5'.$lastCfop,
                        'cfop_externo' => '6'.$lastCfop,
                        'type' => 'single',
                        'enable_stock' => 0,

                        'cst_csosn' => $p->cst_csosn,
                        'cst_pis' => $p->cst_pis,
                        'cst_cofins' => $p->cst_cofins,
                        'cst_ipi' => $p->cst_ipi,
                        'cenq_ipi' => $p->cenq_ipi,

                    ];
                    $prod = Product::create((array)$produtoData);

                    $dataProductVariation = [
                        'product_id' => $prod->id,
                        'name' => 'DUMMY'
                    ];

                    $variacao = ProductVariation::where('product_id', $prod->id)->where('name', 'DUMMY')->first();
                    $produtoVariacao = null;
                    if($variacao == null){
                        $produtoVariacao = ProductVariation::create($dataProductVariation);
                    }else{
                        $produtoVariacao = $variacao;
                    }

                    $dataVariation = [
                        'name' => 'DUMMY',
                        'product_id' => $prod->id,
                        'sub_sku' => $sku,
                        'default_purchase_price' => $valor_venda,
                        'dpp_inc_tax' => $valor_venda,
                        'product_variation_id' => $produtoVariacao->id,
                        'profit_percent' => $business->default_profit_percent,
                        'default_sell_price' => $valor_venda,
                        'sell_price_inc_tax' => $valor_venda
                    ];

                    $variation = Variation::where('product_id', $prod->id)->where('name', 'DUMMY')
                    ->where('product_variation_id', $produtoVariacao->id)->first();
                    $variation = null;
                    if($variation == null){
                        $variation = Variation::create($dataVariation);
                    }

                    \DB::table('product_locations')->insert(
                        [
                            'product_id' => $prod->id,
                            'location_id' => $request->location_id
                        ]
                    );
                }

                $variation = $prod->variations[0];
                // dd($item);
                $tax_id = null;
                $temp = [
                    'product_id' => $variation->product_id,
                    'variation_id' => $variation->id,
                    'quantity' => $p->qCom,
                    'unit_price' => $p->vUnCom,
                    'unit_price_inc_tax' => $p->vUnCom,
                    'line_discount_type' => 'fixed',
                    'line_discount_amount' => 0,
                    'item_tax' => 0,
                    'tax_id' => $tax_id,
                    'sell_line_note' => '',
                    'product_unit_id' => $prod->unit_id,
                    'enable_stock' => $prod->enable_stock,
                    'type' => $prod->type,
                    'combo_variations' => $prod->type == 'combo' ? $variation->combo_variations : []
                ];

                $sell_lines[] = $temp;
            }
            $data_emi = \Carbon\Carbon::parse($item->data)->format('Y-m-d h:i:s');
            // $data_emi = date('Y-m-d H:i');
                // dd($item);
            $now = \Carbon::now()->toDateTimeString();

            $sale_data = [
                'invoice_no' => (string)$item->numero_nf,
                'location_id' => $request->location_id,
                'status' => 'final',
                'contact_id' => $contact ? $contact->id : null,
                'final_total' => (float)$item->total,
                'transaction_date' => $data_emi,
                'discount_amount' => 0,
                'import_time' => $now,
                'commission_agent' => null,
                'valor_recebido' => 0,
                'is_direct_sale' => $type == 'nfe',
                // 'natureza_id' => $natureza->id
                // 'estado' => 'APROVADO',
                // 'numero_nfe' => $type == 'nfe' ? $item->numero_nf : 0,
                // 'numero_nfce' => $type == 'nfce' ? $item->numero_nf : 0,
                // 'chave' => $item->chave
            ];

            $invoice_total = [
                'total_before_tax' => (float)$item->total,
                'tax' => 0
            ];

            $transaction = $this->transactionUtil->createSellTransaction($business_id, $sale_data, $invoice_total, auth()->user()->id, false);
            $cnpj = preg_replace('/[^0-9]/', '', $business->cnpj);
            // echo $cnpj;
            if($type == 'nfce'){
                if(!is_dir(public_path('xml_nfce/'.$cnpj))){
                    mkdir(public_path('xml_nfce/'.$cnpj), 0777, true);
                }
                File::copy($item->file, public_path("xml_nfce/$cnpj/").$item->chave.".xml");
                $transaction->numero_nfce = $item->numero_nf;
            }else{
                if(!is_dir(public_path('xml_nfe/'.$cnpj))){
                    mkdir(public_path('xml_nfe/'.$cnpj), 0777, true);
                }
                File::copy($item->file, public_path("xml_nfe/$cnpj/").$item->chave.".xml");

                $transaction->numero_nfe = $item->numero_nf;
            }

            $transaction->chave = $item->chave;
            $transaction->natureza_id = $natureza->id;
            $transaction->estado = 'APROVADO';
            $transaction->save();

            $this->transactionUtil->createOrUpdateSellLines($transaction, $sell_lines, $request->location_id, false, null, [], false);

        }

        $output = [
            'success' => 1,
            'msg' => 'Sucesso! Total de arquivos importados: ' . $cont
        ];
        // echo $cont;
        return redirect('import-xml')->with('status', $output);
    }

    private function insereNatureza($descricao, $produto, $business_id){
        $natureza = NaturezaOperacao::where('natureza', $descricao)
        ->where('business_id', $business_id)
        ->first();

        if($natureza != null) return $natureza;

        $cfopEstadual = $this->getCfopEstadual($produto->CFOP);
        $cfopInterEstadual = $this->getCfopInterEstadual($produto->CFOP);
        $cfopEntradaEstadual = $this->getCfopEntradaEstadual($produto->CFOP);
        $cfopEntradaInterEstadual = $this->getCfopEntradaInterEstadual($produto->CFOP);

        $data = [
            'natureza' => $descricao,
            'cfop_entrada_estadual' => $cfopEntradaEstadual,
            'cfop_entrada_inter_estadual' => $cfopEntradaInterEstadual,
            'cfop_saida_estadual' => $cfopEstadual,
            'cfop_saida_inter_estadual' => $cfopInterEstadual,
            'business_id' => $business_id,
            'finNFe' => 1,
            'tipo' => 1,
            'sobrescreve_cfop' => 0,
            'bonificacao' => 0,
        ];
        $res = NaturezaOperacao::create($data);
        return $res;
    }

    private function validaUnidadeCadastrada($nome, $user_id){
        $business_id = request()->session()->get('user.business_id');
        $unidade = Unit::where('short_name', $nome)
        ->where('business_id', $business_id)
        ->first();

        if($unidade != null){
            return $unidade;
        }

        //vai inserir
        $data = [
            'business_id' => $business_id,
            'actual_name' => $nome,
            'short_name' => $nome,
            'allow_decimal' => 1,
            'created_by' => $user_id
        ];

        $u = Unit::create($data);
        $unidade = Unit::find($u->id);

        return $unidade;

    }

    private function lastCodeProduct($business_id){
        $prod = Product::orderBy('id', 'desc')
        ->where('business_id', $business_id)->first();
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
}
