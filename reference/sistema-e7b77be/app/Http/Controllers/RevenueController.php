<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaction;
use Yajra\DataTables\Facades\DataTables;
use App\Utils\ModuleUtil;
use App\Utils\TransactionUtil;
use App\Models\BusinessLocation;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\City;
use App\Models\Revenue;
use App\Models\TaxRate;
use App\Models\Account;
use App\Utils\ContactUtil;

class RevenueController extends Controller
{

    protected $contactUtil;

    public function __construct(TransactionUtil $transactionUtil, ModuleUtil $moduleUtil, ContactUtil $contactUtil)
    {
        $this->transactionUtil = $transactionUtil;
        $this->moduleUtil = $moduleUtil;
        $this->dummyPaymentLine = ['method' => 'cash', 'amount' => 0, 'note' => '', 'card_transaction_number' => '', 'card_number' => '', 'card_type' => '', 'card_holder_name' => '', 'card_month' => '', 'card_year' => '', 'card_security' => '', 'cheque_number' => '', 'bank_account_number' => '',
        'is_return' => 0, 'transaction_no' => '', 'data_base' => date('d/m/Y'), 'intervalo' => '', 
        'vencimento' => date('d/m/Y'), 'qtd_parcelas' => 1];

        $this->contactUtil = $contactUtil;

    }

    public function index(){
        if (!auth()->user()->can('revenues.access')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {

            $business_id = request()->session()->get('user.business_id');

            $revenues = Revenue::where('business_id', $business_id)
            ->select(['id', 'vencimento', 'referencia', 'expense_category_id', 'location_id',
                'status', 'valor_total', 'valor_recebido', 'observacao', 'created_by', 'document', 
                'contact_id'])
            ->orderBy('id', 'desc');

            if (request()->has('status')) {
                $status = request()->get('status');
                if ($status == -1) {
                    $revenues->where('status', false);
                }

                if ($status == 1) {
                    $revenues->where('status', true);
                }
            }

            if (!empty(request()->start_date) && !empty(request()->end_date)) {
                $start = request()->start_date;
                $end = request()->end_date;
                $revenues->whereDate('vencimento', '>=', $start)
                ->whereDate('vencimento', '<=', $end);
            }

            if (request()->has('location_id')) {
                $location_id = request()->get('location_id');
                if (!empty($location_id)) {
                    $revenues->where('location_id', $location_id);
                }
            }

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $revenues->whereIn('location_id', $permitted_locations);
            }

            return Datatables::of($revenues)
            
            ->addColumn(
                'action',
                function($row){
                    $html = '<div class="btn-group">
                    <button type="button" class="btn btn-info dropdown-toggle btn-xs" 
                    data-toggle="dropdown" aria-expanded="false"> Ações<span class="caret"></span><span class="sr-only">Toggle Dropdown
                    </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-left" role="menu">';
                    if(!$row->boleto){
                        $html .= '<li><a href="'.action('RevenueController@edit', [$row->id]).'"><i class="glyphicon glyphicon-edit"></i> Editar</a></li>';
                    }

                    if(!$row->status){
                        $html .= '<li><a href="'.action('RevenueController@receive', [$row->id]).'"><i class="glyphicon glyphicon-ok"></i> Receber</a></li>';
                    }
                    if($row->document){
                        $html .= '<li><a href="'.url('uploads/documents/'. $row->document).'" download=""><i class="fa fa-download" aria-hidden="true"></i> Download documento</a></li>';

                        if(isFileImage($row->document)){
                            $html .= '<li><a href="#" data-href="'.url('uploads/documents/'. $row->document).'" class="view_uploaded_document"><i class="fa fa-file" aria-hidden="true"></i>Ver documento</a></li>';
                        }
                    }
                    $html .= '<li>
                    <a data-href="'.action('RevenueController@destroy', [$row->id]).'" class="delete_revenue"><i class="glyphicon glyphicon-trash"></i> Excluir</a>
                    </li>
                    <li class="divider"></li>';

                    if(!$row->boleto){
                        if($row->contact && $row->contact->name != 'Cliente padrão'){
                            $html .= '<li>
                            <a href="'.action('BoletoController@create', [$row->id]).'" class=""><i class="glyphicon glyphicon-list-alt"></i> Gerar boleto</a>
                            </li>';
                        }
                    }else{
                        $html .= '<li>
                        <a target="_blank" href="'.action('BoletoController@ver', [$row->id]).'" class=""><i class="glyphicon glyphicon-list-alt"></i> Download boleto</a>
                        </li>';

                        $html .= '<li>
                        <a href="'.action('BoletoController@gerarRemessa', [$row->id]).'" class=""><i class="glyphicon glyphicon-file"></i> Gerar remessa</a>
                        </li>';
                    }

                    $html .= '</ul></div>';
                    return $html;
                }
            )
            ->addColumn(
                'checkbox',
                function($row){
                    if(!$row->boleto){
                        if($row->contact && $row->contact->name != 'Cliente padrão'){

                            return '<input class="check-boleto check-'.$row->id.'" type="checkbox" style="visibility: hidden" onclick="boleto_selecionado('.$row->id.')"/>';
                        }
                    }else{
                        return '';
                    }
                }
            )
            ->editColumn(
                'expense_category_id',
                function($row){
                    return $row->category ? $row->category->name : '--';
                }
            )

            ->editColumn(
                'status',
                function($row){
                    if($row->status){
                       return '<span class="label bg-success">Recebido</span>';
                   }else{
                       return '<span class="label bg-yellow">Pendente</span>';
                   }
               }
           )
            ->addColumn(
                'location_name',
                function($row){
                    return $row->location->name;
                }
            )

            ->addColumn(
                'contact',
                function($row){

                    return $row->contact ? $row->contact->name : '--';
                }
            )

            ->editColumn(
                'valor_total',
                function($row){

                    return '<span class="display_currency final-total" data-currency_symbol="true" data-orig-value="' . number_format($row->valor_total, 2) .'"> '.$row->valor_total.'</span>';
                }
            )
            ->editColumn(
                'valor_recebido',
                function($row){
                    return '<span class="display_currency valor-recebido" data-currency_symbol="true" data-orig-value="' . number_format($row->valor_recebido, 2) .'"> '.$row->valor_recebido.'</span>';
                }
            )
            ->editColumn(
                'vencimento',
                function($row){
                    return \Carbon\Carbon::parse($row->vencimento)->format('d/m/Y');
                }
            )
            ->editColumn(
                'created_by',
                function($row){
                    return $row->user->first_name;
                }
            )
            ->removeColumn('id')
            ->rawColumns(['action', 'checkbox', 'valor_total', 'valor_recebido', 'status'])
            ->make(true);
        }

        $business_id = request()->session()->get('user.business_id');

        $categories = ExpenseCategory::where('business_id', $business_id)
        ->pluck('name', 'id');

        $users = User::forDropdown($business_id, false, true, true);

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('revenues.index')
        ->with(compact('categories', 'business_locations', 'users'));
    }

    public function create()
    {
        if (!auth()->user()->can('revenues.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        //Check if subscribed or not
        if (!$this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse(action('RevenueController@index'));
        }

        $business_locations = BusinessLocation::forDropdown($business_id);

        $expense_categories = ExpenseCategory::where('business_id', $business_id)
        ->pluck('name', 'id');
        $users = User::forDropdown($business_id, true, true);

        $taxes = TaxRate::forBusinessDropdown($business_id, true, true);

        $payment_line = $this->dummyPaymentLine;

        $payment_types = $this->transactionUtil->payment_types();

        //Accounts
        $accounts = [];
        if ($this->moduleUtil->isModuleEnabled('account')) {
            $accounts = Account::forDropdown($business_id, true, false, true);
        }

        $walk_in_customer = $this->contactUtil->getWalkInCustomer($business_id);

        $types = [];
        if (auth()->user()->can('supplier.create')) {
            $types['supplier'] = __('report.supplier');
        }
        if (auth()->user()->can('customer.create')) {
            $types['customer'] = __('report.customer');
        }
        if (auth()->user()->can('supplier.create') && auth()->user()->can('customer.create')) {
            $types['both'] = __('lang_v1.both_supplier_customer');
        }

        return view('revenues.create')
        ->with('tipo', 'customer')
        ->with('estados', $this->prepareUFs())
        ->with('cities', $this->prepareCities())

        ->with(compact('expense_categories', 'business_locations', 'users', 'taxes', 'payment_line', 'payment_types', 'accounts', 'walk_in_customer', 'types'));
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

    private function prepareUFs(){
        return [
            "AC"=> "AC",
            "AL"=> "AL",
            "AM"=> "AM",
            "AP"=> "AP",
            "BA"=> "BA",
            "CE"=> "CE",
            "DF"=> "DF",
            "ES"=> "ES",
            "GO"=> "GO",
            "MA"=> "MA",
            "MG"=> "MG",
            "MS" => "MS",
            "MT" => "MT",
            "PA" => "PA",
            "PB" => "PB",
            "PE" => "PE",
            "PI" => "PI",
            "PR" => "PR",
            "RJ" => "RJ",
            "RN" => "RN",
            "RS" => "RS",
            "RO" => "RO",
            "RR" => "RR",
            "SC" => "SC",
            "SE" => "SE",
            "SP" => "SP",
            "TO" => "TO"

        ];

    }

    public function store(Request $request){
        if (!auth()->user()->can('revenues.access')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = $request->session()->get('user.business_id');

            $request->validate([
                'document' => 'file|max:'. (config('constants.document_size_limit') / 1000)
            ]);

            $data = $request->vencimento;
            $data = $this->transactionUtil->uf_date($data);

            $inputs = $request->only([ 'referencia', 'vencimento', 'location_id', 'final_total', 'expense_for', 'observacao', 'expense_category_id', 'contact_id', 'tipo_pagamento', 'valor_recebido']);


            $user_id = $request->session()->get('user.id');
            $inputs['business_id'] = $business_id;
            $inputs['created_by'] = $user_id;


            $inputs['valor_total'] = str_replace(",", ".", $inputs['final_total']);

            $inputs['valor_recebido'] = str_replace(",", ".", $inputs['valor_recebido']);

            $inputs['vencimento'] = $data;
            $inputs['recebimento'] = $data;

            $inputs['status'] =  $inputs['valor_recebido'] > 0;

            $document_name = $this->transactionUtil->uploadFile($request, 'document', 'documents');
            if (!empty($document_name)) {
                $inputs['document'] = $document_name;
            }
            Revenue::create($inputs);

            $output = [
                'success' => 1,
                'msg' => 'Conta a receber salva'
            ];

        } catch (\Exception $e) {
            // DB::rollBack();

            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            // echo $e->getMessage();
            // die;
            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return redirect('revenues')->with('status', $output);
    }

    public function destroy($id)
    {
        if (!auth()->user()->can('revenues.access')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $business_id = request()->session()->get('user.business_id');

                $revenue = Revenue::where('business_id', $business_id)
                ->where('id', $id)
                ->first();
                $revenue->delete();


                $output = [
                    'success' => true,
                    'msg' => 'Conta a receber removida.'
                ];
            } catch (\Exception $e) {
                \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

                $output = [
                    'success' => false,
                    'msg' => __("messages.something_went_wrong")
                ];
            }

            return $output;
        }
    }

    public function edit($id)
    {
        if (!auth()->user()->can('revenues.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        //Check if subscribed or not
        if (!$this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse(action('RevenueController@index'));
        }

        $business_locations = BusinessLocation::forDropdown($business_id);

        $expense_categories = ExpenseCategory::where('business_id', $business_id)
        ->pluck('name', 'id');
        $users = User::forDropdown($business_id, true, true);

        $taxes = TaxRate::forBusinessDropdown($business_id, true, true);

        $payment_line = $this->dummyPaymentLine;

        $payment_types = $this->transactionUtil->payment_types();

        //Accounts
        $accounts = [];
        if ($this->moduleUtil->isModuleEnabled('account')) {
            $accounts = Account::forDropdown($business_id, true, false, true);
        }

        $walk_in_customer = $this->contactUtil->getWalkInCustomer($business_id);

        $types = [];
        if (auth()->user()->can('supplier.create')) {
            $types['supplier'] = __('report.supplier');
        }
        if (auth()->user()->can('customer.create')) {
            $types['customer'] = __('report.customer');
        }
        if (auth()->user()->can('supplier.create') && auth()->user()->can('customer.create')) {
            $types['both'] = __('lang_v1.both_supplier_customer');
        }

        $item = Revenue::findorfail($id);

        return view('revenues.edit')
        ->with('tipo', 'customer')
        ->with('estados', $this->prepareUFs())
        ->with('cities', $this->prepareCities())
        ->with(compact('expense_categories', 'business_locations', 'users', 'taxes', 'payment_line', 'payment_types', 'accounts', 'walk_in_customer', 'types', 'item'));
    }

    public function update(Request $request, $id){
        if (!auth()->user()->can('revenues.access')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = $request->session()->get('user.business_id');

            $request->validate([
                'document' => 'file|max:'. (config('constants.document_size_limit') / 1000)
            ]);

            $item = Revenue::findorfail($id);

            $data = $request->vencimento;
            $data = $this->transactionUtil->uf_date($data);

            $user_id = $request->session()->get('user.id');


            $request->merge([
                'valor_total' => str_replace(",", ".", $request->final_total),
                'vencimento' => $data,
                'created_by' => $user_id,
                'status' => $request->valor_recebido > 0,
                'valor_recebido' => str_replace(",", ".", $request->valor_recebido)
            ]);

            $document_name = $this->transactionUtil->uploadFile($request, 'document', 'documents');
            if (!empty($document_name)) {
                $inputs['document'] = $document_name;
            }

            $item->fill($request->all())->save();

            $output = [
                'success' => 1,
                'msg' => 'Conta a receber atualizada'
            ];

        } catch (\Exception $e) {
            // DB::rollBack();

            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());


            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return redirect('revenues')->with('status', $output);
    }

    public function receive($id){
        $item = Revenue::findorfail($id);
        $payment_types = $this->transactionUtil->payment_types();

        return view('revenues.receive', compact('item', 'payment_types'));
    }

    public function receivePut(Request $request, $id){
        $item = Revenue::findorfail($id);
        try{

            $data = $request->recebimento;   
            $data = $this->transactionUtil->uf_date($data);
            $item->status = 1;
            $item->recebimento = $data;
            $item->valor_recebido = str_replace(",", ".", $request->valor_recebido);

            $item->save();
            $output = [
                'success' => 1,
                'msg' => 'Conta recebida'
            ];
        } catch (\Exception $e) {
            // echo $e->getMessage();
            // die;
            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong')
            ];
        }
        return redirect('revenues')->with('status', $output);
    }

}
