<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Manifesto extends Model
{
    protected $fillable = [
		'chave', 'nome', 'documento', 'valor', 'num_prot', 'data_emissao', 
		'sequencia_evento', 'fatura_salva', 'tipo', 'nsu', 'business_id', 'location_id'
	];

	public function estado(){
		if($this->tipo == 0){
			return "--";
		}else if($this->tipo == 1){
			return "Ciência";
		}else if($this->tipo == 2){
			return "Confirmada";
		}else if($this->tipo == 2){
			return "Desconhecimento";
		}else if($this->tipo == 2){
			return "Operação não realizada";
		}
	}
}
