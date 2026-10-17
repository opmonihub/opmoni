<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Difal extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'uf', 'pICMSUFDest', 'pICMSInter', 'pICMSInterPart', 'cfop', 'pFCPUFDest'
    ];

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
