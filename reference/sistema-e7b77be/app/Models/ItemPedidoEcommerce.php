<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemPedidoEcommerce extends Model
{
    protected $fillable = [
		'pedido_id', 'produto_id', 'quantidade', 'variacao_id'
	];

	public function produto(){
		return $this->belongsTo(Product::class, 'produto_id');
	}

	public function variacao(){
		return $this->belongsTo(Variation::class, 'variacao_id');
	}

}
