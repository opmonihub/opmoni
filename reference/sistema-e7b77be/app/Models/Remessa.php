<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Remessa extends Model
{
    use HasFactory;

    protected $fillable = [
        'nome_arquivo', 'business_id'
    ];

    public function boletos(){
        return $this->hasMany('App\Models\RemessaBoleto', 'remessa_id', 'id');
    }
}
