@extends('layouts.app')
@section('title', __('product.add_new_product'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
  <h1>@lang('product.add_new_product')</h1>
    <!-- <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Level</a></li>
        <li class="active">Here</li>
      </ol> -->
    </section>

    <!-- Main content -->
    <section class="content">
      @php
      $form_class = empty($duplicate_product) ? 'create' : '';
      @endphp
      {!! Form::open(['url' => action('ProductController@store'), 'method' => 'post',
      'id' => 'product_add_form','class' => 'product_form ' . $form_class, 'files' => true ]) !!}
      @component('components.widget', ['class' => 'box-primary'])
      <div class="row">
        <div class="col-sm-4">
          <div class="form-group">
            {!! Form::label('name', __('product.product_name') . ':*') !!}
            {!! Form::text('name', !empty($duplicate_product->name) ? $duplicate_product->name : null, ['class' => 'form-control', 'required',
            'placeholder' => __('product.product_name')]); !!}
          </div>
        </div>

        <div class="col-sm-2">
          <div class="form-group">
            {!! Form::label('sku', __('product.sku') . ':') !!} @show_tooltip(__('tooltip.sku'))
            {!! Form::text('sku', null, ['class' => 'form-control',
            'placeholder' => __('product.sku')]); !!}
          </div>
        </div>

        <div class="col-sm-4">
          <div class="form-group">
            {!! Form::label('barcode_type', __('product.barcode_type') . ':*') !!}
            {!! Form::select('barcode_type', $barcode_types, !empty($duplicate_product->barcode_type) ? $duplicate_product->barcode_type : $barcode_default, ['class' => 'form-control select2', 'required']); !!}
          </div>
        </div>

        <div class="col-sm-4">
          <div class="form-group">
            {!! Form::label('codigo_barras', 'Código de barras' . ':') !!}
            {!! Form::text('codigo_barras', null, ['class' => 'form-control',
            'placeholder' => 'Código de barras']); !!}
          </div>
        </div>

        <!-- <div class="clearfix"></div> -->
        <div class="col-sm-4">
          <div class="form-group">
            {!! Form::label('unit_id', __('product.unit') . ':*') !!}
            <div class="input-group">
              {!! Form::select('unit_id', $units, !empty($duplicate_product->unit_id) ? $duplicate_product->unit_id : session('business.default_unit'), ['class' => 'form-control select2', 'required']); !!}
              <span class="input-group-btn">
                <button type="button" @if(!auth()->user()->can('unit.create')) disabled @endif class="btn btn-default bg-white btn-flat btn-modal" data-href="{{action('UnitController@create', ['quick_add' => true])}}" title="@lang('unit.add_unit')" data-container=".view_modal"><i class="fa fa-plus-circle text-primary fa-lg"></i></button>
              </span>
            </div>
          </div>
        </div>

        <div class="col-sm-4 @if(!session('business.enable_sub_units')) hide @endif">
          <div class="form-group">
            {!! Form::label('sub_unit_ids', __('lang_v1.related_sub_units') . ':') !!} @show_tooltip(__('lang_v1.sub_units_tooltip'))

            {!! Form::select('sub_unit_ids[]', [], !empty($duplicate_product->sub_unit_ids) ? $duplicate_product->sub_unit_ids : null, ['class' => 'form-control select2', 'multiple', 'id' => 'sub_unit_ids']); !!}
          </div>
        </div>

        <div class="col-sm-4 @if(!session('business.enable_brand')) hide @endif">
          <div class="form-group">
            {!! Form::label('brand_id', __('product.brand') . ':') !!}
            <div class="input-group">
              {!! Form::select('brand_id', $brands, !empty($duplicate_product->brand_id) ? $duplicate_product->brand_id : null, ['placeholder' => __('messages.please_select'), 'class' => 'form-control select2']); !!}
              <span class="input-group-btn">
                <button type="button" @if(!auth()->user()->can('brand.create')) disabled @endif class="btn btn-default bg-white btn-flat btn-modal" data-href="{{action('BrandController@create', ['quick_add' => true])}}" title="@lang('brand.add_brand')" data-container=".view_modal"><i class="fa fa-plus-circle text-primary fa-lg"></i></button>
              </span>
            </div>
          </div>
        </div>

        <div class="clearfix"></div>

        <div class="col-sm-4 @if(!session('business.enable_category')) hide @endif">
          <div class="form-group">
            {!! Form::label('category_id', __('product.category') . ':') !!}
            {!! Form::select('category_id', $categories, !empty($duplicate_product->category_id) ? $duplicate_product->category_id : null, ['placeholder' => __('messages.please_select'), 'class' => 'form-control select2']); !!}
          </div>
        </div>

        <div class="col-sm-4 @if(!(session('business.enable_category') && session('business.enable_sub_category'))) hide @endif">
          <div class="form-group">
            {!! Form::label('sub_category_id', __('product.sub_category') . ':') !!}
            {!! Form::select('sub_category_id', $sub_categories, !empty($duplicate_product->sub_category_id) ? $duplicate_product->sub_category_id : null, ['placeholder' => __('messages.please_select'), 'class' => 'form-control select2']); !!}
          </div>
        </div>

        @php
        $default_location = null;
        if(count($business_locations) == 1){
          $default_location = array_key_first($business_locations->toArray());
        }
        @endphp
        <div class="col-sm-4">
          <div class="form-group">
            {!! Form::label('product_locations', __('business.business_locations') . ':') !!} @show_tooltip(__('lang_v1.product_location_help'))
            {!! Form::select('product_locations[]', $business_locations, $default_location, ['class' => 'form-control select2', 'multiple', 'id' => 'product_locations']); !!}
          </div>
        </div>


        <div class="clearfix"></div>

        <div class="col-sm-4">
          <div class="form-group">
            <br>
            <label>
              {!! Form::checkbox('enable_stock', 1, !empty($duplicate_product) ? $duplicate_product->enable_stock : true, ['class' => 'input-icheck', 'id' => 'enable_stock']); !!} <strong>@lang('product.manage_stock')</strong>
            </label>@show_tooltip(__('tooltip.enable_stock')) <p class="help-block"><i>@lang('product.enable_stock_help')</i></p>
          </div>
        </div>
        <div class="col-sm-3 @if(!empty($duplicate_product) && $duplicate_product->enable_stock == 0) hide @endif" id="alert_quantity_div">
          <div class="form-group">
            {!! Form::label('alert_quantity',  __('product.alert_quantity') . ':') !!} @show_tooltip(__('tooltip.alert_quantity'))
            {!! Form::number('alert_quantity', !empty($duplicate_product->alert_quantity) ? $duplicate_product->alert_quantity : null , ['class' => 'form-control',
            'placeholder' => __('product.alert_quantity'), 'min' => '0']); !!}
          </div>
        </div>

        <div class="col-sm-3" id="ref_balanca">
          <div class="form-group">
            {!! Form::label('ref_balanca',  'Referência Balança:') !!} @show_tooltip('Utilize esse campo para produtos pesados, configure em configurações/emitente aba PDV o campo Comprimento do SKU do produto corretamente')
            {!! Form::text('ref_balanca', null , ['class' => 'form-control',
            'placeholder' => 'Referência Balança']); !!}
          </div>
        </div>

        @if(!empty($common_settings['enable_product_warranty']))
        <div class="col-sm-4">
          <div class="form-group">
            {!! Form::label('warranty_id', __('lang_v1.warranty') . ':') !!}
            {!! Form::select('warranty_id', $warranties, null, ['class' => 'form-control select2', 'placeholder' => __('messages.please_select')]); !!}
          </div>
        </div>
        @endif
        <!-- include module fields -->
        @if(!empty($pos_module_data))
        @foreach($pos_module_data as $key => $value)
        @if(!empty($value['view_path']))
        @includeIf($value['view_path'], ['view_data' => $value['view_data']])
        @endif
        @endforeach
        @endif
        <div class="clearfix"></div>
        <div class="col-sm-8">
          <div class="form-group">
            {!! Form::label('product_description', __('lang_v1.product_description') . ':') !!}
            {!! Form::textarea('product_description', !empty($duplicate_product->product_description) ? $duplicate_product->product_description : null, ['class' => 'form-control']); !!}
          </div>
        </div>
        <div class="col-sm-4">
          <div class="form-group">
            {!! Form::label('image', __('lang_v1.product_image') . ':') !!}
            {!! Form::file('image', ['id' => 'upload_image', 'accept' => 'image/*']); !!}
            <small><p class="help-block">@lang('purchase.max_file_size', ['size' => (config('constants.document_size_limit') / 1000000)]) <br> @lang('lang_v1.aspect_ratio_should_be_1_1')</p></small>
          </div>
        </div>
      </div>
      @endcomponent

      @if (in_array('ecommerce', $enabled_modules) && auth()->user()->can('ecommerce.view'))
      <div class="box @if(!empty($class)) {{$class}} @else box-primary @endif" id="accordion">
        <div class="box-header with-border" style="cursor: pointer;">
          <h3 class="box-title">
            <a data-toggle="collapse" data-parent="#accordion" href="#collapseFilter">
              Ecommerce
            </a>
          </h3>
        </div>
        <div id="collapseFilter" class="panel-collapse active collapse" aria-expanded="true">
          <div class="box-body">
            <div class="row">
              <div class="col-sm-2">
                <div class="form-group">
                  <br>
                  <label>
                    {!! Form::checkbox('ecommerce', 1, !(empty($duplicate_product)) ? $duplicate_product->ecommerce : false, ['class' => 'input-icheck']); !!} <strong>Ecommerce</strong>
                  </label> @show_tooltip('Se marcado, o produto será visível no ecommerce')
                </div>
              </div>

              <div class="col-sm-2">
                <div class="form-group">
                  <br>
                  <label>
                    {!! Form::checkbox('destaque', 1, !(empty($duplicate_product)) ? $duplicate_product->destaque : false, ['class' => 'input-icheck']); !!} <strong>Destaque</strong>
                  </label> @show_tooltip('Se marcado, o produto será mostrado na primeira pagina')
                </div>
              </div>

              <div class="col-sm-2">
                <div class="form-group">
                  <br>
                  <label>
                    {!! Form::checkbox('novo', 1, !(empty($duplicate_product)) ? $duplicate_product->novo : false, ['class' => 'input-icheck']); !!} <strong>Novo</strong>
                  </label> @show_tooltip('Se marcado, o produto será mostrado como novidade')
                </div>
              </div>

              <div class="clearfix"></div>

              <div class="col-sm-2">
                <div class="form-group">
                  {!! Form::label('weight',  __('lang_v1.weight') . ':') !!}
                  {!! Form::text('weight', !empty($duplicate_product->weight) ? $duplicate_product->weight : null, ['class' => 'form-control', 'placeholder' => __('lang_v1.weight'), 'data-mask="000000.000"', 'data-mask-reverse="true"']); !!}
                </div>
              </div>

              <div class="col-sm-2">
                <div class="form-group">
                  {!! Form::label('altura',  'Altura' . ':') !!}
                  {!! Form::text('altura', null, ['class' => 'form-control', 'placeholder' => 'Altura', 'data-mask="000000,00"', 'data-mask-reverse="true"']); !!}
                </div>
              </div>

              <div class="col-sm-2">
                <div class="form-group">
                  {!! Form::label('largura',  'Largura' . ':') !!}
                  {!! Form::text('largura', null, ['class' => 'form-control', 'placeholder' => 'Largura', 'data-mask="000000,00"', 'data-mask-reverse="true"']); !!}
                </div>
              </div>

              <div class="col-sm-2">
                <div class="form-group">
                  {!! Form::label('comprimento',  'Comprimento' . ':') !!}
                  {!! Form::text('comprimento', null, ['class' => 'form-control', 'placeholder' => 'Comprimento', 'data-mask="000000,00"', 'data-mask-reverse="true"']); !!}
                </div>
              </div>

              <div class="col-sm-2">
                <div class="form-group">
                  {!! Form::label('valor_ecommerce',  'Valor ecommerce' . ':') !!}
                  {!! Form::text('valor_ecommerce', null, ['class' => 'form-control', 'placeholder' => 'Valor ecommerce', 'data-mask="000000,00"', 'data-mask-reverse="true"']); !!}
                </div>
              </div>

            </div>
          </div>
        </div>
      </div>
      @endif

      @component('components.widget', ['class' => 'box-primary'])
      <div class="row">
        @if(session('business.enable_product_expiry'))

        @if(session('business.expiry_type') == 'add_expiry')
        @php
        $expiry_period = 12;
        $hide = true;
        @endphp
        @else
        @php
        $expiry_period = null;
        $hide = false;
        @endphp
        @endif
        <div class="col-sm-4 @if($hide) hide @endif">
          <div class="form-group">
            <div class="multi-input">
              {!! Form::label('expiry_period', __('product.expires_in') . ':') !!}<br>
              {!! Form::text('expiry_period', !empty($duplicate_product->expiry_period) ? @num_format($duplicate_product->expiry_period) : $expiry_period, ['class' => 'form-control pull-left input_number',
              'placeholder' => __('product.expiry_period'), 'style' => 'width:60%;']); !!}
              {!! Form::select('expiry_period_type', ['months'=>__('product.months'), 'days'=>__('product.days'), '' =>__('product.not_applicable') ], !empty($duplicate_product->expiry_period_type) ? $duplicate_product->expiry_period_type : 'months', ['class' => 'form-control select2 pull-left', 'style' => 'width:40%;', 'id' => 'expiry_period_type']); !!}
            </div>
          </div>
        </div>
        @endif

        <div class="col-sm-4">
          <div class="form-group">
            <br>
            <label>
              {!! Form::checkbox('enable_sr_no', 1, !(empty($duplicate_product)) ? $duplicate_product->enable_sr_no : false, ['class' => 'input-icheck']); !!} <strong>@lang('lang_v1.enable_imei_or_sr_no')</strong>
            </label> @show_tooltip(__('lang_v1.tooltip_sr_no'))
          </div>
        </div>

        <div class="col-sm-4">
          <div class="form-group">
            <br>
            <label>
              {!! Form::checkbox('not_for_selling', 1, !(empty($duplicate_product)) ? $duplicate_product->not_for_selling : false, ['class' => 'input-icheck']); !!} <strong>@lang('lang_v1.not_for_selling')</strong>
            </label> @show_tooltip(__('lang_v1.tooltip_not_for_selling'))
          </div>
        </div>



        <!-- Rack, Row & position number -->
        @if(session('business.enable_racks') || session('business.enable_row') || session('business.enable_position'))
        <div class="col-md-12">
          <h4>@lang('lang_v1.rack_details'):
            @show_tooltip(__('lang_v1.tooltip_rack_details'))
          </h4>
        </div>
        @foreach($business_locations as $id => $location)
        <div class="col-sm-3">
          <div class="form-group">
            {!! Form::label('rack_' . $id,  $location . ':') !!}

            @if(session('business.enable_racks'))
            {!! Form::text('product_racks[' . $id . '][rack]', !empty($rack_details[$id]['rack']) ? $rack_details[$id]['rack'] : null, ['class' => 'form-control', 'id' => 'rack_' . $id,
            'placeholder' => __('lang_v1.rack')]); !!}
            @endif

            @if(session('business.enable_row'))
            {!! Form::text('product_racks[' . $id . '][row]', !empty($rack_details[$id]['row']) ? $rack_details[$id]['row'] : null, ['class' => 'form-control', 'placeholder' => __('lang_v1.row')]); !!}
            @endif

            @if(session('business.enable_position'))
            {!! Form::text('product_racks[' . $id . '][position]', !empty($rack_details[$id]['position']) ? $rack_details[$id]['position'] : null, ['class' => 'form-control', 'placeholder' => __('lang_v1.position')]); !!}
            @endif
          </div>
        </div>
        @endforeach
        @endif


        @php
        $custom_labels = json_decode(session('business.custom_labels'), true);
        $product_custom_field1 = !empty($custom_labels['product']['custom_field_1']) ? $custom_labels['product']['custom_field_1'] : __('lang_v1.product_custom_field1');
        $product_custom_field2 = !empty($custom_labels['product']['custom_field_2']) ? $custom_labels['product']['custom_field_2'] : __('lang_v1.product_custom_field2');
        $product_custom_field3 = !empty($custom_labels['product']['custom_field_3']) ? $custom_labels['product']['custom_field_3'] : __('lang_v1.product_custom_field3');
        $product_custom_field4 = !empty($custom_labels['product']['custom_field_4']) ? $custom_labels['product']['custom_field_4'] : __('lang_v1.product_custom_field4');
        @endphp
        <!--custom fields-->
        <div class="clearfix"></div>
        <div class="col-sm-3">
          <div class="form-group">
            {!! Form::label('product_custom_field1',  $product_custom_field1 . ':') !!}
            {!! Form::text('product_custom_field1', !empty($duplicate_product->product_custom_field1) ? $duplicate_product->product_custom_field1 : null, ['class' => 'form-control', 'placeholder' => $product_custom_field1]); !!}
          </div>
        </div>

        <div class="col-sm-3">
          <div class="form-group">
            {!! Form::label('product_custom_field2',  $product_custom_field2 . ':') !!}
            {!! Form::text('product_custom_field2', !empty($duplicate_product->product_custom_field2) ? $duplicate_product->product_custom_field2 : null, ['class' => 'form-control', 'placeholder' => $product_custom_field2]); !!}
          </div>
        </div>

        <div class="col-sm-3">
          <div class="form-group">
            {!! Form::label('product_custom_field3',  $product_custom_field3 . ':') !!}
            {!! Form::text('product_custom_field3', !empty($duplicate_product->product_custom_field3) ? $duplicate_product->product_custom_field3 : null, ['class' => 'form-control', 'placeholder' => $product_custom_field3]); !!}
          </div>
        </div>

        <div class="col-sm-3">
          <div class="form-group">
            {!! Form::label('product_custom_field4',  $product_custom_field4 . ':') !!}
            {!! Form::text('product_custom_field4', !empty($duplicate_product->product_custom_field4) ? $duplicate_product->product_custom_field4 : null, ['class' => 'form-control', 'placeholder' => $product_custom_field4]); !!}
          </div>
        </div>
        <!--custom fields-->
        <div class="clearfix"></div>
        @include('layouts.partials.module_form_part')
      </div>
      @endcomponent


      @component('components.widget', ['class' => 'box-primary'])
      <div class="row">

        <div class="col-sm-4 @if(!session('business.enable_price_tax')) hide @endif">
          <div class="form-group">
            {!! Form::label('tax', __('product.applicable_tax') . ':') !!}
            {!! Form::select('tax', $taxes, !empty($duplicate_product->tax) ? $duplicate_product->tax : null, ['placeholder' => __('messages.please_select'), 'class' => 'form-control select2'], $tax_attributes); !!}
          </div>
        </div>

        <div class="col-sm-4 @if(!session('business.enable_price_tax')) hide @endif">
          <div class="form-group">
            {!! Form::label('tax_type', __('product.selling_price_tax_type') . ':*') !!}
            {!! Form::select('tax_type', ['inclusive' => __('product.inclusive'), 'exclusive' => __('product.exclusive')], !empty($duplicate_product->tax_type) ? $duplicate_product->tax_type : 'exclusive',
            ['class' => 'form-control select2', 'required']); !!}
          </div>
        </div>

        <div class="col-sm-4" style="visibility: hidden;">
          <div class="form-group">
            {!! Form::label('unidade_venda', 'Unidade de Venda' . ':*') !!}
            {!! Form::select('unidade_venda', $unidadesDeMedida, null, ['class' => 'form-control select2',
            'required', 'data-action' => !empty($duplicate_product) ? 'duplicate' : 'add', 'data-product_id' => !empty($duplicate_product) ? $duplicate_product->id : '0']); !!}
          </div>
        </div>

        <div class="clearfix"></div>

        <div class="col-sm-2">
          <div class="form-group">
            {!! Form::label('type', __('product.product_type') . ':*') !!} @show_tooltip(__('tooltip.product_type'))
            {!! Form::select('type', $product_types, !empty($duplicate_product->type) ? $duplicate_product->type : null, ['class' => 'form-control select2',
            'required', 'data-action' => !empty($duplicate_product) ? 'duplicate' : 'add', 'data-product_id' => !empty($duplicate_product) ? $duplicate_product->id : '0']); !!}
          </div>
        </div>

        <div class="col-sm-2">
          <div class="form-group">
            <label for="product_custom_field2">%ICMS:</label>
            <input required value="{{$business->perc_icms_padrao}}" class="form-control" placeholder="%ICMS" data-mask="00.00" name="perc_icms" data-mask-reverse="true" type="text" id="perc_icms">
          </div>
        </div>

        <div class="col-sm-2">
          <div class="form-group">
            <label for="product_custom_field2">%PIS:</label>
            <input required value="{{$business->perc_pis_padrao}}" class="form-control" placeholder="%PIS" data-mask="00.00" name="perc_pis" data-mask-reverse="true" type="text" id="perc_pis">
          </div>
        </div>
        <div class="col-sm-2">
          <div class="form-group">
            <label for="product_custom_field2">%COFINS:</label>
            <input required value="{{$business->perc_cofins_padrao}}" class="form-control" placeholder="%COFINS" data-mask="00.00" data-mask-reverse="true" name="perc_cofins" type="text" id="perc_cofins">
          </div>
        </div>
        <div class="col-sm-2">
          <div class="form-group">
            <label for="product_custom_field2">%IPI:</label>
            <input required value="{{$business->perc_ipi_padrao}}" class="form-control" placeholder="%IPI" data-mask="00.00" data-mask-reverse="true" name="perc_ipi" type="text" id="perc_ipi">
          </div>
        </div>

        <div class="col-sm-2">
          <div class="form-group">
            <label for="product_custom_field2">% Cred. ICMS:</label>
            <input required value="{{$business->pCredSN}}" class="form-control" placeholder="% Cred. ICMS" data-mask="000.00" name="pCredSN" data-mask-reverse="true" type="text" id="pCredSN">
          </div>
        </div>

        <div class="col-sm-6">
          <div class="form-group">
            {!! Form::label('cst_csosn', 'CST/CSOSN' . ':*') !!}
            {!! Form::select('cst_csosn', $listaCSTCSOSN, $business->cst_csosn_padrao, ['class' => 'form-control select2',
            'required', 'data-action' => !empty($duplicate_product) ? 'duplicate' : 'add', 'data-product_id' => !empty($duplicate_product) ? $duplicate_product->id : '0']); !!}
          </div>
        </div>

        <div class="col-sm-6">
          <div class="form-group">
            {!! Form::label('cst_pis', 'CST/PIS' . ':*') !!} 
            {!! Form::select('cst_pis', $listaCST_PIS_COFINS, $business->cst_pis_padrao, ['class' => 'form-control select2',
            'required', 'data-action' => !empty($duplicate_product) ? 'duplicate' : 'add', 'data-product_id' => !empty($duplicate_product) ? $duplicate_product->id : '0']); !!}
          </div>
        </div>

        <div class="col-sm-4">
          <div class="form-group">
            {!! Form::label('cst_cofins', 'CST/COFINS' . ':*') !!} 
            {!! Form::select('cst_cofins', $listaCST_PIS_COFINS, $business->cst_cofins_padrao, ['class' => 'form-control select2',
            'required', 'data-action' => !empty($duplicate_product) ? 'duplicate' : 'add', 'data-product_id' => !empty($duplicate_product) ? $duplicate_product->id : '0']); !!}
          </div>
        </div>

        <div class="col-sm-4">
          <div class="form-group">
            {!! Form::label('cst_ipi', 'CST/IPI' . ':*') !!} 
            {!! Form::select('cst_ipi', $listaCST_IPI, $business->cst_ipi_padrao, ['class' => 'form-control select2',
            'required', 'data-action' => !empty($duplicate_product) ? 'duplicate' : 'add', 'data-product_id' => !empty($duplicate_product) ? $duplicate_product->id : '0']); !!}
          </div>
        </div>

        <div class="col-sm-4">
          <div class="form-group">
            {!! Form::label('cenq_ipi', 'Cód. Enq. IPI' . ':*') !!}
            {!! Form::select('cenq_ipi', $listaCenqIPI, 999, ['class' => 'form-control select2',
            'required', 'data-action' => !empty($duplicate_product) ? 'duplicate' : 'add', 'data-product_id' => !empty($duplicate_product) ? $duplicate_product->id : '0']); !!}
          </div>
        </div>

        <div class="col-sm-2">
          <div class="form-group">
            <label for="product_custom_field2">CFOP Estadual*:</label>
            <input required value="{{$business->cfop_saida_estadual_padrao}}" required class="form-control" data-mask="0000" placeholder="CFOP Estadual" name="cfop_interno" type="number" id="cfop_interno">
          </div>
        </div>

        <div class="col-sm-2">
          <div class="form-group">
            <label for="product_custom_field2">CFOP Inter estadual*:</label>
            <input required value="{{$business->cfop_saida_inter_estadual_padrao}}" required class="form-control" data-mask="0000" placeholder="CFOP Inter estadual" name="cfop_externo" type="number" id="cfop_externo">
          </div>
        </div>

        <div class="col-sm-2">
          <div class="form-group">
            <label for="product_custom_field2">NCM*:</label>
            <input required value="{{$business->ncm_padrao}}" required class="form-control" data-mask="0000.00.00" placeholder="NCM" name="ncm" type="text" id="ncm">
          </div>
        </div>

        <div class="col-sm-2">
          <div class="form-group">
            <label for="product_custom_field2">CEST:</label>
            <input class="form-control" placeholder="CEST" name="cest" type="number" id="cest">
          </div>
        </div>

        <div class="col-sm-4">
          <div class="form-group">
            {!! Form::label('origem', 'Origem' . ':') !!}
            {!! Form::select('origem', App\Models\Product::listaOrigem(), '', ['class' => 'form-control select2']); !!}
          </div>
        </div>

        <div class="clearfix"></div>

        <div class="col-sm-2">
          <div class="form-group">
            {!! Form::label('derivado_petroleo', 'Derivado do petróleo' . ':') !!}
            {!! Form::select('derivado_petroleo', ['0' => 'Não', '1' => 'Sim'] , 0, ['class' => 'form-control']); !!}
          </div>
        </div>
        <div class="div-petroleo" style="display: none">

          <div class="col-sm-5">
            <div class="form-group">
              {!! Form::label('codigo_anp', 'ANP' . ':') !!}
              {!! Form::select('codigo_anp', App\Models\Product::lista_ANP(), '', ['class' => 'form-control select2 w-100', 'style' => 'width: 100%']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('perc_glp', '% GLP' . ':') !!}
              {!! Form::text('perc_glp', '', ['class' => 'form-control',
              'placeholder' => '% GLP', 'data-mask="000.00"', 'data-mask-reverse="true"']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('perc_gnn', '% GNn' . ':') !!}
              {!! Form::text('perc_gnn', '', ['class' => 'form-control',
              'placeholder' => '% GNn', 'data-mask="000.00"', 'data-mask-reverse="true"']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('perc_gni', '% GNi' . ':') !!}
              {!! Form::text('perc_gni', '', ['class' => 'form-control',
              'placeholder' => '% GNi', 'data-mask="000.00"', 'data-mask-reverse="true"']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('valor_partida', 'Valor partida' . ':') !!}
              {!! Form::text('valor_partida', '', ['class' => 'form-control',
              'placeholder' => 'Valor partida', 'data-mask="000000.00"', 'data-mask-reverse="true"']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('unidade_tributavel', 'Un. tributável' . ':') !!}
              {!! Form::text('unidade_tributavel', '', ['class' => 'form-control',
              'placeholder' => 'Un. tributável', 'data-mask="AAAA"', 'data-mask-reverse="true"']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('quantidade_tributavel', 'Qtd. tributável' . ':') !!}
              {!! Form::text('quantidade_tributavel', '', ['class' => 'form-control',
              'placeholder' => 'Qtd. tributável']); !!}
            </div>
          </div>

          <div class="col-sm-3">
            <div class="form-group">
              {!! Form::label('adRemICMSRet', 'Alíquota ad rem do imposto retido' . ':') !!}
              {!! Form::text('adRemICMSRet', '', ['class' => 'form-control',
              'placeholder' => 'Alíquota ad rem do imposto retido', 'data-mask="00,0000"', 'data-mask-reverse="true"']); !!}
            </div>
          </div>

          <div class="col-sm-3">
            <div class="form-group">
              {!! Form::label('pBio', 'Indice de mistura do Biodiesel' . ':') !!}
              {!! Form::text('pBio', '', ['class' => 'form-control',
              'placeholder' => 'Indice de mistura do Biodiesel', 'data-mask="00,0000"', 'data-mask-reverse="true"']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('pOrig', '% de origem' . ':') !!}
              {!! Form::text('pOrig', '100', ['class' => 'form-control',
              'placeholder' => '% de origem', 'data-mask="000,00"', 'data-mask-reverse="true"']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('indImport', 'Indicador de importação' . ':') !!}
              {!! Form::select('indImport', ['0' => 'Não', '1' => 'Sim'] , '0', ['class' => 'form-control']); !!}
            </div>
          </div>

          <div class="col-sm-3">
            <div class="form-group">
              {!! Form::label('cUFOrig', 'UF de origem do produtor ou do importador' . ':') !!}
              {!! Form::select('cUFOrig', App\Models\Product::getUFs() , App\Models\Business::getcUF($business->cidade->uf), ['class' => 'form-control select2', 'style' => 'width: 100%']); !!}
            </div>
          </div>
        </div>

        <div class="clearfix"></div>

        <div class="col-sm-2">
          <div class="form-group">
            {!! Form::label('tipo', 'Tipo' . ':') !!}
            {!! Form::select('tipo', ['normal' => 'Normal', 'veiculo' => 'Veiculo'] , '', ['class' => 'form-control select2']); !!}
          </div>
        </div>

        <div class="col-sm-2">
          <div class="form-group">
            {!! Form::label('perc_icms_interestadual', '%ICMS interestadual' . ':') !!}
            {!! Form::tel('perc_icms_interestadual', '', ['class' => 'form-control',
            'placeholder' => '%ICMS interestadual', 'data-mask="000.00"', 'data-mask-reverse="true"']); !!}
          </div>
        </div>

        <div class="col-sm-2">
          <div class="form-group">
            {!! Form::label('perc_icms_interno', '%ICMS interno' . ':') !!}
            {!! Form::tel('perc_icms_interno', '', ['class' => 'form-control',
            'placeholder' => '%ICMS interno', 'data-mask="000.00"', 'data-mask-reverse="true"']); !!}
          </div>
        </div>

        <div class="col-sm-2">
          <div class="form-group">
            {!! Form::label('perc_fcp_interestadual', '%FCP interestadual' . ':') !!}
            {!! Form::tel('perc_fcp_interestadual', '', ['class' => 'form-control',
            'placeholder' => '%FCP interestadual', 'data-mask="000.00"', 'data-mask-reverse="true"']); !!}
          </div>
        </div>

        <div class="col-sm-3">
          <div class="form-group">
            {!! Form::label('modBC', 'Modalidade Det.' . ':') !!}
            {!! Form::select('modBC', App\Models\Product::modalidadesDeterminacao(), '', ['class' => 'form-control select2']); !!}
          </div>
        </div>

        <div class="col-sm-3">
          <div class="form-group">
            {!! Form::label('modBCST', 'Modalidade Det. ST' . ':') !!}
            {!! Form::select('modBCST', App\Models\Product::modalidadesDeterminacaoST(), '', ['class' => 'form-control select2']); !!}
          </div>
        </div>

        <div class="col-sm-2">
          <div class="form-group">
            {!! Form::label('pICMSST', '%ICMS ST' . ':') !!}
            {!! Form::tel('pICMSST', '', ['class' => 'form-control',
            'placeholder' => '%ICMS ST', 'data-mask="000.00"', 'data-mask-reverse="true"']); !!}
          </div>
        </div>

        <div class="clearfix"></div>

        <div class="veiculo" style="display: none">
          @component('components.widget', ['class' => 'box-danger'])
          <div class="col-sm-12">
            <h4>Dados Veículo:</h4>
          </div>

          <div class="col-sm-12">
            <div class="form-group">
              {!! Form::label('veicProd', 'Detalhamento de Veículo' . ':') !!}
              {!! Form::text('veicProd', '', ['class' => 'form-control',
              'placeholder' => 'Detalhamento de Veículo']); !!}
            </div>
          </div>

          <div class="col-sm-3">
            <div class="form-group">
              {!! Form::label('tpOp', 'Tipo da operação' . ':') !!}
              {!! Form::select('tpOp', App\Models\Veiculo::tiposOperacao() , '', ['class' => 'form-control select2', 'style' => 'width: 100%']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('chassi', 'Chassi' . ':') !!}
              {!! Form::text('chassi', '', ['class' => 'form-control',
              'placeholder' => 'Chassi']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('cCor', 'Código da cor' . ':') !!}
              {!! Form::text('cCor', '', ['class' => 'form-control',
              'placeholder' => 'Código da cor']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('xCor', 'Descrição da cor' . ':') !!}
              {!! Form::text('xCor', '', ['class' => 'form-control',
              'placeholder' => 'Descrição da cor']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('pot', 'Potência Motor (CV)' . ':') !!}
              {!! Form::text('pot', '', ['class' => 'form-control',
              'placeholder' => 'Potência Motor (CV)']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('cilin', 'Cilindradas' . ':') !!}
              {!! Form::text('cilin', '', ['class' => 'form-control',
              'placeholder' => 'Cilindradas']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('pesoL', 'Peso líquido' . ':') !!}
              {!! Form::text('pesoL', '', ['class' => 'form-control',
              'placeholder' => 'Peso líquido']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('pesoB', 'Peso bruto' . ':') !!}
              {!! Form::text('pesoB', '', ['class' => 'form-control',
              'placeholder' => 'Peso bruto']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('nSerie', 'Nº série' . ':') !!}
              {!! Form::text('nSerie', '', ['class' => 'form-control',
              'placeholder' => 'Nº série']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('tpComb', 'Tipo de combustível' . ':') !!}
              {!! Form::select('tpComb', App\Models\Veiculo::tiposCompustivel() , '', ['class' => 'form-control select2', 'style' => 'width: 100%']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('nMotor', 'Nº motor' . ':') !!}
              {!! Form::text('nMotor', '', ['class' => 'form-control',
              'placeholder' => 'Nº série']); !!}
            </div>
          </div>

          <div class="col-sm-3">
            <div class="form-group">
              {!! Form::label('CMT', 'Capacidade Máxima de Tração' . ':') !!}
              {!! Form::text('CMT', '', ['class' => 'form-control',
              'placeholder' => 'Capacidade Máxima de Tração']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('dist', 'Distância entre eixos' . ':') !!}
              {!! Form::text('dist', '', ['class' => 'form-control',
              'placeholder' => 'Distância entre eixos']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('anoMod', 'Ano Modelo de Fab' . ':') !!}
              {!! Form::text('anoMod', '', ['class' => 'form-control',
              'placeholder' => 'Ano Modelo de Fabricação ']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('anoFab', 'Ano de Fabricação' . ':') !!}
              {!! Form::text('anoFab', '', ['class' => 'form-control',
              'placeholder' => 'Ano de Fabricação ']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('tpPint', 'Tipo de pintura' . ':') !!}
              {!! Form::select('tpPint', App\Models\Veiculo::tiposPintura() , '', ['class' => 'form-control select2', 'style' => 'width: 100%']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('tpVeic', 'Tipo do veiculo' . ':') !!}
              {!! Form::select('tpVeic', App\Models\Veiculo::tipos(), '', ['class' => 'form-control select2', 'style' => 'width: 100%']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('espVeic', 'Espécie' . ':') !!}
              {!! Form::select('espVeic', App\Models\Veiculo::especies(), '', ['class' => 'form-control select2', 'style' => 'width: 100%']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('VIN', 'Condição do VIN' . ':') !!}
              {!! Form::select('VIN', ['R' => 'Remarcado', 'N' => 'Normal'], '', ['class' => 'form-control select2', 'style' => 'width: 100%']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('condVeic', 'Condição do Veículo' . ':') !!}
              {!! Form::select('condVeic', ['1' => 'Acabado', '2' => 'Inacabado', '3' => 'Semiacabado'], '', ['class' => 'form-control select2', 'style' => 'width: 100%']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('cMod', 'Código Marca Modelo' . ':') !!}
              {!! Form::text('cMod', '', ['class' => 'form-control',
              'placeholder' => 'Código Marca Modelo']); !!}
            </div>
          </div>

          <div class="col-sm-2">
            <div class="form-group">
              {!! Form::label('cCorDENATRAN', 'Cor' . ':') !!}
              {!! Form::select('cCorDENATRAN', App\Models\Veiculo::cores(), '', ['class' => 'form-control select2', 'style' => 'width: 100%']); !!}
            </div>
          </div>

          <div class="col-sm-3">
            <div class="form-group">
              {!! Form::label('lota', 'Capacidade de lotação' . ':') !!}
              {!! Form::text('lota', '', ['class' => 'form-control',
              'placeholder' => 'Capacidade de lotação']); !!}
            </div>
          </div>

          <div class="col-sm-3">
            <div class="form-group">
              {!! Form::label('tpRest', 'Tipo de restrição' . ':') !!}
              {!! Form::select('tpRest', App\Models\Veiculo::restricoes(), '', ['class' => 'form-control select2', 'style' => 'width: 100%']); !!}
            </div>
          </div>

          @endcomponent
        </div>

        <div class="form-group col-sm-12" id="product_form_part">
          @include('product.partials.single_product_form_part', ['profit_percent' => $default_profit_percent])
        </div>

        <input type="hidden" id="variation_counter" value="1">
        <input type="hidden" id="default_profit_percent"
        value="{{ $default_profit_percent }}">

      </div>


      @endcomponent
      <div class="row">
        <div class="col-sm-12">
          <input type="hidden" name="submit_type" id="submit_type">
          <div class="text-center">
            <div class="btn-group">
              @if($selling_price_group_count)
              <button type="submit" value="submit_n_add_selling_prices" class="btn btn-warning submit_product_form">@lang('lang_v1.save_n_add_selling_price_group_prices')</button>
              @endif

              @can('product.opening_stock')
              <button id="opening_stock_button" @if(!empty($duplicate_product) && $duplicate_product->enable_stock == 0) disabled @endif type="submit" value="submit_n_add_opening_stock" class="btn bg-purple submit_product_form">@lang('lang_v1.save_n_add_opening_stock')</button>
              @endcan

              <button type="submit" value="save_n_add_another" class="btn bg-maroon submit_product_form">@lang('lang_v1.save_n_add_another')</button>

              <button type="submit" value="submit" class="btn btn-primary submit_product_form">@lang('messages.save')</button>
            </div>

          </div>
        </div>
      </div>
      {!! Form::close() !!}

    </section>
    <!-- /.content -->

    @endsection

    @section('javascript')
    @php $asset_v = env('APP_VERSION'); @endphp
    <script src="{{ asset('js/product.js?v=' . $asset_v) }}"></script>

    <script type="text/javascript">
      $(document).ready(function(){
        isDerivadoPetroleo()

        onScan.attachTo(document, {
                suffixKeyCodes: [13], // enter-key expected at the end of a scan
                reactToPaste: true, // Compatibility to built-in scanners in paste-mode (as opposed to keyboard-mode)
                onScan: function(sCode, iQty) {
                  $('input#sku').val(sCode);
                },
                onScanError: function(oDebug) {
                  console.log(oDebug);
                },
                minLength: 2,
                ignoreIfFocusOn: ['input', '.form-control']
                // onKeyDetect: function(iKeyCode){ // output all potentially relevant key events - great for debugging!
                //     console.log('Pressed: ' + iKeyCode);
                // }
              });
      });


      $('#tipo').change(() => {
        let tipo = $('#tipo').val();
        if(tipo == 'veiculo'){
          $('.veiculo').css('display', 'block')
        }else{
          limpaDadosVeiculo()
        }
      })

      function limpaDadosVeiculo(){
        $('.veiculo').css('display', 'none')
      }

      $('#derivado_petroleo').change(() => {
        isDerivadoPetroleo()
      })

      function isDerivadoPetroleo(){
        let derivado_petroleo = $('#derivado_petroleo').val()
        if(derivado_petroleo == 1){
          $('.div-petroleo').css('display', 'block')
        }else{
          $('.div-petroleo').css('display', 'none')
        }
      }
    </script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.11/jquery.mask.min.js"></script>

    @endsection
