<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Veiculo extends Model
{
	protected $fillable = [
		'business_id', 'tipo', 'placa', 'uf', 'cor', 'marca', 'modelo', 'rntrc', 'tipo_carroceira',
		'tipo_rodado', 'tara', 'capacidade', 'proprietario_documento',
		'proprietario_nome', 'proprietario_ie', 'proprietario_uf', 'proprietario_tp'
	];

	public static function tipos(){
		return [
			"2" => "CICLOMOTO",
			"3" => "MOTONETA",
			"4" => "MOTOCICLO",
			"5" => "TRICICLO",
			"6" => "AUTOMÓVEL", 
			"7" => "MICRO-ÔNIBUS", 
			"8" => "ÔNIBUS", 
			"10" => "REBOQUE", 
			"11" => "SEMIRREBOQUE", 
			"13" => "CAMIONETA", 
			"14" => "CAMINHÃO", 
			"17" => "CAMINHÃO TRATOR", 
			"18" => "TRATOR RODAS", 
			"19" => "TRATOR ESTEIRAS", 
			"20" => "TRATOR MISTO", 
			"21" => "QUADRICICLO", 
			"22" => "ESP / ÔNIBUS", 
			"23" => "CAMINHONETE", 
			"24" => "CARGA/CAM", 
			"25" => "UTILITÁRIO", 
			"26" => "MOTOR-CASA"
		];
	}

	public static function getTipo($tipo){
		foreach(Veiculo::tipos() as $key => $t){
			if($tipo == $key) return $t;
		}
	}

	//tipos de rodado
	public static function tiposRodado(){
		return [
			"01" => "TRUCK",
			"02" => "TOCO",
			"03" => "CAVALO MECANICO",
			"04" => "VAN",
			"05" => "UTILITARIO", 
			"06" => "OUTROS"
		];
	}

	public static function getTipoRodado($tipo){
		foreach(Veiculo::tiposRodado() as $key => $t){
			if($tipo == $key) return $t;
		}
	}

	//tipos de carroceria
	public static function tiposCarroceria(){
		return [
			"00" => "NAO APLICAVEL",
			"01" => "ABERTA",
			"02" => "FECHADA/BAU",
			"03" => "GRANELEIRA",
			"04" => "PORTA CONTAINER",
			"05" => "SLIDER"
		];
	}

	public static function getTipoCarrocceria($tipo){
		foreach(Veiculo::tiposCarroceria() as $key => $t){
			if($tipo == $key) return $t;
		}
	}


	//tipos de proprietário
	public static function tiposProprietario(){
		return [
			"0" => "TAC AGREGADO",
			"1" => "TAC INDEPENDENTE",
			"2" => "OUTROS"
		];
	}

	public static function getTipoProprietario($tipo){
		foreach(Veiculo::tiposProprietario() as $key => $t){
			if($tipo == $key) return $t;
		}
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
			'28' => 'SE',
			'42' => 'SC',
			'35' => 'SP',
			'17' => 'TO'
			
		];
	}

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

	public static function tiposOperacao(){
		return [
			'1' => 'Venda concessionária',
			'2' => 'Faturamento direto para consumidor final',
			'3' => 'Venda direta para grandes consumidores (frotista, governo)',
			'0' => 'Outros'
		];
	}

	public static function tiposCompustivel(){
		return [
			'1' => '01 - Álcool',
			'2' => '02 - Gasolina', 
			'3' => '03 - Diesel',
			'4' => '04 - Gasogênio',
			'5' => '05 - Gás Metano',
			'6' => '06 - Elétrico/Fonte Interna',
			'7' => '07 - Elétrico/Fonte Externa',
			'8' => '08 - Gasolina/Gás Natural Combustível',
			'9' => '09 - Álcool/Gás Natural Combustível',
			'10' => '10 - Diesel/Gas Natural Combustível',
			'11' => '11 - Vide/Campo/Observação',
			'12' => '12 - Álcool/Gás Natural Veicular',
			'13' => '13 - Gasolina/Gás Natural Veicular',
			'14' => '14 - Diesel/Gás Natural Veicular',
			'15' => '15 - Gás Natural Veicular',
			'16' => '16 - Álcool/Gasolina',
			'17' => '17 - Gasolina/Álcool/Gás Natural Veicular',
			'18' => '18 - Gasolina/eletrico'
		];
	}

	public static function tiposPintura(){
		return [
			'F'=>'FOSCA',
			'S'=>'SÓLIDA',
			'P'=>'PEROLIZADA'
		];
	}

	public static function especies(){
		return [
			'1' => 'PASSAGEIRO',
			'2' => 'CARGA',
			'3' => 'MISTO',
			'4' => 'CORRIDA',
			'5' => 'TRAÇÃO', 
			'6' => 'ESPECIAL'
		];
	}

	public static function cores(){
		return [
			'1' => 'AMARELO', 
			'2' => 'AZUL', 
			'3' => 'BEGE',
			'4' => 'BRANCA', 
			'5' => 'CINZA', 
			'6' => 'DOURADA',
			'7' => 'GRENÁ', 
			'8' => 'LARANJA', 
			'9' => 'MARROM',
			'10' => 'PRATA', 
			'11' => 'PRETA', 
			'12' => 'ROSA', 
			'13' => 'ROXA',
			'14' => 'VERDE', 
			'15' => 'VERMELHA', 
			'16' => 'FANTASIA'
		];
	}

	public static function restricoes(){
		return [
			'0' => 'Não há',
			'1' => 'Alienação Fiduciária',
			'2' => 'Arrendamento Mercantil',
			'3' => 'Reserva de Domínio',
			'4' => 'Penhor de Veículos',
			'9' => 'Outras'
		];
	}

}
