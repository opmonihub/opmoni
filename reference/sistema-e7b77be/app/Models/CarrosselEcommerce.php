<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarrosselEcommerce extends Model
{
    protected $fillable = [
		'titulo', 'descricao', 'link_acao', 'nome_botao', 'img', 'business_id', 'cor_fundo'
	];

	protected $appends = ['image_url'];

    public function getImageUrlAttribute()
    {
        if (!empty($this->img)) {
            $image_url = asset('/uploads/img/carrossel/' . rawurlencode($this->img));
        } else {
            $image_url = asset('/img/default.png');
        }
        return $image_url;
    }

    /**
    * Get the products image path.
    *
    * @return string
    */
    public function getImagePathAttribute()
    {
        if (!empty($this->img)) {
            $image_path = public_path('uploads') . '/img/carrossel/'  . $this->img;
        } else {
            $image_path = null;
        }
        return $image_path;
    }
}
