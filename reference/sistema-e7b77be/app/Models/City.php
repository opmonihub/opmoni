<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    public static function getCidadeCod($codMun){
    	return City::
    	where('codigo', $codMun)
    	->first();
	}

	protected $fillable = [
		'nome', 'uf', 'codigo'
	];

	public static function ufs(){
		return [

			'AC' => 'AC',
			'AL' => 'AL',
			'AM' => 'AM',
			'AP' => 'AP',
			'BA' => 'BA',
			'CE' => 'CE',
			'DF' => 'DF',
			'ES' => 'ES',
			'GO' => 'GO',
			'MA' => 'MA',
			'MG' => 'MG',
			'MS' => 'MS',
			'MT' => 'MT',
			'PA' => 'PA',
			'PB' => 'PB',
			'PE' => 'PE',
			'PI' => 'PI',
			'PR' => 'PR',
			'RJ' => 'RJ',
			'RN' => 'RN',
			'RO' => 'RO',
			'RR' => 'RR',
			'RS' => 'RS',
			'SE' => 'SE',
			'SC' => 'SC',
			'SP' => 'SP',
			'TO' => 'TO'
			
		];
	}
}
