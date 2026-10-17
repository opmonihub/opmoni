<?php

namespace App\Http\Controllers;

use App\Models\BusinessLocation;
use App\Models\Printer;
use App\Models\InvoiceLayout;
use App\Models\InvoiceScheme;

use Illuminate\Http\Request;
use NFePHP\Common\Certificate;

class LocationSettingsController extends Controller
{
    /**
    * All class instance.
    *
    */
    protected $printReceiptOnInvoice;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->printReceiptOnInvoice = ['1' => 'Sim', '0' => 'Não'];
        $this->receiptPrinterType = ['browser' => 'Impressão baseada em navegador', 'printer' => 'Usar impressora de recibos configurada'];
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($location_id)
    {
        //Check for locations access permission
        if (!auth()->user()->can('business_settings.access') ||!auth()->user()->can_access_this_location($location_id)) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $location = BusinessLocation::where('business_id', $business_id)
        ->findorfail($location_id);

        $printers = Printer::forDropdown($business_id);

        $printReceiptOnInvoice = $this->printReceiptOnInvoice;
        $receiptPrinterType = $this->receiptPrinterType;

        $invoice_layouts = InvoiceLayout::where('business_id', $business_id)
        ->get()
        ->pluck('name', 'id');
        $invoice_schemes = InvoiceScheme::where('business_id', $business_id)
        ->get()
        ->pluck('name', 'id');

        return view('location_settings.index')
        ->with(compact('location', 'printReceiptOnInvoice', 'receiptPrinterType', 'printers', 'invoice_layouts', 'invoice_schemes'))
        ->with('infoCertificado', $this->getInfoCertificado($location));
    }

    public function settingsAjax($location_id)
    {
        //Check for locations access permission
        if (!auth()->user()->can('business_settings.access') ||!auth()->user()->can_access_this_location($location_id)) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $location = BusinessLocation::where('business_id', $business_id)
        ->findorfail($location_id);

        return response()->json($location, 200);
    }

    /**
     * Update the settings
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateSettings($location_id, Request $request)
    {
        try {
            //Check for locations access permission
            if (!auth()->user()->can('business_settings.access') || !auth()->user()->can_access_this_location($location_id)) {
                abort(403, 'Unauthorized action.');
            }

            $input = $request->only(['print_receipt_on_invoice', 'receipt_printer_type', 'printer_id', 'invoice_layout_id', 'invoice_scheme_id', 'info_complementar']);

            //Auto set to browser in demo.
            if (config('app.env') == 'demo') {
                $input['receipt_printer_type'] = 'browser';
            }

            $business_id = request()->session()->get('user.business_id');

            $location = BusinessLocation::where('business_id', $business_id)
            ->findorfail($location_id);

            $location->fill($input);
            $location->update();

            $output = [
                'success' => 1,
                'msg' => __("receipt.receipt_settings_updated")
            ];
        } catch (\Exception $e) {
            $output = [
                'success' => 0,
                'msg' => __("messages.something_went_wrong")
            ];
        }

        return back()->with('status', $output);
    }

    public function updateSettingsCertificado($location_id,Request $request){

        $certificado = $request->file('certificado');
        $senha = $request->senha_certificado;

        if($certificado && $senha != ''){
            $ctx = file_get_contents($certificado);
            $senha = base64_encode($senha);

            $location = BusinessLocation::find($location_id);
            $location->senha_certificado = $senha;
            $location->certificado = $ctx;
            $location->save();
            $output = [
                'success' => 1,
                'msg' => 'Certificado configurado!!'
            ];

        }else{
            $output = [
                'success' => 0,
                'msg' => 'Informe arquivo e senha!!'
            ];


        }
        return back()->with('status', $output);

    }

    private function getInfoCertificado($location){
        if($location->certificado == null) return null;

        try{
            $infoCertificado = Certificate::readPfx($location->certificado, base64_decode($location->senha_certificado));

            $publicKey = $infoCertificado->publicKey;

            $inicio =  $publicKey->validFrom->format('Y-m-d H:i:s');
            $expiracao =  $publicKey->validTo->format('Y-m-d H:i:s');

            return [
                'serial' => $publicKey->serialNumber,
                'inicio' => \Carbon\Carbon::parse($inicio)->format('d-m-Y H:i'),
                'expiracao' => \Carbon\Carbon::parse($expiracao)->format('d-m-Y H:i'),
                'id' => $publicKey->commonName
            ];
        } catch (\Exception $e) {

            return -1;   
        }

    }
}
