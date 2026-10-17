<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\City;
class CidadeController extends Controller
{
    public function lista(){

        
    	$cidades = City::all();

    	foreach($cidades as $c){
    		echo "<strong>$c->id</strong> - $c->nome ($c->uf)<br>";
    	}
    }
}
