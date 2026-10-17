<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemIbpt extends Model
{
    protected $fillable = [
		'ibte_id', 'codigo', 'descricao', 'nacional_federal', 'importado_federal', 'estadual',
		'municipal'
	];
}
