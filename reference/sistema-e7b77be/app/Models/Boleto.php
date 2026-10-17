<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Boleto extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_id', 'revenue_id', 'numero', 'numero_documento', 'carteira', 'convenio', 
        'linha_digitavel', 'nome_arquivo', 'juros', 'multa', 'juros_apos', 'instrucoes', 
        'logo', 'tipo', 'codigo_cliente', 'posto'
    ];

    public function revenue(){
        return $this->belongsTo(Revenue::class, 'revenue_id');
    }

    public function bank(){
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function itemRemessa(){
        return $this->hasOne('App\Models\RemessaBoleto', 'boleto_id', 'id');
    }
}
