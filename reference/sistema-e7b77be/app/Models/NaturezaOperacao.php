<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NaturezaOperacao extends Model
{
	protected $fillable = [
		'natureza', 'cfop_entrada_estadual', 'cfop_entrada_inter_estadual',
		'cfop_saida_estadual', 'cfop_saida_inter_estadual', 'business_id', 
		'sobrescreve_cfop', 'finNFe', 'tipo', 'bonificacao'
	];

	public static function finalidades(){
		return [
			'1' => '1 - NFe normal',
			'2' => '2 - NFe complementar',
			'3' => '3 - NFe de ajuste',
			'4' => '4 - Devolução de mercadoria'
		];
	}
}
