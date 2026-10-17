<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\BusinessLocation;
use App\Models\Transaction;
use App\Models\Contact;
use App\Models\Cte;
use App\Models\Mdfe;
class Business extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */

    public static function getConfig($business_id, $transaction){
        $fistLocation = BusinessLocation::where('business_id', $business_id)->first();
        if($transaction->location_id != null && $transaction->location_id != $fistLocation->id){
            return BusinessLocation::where('id', $transaction->location_id)->first(); 
        } else {
            return Business::find($business_id);
        } 
    }

    public static function getConfigCte($business_id, $cte){
        $fistLocation = BusinessLocation::where('business_id', $business_id)->first();
        if($cte->location_id != $fistLocation->id){
            return BusinessLocation::where('id', $cte->location_id)->first(); 
        } else {
            return Business::find($business_id);
        } 
    }

    public static function getConfigMdfe($business_id, $mdfe){
        $fistLocation = BusinessLocation::where('business_id', $business_id)->first();
        if($mdfe->location_id != $fistLocation->id){
            return BusinessLocation::where('id', $mdfe->location_id)->first(); 
        } else {
            return Business::find($business_id);
        } 
    }

    public static function getcUF($uf){
        $estados = [
            'RO' => '11',
            'AC' => '12',
            'AM' => '13',
            'RR' => '14',
            'PA' => '15',
            'AP' => '16',
            'TO' => '17',
            'MA' => '21',
            'PI' => '22',
            'CE' => '23',
            'RN' => '24',
            'PB' => '25',
            'PE' => '26',
            'AL' => '27',
            'SE' => '28',
            'BA' => '29',
            'MG' => '31',
            'ES' => '32',
            'RJ' => '33',
            'SP' => '35',
            'PR' => '41',
            'SC' => '42',
            'RS' => '43',
            'MS' => '50',
            'MT' => '51',
            'GO' => '52',
            'DF' => '53'
        ];
        return $estados[$uf];
    }

    public static function getUF($cod){
        $estados = [
            '11' => 'RO',
            '12' => 'AC',
            '13' => 'AM',
            '14' => 'RR',
            '15' => 'PA',
            '16' => 'AP',
            '17' => 'TO',
            '21' => 'MA',
            '22' => 'PI',
            '23' => 'CE',
            '24' => 'RN',
            '25' => 'PB',
            '26' => 'PE',
            '27' => 'AL',
            '28' => 'SE',
            '29' => 'BA',
            '31' => 'MG',
            '32' => 'ES',
            '33' => 'RJ',
            '35' => 'SP',
            '41' => 'PR',
            '42' => 'SC',
            '43' => 'RS',
            '50' => 'MS',
            '51' => 'MT',
            '52' => 'GO',
            '53' => 'DF'
        ];
        return $estados[$cod];
    }

    protected $table = 'business';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id', 'woocommerce_api_settings'];

    /**
      * The attributes that should be hidden for arrays.
      *
      * @var array
      */
    protected $hidden = ['woocommerce_api_settings'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'ref_no_prefixes' => 'array',
        'enabled_modules' => 'array',
        'email_settings' => 'array',
        'sms_settings' => 'array',
        'common_settings' => 'array',
        'weighing_scale_setting' => 'array'
    ];

    public function cidade()
    {
        return $this->belongsTo(\App\Models\City::class);
    }

    /**
     * Returns the date formats
     */
    public static function date_formats()
    {
        return [
            'd-m-Y' => 'dd-mm-yyyy',
            'm-d-Y' => 'mm-dd-yyyy',
            'd/m/Y' => 'dd/mm/yyyy',
            'm/d/Y' => 'mm/dd/yyyy'
        ];
    }

    /**
     * Get the owner details
     */
    public function owner()
    {
        return $this->hasOne(\App\Models\User::class, 'id', 'owner_id');
    }

    /**
     * Get the Business currency.
     */
    public function currency()
    {
        return $this->belongsTo(\App\Models\Currency::class);
    }

    /**
     * Get the Business currency.
     */
    public function locations()
    {
        return $this->hasMany(\App\Models\BusinessLocation::class);
    }

    /**
     * Get the Business printers.
     */
    public function printers()
    {
        return $this->hasMany(\App\Models\Printer::class);
    }

    /**
    * Get the Business subscriptions.
    */
    public function subscriptions()
    {
        return $this->hasMany('\Modules\Superadmin\Entities\Subscription');
    }

    /**
     * Creates a new business based on the input provided.
     *
     * @return object
     */
    public static function create_business($details)
    {
        $business = Business::create($details);
        return $business;
    }

    /**
     * Updates a business based on the input provided.
     * @param int $business_id
     * @param array $details
     *
     * @return object
     */
    public static function update_business($business_id, $details)
    {
        if (!empty($details)) {
            Business::where('id', $business_id)
            ->update($details);
        }
    }

    public function getBusinessAddressAttribute() 
    {
        $location = $this->locations->first();
        $address = $location->city . 
        ', ' . $location->state . '<br>' . $location->country . ', ' . $location->zip_code;

        return $address;
    }

    public function getRegistros(){
        return [
            'clientes' => sizeof($this->clientes()),
            'fornecedores' => sizeof($this->fornecedores()),
            'vendas' => sizeof($this->vendas()),
            'vendas_pdv' => sizeof($this->vendasEmPdv()),
            'nfes' => sizeof($this->nfes()),
            'nfces' => sizeof($this->nfces()),
            'ctes' => sizeof($this->ctes()),
            'mdfes' => sizeof($this->mdfes()),
        ];
    }

    private function getNfes(){
    }

    public function clientes(){
        $contacts = Contact::where('business_id', $this->id)
        ->where('type', 'customer')
        ->get();

        return $contacts;
    }

    public function fornecedores(){
        $contacts = Contact::where('business_id', $this->id)
        ->where('type', 'supplier')
        ->get();

        return $contacts;
    }

    public function vendas(){
        $vendas = Transaction::where('business_id', $this->id)
        ->where('is_direct_sale', 1)
        ->where('type', 'sell')
        ->get();

        return $vendas;
    }

    public function vendasEmPdv(){
        $vendas = Transaction::where('business_id', $this->id)
        ->where('is_direct_sale', 0)
        ->where('type', 'sell')
        ->get();

        return $vendas;
    }

    public function nfes(){
        $vendas = Transaction::where('business_id', $this->id)
        ->where('is_direct_sale', 1)
        ->where('type', 'sell')
        ->where('numero_nfe', '>', 0)
        ->get();

        return $vendas;
    }

    public function nfces(){
        $vendas = Transaction::where('business_id', $this->id)
        ->where('is_direct_sale', 0)
        ->where('type', 'sell')
        ->where('numero_nfce', '>', 0)
        ->get();

        return $vendas;
    }

    public function ctes(){
        $ctes = Cte::where('business_id', $this->id)
        ->where('cte_numero', '>', 0)
        ->get();

        return $ctes;
    }

    public function mdfes(){
        $ctes = Mdfe::where('business_id', $this->id)
        ->where('mdfe_numero', '>', 0)
        ->get();

        return $ctes;
    }

    public function getPwdCertificado()
    {
        $pwd = base64_decode($this->senha_certificado);

        return $pwd;
    }
}
