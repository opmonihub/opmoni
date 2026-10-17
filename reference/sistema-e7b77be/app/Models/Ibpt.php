<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\ItemIbpt;
class Ibpt extends Model
{
    protected $fillable = [
		'uf', 'versao'
	];

	public function itens(){
		return $this->hasMany('App\Models\ItemIbte', 'ibte_id', 'id');
	}

	public static function estados(){
		return [
			"AC" => "AC",
			"AL" => "AL",
			"AM" => "AM",
			"AP" => "AP",
			"BA" => "BA",
			"CE" => "CE",
			"DF" => "DF",
			"ES" => "ES",
			"GO" => "GO",
			"MA" => "MA",
			"MG" => "MG",
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
			"TO" => "TO",
		];
	}

	public static function getIBPT($uf, $codigo){
		$trib = ItemIbpt::
		join('ibpts', 'ibpts.id' , '=', 'item_ibpts.ibte_id')
		->where('ibpts.uf', $uf)
		->where('item_ibpts.codigo', $codigo)
		->first();

		return $trib;
	}

}
