<?php

namespace App\Http\Controllers;

use App\Models\BusinessLocation;
use App\Models\CashRegister;
use App\Models\User;
use App\Models\SangriaSuprimento;
use App\Utils\CashRegisterUtil;
use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use NFePHP\DA\NFe\CupomCaixa;

class CashRegisterController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $cashRegisterUtil;
    protected $moduleUtil;

    /**
     * Constructor
     *
     * @param CashRegisterUtil $cashRegisterUtil
     * @return void
     */
    public function __construct(CashRegisterUtil $cashRegisterUtil, ModuleUtil $moduleUtil)
    {

        $this->cashRegisterUtil = $cashRegisterUtil;
        $this->moduleUtil = $moduleUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('cash_register.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //like:repair
        $sub_type = request()->get('sub_type');

        //Check if there is a open register, if yes then redirect to POS screen.
        if ($this->cashRegisterUtil->countOpenedRegister() != 0) {
            return redirect()->action('SellPosController@create', ['sub_type' => $sub_type]);
        }
        $business_id = request()->session()->get('user.business_id');
        $business_locations = BusinessLocation::forDropdown($business_id);

        return view('cash_register.create')->with(compact('business_locations', 'sub_type'));
    }

    public function getSangriaSuprimento(Request $request){

        return view('cash_register.sangria_suprimento');
    }

    public function storeSangriaSuprimento(Request $request){
        try{

            $user_id = auth()->user()->id;
            $cash = CashRegister::where('user_id', $user_id)
            ->where('status', 'open')
            ->first();

            if($cash){
                SangriaSuprimento::create([
                    'cash_id' => $cash->id,
                    'type' => $request->type,
                    'value' => str_replace(",", ".", $request->value),
                    'note'  => $request->note ?? ""
                ]);
                if($request->type == 'suprimento'){
                    $msg = 'Suprimento realizado!';                
                }else{
                    $msg = 'Sangria realizada';
                }
                $output = [
                    'success' => 1,
                    'msg' => $msg
                ];
            }else{
                $output = [
                    'success' => 0,
                    'msg' => 'Algo errado com o caixa'
                ];
            }
        } catch (\Exception $e) {
            $output = [
                'success' => 0,
                'msg' => $e->getMessage()
            ];
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
        }
        return redirect()->back()->with('status', $output);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //like:repair
        $sub_type = request()->get('sub_type');

        try {

            $initial_amount = 0;
            if (!empty($request->input('amount'))) {
                $initial_amount = $this->cashRegisterUtil->num_uf($request->input('amount'));

            }
            $user_id = $request->session()->get('user.id');
            $business_id = $request->session()->get('user.business_id');

            $register = CashRegister::create([
                'business_id' => $business_id,
                'user_id' => $user_id,
                'status' => 'open',
                'location_id' => $request->input('location_id'),
                'created_at' => \Carbon::now()->format('Y-m-d H:i:00')
            ]);

            $register->cash_register_transactions()->create([
                'amount' => $initial_amount,
                'pay_method' => 'cash',
                'type' => 'credit',
                'transaction_type' => 'initial'
            ]);
        } catch (\Exception $e) {
            die;
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
        }

        return redirect()->action('SellPosController@create', ['sub_type' => $sub_type]);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\CashRegister  $cashRegister
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $register_details =  $this->cashRegisterUtil->getRegisterDetails($id);
        $user_id = $register_details->user_id;
        $open_time = $register_details['open_time'];
        $close_time = !empty($register_details['closed_at']) ? $register_details['closed_at'] : \Carbon::now()->toDateTimeString();
        $details = $this->cashRegisterUtil->getRegisterTransactionDetails($user_id, $open_time, $close_time);

        $payment_types = $this->cashRegisterUtil->payment_types();

        return view('cash_register.register_details')
        ->with(compact('register_details', 'details', 'payment_types', 'close_time'));
    }

    /**
     * Shows register details modal.
     *
     * @param  void
     * @return \Illuminate\Http\Response
     */
    public function getRegisterDetails()
    {
        $register_details =  $this->cashRegisterUtil->getRegisterDetails();

        $user_id = auth()->user()->id;
        $open_time = $register_details['open_time'];
        $close_time = \Carbon::now()->toDateTimeString();

        $is_types_of_service_enabled = $this->moduleUtil->isModuleEnabled('types_of_service');

        $details = $this->cashRegisterUtil->getRegisterTransactionDetails($user_id, $open_time, $close_time, $is_types_of_service_enabled);

        $payment_types = $this->cashRegisterUtil->payment_types($register_details->location_id);
        
        return view('cash_register.register_details')
        ->with(compact('register_details', 'details', 'payment_types', 'close_time'));
    }

    /**
     * Shows close register form.
     *
     * @param  void
     * @return \Illuminate\Http\Response
     */
    public function getCloseRegister()
    {
        $register_details =  $this->cashRegisterUtil->getRegisterDetails();

        $user_id = auth()->user()->id;
        $open_time = $register_details['open_time'];
        $close_time = \Carbon::now()->toDateTimeString();

        $is_types_of_service_enabled = $this->moduleUtil->isModuleEnabled('types_of_service');

        $details = $this->cashRegisterUtil->getRegisterTransactionDetails($user_id, $open_time, $close_time, $is_types_of_service_enabled);
        
        $payment_types = $this->cashRegisterUtil->payment_types($register_details->location_id);
        return view('cash_register.close_register_modal')
        ->with(compact('register_details', 'details', 'payment_types'));
    }

    /**
     * Closes currently opened register.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function postCloseRegister(Request $request)
    {
        try {
            //Disable in demo
            // if (config('app.env') == 'demo') {
            //     $output = ['success' => 0,
            //                     'msg' => 'Feature disabled in demo!!'
            //                 ];
            //     return redirect()->action('HomeController@index')->with('status', $output);
            // }

            $input = $request->only(['closing_amount', 'total_card_slips', 'total_cheques',
                'closing_note']);
            $input['closing_amount'] = $this->cashRegisterUtil->num_uf($input['closing_amount']);
            $user_id = $request->session()->get('user.id');
            $input['closed_at'] = \Carbon::now()->format('Y-m-d H:i:s');
            $input['status'] = 'close';

            CashRegister::where('user_id', $user_id)
            ->where('status', 'open')
            ->update($input);
            $output = [
                'success' => 1,
                'msg' => __('cash_register.close_success')
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            $output = [
                'success' => 0,
                'msg' => __("messages.something_went_wrong")
            ];
        }

        return redirect()->action('HomeController@index')->with('status', $output);
    }

    public function sangriaSuprimentoDestroy($id){
        try{
            SangriaSuprimento::where('id', $id)->delete();
            $output['success'] = true;
            $output['msg'] = "Registro removido!!";
        }catch(\Exception $e){
            $output['success'] = false;
            $output['msg'] = trans("messages.something_went_wrong") . $e->getMessage();
        }
        return $output;
    }

    public function print80($id){
        $item = CashRegister::findorfail($id);

        $register_details =  $this->cashRegisterUtil->getRegisterDetails($id);
        $user_id = $register_details->user_id;
        $user = User::find($user_id);

        $open_time = $register_details['open_time'];
        $close_time = !empty($register_details['closed_at']) ? $register_details['closed_at'] : \Carbon::now()->toDateTimeString();
        $details = $this->cashRegisterUtil->getRegisterTransactionDetails($user_id, $open_time, $close_time);

        $payment_types = $this->cashRegisterUtil->payment_types();

        try {

            // $danfe = new Cupom($transaction);
            // $id = $danfe->monta($logo);
            // $pdf = $danfe->render();
            // return response($pdf)
            // ->header('Content-Type', 'application/pdf');

            $cupom = new CupomCaixa($register_details, $user, $open_time, $close_time, $details, $payment_types);
            $cupom->monta();
            $pdf = $cupom->render();

            return response($pdf)
            ->header('Content-Type', 'application/pdf');


        } catch (InvalidArgumentException $e) {
            echo "Ocorreu um erro durante o processamento :" . $e->getMessage();
        }  

    }
}
