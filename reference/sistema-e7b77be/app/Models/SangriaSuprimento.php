<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SangriaSuprimento extends Model
{
    use HasFactory;

    protected $fillable = [ 'cash_id', 'type', 'value', 'note' ];
}
