<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CupomCliente extends Model
{
    use HasFactory;

    protected $fillable = [
        'cupom_id', 'cliente_id'
    ];

}
