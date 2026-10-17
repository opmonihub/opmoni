<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mdfe extends Model
{

	protected $fillable = [
		'uf_inicio', 'uf_fim', 'encerrado', 'data_inicio_viagem', 'carga_posterior',
		'veiculo_tracao_id', 'veiculo_reboque1_id', 'veiculo_reboque2_id',
		'veiculo_reboque3_id', 'estado', 'seguradora_nome',
		'seguradora_cnpj', 'numero_apolice','numero_averbacao', 'valor_carga',
		'quantidade_carga', 'info_complementar', 'info_adicional_fisco', 'cnpj_contratante',
		'mdfe_numero', 'condutor_nome', 'condutor_cpf','tp_emit', 'tp_transp', 'lac_rodo',
		'chave', 'protocolo', 'business_id', 'location_id', 'produto_pred_nome',
		'produto_pred_ncm', 'produto_pred_cod_barras', 'cep_carrega', 'cep_descarrega',
		'tp_carga', 'latitude_carregamento', 'longitude_carregamento',
		'latitude_descarregamento', 'longitude_descarregamento',
		// Campos de pagamento (NT 2025.001)
		'responsavel_pag_nome', 'responsavel_pag_cpf_cnpj', 'valor_frete',
		'tipo_componente', 'valor_contrato', 'indicador_pagamento',
		'numero_parcela', 'data_vencimento', 'valor_parcela',
		'codigo_banco', 'codigo_agencia', 'cnpj_ipef', 'chave_pix'
	];

	public function veiculoTracao(){
		return $this->belongsTo(Veiculo::class, 'veiculo_tracao_id');
	}

	public function veiculoReboque1(){
		return $this->belongsTo(Veiculo::class, 'veiculo_reboque1_id');
	}

	public function veiculoReboque2(){
		return $this->belongsTo(Veiculo::class, 'veiculo_reboque2_id');
	}

	public function veiculoReboque3(){
		return $this->belongsTo(Veiculo::class, 'veiculo_reboque2_id');
	}

	public function municipiosCarregamento(){
		return $this->hasMany('App\Models\MunicipioCarregamento', 'mdfe_id', 'id');
	}

	public function ciots(){
		return $this->hasMany('App\Models\Ciot', 'mdfe_id', 'id');
	}

	public function percurso(){
		return $this->hasMany('App\Models\Percurso', 'mdfe_id', 'id');
	}

	public function valesPedagio(){
		return $this->hasMany('App\Models\ValePedagio', 'mdfe_id', 'id');
	}

	public function infoDescarga(){
		return $this->hasMany('App\Models\InfoDescarga', 'mdfe_id', 'id');
	}

	public static function cUF(){
		return [
			'12' => 'AC',
			'27' => 'AL',
			'13' => 'AM',
			'16' => 'AP',
			'29' => 'BA',
			'23' => 'CE',
			'53' => 'DF',
			'32' => 'ES',
			'52' => 'GO',
			'21' => 'MA',
			'31' => 'MG',
			'50' => 'MS',
			'51' => 'MT',
			'15' => 'PA',
			'25' => 'PB',
			'26' => 'PE',
			'22' => 'PI',
			'41' => 'PR',
			'33' => 'RJ',
			'24' => 'RN',
			'11' => 'RO',
			'14' => 'RR',
			'43' => 'RS',
			'42' => 'SC',
			'28' => 'SE',
			'35' => 'SP',
			'17' => 'TO'
		];
	}

	public static function tiposUnidadeTransporte(){
		return [
			'1' => 'Rodoviário Tração',
			'2' => 'Rodoviário Reboque',
			'3' => 'Navio',
			'4' => 'Balsa',
			'5' => 'Aeronave',
			'6' => 'Vagão',
			'7' => 'Outros'
		];
	}

	public static function tiposCarga(){
		return [
			'01' => 'Granel sólido', 
			'02' => 'Granel líquido', 
			'03' => 'Frigorificada', 
			'04' => 'Conteinerizada', 
			'05' => 'Carga Geral',
			'06' => 'Neogranel',
			'07' => 'Perigosa (granel sólido)',
			'08' => 'Perigosa (granel líquido)',
			'09' => 'Perigosa (carga frigorificada)',
			'10' => 'Perigosa (conteinerizada)',
			'11' => 'Perigosa (carga geral)'
		];
	}

	public function lastMDFe($mdfe){
        $fistLocation = BusinessLocation::where('business_id', $this->business_id)->first();
        if($mdfe->location_id != $fistLocation->id){
           $config = BusinessLocation::where('id', $mdfe->location_id)->first(); 
        } else {
            $config = Business::find($this->business_id);
        } 

        $mdfe = Mdfe::
        where('mdfe_numero', '>', 0)
        ->where('business_id', $this->business_id)
        ->where('location_id', $this->location_id)
        ->orderBy('mdfe_numero', 'desc')
        ->first();

        // $config = Business::find($this->business_id);
        
        if($mdfe == null){
            return $config->ultimo_numero_mdfe;
        }

        if($config->ultimo_numero_mdfe > $mdfe->mdfe_numero){
            return $config->ultimo_numero_mdfe;
        }else{
            return $mdfe->mdfe_numero;
        }
    }

    public static function lastMDFeAux($business_id){
        $mdfe = Mdfe::
        where('mdfe_numero', '>', 0)
        ->where('business_id', $business_id)
        ->orderBy('mdfe_numero', 'desc')
        ->first();

        $config = Business::find($business_id);

        if($mdfe == null){
            return $config->ultimo_numero_mdfe;
        }

        if($config->ultimo_numero_mdfe > $mdfe->mdfe_numero){
            return $config->ultimo_numero_mdfe;
        }else{
            return $mdfe->mdfe_numero;
        }
    }

    /**
     * Verifica se o MDF-e é carga lotação (apenas 1 documento fiscal)
     * Necessário para validar obrigatoriedade do grupo infPag (NT 2025.001)
     *
     * @return bool
     */
    public function isCargaLotacao(){
        $totalDocs = 0;

        foreach($this->infoDescarga as $info) {
            if($info->nfe) $totalDocs++;
            if($info->cte) $totalDocs++;
        }

        return $totalDocs === 1;
    }

    /**
     * Verifica se as informações de pagamento são obrigatórias
     * Obrigatório quando: Modal=1 (Rodoviário) + tpEmit=1,2,3 + Carga Lotação
     *
     * @return bool
     */
    public function requiresPaymentInfo(){
        // Modal rodoviário = 1 (sempre no sistema atual)
        // tpEmit: 1=Prestador, 2=Transportador, 3=Transportador com CT-e Globalizado
        $requiresEmitType = in_array($this->tp_emit, [1, 2, 3]);

        return $requiresEmitType && $this->isCargaLotacao();
    }
}
