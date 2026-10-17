<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentPlan extends Model
{
    use HasFactory;
    protected $fillable = [
        'payerFirstName', 'payerLastName', 'payerEmail', 'docNumber', 'plan_tenant_id', 'transacao_id',
        'status', 'valor', 'forma_pagamento', 'qr_code', 'qr_code_base64', 'link_boleto', 'numero_cartao',
        'package_id', 'business_id'
    ];

    public function planTenant(){
        return $this->belongsTo(PlanTenant::class, 'plan_tenant_id');
    }

    public function planRole(){
        return $this->belongsTo(PlanRole::class, 'plan_role_id');
    }

    public static function PaymentStatus(){
        return [
            'pending' => 'Pendente',
            'approved' => 'Aprovado',
            'rejected' => 'Rejeitado',
            'cancelled' => 'Cancelado'
        ];
    }

    public static function PaymentStatusFilter(){
        return [
            '' => 'Todos',
            'pending' => 'Pendente',
            'approved' => 'Aprovado',
            'rejected' => 'Rejeitado',
            'cancelled' => 'Cancelado'
        ];
    }

    public function getStatusBr(){
        if($this->status == 'pending'){
            return 'Pendente';
        }else if($this->status == 'approved'){
            return 'Aprovado';
        }else if($this->status == 'rejected'){
            return 'Rejeitado';
        }else{
            return 'Cancelado';
        }

    }
}
