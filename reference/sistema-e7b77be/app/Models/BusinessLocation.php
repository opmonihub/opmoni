<?php

namespace App\Models;

use DB;
use Illuminate\Database\Eloquent\Model;
use App\Models\SellingPriceGroup;
use App\Models\Variation;

class BusinessLocation extends Model
{

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'featured_products' => 'array'
    ];

    public static function getcUF($uf){
        $estados = [
            'RO' => '11',
            'AC' => '12',
            'AM' => '13',
            'RR' => '14',
            'PA' => '15',
            'AP' => '16',
            'TO' => '17',
            'MA' => '21',
            'PI' => '22',
            'CE' => '23',
            'RN' => '24',
            'PB' => '25',
            'PE' => '26',
            'AL' => '27',
            'SE' => '28',
            'BA' => '29',
            'MG' => '31',
            'ES' => '32',
            'RJ' => '33',
            'SP' => '35',
            'PR' => '41',
            'SC' => '42',
            'RS' => '43',
            'MS' => '50',
            'MT' => '51',
            'GO' => '52',
            'DF' => '53'
        ];
        return $estados[$uf];
    }

    public function cidade()
    {
        return $this->belongsTo(\App\Models\City::class);
    }

    public function business()
    {
        return $this->belongsTo(\App\Models\Business::class);
    }

    /**
     * Return list of locations for a business
     *
     * @param int $business_id
     * @param boolean $show_all = false
     * @param array $receipt_printer_type_attribute =
     *
     * @return array
     */
    public static function forDropdown($business_id, $show_all = false, $receipt_printer_type_attribute = false, $append_id = true)
    {
        $query = BusinessLocation::where('business_id', $business_id)->Active();

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('id', $permitted_locations);
        }

        if ($append_id) {
            $query->select(
                DB::raw("IF(location_id IS NULL OR location_id='', name, CONCAT(name, ' (', location_id, ')')) AS name"),
                'id',
                'receipt_printer_type',
                'selling_price_group_id',
                'default_payment_accounts'
            );
        }

        $result = $query->get();

        $locations = $result->pluck('name', 'id');

        $price_groups = SellingPriceGroup::forDropdown($business_id);

        if ($show_all) {
            $locations->prepend(__('report.all_locations'), '');
        }

        if ($receipt_printer_type_attribute) {
            $attributes = collect($result)->mapWithKeys(function ($item) use ($price_groups) {
                return [$item->id => [
                            'data-receipt_printer_type' => $item->receipt_printer_type,
                            'data-default_price_group' => !empty($item->selling_price_group_id) && array_key_exists($item->selling_price_group_id, $price_groups) ? $item->selling_price_group_id : null,
                            'data-default_payment_accounts' => $item->default_payment_accounts
                        ]
                    ];
            })->all();

            return ['locations' => $locations, 'attributes' => $attributes];
        } else {
            return $locations;
        }
    }

    public function price_group()
    {
        return $this->belongsTo(\App\Models\SellingPriceGroup::class, 'selling_price_group_id');
    }

    /**
     * Scope a query to only include active location.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    /**
     * Get the featured products.
     *
     * @return array/object
     */
    public function getFeaturedProducts($is_array = false, $check_location = true)
    {

        if (empty($this->featured_products)) {
            return [];
        }
        $query = Variation::whereIn('variations.id', $this->featured_products)
                                    ->join('product_locations as pl', 'pl.product_id', '=', 'variations.product_id')
                                    ->join('products as p', 'p.id', '=', 'variations.product_id')
                                    ->where('p.not_for_selling', 0)
                                    ->with(['product_variation', 'product', 'media'])
                                    ->select('variations.*');

        if ($check_location) {
            $query->where('pl.location_id', $this->id);
        }
        $featured_products = $query->get();
        if ($is_array) {
            $array = [];
            foreach ($featured_products as $featured_product) {
                $array[$featured_product->id] = $featured_product->full_name;
            }
            return $array;
        }
        return $featured_products;
    }
}
