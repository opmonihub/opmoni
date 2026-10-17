<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfigEcommerce extends Model
{
    protected $fillable = [
        'nome', 'rua', 'numero', 'bairro', 'cidade', 'cep', 'telefone',
        'email', 'link_facebook', 'link_twiter', 'link_instagram', 'frete_gratis_valor', 
        'mercadopago_public_key', 'mercadopago_access_token', 'funcionamento', 'latitude',
        'longitude', 'politica_privacidade', 'business_id', 'src_mapa', 'google_api', 'token',
        'cor_fundo', 'cor_btn', 'logo', 'img_contato', 'mensagem_agradecimento', 'uf', 
        'fav_icon', 'timer_carrossel'
    ];

    protected $appends = ['logo_url', 'contato_url', 'fav_url'];

    public function getLogoUrlAttribute()
    {
        if (!empty($this->logo)) {
            $image_url = asset('/uploads/ecommerce_logos/' . rawurlencode($this->logo));
        } else {
            $image_url = '';
        }
        return $image_url;
    }


    public function getContatoUrlAttribute()
    {
        if (!empty($this->img_contato)) {
            $image_url = asset('/uploads/ecommerce_contatos/' . rawurlencode($this->img_contato));
        } else {
            $image_url = '';
        }
        return $image_url;
    }

    public function getFavUrlAttribute()
    {
        if (!empty($this->fav_icon)) {
            $image_url = asset('/uploads/ecommerce_fav/' . rawurlencode($this->fav_icon));
        } else {
            $image_url = '';
        }
        return $image_url;
    }

}
