<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PercentualImpostoEstado extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id', 'uf', 'percentual_icms', 'percentual_pis', 'percentual_cofins', 'percentual_ipi',
        'percentual_fcp', 'percentual_icms_interno', 'percentual_red_bc'
    ];

    public function product(){
        return $this->belongsTo(Product::class, 'product_id');
    }

    public static function estados(){
        return [
            "" => "",
            "AC" => "AC",
            "AL" => "AL",
            "AM" => "AM",
            "AP" => "AP",
            "BA" => "BA",
            "CE" => "CE",
            "DF" => "DF",
            "ES" => "ES",
            "GO" => "GO",
            "MA" => "MA",
            "MG" => "MG",
            "MS" => "MS",
            "MT" => "MT",
            "PA" => "PA",
            "PB" => "PB",
            "PE" => "PE",
            "PI" => "PI",
            "PR" => "PR",
            "RJ" => "RJ",
            "RN" => "RN",
            "RS" => "RS",
            "RO" => "RO",
            "RR" => "RR",
            "SC" => "SC",
            "SE" => "SE",
            "SP" => "SP",
            "TO" => "TO",
        ];
    }
}
