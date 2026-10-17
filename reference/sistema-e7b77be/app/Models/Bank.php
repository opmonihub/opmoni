<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bank extends Model
{
    use HasFactory;

    protected $fillable = [
        'banco', 'agencia', 'conta', 'titular', 'business_id', 'padrao', 'cnpj', 'endereco',
        'cidade_id', 'cep', 'bairro', 'carteira', 'convenio', 'juros', 'multa', 'juros_apos', 
        'tipo'
    ];

    public static function bancos(){
        return [
            '001' => 'Banco do Brasil',
            '341' => 'Itau',
            '237' => 'Bradesco',
            '748' => 'Sicredi',
            '104' => 'Caixa Econônica Federal',
            '033' => 'Santander',
            '756' => 'Sicoob'
        ];
    }

    private function getBanco(){
        $bancos = Bank::bancos();
        return $bancos[$this->banco];
    }

    public function getInfoAttribute()
    {
        
        return $this->getBanco() . " - agência: $this->agencia | conta: $this->conta";
    }

    public function city(){
        return $this->belongsTo(City::class, 'cidade_id');
    }
}
