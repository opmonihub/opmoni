<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionPayment extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * Get the phone record associated with the user.
     */
    public function payment_account()
    {
        return $this->belongsTo(\App\Models\Account::class, 'account_id');
    }

    /**
     * Get the transaction related to this payment.
     */
    public function transaction()
    {
        return $this->belongsTo(\App\Models\Transaction::class, 'transaction_id');
    }

    /**
     * Get the user.
     */
    public function created_user()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /**
     * Retrieves documents path if exists
     */
    public function getDocumentPathAttribute()
    {
        $path = !empty($this->document) ? asset('/uploads/documents/' . $this->document) : null;
        
        return $path;
    }

    /**
     * Removes timestamp from document name
     */
    public function getDocumentNameAttribute()
    {
        $document_name = !empty(explode("_", $this->document, 2)[1]) ? explode("_", $this->document, 2)[1] : $this->document ;
        return $document_name;
    }

    public function getPaymentString(){
        if($this->method == 'cash'){
            $name = 'Dinheiro';
        }else if($this->method == 'card'){
            if($this->card_type == 'debit'){
                $name = 'Cartão de débito';
            }else{
                $name = 'Cartão de crédtio';
            }
        }else if($this->method == 'debit'){
            $name = 'Cartão de débito';
        }else if($this->method == 'cheque'){
            $name = 'Cheque';
        }else if($this->method == 'boleto'){
            $name = 'Boleto';
        }else if($this->method == 'pix'){
            $name = 'Pix';
        }else if($this->method == 'vale_alimentacao'){
            $name = 'Vale alimentação';
        }else if($this->method == 'vale_refeicao'){
            $name = 'Vale refeição';
        }else{
            $name = 'Outros';
        }
        return $name;
    }
}
