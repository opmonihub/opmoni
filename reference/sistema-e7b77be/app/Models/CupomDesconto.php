<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CupomDesconto extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'codigo', 'valor', 'status', 'tipo'
    ];
}
