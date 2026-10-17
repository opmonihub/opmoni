<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClienteEcommerce extends Model
{
	protected $hidden = [
        'senha'
    ];

	protected $fillable = [
		'nome', 'sobre_nome', 'cpf', 'email', 'telefone', 'senha', 'status', 'business_id', 
		'token', 'ie'
	];

	public function enderecos(){
		return $this->hasMany('App\Models\EnderecoEcommerce', 'cliente_id', 'id');
	}

	public function pedidos(){
		return PedidoEcommerce::
		where('cliente_id', $this->id)
		->where('status', '!=', 0)
		->get();
	}
    
}
