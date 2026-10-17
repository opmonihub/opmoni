<div class="pos-tab-content">
    <div class="row">
        <div class="col-sm-4">
            <div class="form-group">
                {!! Form::label('sku_prefix', 'Prefixo SKU:') !!}
                {!! Form::text('sku_prefix', $business->sku_prefix, ['class' => 'form-control text-uppercase']); !!}
            </div>
        </div>
        
        <div class="col-sm-4">
            {!! Form::label('enable_product_expiry', __( 'product.enable_product_expiry' ) . ':') !!}
            @show_tooltip(__('lang_v1.tooltip_enable_expiry'))

            <div class="input-group">
                <span class="input-group-addon">
                    {!! Form::checkbox('enable_product_expiry', 1, $business->enable_product_expiry ); !!} 
                </span>

                <select class="form-control" id="expiry_type"
                name="expiry_type" 
                @if(!$business->enable_product_expiry) disabled @endif>
                <option value="add_expiry" @if($business->expiry_type == 'add_expiry') selected @endif>
                    {{__('lang_v1.add_expiry')}}
                </option>
                <option value="add_manufacturing" @if($business->expiry_type == 'add_manufacturing') selected @endif>{{__('lang_v1.add_manufacturing_auto_expiry')}}</option>
            </select>
        </div>
    </div>

    <div class="col-sm-4 @if(!$business->enable_product_expiry) hide @endif" id="on_expiry_div">
        <div class="form-group">
            <div class="multi-input">
                {!! Form::label('on_product_expiry', __('lang_v1.on_product_expiry') . ':') !!}
                @show_tooltip(__('lang_v1.tooltip_on_product_expiry'))
                <br>

                {!! Form::select('on_product_expiry',     ['keep_selling'=>__('lang_v1.keep_selling'), 'stop_selling'=>__('lang_v1.stop_selling') ], $business->on_product_expiry, ['class' => 'form-control pull-left', 'style' => 'width:60%;']); !!}

                @php
                $disabled = '';
                if($business->on_product_expiry == 'keep_selling'){
                $disabled = 'disabled';
            }
            @endphp

            {!! Form::number('stop_selling_before', $business->stop_selling_before, ['class' => 'form-control pull-left', 'placeholder' => 'stop n days before', 'style' => 'width:40%;', $disabled, 'required', 'id' => 'stop_selling_before']); !!}
        </div>
    </div>
</div>
</div>

<div class="row">
    <div class="col-sm-4">
        <div class="form-group">
            <div class="checkbox">
              <label>
                {!! Form::checkbox('enable_brand', 1, $business->enable_brand, 
                [ 'class' => 'input-icheck']); !!} {{ __( 'lang_v1.enable_brand' ) }}
            </label>
        </div>
    </div>
</div>

<div class="col-sm-4">
    <div class="form-group">
        <div class="checkbox">
          <label>
            {!! Form::checkbox('enable_category', 1, $business->enable_category, [ 'class' => 'input-icheck', 'id' => 'enable_category']); !!} {{ __( 'lang_v1.enable_category' ) }}
        </label>
    </div>
</div>
</div>

<div class="col-sm-4 enable_sub_category @if($business->enable_category != 1) hide @endif">
    <div class="form-group">
        <div class="checkbox">
          <label>
            {!! Form::checkbox('enable_sub_category', 1, $business->enable_sub_category, [ 'class' => 'input-icheck', 'id' => 'enable_sub_category']); !!} {{ __( 'lang_v1.enable_sub_category' ) }}
        </label>
    </div>
</div>
</div>
</div>

<div class="row">
    <div class="col-sm-4">
        <div class="form-group">
            <div class="checkbox">
              <label>
                {!! Form::checkbox('enable_price_tax', 1, $business->enable_price_tax, [ 'class' => 'input-icheck']); !!} {{ __( 'lang_v1.enable_price_tax' ) }}
            </label>
        </div>
    </div>
</div>

<div class="col-sm-4">
    <div class="form-group">
        {!! Form::label('default_unit', 'Unidade padrão:') !!}
        <div class="input-group">
            <span class="input-group-addon">
                <i class="fa fa-balance-scale"></i>
            </span>
            {!! Form::select('default_unit', $units_dropdown, $business->default_unit, ['class' => 'form-control select2', 'style' => 'width: 100%;' ]); !!}
        </div>
    </div>
</div>

<div class="col-sm-4">
    <div class="form-group">
        <div class="checkbox">
          <label>
            {!! Form::checkbox('enable_sub_units', 1, $business->enable_sub_units, [ 'class' => 'input-icheck']); !!} {{ __( 'lang_v1.enable_sub_units' ) }}
        </label>
        @show_tooltip(__('lang_v1.sub_units_tooltip'))
    </div>
</div>
</div>

<div class="clearfix"></div>

<div class="col-sm-4">
    <div class="form-group">
        <div class="checkbox">
          <label>
            {!! Form::checkbox('enable_racks', 1, $business->enable_racks, [ 'class' => 'input-icheck']); !!} {{ __( 'lang_v1.enable_racks' ) }}
        </label>
        @show_tooltip(__('lang_v1.tooltip_enable_racks'))
    </div>
</div>
</div>

<div class="col-sm-4">
    <div class="form-group">
        <div class="checkbox">
          <label>
            {!! Form::checkbox('enable_row', 1, $business->enable_row, [ 'class' => 'input-icheck']); !!} {{ __( 'lang_v1.enable_row' ) }}
        </label>
    </div>
</div>
</div>



<div class="col-sm-4">
    <div class="form-group">
        <div class="checkbox">
          <label>
            {!! Form::checkbox('enable_position', 1, $business->enable_position, [ 'class' => 'input-icheck']); !!} {{ __( 'lang_v1.enable_position' ) }}
        </label>
    </div>
</div>
</div>

<div class="clearfix"></div>

<div class="col-sm-4">
    <div class="form-group">
        <div class="checkbox">
          <label>
            {!! Form::checkbox('common_settings[enable_product_warranty]', 1, !empty($common_settings['enable_product_warranty']) ? true : false, 
            [ 'class' => 'input-icheck']); !!} {{ __( 'lang_v1.enable_product_warranty' ) }}
        </label>
    </div>
</div>
</div>

<div class="clearfix"></div>


<div class="col-sm-6">
    <div class="form-group">
        {!! Form::label('cst_csosn_padrao', 'CST/CSOSN Padrão' . ':*') !!}
        <div class="input-group">
            <span class="input-group-addon">
                <i class="fa fas fa-circle"></i>
            </span>
            {!! Form::select('cst_csosn_padrao', $listaCSTCSOSN, $business->cst_csosn_padrao, ['class' => 'form-control',
            'required', 'data-action' => !empty($duplicate_product) ? 'duplicate' : 'add', 'data-product_id' => !empty($duplicate_product) ? $duplicate_product->id : '0']); !!}
        </div>
    </div>
</div>

<div class="col-sm-6">
    <div class="form-group">
        {!! Form::label('cst_pis_padrao', 'CST/PIS Padrão' . ':*') !!}
        <div class="input-group">
            <span class="input-group-addon">
                <i class="fa fas fa-circle"></i>
            </span>
            {!! Form::select('cst_pis_padrao', $listaCST_PIS_COFINS, $business->cst_pis_padrao, ['class' => 'form-control',
            'required', 'data-action' => !empty($duplicate_product) ? 'duplicate' : 'add', 'data-product_id' => !empty($duplicate_product) ? $duplicate_product->id : '0']); !!}
        </div>
    </div>
</div>

<div class="col-sm-6">
    <div class="form-group">
        {!! Form::label('cst_cofins_padrao', 'CST/COFINS Padrão' . ':*') !!}
        <div class="input-group">
            <span class="input-group-addon">
                <i class="fa fas fa-circle"></i>
            </span>
            {!! Form::select('cst_cofins_padrao', $listaCST_PIS_COFINS, $business->cst_cofins_padrao, ['class' => 'form-control',
            'required', 'data-action' => !empty($duplicate_product) ? 'duplicate' : 'add', 'data-product_id' => !empty($duplicate_product) ? $duplicate_product->id : '0']); !!}
        </div>
    </div>
</div>

<div class="col-sm-6">
    <div class="form-group">
        {!! Form::label('cst_ipi_padrao', 'CST/IPI Padrão' . ':*') !!}
        <div class="input-group">
            <span class="input-group-addon">
                <i class="fa fas fa-circle"></i>
            </span>
            {!! Form::select('cst_ipi_padrao', $listaCST_IPI, $business->cst_ipi_padrao, ['class' => 'form-control',
            'required', 'data-action' => !empty($duplicate_product) ? 'duplicate' : 'add', 'data-product_id' => !empty($duplicate_product) ? $duplicate_product->id : '0']); !!}
        </div>
    </div>
</div>

<div class="col-sm-3">
    <div class="form-group">
        {!! Form::label('perc_icms_padrao', '%ICMS Padrão' . ':*') !!}
        <div class="">
           {!! Form::text('perc_icms_padrao', $business->perc_icms_padrao, ['class' => 'form-control text-uppercase', 'data-mask="00.00"', 'data-mask-reverse="true"']); !!}
       </div>
   </div>
</div>

<div class="col-sm-3">
    <div class="form-group">
        {!! Form::label('perc_pis_padrao', '%PIS Padrão' . ':*') !!}
        <div class="">
           {!! Form::text('perc_pis_padrao', $business->perc_pis_padrao, ['class' => 'form-control text-uppercase', 'data-mask="00.00"', 'data-mask-reverse="true"']); !!}

       </div>
   </div>
</div>

<div class="col-sm-3">
    <div class="form-group">
        {!! Form::label('perc_cofins_padrao', '%COFINS Padrão' . ':*') !!}
        <div class="">
           {!! Form::text('perc_cofins_padrao', $business->perc_cofins_padrao, ['class' => 'form-control text-uppercase', 'data-mask="00.00"', 'data-mask-reverse="true"']); !!}

       </div>
   </div>
</div>

<div class="col-sm-3">
    <div class="form-group">
        {!! Form::label('perc_ipi_padrao', '%IPI Padrão' . ':*') !!}
        <div class="">
           {!! Form::text('perc_ipi_padrao', $business->perc_ipi_padrao, ['class' => 'form-control text-uppercase', 'data-mask="00.00"', 'data-mask-reverse="true"']); !!}

       </div>
   </div>
</div>

<div class="col-sm-3">
    <div class="form-group">
        {!! Form::label('ncm_padrao', 'NCM Padrão' . ':*') !!}
        <div class="">
           {!! Form::text('ncm_padrao', $business->ncm_padrao, ['class' => 'form-control text-uppercase', 'data-mask="0000.00.00"']); !!}

       </div>
   </div>
</div>

<div class="col-sm-3">
    <div class="form-group">
        {!! Form::label('cfop_saida_estadual_padrao', 'CFOP saida estadual Padrão' . ':*') !!}
        <div class="">
           {!! Form::text('cfop_saida_estadual_padrao', $business->cfop_saida_estadual_padrao, ['class' => 'form-control text-uppercase', 'data-mask="0000"']); !!}

       </div>
   </div>
</div>

<div class="col-sm-3">
    <div class="form-group">
        {!! Form::label('cfop_saida_inter_estadual_padrao', 'CFOP saida inter estadual Padrão' . ':*') !!}
        <div class="">
           {!! Form::text('cfop_saida_inter_estadual_padrao', $business->cfop_saida_inter_estadual_padrao, ['class' => 'form-control text-uppercase', 'data-mask="0000"']); !!}

       </div>
   </div>
</div>

<div class="col-sm-3">
    <div class="form-group">
        {!! Form::label('pCredSN', '% Cred. ICMS' . ':') !!}
        <div class="">
           {!! Form::text('pCredSN', $business->pCredSN, ['class' => 'form-control text-uppercase', 'data-mask="000.00"', 'data-mask-reverse="true"']); !!}

       </div>
   </div>
</div>

<div class="col-sm-3">
    <div class="form-group">
        {!! Form::label('exclusao_icms_pis_cofins', 'Exclusão de ICMS pra PIS e COFINS' . ':') !!}
        <div class="">
           {!! Form::select('exclusao_icms_pis_cofins', [0 => 'Não', 1 => 'Sim'], $business->exclusao_icms_pis_cofins, ['class' => 'form-control', 'data-mask="000.00"', 'data-mask-reverse="true"']); !!}

       </div>
   </div>
</div>


</div>
</div>