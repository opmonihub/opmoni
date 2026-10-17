<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashRegister extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * Get the Cash registers transactions.
     */
    public function cash_register_transactions()
    {
        return $this->hasMany(\App\Models\CashRegisterTransaction::class);
    }

    public function total_sangrias_suprimentos($type){
        $item = SangriaSuprimento::where('cash_id', $this->id)
        ->where('type', $type)
        ->sum('value');
        if($item){
            return $item;
        }else{
            return 0;
        }
    }
}
