<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inutil extends Model
{
    protected $fillable = [
        'business_id',
        'nNFIni',
        'nNFFin',
        'serie',
        'status',
        'tpAmb',
        'modelo',
        'xJust'
    ];
}
