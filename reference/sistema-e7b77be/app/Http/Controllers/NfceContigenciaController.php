<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\BusinessLocation;
use App\Models\Contigencia;

class NfceContigenciaController extends Controller
{
    public function index(Request $request){
        $business_id = request()->session()->get('user.business_id');
        $data_inicio = $request->data_inicio;
        $data_final = $request->data_final;

        $data = Transaction::where('business_id', $business_id)
        ->where('contigencia', 1)
        ->where('numero_nfce', '>', 0)

        ->when(!empty($data_inicio), function ($query) use ($data_inicio) {
            return $query->whereDate('created_at', '>=', $this->parseDate($data_inicio));
        })
        ->when(!empty($data_final), function ($query) use ($data_final) {
            return $query->whereDate('created_at', '<=', $this->parseDate($data_final));
        })
        ->whereNull('reenvio_contigencia')
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
        $contigencia = $this->getContigencia();
        return view('nfce.contigencia', compact('data', 'default_location', 'contigencia', 'data_inicio', 'data_final'));
    }

    private function parseDate($date){
        return date('Y-m-d', strtotime(str_replace("/", "-", $date)));
    }

    private function getContigencia(){

        $business_id = request()->session()->get('user.business_id');

        $active = Contigencia::
        where('business_id', $business_id)
        ->where('status', 1)
        ->where('documento', 'NFCe')
        ->first();
        return $active != null ? 1 : 0;
    }
}
