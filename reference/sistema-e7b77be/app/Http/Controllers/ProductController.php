<?php

namespace App\Http\Controllers;

use App\Models\Brands;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Category;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\PurchaseLine;
use App\Models\SellingPriceGroup;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Utils\ModuleUtil;
use App\Utils\ProductUtil;
use App\Models\Variation;
use App\Models\VariationGroupPrice;
use App\Models\VariationLocationDetails;
use App\Models\VariationTemplate;
use App\Models\Warranty;
use App\Models\ProdutoImagem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Yajra\DataTables\Facades\DataTables;

class ProductController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $productUtil;
    protected $moduleUtil;

    private $barcode_types;

    /**
     * Constructor
     *
     * @param ProductUtils $product
     * @return void
     */
    public function __construct(ProductUtil $productUtil, ModuleUtil $moduleUtil)
    {
        $this->productUtil = $productUtil;
        $this->moduleUtil = $moduleUtil;

        //barcode types
        $this->barcode_types = $this->productUtil->barcode_types();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {

        if (!auth()->user()->can('product.view') && !auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $selling_price_group_count = SellingPriceGroup::countSellingPriceGroups($business_id);

        if (request()->ajax()) {
            $query = Product::leftJoin('brands', 'products.brand_id', '=', 'brands.id')
            ->join('units', 'products.unit_id', '=', 'units.id')
            ->leftJoin('categories as c1', 'products.category_id', '=', 'c1.id')
            ->leftJoin('categories as c2', 'products.sub_category_id', '=', 'c2.id')
            ->leftJoin('tax_rates', 'products.tax', '=', 'tax_rates.id')
            ->join('variations as v', 'v.product_id', '=', 'products.id')
            ->leftJoin('variation_location_details as vld', 'vld.variation_id', '=', 'v.id')
            ->where('products.business_id', $business_id)
            ->where('products.type', '!=', 'modifier');

            //Filter by location
            $location_id = request()->get('location_id', null);
            $permitted_locations = auth()->user()->permitted_locations();

            if (!empty($location_id) && $location_id != 'none') {
                if ($permitted_locations == 'all' || in_array($location_id, $permitted_locations)) {
                    $query->whereHas('product_locations', function ($query) use ($location_id) {
                        $query->where('product_locations.location_id', '=', $location_id);
                    });
                }
            } elseif ($location_id == 'none') {
                $query->doesntHave('product_locations');
            } else {
                if ($permitted_locations != 'all') {
                    $query->whereHas('product_locations', function ($query) use ($permitted_locations) {
                        $query->whereIn('product_locations.location_id', $permitted_locations);
                    });
                } else {
                    $query->with('product_locations');
                }
            }

            $products = $query->select(
                'products.id',
                'products.name as product',
                'products.type',
                'c1.name as category',
                'c2.name as sub_category',
                'units.actual_name as unit',
                'brands.name as brand',
                'tax_rates.name as tax',
                'products.sku',
                'products.ncm',
                'products.cfop_interno',
                'products.cfop_externo',
                'products.cest',
                'products.image',
                'products.ecommerce',
                'products.enable_stock',
                'products.is_inactive',
                'products.not_for_selling',
                'products.product_custom_field3',
                'products.product_custom_field4',
                DB::raw('SUM(vld.qty_available) as current_stock'),
                DB::raw('MAX(v.sell_price_inc_tax) as max_price'),
                DB::raw('MIN(v.sell_price_inc_tax) as min_price'),
                DB::raw('MAX(v.dpp_inc_tax) as max_purchase_price'),
                DB::raw('MIN(v.dpp_inc_tax) as min_purchase_price')

            )->groupBy('products.id');

            $type = request()->get('type', null);
            if (!empty($type)) {
                $products->where('products.type', $type);
            }

            $category_id = request()->get('category_id', null);
            if (!empty($category_id)) {
                $products->where('products.category_id', $category_id);
            }

            $brand_id = request()->get('brand_id', null);
            if (!empty($brand_id)) {
                $products->where('products.brand_id', $brand_id);
            }

            $unit_id = request()->get('unit_id', null);
            if (!empty($unit_id)) {
                $products->where('products.unit_id', $unit_id);
            }

            $tax_id = request()->get('tax_id', null);
            if (!empty($tax_id)) {
                $products->where('products.tax', $tax_id);
            }

            $active_state = request()->get('active_state', null);
            if ($active_state == 'active') {
                $products->Active();
            }
            if ($active_state == 'inactive') {
                $products->Inactive();
            }
            $not_for_selling = request()->get('not_for_selling', null);
            if ($not_for_selling == 'true') {
                $products->ProductNotForSales();
            }

            $ecommerce = request()->get('ecommerce', null);
            if ($ecommerce == 'true') {
                $products->where('products.ecommerce', 1);
            }

            if (!empty(request()->get('repair_model_id'))) {
                $products->where('products.repair_model_id', request()->get('repair_model_id'));
            }

            return Datatables::of($products)
            ->addColumn(
                'product_locations',
                function ($row) {
                    return $row->product_locations->implode('name', ', ');
                }
            )
            ->editColumn('category', '{{$category}} @if(!empty($sub_category))<br/> -- {{$sub_category}}@endif')
            ->addColumn(
                'action',
                function ($row) use ($selling_price_group_count) {
                    $html =
                    '<div class="btn-group"><button type="button" class="btn btn-info dropdown-toggle btn-xs" data-toggle="dropdown" aria-expanded="false">'. __("messages.actions") . '<span class="caret"></span><span class="sr-only">Toggle Dropdown</span></button><ul class="dropdown-menu dropdown-menu-left" role="menu"><li><a href="' . action('LabelsController@show') . '?product_id=' . $row->id . '" data-toggle="tooltip" title="Print Barcode/Label"><i class="fa fa-barcode"></i> ' . __('barcode.labels') . '</a></li>';

                    if ($row->ecommerce == 1) {
                        $html .=
                        '<li><a href="' . action('ProductController@galery', [$row->id]) . '"><i class="fa fa-file-image"></i> ' . "Galeria ecommerce" . '</a></li>';
                    }

                    if (auth()->user()->can('product.view')) {
                        $html .=
                        '<li><a href="' . action('ProductController@view', [$row->id]) . '" class="view-product"><i class="fa fa-eye"></i> ' . __("messages.view") . '</a></li>';
                    }

                    if (auth()->user()->can('product.update')) {
                        $html .=
                        '<li><a href="' . action('ProductController@edit', [$row->id]) . '"><i class="glyphicon glyphicon-edit"></i> ' . __("messages.edit") . '</a></li>';
                    }

                    if (auth()->user()->can('product.delete')) {
                        $html .=
                        '<li><a href="' . action('ProductController@destroy', [$row->id]) . '" class="delete-product"><i class="fa fa-trash"></i> ' . __("messages.delete") . '</a></li>';
                    }

                    if ($row->is_inactive == 1) {
                        $html .=
                        '<li><a href="' . action('ProductController@activate', [$row->id]) . '" class="activate-product"><i class="fas fa-check-circle"></i> ' . __("lang_v1.reactivate") . '</a></li>';
                    }

                    $html .= '<li class="divider"></li>';

                    if ($row->enable_stock == 1 && auth()->user()->can('product.opening_stock')) {
                        $html .=
                        '<li><a href="#" data-href="' . action('OpeningStockController@add', ['product_id' => $row->id]) . '" class="add-opening-stock"><i class="fa fa-database"></i> ' . __("lang_v1.add_edit_opening_stock") . '</a></li>';
                    }

                    if (auth()->user()->can('product.view')) {
                        $html .=
                        '<li><a href="' . action('ProductController@productStockHistory', [$row->id]) . '"><i class="fas fa-history"></i> ' . __("lang_v1.product_stock_history") . '</a></li>';
                    }

                    if (auth()->user()->can('product.create')) {

                        if ($selling_price_group_count > 0) {
                            $html .=
                            '<li><a href="' . action('ProductController@addSellingPrices', [$row->id]) . '"><i class="fas fa-money-bill-alt"></i> ' . __("lang_v1.add_selling_price_group_prices") . '</a></li>';
                        }

                        $html .=
                        '<li><a href="' . action('ProductController@create', ["d" => $row->id]) . '"><i class="fa fa-copy"></i> ' . 'Duplicar produto' . '</a></li>';
                    }

                    $html .= '</ul></div>';

                    return $html;
                }
            )
            ->editColumn('product', function ($row) {
                $product = $row->is_inactive == 1 ? $row->product . ' <span class="label bg-gray">' . __("lang_v1.inactive") .'</span>' : $row->product;

                $product = $row->not_for_selling == 1 ? $product . ' <span class="label bg-gray">' . __("lang_v1.not_for_selling") .
                '</span>' : $product;

                return $product;
            })
            ->editColumn('image', function ($row) {
                return '<div style="display: flex;"><img src="' . $row->image_url . '" alt="Product image" class="product-thumbnail-small"></div>';
            })
            ->editColumn('type', '@lang("lang_v1." . $type)')
            ->addColumn('mass_delete', function ($row) {
                return  '<input type="checkbox" class="row-select" value="' . $row->id .'">' ;
            })
            ->editColumn('current_stock', '
                @if($enable_stock == 1) 
                @if($unit == "Unidade" || $unit == "Unid" || $unit == "UN")
                {{@number_format($current_stock)}} 
                @else
                {{@number_format($current_stock, 3)}} 
                @endif
                @else -- @endif {{$unit}}')
            ->addColumn(
                'purchase_price',
                '<div style="white-space: nowrap;"><span class="display_currency" data-currency_symbol="true">{{$min_purchase_price}}</span> @if($max_purchase_price != $min_purchase_price && $type == "variable") -  <span class="display_currency" data-currency_symbol="true">{{$max_purchase_price}}</span>@endif </div>'
            )
            ->addColumn(
                'selling_price',
                '<div style="white-space: nowrap;"><span class="display_currency" data-currency_symbol="true">{{$min_price}}</span> @if($max_price != $min_price && $type == "variable") -  <span class="display_currency" data-currency_symbol="true">{{$max_price}}</span>@endif </div>'
            )
            ->addColumn('cfop', function ($row) {
                return $row->cfop_interno. '/' .$row->cfop_externo;
            })
            ->setRowAttr([
                'data-href' => function ($row) {
                    if (auth()->user()->can("product.view")) {
                        return  action('ProductController@view', [$row->id]) ;
                    } else {
                        return '';
                    }
                }])
            ->rawColumns(['action', 'image', 'mass_delete', 'product', 'selling_price', 'purchase_price', 'category'])
            ->make(true);
        }

        $rack_enabled = (request()->session()->get('business.enable_racks') || request()->session()->get('business.enable_row') || request()->session()->get('business.enable_position'));

        $categories = Category::forDropdown($business_id, 'product');

        $brands = Brands::forDropdown($business_id);

        $units = Unit::forDropdown($business_id);

        $tax_dropdown = TaxRate::forBusinessDropdown($business_id, false);
        $taxes = $tax_dropdown['tax_rates'];

        $business_locations = BusinessLocation::forDropdown($business_id);
        $business_locations->prepend(__('lang_v1.none'), 'none');

        if ($this->moduleUtil->isModuleInstalled('Manufacturing') && (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'manufacturing_module'))) {
            $show_manufacturing_data = true;
        } else {
            $show_manufacturing_data = false;
        }

        //list product screen filter from module
        $pos_module_data = $this->moduleUtil->getModuleData('get_filters_for_list_product_screen');

        return view('product.index')
        ->with(compact(
            'rack_enabled',
            'categories',
            'brands',
            'units',
            'taxes',
            'business_locations',
            'show_manufacturing_data',
            'pos_module_data'
        ));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        //Check if subscribed or not, then check for products quota
        if (!$this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse();
        } elseif (!$this->moduleUtil->isQuotaAvailable('products', $business_id)) {
            return $this->moduleUtil->quotaExpiredResponse('products', $business_id, action('ProductController@index'));
        }

        $categories = Category::forDropdown($business_id, 'product');

        $brands = Brands::where('business_id', $business_id)
        ->pluck('name', 'id');
        $units = Unit::forDropdown($business_id, true);

        $tax_dropdown = TaxRate::forBusinessDropdown($business_id, true, true);
        $taxes = $tax_dropdown['tax_rates'];
        $tax_attributes = $tax_dropdown['attributes'];

        $barcode_types = $this->barcode_types;
        $barcode_default =  $this->productUtil->barcode_default();

        $default_profit_percent = request()->session()->get('business.default_profit_percent');;

        //Get all business locations
        $business_locations = BusinessLocation::forDropdown($business_id);

        //Duplicate product
        $duplicate_product = null;
        $rack_details = null;

        $sub_categories = [];
        if (!empty(request()->input('d'))) {

            $duplicate_product = Product::where('business_id', $business_id)->find(request()->input('d'));
            $duplicate_product->name .= ' (copia)';

            if (!empty($duplicate_product->category_id)) {
                $sub_categories = Category::where('business_id', $business_id)
                ->where('parent_id', $duplicate_product->category_id)
                ->pluck('name', 'id')
                ->toArray();
            }

            //Rack details
            if (!empty($duplicate_product->id)) {
                $rack_details = $this->productUtil->getRackDetails($business_id, $duplicate_product->id);
            }
        }

        $selling_price_group_count = SellingPriceGroup::countSellingPriceGroups($business_id);

        $module_form_parts = $this->moduleUtil->getModuleData('product_form_part');
        $product_types = $this->product_types();

        $common_settings = session()->get('business.common_settings');
        $warranties = Warranty::forDropdown($business_id);

        //product screen view from module
        $pos_module_data = $this->moduleUtil->getModuleData('get_product_screen_top_view');

        $listaCSTCSOSN = Product::listaCSTCSOSN();
        $listaCST_PIS_COFINS = Product::listaCST_PIS_COFINS();
        $listaCST_IPI = Product::listaCST_IPI();
        $unidadesDeMedida = Product::unidadesMedida();
        $business = Business::find($business_id);

        $listaCenqIPI = Product::listaCenqIPI();

        return view('product.create')
        ->with('listaCSTCSOSN', $listaCSTCSOSN)
        ->with('listaCST_PIS_COFINS', $listaCST_PIS_COFINS)
        ->with('listaCST_IPI', $listaCST_IPI)
        ->with('unidadesDeMedida', $unidadesDeMedida)
        ->with('business', $business)

        ->with(compact('categories', 'brands', 'units', 'taxes', 'barcode_types', 'default_profit_percent', 'tax_attributes', 'barcode_default', 'business_locations', 'duplicate_product', 'sub_categories', 'rack_details', 'selling_price_group_count', 'module_form_parts', 'product_types', 'common_settings', 'warranties', 'pos_module_data', 'listaCenqIPI'));
    }

    private function product_types()
    {
        //Product types also includes modifier.
        return ['single' => __('lang_v1.single'),
        'variable' => __('lang_v1.variable'),
        'combo' => __('lang_v1.combo')
    ];
}

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        try {

            $request->merge([ 'codigo_barras' => $request->codigo_barras ?? '']);
            $request->merge([ 'codigo_anp' => $request->codigo_anp ?? '']);
            $request->merge([ 'perc_glp' => $request->perc_glp ?? 0]);
            $request->merge([ 'perc_gnn' => $request->perc_gnn ?? 0]);
            $request->merge([ 'perc_gni' => $request->perc_gni ?? 0]);
            $request->merge([ 'valor_partida' => $request->valor_partida ?? 0]);
            $request->merge([ 'unidade_tributavel' => $request->unidade_tributavel ?? '']);
            $request->merge([ 'quantidade_tributavel' => $request->quantidade_tributavel ?? 0]);

            $request->merge([ 'veicProd' => $request->veicProd ?? '']);
            $request->merge([ 'tpOp' => $request->tpOp ?? '']);
            $request->merge([ 'chassi' => $request->chassi ?? '']);
            $request->merge([ 'cCor' => $request->cCor ?? '']);
            $request->merge([ 'xCor' => $request->xCor ?? '']);
            $request->merge([ 'pot' => $request->pot ?? 0]);
            $request->merge([ 'cilin' => $request->cilin ?? 0]);
            $request->merge([ 'pesoL' => $request->pesoL ?? '']);
            $request->merge([ 'pesoB' => $request->pesoB ?? '']);
            $request->merge([ 'nSerie' => $request->nSerie ?? '']);
            $request->merge([ 'tpComb' => $request->tpComb ?? '']);
            $request->merge([ 'nMotor' => $request->nMotor ?? '']);
            $request->merge([ 'CMT' => $request->CMT ?? '']);
            $request->merge([ 'dist' => $request->dist ?? '']);
            $request->merge([ 'anoMod' => $request->anoMod ?? '']);
            $request->merge([ 'anoFab' => $request->anoFab ?? '']);
            $request->merge([ 'tpPint' => $request->tpPint ?? '']);
            $request->merge([ 'tpVeic' => $request->tpVeic ?? '']);
            $request->merge([ 'espVeic' => $request->espVeic ?? '']);
            $request->merge([ 'VIN' => $request->VIN ?? '']);
            $request->merge([ 'condVeic' => $request->condVeic ?? '']);
            $request->merge([ 'cMod' => $request->cMod ?? '']);
            $request->merge([ 'cCorDENATRAN' => $request->cCorDENATRAN ?? '']);
            $request->merge([ 'lota' => $request->lota ?? '']);
            $request->merge([ 'tpRest' => $request->tpRest ?? '']);
            $request->merge([ 'ref_balanca' => $request->ref_balanca ?? '']);

            $request->merge([ 'perc_icms_interestadual' => $request->perc_icms_interestadual ?? 0]);
            $request->merge([ 'perc_icms_interno' => $request->perc_icms_interno ?? 0]);
            $request->merge([ 'pCredSN' => $request->pCredSN ?? 0]);
            $request->merge([ 'perc_fcp_interestadual' => $request->perc_fcp_interestadual ?? 0]);
            $request->merge([ 'pICMSST' => $request->pICMSST ?? 0]);

            $request->merge([ 'adRemICMSRet' => $request->adRemICMSRet ? str_replace(",", ".", $request->adRemICMSRet) : 0]);
            $request->merge([ 'pBio' => $request->pBio ? str_replace(",", ".", $request->pBio) : 0]);
            $request->merge([ 'pOrig' => $request->pOrig ? str_replace(",", ".", $request->pOrig) : 0]);
            
            $business_id = $request->session()->get('user.business_id');
            $request->merge([ 'sell_price_inc_tax' => $request->single_dsp]);

            $form_fields = ['name', 'brand_id', 'unit_id', 'category_id', 'tax', 'type', 'barcode_type', 'sku', 'alert_quantity', 'tax_type', 'weight', 'product_custom_field1', 'product_custom_field2', 'product_custom_field3', 'product_custom_field4', 'product_description', 'sub_unit_ids', 'perc_icms', 'perc_cofins', 'perc_pis', 'perc_ipi', 'cfop_interno', 'cfop_externo', 'cst_csosn', 'cst_pis', 'cst_cofins', 'cst_ipi', 'ncm', 'cest', 'codigo_barras', 'codigo_anp', 'perc_glp', 'perc_gnn', 'perc_gni', 'valor_partida', 'unidade_tributavel', 'quantidade_tributavel', 'tipo', 'veicProd', 'tpOp', 'chassi', 'cCor', 'xCor', 'pot', 'cilin', 'pesoL', 'pesoB', 'nSerie', 'tpComb', 'nMotor', 'CMT', 'dist', 'anoMod', 'anoFab', 'tpPint', 'tpVeic', 'espVeic', 'VIN', 'condVeic', 'cMod', 'cCorDENATRAN', 'lota', 'tpRest', 'ecommerce', 'destaque', 'novo', 'altura', 'largura', 'comprimento', 'valor_ecommerce', 'origem', 'cenq_ipi', 
            'perc_icms_interestadual', 'perc_icms_interno', 'perc_fcp_interestadual', 'pICMSST', 'modBC', 'modBCST', 'pCredSN', 'ref_balanca', 'adRemICMSRet', 'pBio', 'pOrig', 'indImport', 'cUFOrig'];

            $module_form_fields = $this->moduleUtil->getModuleFormField('product_form_fields');
            if (!empty($module_form_fields)) {
                $form_fields = array_merge($form_fields, $module_form_fields);
            }

            $product_details = $request->only($form_fields);
            $product_details['business_id'] = $business_id;
            $product_details['created_by'] = $request->session()->get('user.id');
            $product_details['valor_ecommerce'] = isset($product_details['valor_ecommerce']) ? str_replace(",", ".", $product_details['valor_ecommerce']) : 0;

            $product_details['weight'] = isset($product_details['weight']) ? str_replace(",", ".", 
                $product_details['weight']) : 0;

            $product_details['altura'] = isset($product_details['altura']) ? str_replace(",", ".", 
                $product_details['altura']) : 0;

            $product_details['largura'] = isset($product_details['largura']) ? str_replace(",", ".", 
                $product_details['largura']) : 0;

            $product_details['comprimento'] = isset($product_details['comprimento']) ? str_replace(",", ".", 
                $product_details['comprimento']) : 0;

            $product_details['enable_stock'] = (!empty($request->input('enable_stock')) &&  $request->input('enable_stock') == 1) ? 1 : 0;
            $product_details['not_for_selling'] = (!empty($request->input('not_for_selling')) &&  $request->input('not_for_selling') == 1) ? 1 : 0;

            if (!empty($request->input('sub_category_id'))) {
                $product_details['sub_category_id'] = $request->input('sub_category_id') ;
            }

            if (empty($product_details['sku'])) {
                $product_details['sku'] = ' ';
            }

            $expiry_enabled = $request->session()->get('business.enable_product_expiry');
            if (!empty($request->input('expiry_period_type')) && !empty($request->input('expiry_period')) && !empty($expiry_enabled) && ($product_details['enable_stock'] == 1)) {
                $product_details['expiry_period_type'] = $request->input('expiry_period_type');
                $product_details['expiry_period'] = $this->productUtil->num_uf($request->input('expiry_period'));
            }

            if (!empty($request->input('enable_sr_no')) &&  $request->input('enable_sr_no') == 1) {
                $product_details['enable_sr_no'] = 1;
            }

            //upload document
            $product_details['image'] = $this->productUtil->uploadFile($request, 'image', config('constants.product_img_path'), 'image');
            $common_settings = session()->get('business.common_settings');

            $product_details['warranty_id'] = !empty($request->input('warranty_id')) ? $request->input('warranty_id') : null;

            DB::beginTransaction();

            $product = Product::create($product_details);

            if (empty(trim($request->input('sku')))) {
                $sku = $this->productUtil->generateProductSku($product->id);
                $product->sku = $sku;
                $product->save();
            }

            //Add product locations
            $product_locations = $request->input('product_locations');
            if (!empty($product_locations)) {
                $product->product_locations()->sync($product_locations);
            }

            if ($product->type == 'single') {
                // echo "single";
                $this->productUtil->createSingleProductVariation($product->id, $product->sku, $request->input('single_dpp'), $request->input('single_dpp_inc_tax'), $request->input('profit_percent'), $request->input('single_dsp'), $request->input('single_dsp_inc_tax'));
            } elseif ($product->type == 'variable') {
                if (!empty($request->input('product_variation'))) {
                    $input_variations = $request->input('product_variation');
                    $this->productUtil->createVariableProductVariations($product->id, $input_variations);
                }
            } elseif ($product->type == 'combo') {

                //Create combo_variations array by combining variation_id and quantity.
                $combo_variations = [];
                if (!empty($request->input('composition_variation_id'))) {
                    $composition_variation_id = $request->input('composition_variation_id');
                    $quantity = $request->input('quantity');
                    $unit = $request->input('unit');

                    foreach ($composition_variation_id as $key => $value) {
                        $combo_variations[] = [
                            'variation_id' => $value,
                            'quantity' => $this->productUtil->num_uf($quantity[$key]),
                            'unit_id' => $unit[$key]
                        ];
                    }
                }

                $this->productUtil->createSingleProductVariation($product->id, $product->sku, $request->input('item_level_purchase_price_total'), $request->input('purchase_price_inc_tax'), $request->input('profit_percent'), $request->input('selling_price'), $request->input('selling_price_inc_tax'), $combo_variations);
            }


            //Add product racks details.
            $product_racks = $request->get('product_racks', null);
            if (!empty($product_racks)) {
                $this->productUtil->addRackDetails($business_id, $product->id, $product_racks);
            }

            //Set Module fields
            if (!empty($request->input('has_module_data'))) {
                $this->moduleUtil->getModuleData('after_product_saved', ['product' => $product, 'request' => $request]);
            }

            DB::commit();
            $output = [
                'success' => 1,
                'msg' => __('product.product_added_success')
            ];
        } catch (\Exception $e) {
        // print_r($e->getMessage());
        // die();
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __("messages.something_went_wrong")
            ];
            return redirect('products')->with('status', $output);
        }

        if ($request->input('submit_type') == 'submit_n_add_opening_stock') {
            return redirect()->action(
                'OpeningStockController@add',
                ['product_id' => $product->id]
            );
        } elseif ($request->input('submit_type') == 'submit_n_add_selling_prices') {
            return redirect()->action(
                'ProductController@addSellingPrices',
                [$product->id]
            );
        } elseif ($request->input('submit_type') == 'save_n_add_another') {
            return redirect()->action(
                'ProductController@create'
            )->with('status', $output);
        }

        return redirect('products')->with('status', $output);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        if (!auth()->user()->can('product.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $details = $this->productUtil->getRackDetails($business_id, $id, true);

        return view('product.show')->with(compact('details'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $categories = Category::forDropdown($business_id, 'product');
        $brands = Brands::where('business_id', $business_id)
        ->pluck('name', 'id');

        $tax_dropdown = TaxRate::forBusinessDropdown($business_id, true, true);
        $taxes = $tax_dropdown['tax_rates'];
        $tax_attributes = $tax_dropdown['attributes'];

        $barcode_types = $this->barcode_types;

        $product = Product::where('business_id', $business_id)
        ->with(['product_locations'])
        ->where('id', $id)
        ->firstOrFail();

        //Sub-category
        $sub_categories = [];
        $sub_categories = Category::where('business_id', $business_id)
        ->where('parent_id', $product->category_id)
        ->pluck('name', 'id')
        ->toArray();
        $sub_categories = [ "" => "None"] + $sub_categories;

        $default_profit_percent = request()->session()->get('business.default_profit_percent');

        //Get units.
        $units = Unit::forDropdown($business_id, true);
        $sub_units = $this->productUtil->getSubUnits($business_id, $product->unit_id, true);

        //Get all business locations
        $business_locations = BusinessLocation::forDropdown($business_id);
        //Rack details
        $rack_details = $this->productUtil->getRackDetails($business_id, $id);

        $selling_price_group_count = SellingPriceGroup::countSellingPriceGroups($business_id);

        $module_form_parts = $this->moduleUtil->getModuleData('product_form_part');
        $product_types = $this->product_types();
        $common_settings = session()->get('business.common_settings');
        $warranties = Warranty::forDropdown($business_id);

        //product screen view from module
        $pos_module_data = $this->moduleUtil->getModuleData('get_product_screen_top_view');

        $listaCSTCSOSN = Product::listaCSTCSOSN();
        $listaCST_PIS_COFINS = Product::listaCST_PIS_COFINS();
        $listaCST_IPI = Product::listaCST_IPI();
        $unidadesDeMedida = Product::unidadesMedida();

        $listaCenqIPI = Product::listaCenqIPI();

        return view('product.edit')
        ->with('listaCSTCSOSN', $listaCSTCSOSN)
        ->with('listaCST_PIS_COFINS', $listaCST_PIS_COFINS)
        ->with('listaCST_IPI', $listaCST_IPI)
        ->with('unidadesDeMedida', $unidadesDeMedida)
        ->with(compact('categories', 'brands', 'units', 'sub_units', 'taxes', 'tax_attributes', 'barcode_types', 'product', 'sub_categories', 'default_profit_percent', 'business_locations', 'rack_details', 'selling_price_group_count', 'module_form_parts', 'product_types', 'common_settings', 'warranties', 'pos_module_data', 'listaCenqIPI'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        $request->merge([ 'perc_icms_interestadual' => $request->perc_icms_interestadual ?? 0]);
        $request->merge([ 'perc_icms_interno' => $request->perc_icms_interno ?? 0]);
        $request->merge([ 'perc_fcp_interestadual' => $request->perc_fcp_interestadual ?? 0]);
        $request->merge([ 'pICMSST' => $request->pICMSST ?? 0]);
        $request->merge([ 'pCredSN' => $request->pCredSN ?? 0]);
        $request->merge([ 'ref_balanca' => $request->ref_balanca ?? '']);

        try {
            $business_id = $request->session()->get('user.business_id');
            $product_details = $request->only(['name', 'brand_id', 'unit_id', 'category_id', 'tax', 'barcode_type', 
                'sku', 'alert_quantity', 'tax_type', 'weight', 'product_custom_field1', 'product_custom_field2', 
                'product_custom_field3', 'product_custom_field4', 'product_description', 'sub_unit_ids', 'perc_icms', 
                'perc_pis', 'perc_cofins', 'perc_ipi', 'cfop_interno', 'cfop_externo', 'cst_csosn', 'cst_pis', 
                'cst_cofins', 'cst_ipi', 'ncm', 'cest', 'codigo_barras', 'codigo_anp', 'perc_glp', 'perc_gnn', 
                'perc_gni', 'valor_partida', 'unidade_tributavel', 'quantidade_tributavel', 'tipo', 'veicProd', 
                'tpOp', 'chassi', 'cCor', 'xCor', 'pot', 'cilin', 'pesoL', 'pesoB', 'nSerie', 'tpComb', 'nMotor', 
                'CMT', 'dist', 'anoMod', 'anoFab', 'tpPint', 'tpVeic', 'espVeic', 'VIN', 'condVeic', 'cMod', 
                'cCorDENATRAN', 'lota', 'tpRest', 'ecommerce', 'destaque', 'novo', 'altura', 'largura', 'comprimento', 
                'valor_ecommerce', 'origem', 'cenq_ipi', 'perc_icms_interestadual', 'perc_icms_interno', 
                'perc_fcp_interestadual', 'pICMSST', 'modBC', 'modBCST', 'pCredSN', 'ref_balanca', 'adRemICMSRet', 'pBio', 
                'pOrig', 'indImport', 'cUFOrig'
            ]);

            DB::beginTransaction();

            $product = Product::where('business_id', $business_id)
            ->where('id', $id)
            ->with(['product_variations'])
            ->first();

            $module_form_fields = $this->moduleUtil->getModuleFormField('product_form_fields');
            if (!empty($module_form_fields)) {
                foreach ($module_form_fields as $column) {
                    $product->$column = $request->input($column);
                }
            }

            $product->name = $product_details['name'];
            $product->brand_id = $product_details['brand_id'];
            $product->unit_id = $product_details['unit_id'];
            $product->category_id = $product_details['category_id'];
            $product->tax = $product_details['tax'];
            $product->barcode_type = $product_details['barcode_type'];
            $product->sku = $product_details['sku'];
            $product->alert_quantity = $product_details['alert_quantity'];
            $product->tax_type = $product_details['tax_type'];
            $product->weight = isset($product_details['weight']) ? str_replace(",", ".", $product_details['weight']) : 0;
            $product->product_custom_field1 = $product_details['product_custom_field1'];
            $product->product_custom_field2 = $product_details['product_custom_field2'];
            $product->product_custom_field3 = $product_details['product_custom_field3'];
            $product->product_custom_field4 = $product_details['product_custom_field4'];
            $product->product_description = $product_details['product_description'];
            $product->sub_unit_ids = !empty($product_details['sub_unit_ids']) ? $product_details['sub_unit_ids'] : null;
            $product->warranty_id = !empty($request->input('warranty_id')) ? $request->input('warranty_id') : null;

            $product->perc_icms = $product_details['perc_icms'];
            $product->perc_pis = $product_details['perc_pis'];
            $product->perc_cofins = $product_details['perc_cofins'];
            $product->perc_ipi = $product_details['perc_ipi'];
            $product->pCredSN = $product_details['pCredSN'];

            $product->cfop_interno = $product_details['cfop_interno'];
            $product->cfop_externo = $product_details['cfop_externo'];
            $product->cst_csosn = $product_details['cst_csosn'];
            $product->cst_pis = $product_details['cst_pis'];
            $product->cst_cofins = $product_details['cst_cofins'];
            $product->cst_ipi = $product_details['cst_ipi'];
            $product->ncm = $product_details['ncm'];
            $product->cest = $product_details['cest'];
            $product->codigo_barras = $product_details['codigo_barras'];

            $product->codigo_anp = $product_details['codigo_anp'];
            $product->perc_glp = $product_details['perc_glp'];
            $product->perc_gnn = $product_details['perc_gnn'];
            $product->perc_gni = $product_details['perc_gni'];
            $product->valor_partida = $product_details['valor_partida'];
            $product->unidade_tributavel = $product_details['unidade_tributavel'];
            $product->quantidade_tributavel = $product_details['quantidade_tributavel'];

            $product->origem = $product_details['origem'];
            $product->tipo = $product_details['tipo'];
            $product->ref_balanca = $product_details['ref_balanca'] ?? '';
            $product->veicProd = $product_details['veicProd'];
            $product->tpOp = $product_details['tpOp'];
            $product->chassi = $product_details['chassi'];
            $product->cCor = $product_details['cCor'];
            $product->xCor = $product_details['xCor'];
            $product->pot = $product_details['pot'];
            $product->cilin = $product_details['cilin'];
            $product->pesoL = $product_details['pesoL'];
            $product->pesoB = $product_details['pesoB'];
            $product->nSerie = $product_details['nSerie'];
            $product->tpComb = $product_details['tpComb'];
            $product->nMotor = $product_details['nMotor'];
            $product->CMT = $product_details['CMT'];
            $product->dist = $product_details['dist'];
            $product->anoMod = $product_details['anoMod'];
            $product->anoFab = $product_details['anoFab'];
            $product->tpPint = $product_details['tpPint'];
            $product->tpVeic = $product_details['tpVeic'];
            $product->espVeic = $product_details['espVeic'];
            $product->VIN = $product_details['VIN'];
            $product->condVeic = $product_details['condVeic'];
            $product->cMod = $product_details['cMod'];
            $product->cenq_ipi = $product_details['cenq_ipi'];
            $product->cCorDENATRAN = $product_details['cCorDENATRAN'];

            $product->perc_icms_interestadual = $product_details['perc_icms_interestadual'];
            $product->perc_icms_interno = $product_details['perc_icms_interno'];
            $product->perc_fcp_interestadual = $product_details['perc_fcp_interestadual'];
            $product->pICMSST = $product_details['pICMSST'];
            $product->modBC = $product_details['modBC'];
            $product->modBCST = $product_details['modBCST'];

            $product->adRemICMSRet = $request->adRemICMSRet ? str_replace(",", ".", $request->adRemICMSRet) : 0;
            $product->pBio = $request->pBio ? str_replace(",", ".", $request->pBio) : 0;
            $product->pOrig = $request->pOrig ? str_replace(",", ".", $request->pOrig) : 0; 
            $product->indImport = $request->indImport; 
            $product->cUFOrig = $request->cUFOrig; 

            // echo $product->cCorDENATRAN;
            // die;
            $product->lota = $product_details['lota'];
            $product->tpRest = $product_details['tpRest'];
            $product->ecommerce = $product_details['ecommerce'] ?? 0;
            $product->destaque = $product_details['destaque'] ?? 0;
            $product->novo = $product_details['novo'] ?? 0;
            $product->altura = isset($product_details['altura']) ? str_replace(",", ".", $product_details['altura']) : 0;
            $product->largura = isset($product_details['largura']) ? str_replace(",", ".", $product_details['largura']) : 0;
            $product->comprimento = isset($product_details['comprimento']) ? str_replace(",", ".", $product_details['comprimento']) : 0;
            $product->valor_ecommerce = isset($product_details['valor_ecommerce']) ? str_replace(",", ".", $product_details['valor_ecommerce']) : 0;

            if (!empty($request->input('enable_stock')) &&  $request->input('enable_stock') == 1) {
                $product->enable_stock = 1;
            } else {
                $product->enable_stock = 0;
            }

            $product->not_for_selling = (!empty($request->input('not_for_selling')) &&  $request->input('not_for_selling') == 1) ? 1 : 0;

            if (!empty($request->input('sub_category_id'))) {
                $product->sub_category_id = $request->input('sub_category_id');
            } else {
                $product->sub_category_id = null;
            }

            $expiry_enabled = $request->session()->get('business.enable_product_expiry');
            if (!empty($expiry_enabled)) {
                if (!empty($request->input('expiry_period_type')) && !empty($request->input('expiry_period')) && ($product->enable_stock == 1)) {
                    $product->expiry_period_type = $request->input('expiry_period_type');
                    $product->expiry_period = $this->productUtil->num_uf($request->input('expiry_period'));
                } else {
                    $product->expiry_period_type = null;
                    $product->expiry_period = null;
                }
            }

            if (!empty($request->input('enable_sr_no')) &&  $request->input('enable_sr_no') == 1) {
                $product->enable_sr_no = 1;
            } else {
                $product->enable_sr_no = 0;
            }

            //upload document
            $file_name = $this->productUtil->uploadFile($request, 'image', config('constants.product_img_path'), 'image');
            if (!empty($file_name)) {

                //If previous image found then remove
                if (!empty($product->image_path) && file_exists($product->image_path)) {
                    unlink($product->image_path);
                }

                $product->image = $file_name;
                //If product image is updated update woocommerce media id
                if (!empty($product->woocommerce_media_id)) {
                    $product->woocommerce_media_id = null;
                }
            }

            $product->save();
            $product->touch();

            //Add product locations
            $product_locations = !empty($request->input('product_locations')) ?
            $request->input('product_locations') : [];
            $product->product_locations()->sync($product_locations);

            if ($product->type == 'single') {

                $single_data = $request->only(['single_variation_id', 'single_dpp', 'single_dpp_inc_tax', 'single_dsp_inc_tax', 'profit_percent', 'single_dsp']);
                $variation = Variation::find($single_data['single_variation_id']);


                $variation->sub_sku = $product->sku;
                $variation->default_purchase_price = $this->productUtil->num_uf($single_data['single_dpp']);
                $variation->dpp_inc_tax = $this->productUtil->num_uf($single_data['single_dpp_inc_tax']);
                $variation->profit_percent = $this->productUtil->num_uf($single_data['profit_percent']);
                $variation->default_sell_price = $this->productUtil->num_uf($single_data['single_dsp']);
                // $variation->sell_price_inc_tax = $this->productUtil->num_uf($single_data['selling_price']);
                $variation->sell_price_inc_tax = $this->productUtil->num_uf($single_data['single_dsp']);
                $variation->save();

                Media::uploadMedia($product->business_id, $variation, $request, 'variation_images');
            } elseif ($product->type == 'variable') {
                //Update existing variations
                $input_variations_edit = $request->get('product_variation_edit');
                if (!empty($input_variations_edit)) {
                    $this->productUtil->updateVariableProductVariations($product->id, $input_variations_edit);
                }

                //Add new variations created.
                $input_variations = $request->input('product_variation');
                if (!empty($input_variations)) {
                    $this->productUtil->createVariableProductVariations($product->id, $input_variations);
                }
            } elseif ($product->type == 'combo') {

                //Create combo_variations array by combining variation_id and quantity.
                $combo_variations = [];
                if (!empty($request->input('composition_variation_id'))) {
                    $composition_variation_id = $request->input('composition_variation_id');
                    $quantity = $request->input('quantity');
                    $unit = $request->input('unit');

                    foreach ($composition_variation_id as $key => $value) {
                        $combo_variations[] = [
                            'variation_id' => $value,
                            'quantity' => $quantity[$key],
                            'unit_id' => $unit[$key]
                        ];
                    }
                }

                $variation = Variation::find($request->input('combo_variation_id'));
                $variation->sub_sku = $product->sku;
                $variation->default_purchase_price = $this->productUtil->num_uf($request->input('item_level_purchase_price_total'));
                $variation->dpp_inc_tax = $this->productUtil->num_uf($request->input('purchase_price_inc_tax'));
                $variation->profit_percent = $this->productUtil->num_uf($request->input('profit_percent'));
                $variation->default_sell_price = $this->productUtil->num_uf($request->input('selling_price'));
                $variation->sell_price_inc_tax = $this->productUtil->num_uf($request->input('selling_price_inc_tax'));
                $variation->combo_variations = $combo_variations;
                $variation->save();
            }

            //Add product racks details.
            $product_racks = $request->get('product_racks', null);
            if (!empty($product_racks)) {
                $this->productUtil->addRackDetails($business_id, $product->id, $product_racks);
            }

            $product_racks_update = $request->get('product_racks_update', null);
            if (!empty($product_racks_update)) {
                $this->productUtil->updateRackDetails($business_id, $product->id, $product_racks_update);
            }

            //Set Module fields
            if (!empty($request->input('has_module_data'))) {
                $this->moduleUtil->getModuleData('after_product_saved', ['product' => $product, 'request' => $request]);
            }

            DB::commit();
            $output = [
                'success' => 1,
                'msg' => __('product.product_updated_success')
            ];
        } catch (\Exception $e) {
            print_r($e->getMessage());
            die();
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __("messages.something_went_wrong")
            ];
        }

        if ($request->input('submit_type') == 'update_n_edit_opening_stock') {
            return redirect()->action(
                'OpeningStockController@add',
                ['product_id' => $product->id]
            );
        } elseif ($request->input('submit_type') == 'submit_n_add_selling_prices') {
            return redirect()->action(
                'ProductController@addSellingPrices',
                [$product->id]
            );
        } elseif ($request->input('submit_type') == 'save_n_add_another') {
            return redirect()->action(
                'ProductController@create'
            )->with('status', $output);
        }

        return redirect('products')->with('status', $output);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (!auth()->user()->can('product.delete')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {

            try {
                $business_id = request()->session()->get('user.business_id');

                $can_be_deleted = true;
                $error_msg = '';

                //Check if any purchase or transfer exists
                $count = PurchaseLine::join(
                    'transactions as T',
                    'purchase_lines.transaction_id',
                    '=',
                    'T.id'
                )
                ->whereIn('T.type', ['purchase'])
                ->where('T.business_id', $business_id)
                ->where('purchase_lines.product_id', $id)
                ->count();
                if ($count > 0) {
                    $can_be_deleted = false;
                    $error_msg = __('lang_v1.purchase_already_exist');
                } else {
                    //Check if any opening stock sold
                    $count = PurchaseLine::join(
                        'transactions as T',
                        'purchase_lines.transaction_id',
                        '=',
                        'T.id'
                    )
                    ->where('T.type', 'opening_stock')
                    ->where('T.business_id', $business_id)
                    ->where('purchase_lines.product_id', $id)
                    ->where('purchase_lines.quantity_sold', '>', 0)
                    ->count();
                    if ($count > 0) {
                        $can_be_deleted = false;
                        $error_msg = __('lang_v1.opening_stock_sold');
                    } else {
                        //Check if any stock is adjusted
                        $count = PurchaseLine::join(
                            'transactions as T',
                            'purchase_lines.transaction_id',
                            '=',
                            'T.id'
                        )
                        ->where('T.business_id', $business_id)
                        ->where('purchase_lines.product_id', $id)
                        ->where('purchase_lines.quantity_adjusted', '>', 0)
                        ->count();
                        if ($count > 0) {
                            $can_be_deleted = false;
                            $error_msg = __('lang_v1.stock_adjusted');
                        }
                    }
                }

                $product = Product::where('id', $id)
                ->where('business_id', $business_id)
                ->with('variations')
                ->first();

                //Check if product is added as an ingredient of any recipe
                if ($this->moduleUtil->isModuleInstalled('Manufacturing')) {
                    $variation_ids = $product->variations->pluck('id');

                    $exists_as_ingredient = \Modules\Manufacturing\Entities\MfgRecipeIngredient::whereIn('variation_id', $variation_ids)
                    ->exists();
                    $can_be_deleted = !$exists_as_ingredient;
                    $error_msg = __('manufacturing::lang.added_as_ingredient');
                }

                if ($can_be_deleted) {
                    if (!empty($product)) {
                        DB::beginTransaction();
                        //Delete variation location details
                        VariationLocationDetails::where('product_id', $id)
                        ->delete();
                        $product->delete();

                        DB::commit();
                    }

                    $output = ['success' => true,
                    'msg' => __("lang_v1.product_delete_success")
                ];
            } else {
                $output = ['success' => false,
                'msg' => $error_msg
            ];
        }
    } catch (\Exception $e) {
        DB::rollBack();
        \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

        $output = ['success' => false,
        'msg' => __("messages.something_went_wrong")
    ];
}

return $output;
}
}

    /**
     * Get subcategories list for a category.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getSubCategories(Request $request)
    {
        if (!empty($request->input('cat_id'))) {
            $category_id = $request->input('cat_id');
            $business_id = $request->session()->get('user.business_id');
            $sub_categories = Category::where('business_id', $business_id)
            ->where('parent_id', $category_id)
            ->select(['name', 'id'])
            ->get();
            $html = '<option value="">None</option>';
            if (!empty($sub_categories)) {
                foreach ($sub_categories as $sub_category) {
                    $html .= '<option value="' . $sub_category->id .'">' .$sub_category->name . '</option>';
                }
            }
            echo $html;
            exit;
        }
    }

    /**
     * Get product form parts.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getProductVariationFormPart(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $business = Business::findorfail($business_id);
        $profit_percent = $business->default_profit_percent;

        $action = $request->input('action');
        if ($request->input('action') == "add") {
            if ($request->input('type') == 'single') {
                return view('product.partials.single_product_form_part')
                ->with(['profit_percent' => $profit_percent]);
            } elseif ($request->input('type') == 'variable') {
                $variation_templates = VariationTemplate::where('business_id', $business_id)->pluck('name', 'id')->toArray();
                $variation_templates = [ "" => __('messages.please_select')] + $variation_templates;

                return view('product.partials.variable_product_form_part')
                ->with(compact('variation_templates', 'profit_percent', 'action'));
            } elseif ($request->input('type') == 'combo') {
                return view('product.partials.combo_product_form_part')
                ->with(compact('profit_percent', 'action'));
            }
        } elseif ($request->input('action') == "edit" || $request->input('action') == "duplicate") {
            $product_id = $request->input('product_id');
            $action = $request->input('action');
            if ($request->input('type') == 'single') {
                $product_deatails = ProductVariation::where('product_id', $product_id)
                ->with(['variations', 'variations.media'])
                ->first();

                return view('product.partials.edit_single_product_form_part')
                ->with(compact('product_deatails', 'action'));
            } elseif ($request->input('type') == 'variable') {
                $product_variations = ProductVariation::where('product_id', $product_id)
                ->with(['variations', 'variations.media'])
                ->get();
                return view('product.partials.variable_product_form_part')
                ->with(compact('product_variations', 'profit_percent', 'action'));
            } elseif ($request->input('type') == 'combo') {
                $product_deatails = ProductVariation::where('product_id', $product_id)
                ->with(['variations', 'variations.media'])
                ->first();
                $combo_variations = $this->__getComboProductDetails($product_deatails['variations'][0]->combo_variations, $business_id);

                $variation_id = $product_deatails['variations'][0]->id;
                return view('product.partials.combo_product_form_part')
                ->with(compact('combo_variations', 'profit_percent', 'action', 'variation_id'));
            }
        }
    }

    /**
     * Get product form parts.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getVariationValueRow(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $business = Business::findorfail($business_id);
        $profit_percent = $business->default_profit_percent;

        $variation_index = $request->input('variation_row_index');
        $value_index = $request->input('value_index') + 1;

        $row_type = $request->input('row_type', 'add');

        return view('product.partials.variation_value_row')
        ->with(compact('profit_percent', 'variation_index', 'value_index', 'row_type'));
    }

    /**
     * Get product form parts.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getProductVariationRow(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $business = Business::findorfail($business_id);
        $profit_percent = $business->default_profit_percent;

        $variation_templates = VariationTemplate::where('business_id', $business_id)
        ->pluck('name', 'id')->toArray();
        $variation_templates = [ "" => __('messages.please_select')] + $variation_templates;

        $row_index = $request->input('row_index', 0);
        $action = $request->input('action');

        return view('product.partials.product_variation_row')
        ->with(compact('variation_templates', 'row_index', 'action', 'profit_percent'));
    }

    /**
     * Get product form parts.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getVariationTemplate(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $business = Business::findorfail($business_id);
        $profit_percent = $business->default_profit_percent;

        $template = VariationTemplate::where('id', $request->input('template_id'))
        ->with(['values'])
        ->first();
        $row_index = $request->input('row_index');

        return view('product.partials.product_variation_template')
        ->with(compact('template', 'row_index', 'profit_percent'));
    }

    /**
     * Return the view for combo product row
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getComboProductEntryRow(Request $request)
    {
        if (request()->ajax()) {
            $product_id = $request->input('product_id');
            $variation_id = $request->input('variation_id');
            $business_id = $request->session()->get('user.business_id');

            if (!empty($product_id)) {
                $product = Product::where('id', $product_id)
                ->with(['unit'])
                ->first();

                $query = Variation::where('product_id', $product_id)
                ->with(['product_variation']);

                if ($variation_id !== '0') {
                    $query->where('id', $variation_id);
                }
                $variations =  $query->get();

                $sub_units = $this->productUtil->getSubUnits($business_id, $product['unit']->id);

                return view('product.partials.combo_product_entry_row')
                ->with(compact('product', 'variations', 'sub_units'));
            }
        }
    }

    /**
     * Retrieves products list.
     *
     * @param  string  $q
     * @param  boolean  $check_qty
     *
     * @return JSON
     */
    public function getProducts()
    {
        if (request()->ajax()) {
            $search_term = request()->input('term', '');
            $location_id = request()->input('location_id', null);

            $check_qty = request()->input('check_qty', false);
            $price_group_id = request()->input('price_group', null);
            $business_id = request()->session()->get('user.business_id');
            $not_for_selling = request()->get('not_for_selling', null);
            $price_group_id = request()->input('price_group', '');
            $product_types = request()->get('product_types', []);

            $search_fields = request()->get('search_fields', ['name', 'codigo_barras', 'sku']);
            $search_fields[] = 'codigo_barras';

            $location = BusinessLocation::find($location_id);

            if($location){
                $business = $location->business;
            }else{
                $business = Business::findorfail($business_id);
            }
            $result = $this->productUtil->filterProduct($business_id, $search_term, $location_id, $not_for_selling, $price_group_id, $product_types, $search_fields, $check_qty);

            foreach($result as $r){
                // $r->selling_price = number_format($r->selling_price, 2, ',', '');
                // $r->selling_price = num_format($r->selling_price);
                $r->selling_price = number_format($r->selling_price, $business->casas_decimais_valor, ',', '');
                if($r->ref_balanca != ""){
                    $r->selling_price = number_format(1, $business->casas_decimais_valor, ',', '');
                }
            }

            return json_encode($result);
        }
    }

    /**
     * Retrieves products list without variation list
     *
     * @param  string  $q
     * @param  boolean  $check_qty
     *
     * @return JSON
     */
    public function getProductsWithoutVariations()
    {
        if (request()->ajax()) {
            $term = request()->input('term', '');
            //$location_id = request()->input('location_id', '');

            //$check_qty = request()->input('check_qty', false);

            $business_id = request()->session()->get('user.business_id');

            $products = Product::join('variations', 'products.id', '=', 'variations.product_id')
            ->where('products.business_id', $business_id)
            ->where('products.type', '!=', 'modifier');

            //Include search
            if (!empty($term)) {
                $products->where(function ($query) use ($term) {
                    $query->where('products.name', 'like', '%' . $term .'%');
                    $query->orWhere('sku', 'like', '%' . $term .'%');
                    $query->orWhere('sub_sku', 'like', '%' . $term .'%');
                });
            }

            //Include check for quantity
            // if($check_qty){
            //     $products->where('VLD.qty_available', '>', 0);
            // }

            $products = $products->groupBy('products.id')
            ->select(
                'products.id as product_id',
                'products.name',
                'products.type',
                'products.enable_stock',
                'products.sku'
            )
            ->orderBy('products.name')
            ->get();
            return json_encode($products);
        }
    }

    /**
     * Checks if product sku already exists.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function checkProductSku(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $sku = $request->input('sku');
        $product_id = $request->input('product_id');

        //check in products table
        $query = Product::where('business_id', $business_id)
        ->where('sku', $sku);
        if (!empty($product_id)) {
            $query->where('id', '!=', $product_id);
        }
        $count = $query->count();

        //check in variation table if $count = 0
        if ($count == 0) {
            $count = Variation::where('sub_sku', $sku)
            ->join('products', 'variations.product_id', '=', 'products.id')
            ->where('product_id', '!=', $product_id)
            ->where('business_id', $business_id)
            ->count();
        }
        if ($count == 0) {
            echo "true";
            exit;
        } else {
            echo "false";
            exit;
        }
    }

    /**
     * Loads quick add product modal.
     *
     * @return \Illuminate\Http\Response
     */
    public function quickAdd()
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $product_name = !empty(request()->input('product_name'))? request()->input('product_name') : '';

        $product_for = !empty(request()->input('product_for'))? request()->input('product_for') : null;

        $business_id = request()->session()->get('user.business_id');
        $categories = Category::forDropdown($business_id, 'product');
        $brands = Brands::where('business_id', $business_id)
        ->pluck('name', 'id');
        $units = Unit::forDropdown($business_id, true);

        $tax_dropdown = TaxRate::forBusinessDropdown($business_id, true, true);
        $taxes = $tax_dropdown['tax_rates'];
        $tax_attributes = $tax_dropdown['attributes'];

        $barcode_types = $this->barcode_types;

        $default_profit_percent = Business::where('id', $business_id)->value('default_profit_percent');

        $locations = BusinessLocation::forDropdown($business_id);

        $enable_expiry = request()->session()->get('business.enable_product_expiry');
        $enable_lot = request()->session()->get('business.enable_lot_number');

        $module_form_parts = $this->moduleUtil->getModuleData('product_form_part');

        //Get all business locations
        $business_locations = BusinessLocation::forDropdown($business_id);

        $common_settings = session()->get('business.common_settings');
        $warranties = Warranty::forDropdown($business_id);


        $listaCSTCSOSN = Product::listaCSTCSOSN();
        $listaCST_PIS_COFINS = Product::listaCST_PIS_COFINS();
        $listaCST_IPI = Product::listaCST_IPI();
        $unidadesDeMedida = Product::unidadesMedida();
        $business = Business::find($business_id);

        return view('product.partials.quick_add_product')
        ->with('listaCSTCSOSN', $listaCSTCSOSN)
        ->with('listaCST_PIS_COFINS', $listaCST_PIS_COFINS)
        ->with('listaCST_IPI', $listaCST_IPI)
        ->with('unidadesDeMedida', $unidadesDeMedida)
        ->with('business', $business)
        ->with(compact('categories', 'brands', 'units', 'taxes', 'barcode_types', 'default_profit_percent', 'tax_attributes', 'product_name', 'locations', 'product_for', 'enable_expiry', 'enable_lot', 'module_form_parts', 'business_locations', 'common_settings', 'warranties'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function saveQuickProduct(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = $request->session()->get('user.business_id');
            // $form_fields = ['name', 'brand_id', 'unit_id', 'category_id', 'tax', 'barcode_type','tax_type', 'sku',
            // 'alert_quantity', 'type', 'sub_unit_ids', 'sub_category_id', 'weight', 'product_custom_field1', 'product_custom_field2', 'product_custom_field3', 'product_custom_field4', 'product_description'];

            $form_fields = ['name', 'brand_id', 'unit_id', 'category_id', 'tax', 'type', 'barcode_type', 'sku', 'alert_quantity', 'tax_type', 'weight', 'product_custom_field1', 'product_custom_field2', 'product_custom_field3', 'product_custom_field4', 'product_description', 'sub_unit_ids', 'perc_icms', 'perc_cofins', 'perc_pis', 'perc_ipi', 'cfop_interno', 'cfop_externo', 'cst_csosn', 'cst_pis', 'cst_cofins', 'cst_ipi', 'ncm', 'cest', 'codigo_barras', 'codigo_anp', 'perc_glp', 'perc_gnn', 'perc_gni', 'valor_partida', 'unidade_tributavel', 'quantidade_tributavel', 'tipo', 'veicProd', 'tpOp', 'chassi', 'cCor', 'xCor', 'pot', 'cilin', 'pesoL', 'pesoB', 'nSerie', 'tpComb', 'nMotor', 'CMT', 'dist', 'anoMod', 'anoFab', 'tpPint', 'tpVeic', 'espVeic', 'VIN', 'condVeic', 'cMod', 'cCorDENATRAN', 'lota', 'tpRest', 'origem'];

            $module_form_fields = $this->moduleUtil->getModuleData('product_form_fields');
            if (!empty($module_form_fields)) {
                foreach ($module_form_fields as $key => $value) {
                    if (!empty($value) && is_array($value)) {
                        $form_fields = array_merge($form_fields, $value);
                    }
                }
            }
            $product_details = $request->only($form_fields);

            $product_details['type'] = empty($product_details['type']) ? 'single' : $product_details['type'];
            $product_details['business_id'] = $business_id;
            $product_details['created_by'] = $request->session()->get('user.id');
            if (!empty($request->input('enable_stock')) &&  $request->input('enable_stock') == 1) {
                $product_details['enable_stock'] = 1 ;
                //TODO: Save total qty
                //$product_details['total_qty_available'] = 0;
            }
            if (!empty($request->input('not_for_selling')) &&  $request->input('not_for_selling') == 1) {
                $product_details['not_for_selling'] = 1 ;
            }
            if (empty($product_details['sku'])) {
                $product_details['sku'] = ' ';
            }

            $expiry_enabled = $request->session()->get('business.enable_product_expiry');
            if (!empty($request->input('expiry_period_type')) && !empty($request->input('expiry_period')) && !empty($expiry_enabled)) {
                $product_details['expiry_period_type'] = $request->input('expiry_period_type');
                $product_details['expiry_period'] = $this->productUtil->num_uf($request->input('expiry_period'));
            }

            if (!empty($request->input('enable_sr_no')) &&  $request->input('enable_sr_no') == 1) {
                $product_details['enable_sr_no'] = 1 ;
            }

            $product_details['warranty_id'] = !empty($request->input('warranty_id')) ? $request->input('warranty_id') : null;

            DB::beginTransaction();

            $product = Product::create($product_details);

            if (empty(trim($request->input('sku')))) {
                $sku = $this->productUtil->generateProductSku($product->id);
                $product->sku = $sku;
                $product->save();
            }

            $this->productUtil->createSingleProductVariation(
                $product->id,
                $product->sku,
                $request->input('single_dpp'),
                $request->input('single_dpp_inc_tax'),
                $request->input('profit_percent'),
                $request->input('single_dsp'),
                $request->input('single_dsp_inc_tax')
            );

            if ($product->enable_stock == 1 && !empty($request->input('opening_stock'))) {
                $user_id = $request->session()->get('user.id');

                $transaction_date = $request->session()->get("financial_year.start");
                $transaction_date = \Carbon::createFromFormat('Y-m-d', $transaction_date)->toDateTimeString();

                $this->productUtil->addSingleProductOpeningStock($business_id, $product, $request->input('opening_stock'), $transaction_date, $user_id);
            }

            //Add product locations
            $product_locations = $request->input('product_locations');
            if (!empty($product_locations)) {
                $product->product_locations()->sync($product_locations);
            }

            DB::commit();

            $output = ['success' => 1,
            'msg' => __('product.product_added_success'),
            'product' => $product,
            'variation' => $product->variations->first(),
            'locations' => $product_locations
        ];
    } catch (\Exception $e) {
        DB::rollBack();
        \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

        $output = ['success' => 0,
        'msg' => __("messages.something_went_wrong")
    ];
}

return $output;
}

    /**
     * Display the specified resource.
     *
     * @param  \App\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function view($id)
    {
        if (!auth()->user()->can('product.view')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');

            $product = Product::where('business_id', $business_id)
            ->with(['brand', 'unit', 'category', 'sub_category', 'product_tax', 'variations', 'variations.product_variation', 'variations.group_prices', 'variations.media', 'product_locations', 'warranty'])
            ->findOrFail($id);

            $price_groups = SellingPriceGroup::where('business_id', $business_id)->active()->pluck('name', 'id');

            $allowed_group_prices = [];
            foreach ($price_groups as $key => $value) {
                if (auth()->user()->can('selling_price_group.' . $key)) {
                    $allowed_group_prices[$key] = $value;
                }
            }

            $group_price_details = [];

            foreach ($product->variations as $variation) {
                foreach ($variation->group_prices as $group_price) {
                    $group_price_details[$variation->id][$group_price->price_group_id] = $group_price->price_inc_tax;
                }
            }

            $rack_details = $this->productUtil->getRackDetails($business_id, $id, true);

            $combo_variations = [];
            if ($product->type == 'combo') {
                $combo_variations = $this->__getComboProductDetails($product['variations'][0]->combo_variations, $business_id);
            }

            return view('product.view-modal')->with(compact(
                'product',
                'rack_details',
                'allowed_group_prices',
                'group_price_details',
                'combo_variations'
            ));
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
        }
    }

    /**
     * Gives the details of combo product
     *
     * @param array $combo_variations
     * @param int $business_id
     *
     * @return array
     */
    private function __getComboProductDetails($combo_variations, $business_id)
    {
        foreach ($combo_variations as $key => $value) {
            $combo_variations[$key]['variation'] =
            Variation::with(['product'])
            ->find($value['variation_id']);

            $combo_variations[$key]['sub_units'] = $this->productUtil->getSubUnits($business_id, $combo_variations[$key]['variation']['product']->unit_id, true);

            $combo_variations[$key]['multiplier'] = 1;

            if (!empty($combo_variations[$key]['sub_units'])) {
                if (isset($combo_variations[$key]['sub_units'][$combo_variations[$key]['unit_id']])) {
                    $combo_variations[$key]['multiplier'] = $combo_variations[$key]['sub_units'][$combo_variations[$key]['unit_id']]['multiplier'];
                    $combo_variations[$key]['unit_name'] = $combo_variations[$key]['sub_units'][$combo_variations[$key]['unit_id']]['name'];
                }
            }
        }

        return $combo_variations;
    }

    /**
     * Mass deletes products.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function massDestroy(Request $request)
    {
        if (!auth()->user()->can('product.delete')) {
            abort(403, 'Unauthorized action.');
        }
        try {
            $purchase_exist = false;

            if (!empty($request->input('selected_rows'))) {
                $business_id = $request->session()->get('user.business_id');

                $selected_rows = explode(',', $request->input('selected_rows'));

                $products = Product::where('business_id', $business_id)
                ->whereIn('id', $selected_rows)
                ->with(['purchase_lines', 'variations'])
                ->get();
                $deletable_products = [];

                $is_mfg_installed = $this->moduleUtil->isModuleInstalled('Manufacturing');

                DB::beginTransaction();

                foreach ($products as $product) {
                    $can_be_deleted = true;
                    //Check if product is added as an ingredient of any recipe
                    if ($is_mfg_installed) {
                        $variation_ids = $product->variations->pluck('id');

                        $exists_as_ingredient = \Modules\Manufacturing\Entities\MfgRecipeIngredient::whereIn('variation_id', $variation_ids)
                        ->exists();
                        $can_be_deleted = !$exists_as_ingredient;
                    }
                    
                    //Delete if no purchase found
                    if ($can_be_deleted) {
                        //Delete variation location details
                        VariationLocationDetails::where('product_id', $product->id)
                        ->delete();
                        $product->delete();
                    } else {
                        $purchase_exist = true;
                    }
                }

                DB::commit();
            }

            if (!$purchase_exist) {
                $output = [
                    'success' => 1,
                    'msg' => __('lang_v1.deleted_success')
                ];
            } else {
                $output = [
                    'success' => 0,
                    'msg' => __('lang_v1.products_could_not_be_deleted')
                ];
            }
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __("messages.something_went_wrong")
            ];
        }

        return redirect()->back()->with(['status' => $output]);
    }

    /**
     * Shows form to add selling price group prices for a product.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function addSellingPrices($id)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $product = Product::where('business_id', $business_id)
        ->with(['variations', 'variations.group_prices', 'variations.product_variation'])
        ->findOrFail($id);

        $price_groups = SellingPriceGroup::where('business_id', $business_id)
        ->active()
        ->get();
        $variation_prices = [];
        foreach ($product->variations as $variation) {
            foreach ($variation->group_prices as $group_price) {
                $variation_prices[$variation->id][$group_price->price_group_id] = $group_price->price_inc_tax;
            }
        }
        return view('product.add-selling-prices')->with(compact('product', 'price_groups', 'variation_prices'));
    }

    /**
     * Saves selling price group prices for a product.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function saveSellingPrices(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = $request->session()->get('user.business_id');
            $product = Product::where('business_id', $business_id)
            ->with(['variations'])
            ->findOrFail($request->input('product_id'));
            DB::beginTransaction();
            foreach ($product->variations as $variation) {
                $variation_group_prices = [];
                foreach ($request->input('group_prices') as $key => $value) {
                    if (isset($value[$variation->id])) {
                        $variation_group_price =
                        VariationGroupPrice::where('variation_id', $variation->id)
                        ->where('price_group_id', $key)
                        ->first();
                        if (empty($variation_group_price)) {
                            $variation_group_price = new VariationGroupPrice([
                                'variation_id' => $variation->id,
                                'price_group_id' => $key
                            ]);
                        }

                        $variation_group_price->price_inc_tax = $this->productUtil->num_uf($value[$variation->id]);
                        $variation_group_prices[] = $variation_group_price;
                    }
                }

                if (!empty($variation_group_prices)) {
                    $variation->group_prices()->saveMany($variation_group_prices);
                }
            }
            //Update product updated_at timestamp
            $product->touch();

            DB::commit();
            $output = ['success' => 1,
            'msg' => __("lang_v1.updated_success")
        ];
    } catch (\Exception $e) {
        DB::rollBack();
        \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

        $output = ['success' => 0,
        'msg' => __("messages.something_went_wrong")
    ];
}

if ($request->input('submit_type') == 'submit_n_add_opening_stock') {
    return redirect()->action(
        'OpeningStockController@add',
        ['product_id' => $product->id]
    );
} elseif ($request->input('submit_type') == 'save_n_add_another') {
    return redirect()->action(
        'ProductController@create'
    )->with('status', $output);
}

return redirect('products')->with('status', $output);
}

public function viewGroupPrice($id)
{
    if (!auth()->user()->can('product.view')) {
        abort(403, 'Unauthorized action.');
    }

    $business_id = request()->session()->get('user.business_id');

    $product = Product::where('business_id', $business_id)
    ->where('id', $id)
    ->with(['variations', 'variations.product_variation', 'variations.group_prices'])
    ->first();

    $price_groups = SellingPriceGroup::where('business_id', $business_id)->active()->pluck('name', 'id');

    $allowed_group_prices = [];
    foreach ($price_groups as $key => $value) {
        if (auth()->user()->can('selling_price_group.' . $key)) {
            $allowed_group_prices[$key] = $value;
        }
    }

    $group_price_details = [];

    foreach ($product->variations as $variation) {
        foreach ($variation->group_prices as $group_price) {
            $group_price_details[$variation->id][$group_price->price_group_id] = $group_price->price_inc_tax;
        }
    }

    return view('product.view-product-group-prices')->with(compact('product', 'allowed_group_prices', 'group_price_details'));
}

    /**
     * Mass deactivates products.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function massDeactivate(Request $request)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }
        try {
            if (!empty($request->input('selected_products'))) {
                $business_id = $request->session()->get('user.business_id');

                $selected_products = explode(',', $request->input('selected_products'));

                DB::beginTransaction();

                $products = Product::where('business_id', $business_id)
                ->whereIn('id', $selected_products)
                ->update(['is_inactive' => 1]);

                DB::commit();
            }

            $output = ['success' => 1,
            'msg' => __('lang_v1.products_deactivated_success')
        ];
    } catch (\Exception $e) {
        DB::rollBack();
        \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

        $output = ['success' => 0,
        'msg' => __("messages.something_went_wrong")
    ];
}

return $output;
}

    /**
     * Activates the specified resource from storage.
     *
     * @param  \App\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function activate($id)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $business_id = request()->session()->get('user.business_id');
                $product = Product::where('id', $id)
                ->where('business_id', $business_id)
                ->update(['is_inactive' => 0]);

                $output = ['success' => true,
                'msg' => __("lang_v1.updated_success")
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = ['success' => false,
            'msg' => __("messages.something_went_wrong")
        ];
    }

    return $output;
}
}

    /**
     * Deletes a media file from storage and database.
     *
     * @param  int  $media_id
     * @return json
     */
    public function deleteMedia($media_id)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $business_id = request()->session()->get('user.business_id');

                Media::deleteMedia($business_id, $media_id);

                $output = ['success' => true,
                'msg' => __("lang_v1.file_deleted_successfully")
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = ['success' => false,
            'msg' => __("messages.something_went_wrong")
        ];
    }

    return $output;
}
}

public function getProductsApi($id = null)
{
    try {
        $api_token = request()->header('API-TOKEN');
        $filter_string = request()->header('FILTERS');
        $order_by = request()->header('ORDER-BY');

        parse_str($filter_string, $filters);

        $api_settings = $this->moduleUtil->getApiSettings($api_token);

        $limit = !empty(request()->input('limit')) ? request()->input('limit') : 10;

        $location_id = $api_settings->location_id;

        $query = Product::where('business_id', $api_settings->business_id)
        ->active()
        ->with(['brand', 'unit', 'category', 'sub_category',
            'product_variations', 'product_variations.variations', 'product_variations.variations.media',
            'product_variations.variations.variation_location_details' => function ($q) use ($location_id) {
                $q->where('location_id', $location_id);
            }]);

        if (!empty($filters['categories'])) {
            $query->whereIn('category_id', $filters['categories']);
        }

        if (!empty($filters['brands'])) {
            $query->whereIn('brand_id', $filters['brands']);
        }

        if (!empty($filters['category'])) {
            $query->where('category_id', $filters['category']);
        }

        if (!empty($filters['sub_category'])) {
            $query->where('sub_category_id', $filters['sub_category']);
        }

        if ($order_by == 'name') {
            $query->orderBy('name', 'asc');
        } elseif ($order_by == 'date') {
            $query->orderBy('created_at', 'desc');
        }

        if (empty($id)) {
            $products = $query->paginate($limit);
        } else {
            $products = $query->find($id);
        }
    } catch (\Exception $e) {
        \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

        return $this->respondWentWrong($e);
    }

    return $this->respond($products);
}

public function getVariationsApi()
{
    try {
        $api_token = request()->header('API-TOKEN');
        $variations_string = request()->header('VARIATIONS');

        if (is_numeric($variations_string)) {
            $variation_ids = intval($variations_string);
        } else {
            parse_str($variations_string, $variation_ids);
        }

        $api_settings = $this->moduleUtil->getApiSettings($api_token);
        $location_id = $api_settings->location_id;
        $business_id = $api_settings->business_id;

        $query = Variation::with([
            'product_variation',
            'product' => function ($q) use ($business_id) {
                $q->where('business_id', $business_id);
            },
            'product.unit',
            'variation_location_details' => function ($q) use ($location_id) {
                $q->where('location_id', $location_id);
            }
        ]);

        $variations = is_array($variation_ids) ? $query->whereIn('id', $variation_ids)->get() : $query->where('id', $variation_ids)->first();
    } catch (\Exception $e) {
        \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

        return $this->respondWentWrong($e);
    }

    return $this->respond($variations);
}

    /**
     * Shows form to edit multiple products at once.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function bulkEdit(Request $request)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        $selected_products_string = $request->input('selected_products');
        if (!empty($selected_products_string)) {
            $selected_products = explode(',', $selected_products_string);
            $business_id = $request->session()->get('user.business_id');

            $products = Product::where('business_id', $business_id)
            ->whereIn('id', $selected_products)
            ->with(['variations', 'variations.product_variation', 'variations.group_prices', 'product_locations'])
            ->get();

            $all_categories = Category::catAndSubCategories($business_id);

            $categories = [];
            $sub_categories = [];
            foreach ($all_categories as $category) {
                $categories[$category['id']] = $category['name'];

                if (!empty($category['sub_categories'])) {
                    foreach ($category['sub_categories'] as $sub_category) {
                        $sub_categories[$category['id']][$sub_category['id']] = $sub_category['name'];
                    }
                }
            }

            $brands = Brands::where('business_id', $business_id)
            ->pluck('name', 'id');

            $tax_dropdown = TaxRate::forBusinessDropdown($business_id, true, true);
            $taxes = $tax_dropdown['tax_rates'];
            $tax_attributes = $tax_dropdown['attributes'];

            $price_groups = SellingPriceGroup::where('business_id', $business_id)->active()->pluck('name', 'id');
            $business_locations = BusinessLocation::forDropdown($business_id);

            return view('product.bulk-edit')->with(compact(
                'products',
                'categories',
                'brands',
                'taxes',
                'tax_attributes',
                'sub_categories',
                'price_groups',
                'business_locations'
            ));
        }
    }

    /**
     * Updates multiple products at once.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function bulkUpdate(Request $request)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $products = $request->input('products');
            $business_id = $request->session()->get('user.business_id');

            DB::beginTransaction();
            foreach ($products as $id => $product_data) {
                $update_data = [
                    'category_id' => $product_data['category_id'],
                    'sub_category_id' => $product_data['sub_category_id'],
                    'brand_id' => $product_data['brand_id'],
                    'tax' => $product_data['tax'],
                ];

                //Update product
                $product = Product::where('business_id', $business_id)
                ->findOrFail($id);

                $product->update($update_data);

                //Add product locations
                $product_locations = !empty($product_data['product_locations']) ?
                $product_data['product_locations'] : [];
                $product->product_locations()->sync($product_locations);

                $variations_data = [];

                //Format variations data
                foreach ($product_data['variations'] as $key => $value) {
                    $variation = Variation::where('product_id', $product->id)->findOrFail($key);
                    $variation->default_purchase_price = $this->productUtil->num_uf($value['default_purchase_price']);
                    $variation->dpp_inc_tax = $this->productUtil->num_uf($value['dpp_inc_tax']);
                    $variation->profit_percent = $this->productUtil->num_uf($value['profit_percent']);
                    $variation->default_sell_price = $this->productUtil->num_uf($value['default_sell_price']);
                    $variation->sell_price_inc_tax = $this->productUtil->num_uf($value['sell_price_inc_tax']);
                    $variations_data[] = $variation;

                    //Update price groups
                    if (!empty($value['group_prices'])) {
                        foreach ($value['group_prices'] as $k => $v) {
                            VariationGroupPrice::updateOrCreate(
                                ['price_group_id' => $k, 'variation_id' => $variation->id],
                                ['price_inc_tax' => $this->productUtil->num_uf($v)]
                            );
                        }
                    }
                }
                $product->variations()->saveMany($variations_data);
            }
            DB::commit();

            $output = ['success' => 1,
            'msg' => __("lang_v1.updated_success")
        ];
    } catch (\Exception $e) {
        DB::rollBack();
        \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

        $output = ['success' => 0,
        'msg' => __("messages.something_went_wrong")
    ];
}

return redirect('products')->with('status', $output);
}

    /**
     * Adds product row to edit in bulk edit product form
     *
     * @param  int  $product_id
     * @return \Illuminate\Http\Response
     */
    public function getProductToEdit($product_id)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = request()->session()->get('user.business_id');

        $product = Product::where('business_id', $business_id)
        ->with(['variations', 'variations.product_variation', 'variations.group_prices'])
        ->findOrFail($product_id);
        $all_categories = Category::catAndSubCategories($business_id);

        $categories = [];
        $sub_categories = [];
        foreach ($all_categories as $category) {
            $categories[$category['id']] = $category['name'];

            if (!empty($category['sub_categories'])) {
                foreach ($category['sub_categories'] as $sub_category) {
                    $sub_categories[$category['id']][$sub_category['id']] = $sub_category['name'];
                }
            }
        }

        $brands = Brands::where('business_id', $business_id)
        ->pluck('name', 'id');

        $tax_dropdown = TaxRate::forBusinessDropdown($business_id, true, true);
        $taxes = $tax_dropdown['tax_rates'];
        $tax_attributes = $tax_dropdown['attributes'];

        $price_groups = SellingPriceGroup::where('business_id', $business_id)->active()->pluck('name', 'id');

        return view('product.partials.bulk_edit_product_row')->with(compact(
            'product',
            'categories',
            'brands',
            'taxes',
            'tax_attributes',
            'sub_categories',
            'price_groups'
        ));
    }

    /**
     * Gets the sub units for the given unit.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $unit_id
     * @return \Illuminate\Http\Response
     */
    public function getSubUnits(Request $request)
    {
        if (!empty($request->input('unit_id'))) {
            $unit_id = $request->input('unit_id');
            $business_id = $request->session()->get('user.business_id');
            $sub_units = $this->productUtil->getSubUnits($business_id, $unit_id, true);

            //$html = '<option value="">' . __('lang_v1.all') . '</option>';
            $html = '';
            if (!empty($sub_units)) {
                foreach ($sub_units as $id => $sub_unit) {
                    $html .= '<option value="' . $id .'">' .$sub_unit['name'] . '</option>';
                }
            }

            return $html;
        }
    }

    public function updateProductLocation(Request $request)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $selected_products = $request->input('products');
            $update_type = $request->input('update_type');
            $location_ids = $request->input('product_location');

            $business_id = $request->session()->get('user.business_id');

            $product_ids = explode(',', $selected_products);

            $products = Product::where('business_id', $business_id)
            ->whereIn('id', $product_ids)
            ->with(['product_locations'])
            ->get();
            DB::beginTransaction();
            foreach ($products as $product) {
                $product_locations = $product->product_locations->pluck('id')->toArray();

                if ($update_type == 'add') {
                    $product_locations = array_unique(array_merge($location_ids, $product_locations));
                    $product->product_locations()->sync($product_locations);
                } elseif ($update_type == 'remove') {
                    foreach ($product_locations as $key => $value) {
                        if (in_array($value, $location_ids)) {
                            unset($product_locations[$key]);
                        }
                    }
                    $product->product_locations()->sync($product_locations);
                }
            }
            DB::commit();
            $output = [
                'success' => 1,
                'msg' => __("lang_v1.updated_success")
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __("messages.something_went_wrong")
            ];
        }

        return $output;
    }

    public function galery($id){
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $categories = Category::forDropdown($business_id, 'product');
        $brands = Brands::where('business_id', $business_id)
        ->pluck('name', 'id');

        $tax_dropdown = TaxRate::forBusinessDropdown($business_id, true, true);
        $taxes = $tax_dropdown['tax_rates'];
        $tax_attributes = $tax_dropdown['attributes'];

        $barcode_types = $this->barcode_types;

        $product = Product::where('business_id', $business_id)
        ->with(['product_locations'])
        ->where('id', $id)
        ->firstOrFail();

        return view('product.galery')
        ->with(compact('categories', 'brands', 'product'));
    }

    public function galerySave(Request $request){

        try{
            if($request->hasFile('image')){

                $img = $this->productUtil->uploadFile($request, 'image', config('constants.product_img_path'), 'image');

                ProdutoImagem::create([
                    'img' => $img,
                    'produto_id' => $request->id
                ]);

                $output = [
                    'success' => 1,
                    'msg' => 'Imagem salva'
                ];
            }else{
                $output = [
                    'success' => 0,
                    'msg' => 'Envie a imagem!'
                ];
            }

        }catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __("messages.something_went_wrong")
            ];
        }

        return redirect()->back()->with('status', $output);

    }

    public function galeryDelete($id){
        try{
            $imagem = ProdutoImagem::find($id);
            if(file_exists(public_path('uploads/img/').$imagem->img)){
                unlink(public_path('uploads/img/').$imagem->img);
            }

            $imagem->delete();
            $output = [
                'success' => 1,
                'msg' => 'Imagem removida!'
            ];
        }catch (\Exception $e) {

            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => __("messages.something_went_wrong")
            ];
        }

        return redirect()->back()->with('status', $output);
    }

    public function productStockHistory($id)
    {
        if (!auth()->user()->can('product.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        if (request()->ajax()) {

            $stock_details = $this->productUtil->getVariationStockDetails($business_id, $id, request()->input('location_id'));
            $stock_history = $this->productUtil->getVariationStockHistory($business_id, $id, request()->input('location_id'));

            return view('product.stock_history_details')
            ->with(compact('stock_details', 'stock_history'));
        }
        
        $product = Product::where('business_id', $business_id)
        ->with(['variations', 'variations.product_variation'])
        ->findOrFail($id);
        
        //Get all business locations
        $business_locations = BusinessLocation::forDropdown($business_id);
        

        return view('product.stock_history')
        ->with(compact('product', 'business_locations'));
    }

    /**
     * Toggle WooComerce sync
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function toggleWooCommerceSync(Request $request)
    {

        try {
            $selected_products = $request->input('woocommerce_products_sync');
            $woocommerce_disable_sync = $request->input('woocommerce_disable_sync');

            $business_id = $request->session()->get('user.business_id');
            $product_ids = explode(',', $selected_products);
            
            DB::beginTransaction();
            if ($this->moduleUtil->isModuleInstalled('Woocommerce')) {   
                Product::where('business_id', $business_id)
                ->whereIn('id', $product_ids)
                ->update(['woocommerce_disable_sync' => $woocommerce_disable_sync]);
            }
            DB::commit();
            $output = [
                'success' => 1,
                'msg' => __("lang_v1.success")
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = [
                'success' => 0,
                'msg' => __("messages.something_went_wrong")
            ];
        }

        return $output;
    }

}
