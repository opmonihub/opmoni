<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProdutoImagem extends Model
{
	protected $fillable = [
		'produto_id', 'img'
	];

	protected $appends = ['image_url'];

    public function getImageUrlAttribute()
	{
        if (!empty($this->img)) {
            $image_url = asset('/uploads/img/' . rawurlencode($this->img));
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
            $image_path = public_path('uploads') . '/' . config('constants.product_img_path') . '/' . 
            $this->img;
        } else {
            $image_path = null;
        }
        return $image_path;
    }
}
