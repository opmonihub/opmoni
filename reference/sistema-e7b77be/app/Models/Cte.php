<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\ConfigNota;
class Cte extends Model
{
    protected $fillable = [
        'business_id', 'chave_nfe', 'remetente_id', 'destinatario_id', 'usuario_id', 
        'natureza_id', 'tomador', 'tipo_servico', 'tomador_outros_id', 'municipio_envio', 'municipio_inicio', 'municipio_fim', 
        'logradouro_tomador', 'numero_tomador', 'bairro_tomador', 'cep_tomador', 
        'municipio_tomador', 'valor_transporte', 'valor_receber', 'valor_carga', 
        'produto_predominante', 'data_previsata_entrega', 'observacao',
        'sequencia_cce', 'cte_numero', 'chave', 'path_xml', 'estado', 'retira', 
        'detalhes_retira', 'modal', 'veiculo_id', 'tpDoc', 'descOutros', 'nDoc', 'vDocFisc', 
        'location_id', 'globalizado', 'cst', 'perc_icms', 'recebedor_id', 'expedidor_id', 'recibo',
        'tp_cte', 'chave_original', 'chave_complementada', 'ind_altera_toma', 'cte_original_id'
    ];

    // 0-Remetente; 1-Expedidor; 2-Recebedor; 3-Destinatário; 4-Outros

    public function getTomador(){
        if($this->tomador == 0) return 'Remetente';
        else if($this->tomador == 1) return 'Expedidor';
        else if($this->tomador == 2) return 'Recebedor';
        else if($this->tomador == 3) return 'Destinatário';
        else if($this->tomador == 4) return 'Outros';
    }

    public function tomadorOutros(){
        return $this->belongsTo(Contact::class, 'tomador_outros_id');
    }

    public function documentosAnteriores(){
        return $this->hasMany(CteDocumentoAnterior::class, 'cte_id', 'id');
    }

    public function original(){
        return $this->belongsTo(Cte::class, 'cte_original_id');
    }

    public function substitutos(){
        return $this->hasMany(Cte::class, 'cte_original_id', 'id');
    }

    public function componentes(){
        return $this->hasMany('App\Models\ComponenteCte', 'cte_id', 'id');
    }

    public function medidas(){
        return $this->hasMany('App\Models\MedidaCte', 'cte_id', 'id');
    }

    public function natureza(){
        return $this->belongsTo(NaturezaOperacao::class, 'natureza_id');
    }

    public function location()
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function somaDespesa(){
        $total = 0;
        foreach($this->despesas as $d){
            $total += $d->valor;
        }
        return $total;
    }

    public function somaReceita(){
        $total = 0;
        foreach($this->receitas as $r){
            $total += $r->valor;
        }
        return $total;
    }

    public function destinatario(){
        return $this->belongsTo(Contact::class, 'destinatario_id');
    }

    public function recebedor(){
        return $this->belongsTo(Contact::class, 'recebedor_id');
    }

    public function expedidor(){
        return $this->belongsTo(Contact::class, 'expedidor_id');
    }

    public function veiculo(){
        return $this->belongsTo(Veiculo::class, 'veiculo_id');
    }

    public function remetente(){
        return $this->belongsTo(Contact::class, 'remetente_id');
    }

    public function municipioTomador(){
        return $this->belongsTo(City::class, 'municipio_tomador');
    }

    public function municipioEnvio(){
        return $this->belongsTo(City::class, 'municipio_envio');
    }

    public function municipioInicio(){
        return $this->belongsTo(City::class, 'municipio_inicio');
    }

    public function municipioFim(){
        return $this->belongsTo(City::class, 'municipio_fim');
    }

    public function lastCTe($cte){
        $fistLocation = BusinessLocation::where('business_id', $this->business_id)->first();
        if($cte->location_id != $fistLocation->id){
           $config = BusinessLocation::where('id', $cte->location_id)->first(); 
        } else {
            $config = Business::find($this->business_id);
        } 

        $cte = Cte::
        where('cte_numero', '>', 0)
        ->where('business_id', $this->business_id)
        ->where('location_id', $this->location_id)
        ->orderBy('cte_numero', 'desc')
        ->first();

        // $config = Business::find($this->business_id);
        
        if($cte == null){
            return $config->ultimo_numero_cte;
        }

        if($config->ultimo_numero_cte > $cte->cte_numero){
            return $config->ultimo_numero_cte;
        }else{
            return $cte->cte_numero;
        }
    }

    public static function lastCTeAux($business_id){
        $cte = Cte::
        where('cte_numero', '>', 0)
        ->where('business_id', $business_id)
        ->orderBy('cte_numero', 'desc')
        ->first();

        $config = Business::find($business_id);

        if($cte == null){
            return $config->ultimo_numero_cte;
        }

        if($config->ultimo_numero_cte > $cte->cte_numero){
            return $config->ultimo_numero_cte;
        }else{
            return $cte->cte_numero;
        }
    }

    public static function unidadesMedida(){
        return [
            '00' => 'M3',
            '01' => 'KG',
            '02' => 'TON',
            '03' => 'UNIDADE',
            '04' => 'M2',
        ];
    }

    public static function modals(){
        return [
            '01' => 'RODOVIARIO',
            '02' => 'AEREO',
            '03' => 'AQUAVIARIO',
            '04' => 'FERROVIARIO', 
            '05' => 'DUTOVIARIO', 
            '06' => 'MULTIMODAL',
        ];
    }

    public static function tiposMedida(){
        return [
            'PESO BRUTO' => 'PESO BRUTO',
            'PESO DECLARADO' => 'PESO DECLARADO',
            'PESO CUBADO' => 'PESO CUBADO',
            'PESO AFORADO' => 'PESO AFORADO', 
            'PESO AFERIDO' => 'PESO AFERIDO',
            'LITRAGEM' => 'LITRAGEM', 
            'CAIXAS' => 'CAIXAS'
        ];
    }

    public static function tiposTomador(){
        return [
            '0' => 'Remetente',
            '1' => 'Expedidor', 
            '2' => 'Recebedor',
            '3' => 'Destinatário',
            '4' => 'Outros'
        ];
    }

    public static function tiposServico(){
        return [
            '0' => 'Normal',
            '1' => 'Subcontratação',
            '2' => 'Redespacho',
            '3' => 'Redespacho Intermediário',
            '4' => 'Serviço Vinculado a Multimodal'
        ];
    }

    public static function gruposCte(){
        return [
            'ide',
            'toma03',
            'toma04',
            'enderToma',
            'autXML',
            'compl',
            'ObsCont',
            'ObsFisco',
            'emit',
            'enderEmit',
            'rem',
            'enderReme',
            'infNF',
            'infOutros',
            'infUnidTransp',
            'IacUnidCarga',
            'infUnidCarga',
            'exped',
            'enderExped',
            'receb',
            'enderReceb',
            'dest',
            'enderDest',
            'vPrest',
            'Comp',
            'imp',
            'ICMS',
            'infQ',
            'docAnt'
        ];
    }

    public static function filtroData($dataInicial, $dataFinal, $estado){
        $c = Cte::
        whereBetween('data_registro', [$dataInicial, 
            $dataFinal]);

        if($estado != 'TODOS') $c->where('ctes.estado', $estado);

        return $c->get();
    }

    public static function filtroDataCliente($cliente, $dataInicial, $dataFinal, $estado){
        $c = Cte::
        select('ctes.*')
        ->join('clientes', 'clientes.id' , '=', 'ctes.cliente_id')
        ->where('clientes.razao_social', 'LIKE', "%$cliente%")

        ->whereBetween('data_registro', [$dataInicial, 
            $dataFinal]);

        if($estado != 'TODOS') $c->where('ctes.estado', $estado);
        return $c->get();
    }

    public static function filtroCliente($cliente, $estado){
        $c = Cte::
        select('ctes.*')
        ->join('clientes', 'clientes.id' , '=', 'ctes.cliente_id')
        ->where('clientes.razao_social', 'LIKE', "%$cliente%");

        if($estado != 'TODOS') $c->where('ctes.estado', $estado);

        return $c->get();
    }

    public static function filtroEstado($estado){
        $c = Cte::
        where('ctes.estado', $estado);

        return $c->get();
    }

     public static function getCsts(){
        return [
            '00' => '00 - tributação normal ICMS',
            '20' => '20 - tributação com BC reduzida do ICMS', 
            '40' => '40 - ICMS isenção',
            '41' => '41 - ICMS não tributada',
            '51' => '51 - ICMS diferido',
            '60' => '60 - ICMS cobrado por substituição tributária',
            '90' => '90 - ICMS outros',
        ];
    }
}
