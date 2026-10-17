<div class="modal-dialog modal-xl" role="document">
  <div class="modal-content">

    @php

    if(isset($update_action)) {
      $url = $update_action;
      $customer_groups = [];
      $opening_balance = 0;
      $lead_users = $contact->leadUsers->pluck('id');
    } else {
      $url = action('ContactController@update', [$contact->id]);
      $sources = [];
      $life_stages = [];
      $users = [];
      $lead_users = [];
    }
    @endphp

    {!! Form::open(['url' => $url, 'method' => 'PUT', 'id' => 'contact_edit_form']) !!}

    <div class="modal-header">
      <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      <h4 class="modal-title">Editar</h4>
    </div>

    <div class="modal-body">

      <div class="row">

        <div class="col-md-6">
          <div class="form-group">
            {!! Form::label('type', __('contact.contact_type') . ':*' ) !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-user"></i>
              </span>
              {!! Form::select('type', $types, $contact->type, ['class' => 'form-control', 'id' => 'contact_type','placeholder' => __('messages.please_select'), 'required']); !!}
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="form-group">
            {!! Form::label('name', 'Razão social' . ':*') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-user"></i>
              </span>
              {!! Form::text('name', $contact->name, ['class' => 'form-control','placeholder' => 'Razão social', 'required']); !!}
            </div>
          </div>
        </div>
        <div class="clearfix"></div>
        <div class="col-md-4">
          <div class="form-group">
            {!! Form::label('supplier_business_name', __('business.business_name') . ':') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-briefcase"></i>
              </span>
              {!! Form::text('supplier_business_name',
              $contact->supplier_business_name, ['class' => 'form-control', 'placeholder' => __('business.business_name')]); !!}
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="form-group">
            {!! Form::label('contact_id', __('lang_v1.contact_id') . ':') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-id-badge"></i>
              </span>
              <input type="hidden" id="hidden_id" value="{{$contact->id}}">
              {!! Form::text('contact_id', $contact->contact_id, ['class' => 'form-control','placeholder' => __('lang_v1.contact_id')]); !!}
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="form-group">
            {!! Form::label('tax_number', __('contact.tax_no') . ':') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-info"></i>
              </span>
              {!! Form::text('tax_number', $contact->tax_number, ['class' => 'form-control', 'placeholder' => __('contact.tax_no')]); !!}
            </div>
          </div>
        </div>

        <!-- lead additional field -->
        <div class="col-md-4 lead_additional_div">
          <div class="form-group">
            {!! Form::label('crm_source', __('lang_v1.source') . ':' ) !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fas fa fa-search"></i>
              </span>
              {!! Form::select('crm_source', $sources, $contact->crm_source , ['class' => 'form-control', 'id' => 'crm_source','placeholder' => __('messages.please_select')]); !!}
            </div>
          </div>
        </div>
        <div class="col-md-4 lead_additional_div">
          <div class="form-group">
            {!! Form::label('crm_life_stage', __('lang_v1.life_stage') . ':' ) !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fas fa fa-life-ring"></i>
              </span>
              {!! Form::select('crm_life_stage', $life_stages, $contact->crm_life_stage , ['class' => 'form-control', 'id' => 'crm_life_stage','placeholder' => __('messages.please_select')]); !!}
            </div>
          </div>
        </div>
        <div class="col-md-6 lead_additional_div">
          <div class="form-group">
            {!! Form::label('user_id', __('lang_v1.assigned_to') . ':*' ) !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-user"></i>
              </span>
              {!! Form::select('user_id[]', $users, $lead_users , ['class' => 'form-control select2', 'id' => 'user_id', 'multiple', 'required', 'style' => 'width: 100%;']); !!}
            </div>
          </div>
        </div>
        <div class="col-md-4 opening_balance">
          <div class="form-group">
            {!! Form::label('opening_balance', __('lang_v1.opening_balance') . ':') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fas fa-money-bill-alt"></i>
              </span>
              {!! Form::text('opening_balance', $opening_balance, ['class' => 'form-control input_number']); !!}
            </div>
          </div>
        </div>

        <div class="col-md-4 pay_term">
          <div class="form-group">
            <div class="multi-input">
              {!! Form::label('pay_term_number', __('contact.pay_term') . ':') !!} @show_tooltip(__('tooltip.pay_term'))
              <br/>
              {!! Form::number('pay_term_number', $contact->pay_term_number, ['class' => 'form-control width-40 pull-left', 'placeholder' => __('contact.pay_term')]); !!}

              {!! Form::select('pay_term_type', ['months' => __('lang_v1.months'), 'days' => __('lang_v1.days')], $contact->pay_term_type, ['class' => 'form-control width-60 pull-left','placeholder' => __('messages.please_select')]); !!}
            </div>
          </div>
        </div>

        <div class="col-md-4 customer_fields">
          <div class="form-group">
            {!! Form::label('customer_group_id', __('lang_v1.customer_group') . ':') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-users"></i>
              </span>
              {!! Form::select('customer_group_id', $customer_groups, $contact->customer_group_id, ['class' => 'form-control']); !!}
            </div>
          </div>
        </div>

        <div class="col-md-2">
          <div class="form-group">
            {!! Form::label('tipo', 'Tipo' . ':') !!}
            <div class="input-group" style="width: 100%;">

              {!! Form::select('tipo', ['j' => 'Juridica', 'f' => 'Fisica'], $type, ['class' => 'form-control']); !!}
            </div>
          </div>
        </div>

        <div class="col-md-4">
          <div class="form-group">
            <label for="product_custom_field2">CNPJ/CPF:</label>
            <input class="form-control" value="{{$contact->cpf_cnpj}}" placeholder="CPF/CNPJ" name="cpf_cnpj" type="text" id="cpf_cnpj">
          </div>
        </div>

        <div class="col-md-4 ">
          <div class="form-group">
            <label for="product_custom_field2">INS.ESTADUAL:</label>
            <input class="form-control" value="{{$contact->ie_rg}}" placeholder="I.E/RG" name="ie_rg" id="ie_rg">
          </div>
        </div>

        <div class="col-md-6">
          <div class="form-group">
            {!! Form::label('city_id', 'Cidade:*') !!}
            {!! Form::select('city_id', $cities, $contact->city_id, ['class' => 'form-control select2', 'required']); !!}
          </div>
        </div>

        <div class="col-md-2">
          <div class="form-group">

            {!! Form::label('consumidor_final', 'Consumidor final' . ':') !!}
            {!! Form::select('consumidor_final', ['1' => 'Sim', '0' => 'Não'], $contact->consumidor_final, ['class' => 'form-control select2', 'required']); !!}
          </div>
        </div>

        <div class="col-md-2">
          <div class="form-group">

            {!! Form::label('contribuinte', 'Contribuinte' . ':') !!}
            {!! Form::select('contribuinte', ['1' => 'Sim', '0' => 'Não'], $contact->contribuinte, ['class' => 'form-control select2', 'required']); !!}
          </div>
        </div>

        <div class="col-md-4 customer_fields">
          <div class="form-group">
            {!! Form::label('cod_pais', 'Pais:') !!}
            {!! Form::select('cod_pais', $paises, $contact->cod_pais, ['id' => 'cod_pais', 'class' => 'form-control select2', 'required']); !!}
          </div>
        </div>

        <div class="col-md-4 customer_fields">
          <div class="form-group">

            {!! Form::label('id_estrangeiro', 'ID Estrangeiro' . ':') !!}
            {!! Form::text('id_estrangeiro', $contact->id_estrangeiro, ['class' => 'form-control', 'placeholder' => 'ID Estrangeiro']); !!}
          </div>
        </div>

        <div class="col-md-4 customer_fields">
          <div class="form-group">
            {!! Form::label('credit_limit', __('lang_v1.credit_limit') . ':') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fas fa-money-bill-alt"></i>
              </span>
              {!! Form::text('credit_limit', @num_format($contact->credit_limit), ['class' => 'form-control input_number']); !!}
            </div>
            <p class="help-block">@lang('lang_v1.credit_limit_help')</p>
          </div>
        </div>

        <div class="col-md-12">
          <hr/>
        </div>

        <div class="col-md-4 ">
          <div class="form-group">
            <label for="product_custom_field2">Rua*:</label>
            <input class="form-control" value="{{$contact->rua}}" required placeholder="Rua" name="rua" type="text" id="rua">
          </div>
        </div>
        <div class="col-md-2 ">
          <div class="form-group">
            <label for="product_custom_field2">Nº*:</label>
            <input class="form-control" value="{{$contact->numero}}" required placeholder="Nº" name="numero" type="text" id="numero">
          </div>
        </div>

        <div class="col-md-3">
          <div class="form-group">
            {!! Form::label('complement', 'Complemento:') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-map-marker"></i>
              </span>
              {!! Form::text('complement', $contact->complement, ['class' => 'form-control',
              'placeholder' => 'Complemento']); !!}
            </div>
          </div>
        </div>

        <div class="col-md-3 ">
          <div class="form-group">
            <label for="product_custom_field2">Bairro*:</label>
            <input class="form-control" value="{{$contact->bairro}}" required placeholder="Bairro" name="bairro" type="text" id="bairro">
          </div>
        </div>

        <div class="col-md-2 ">
          <div class="form-group">
            <label for="product_custom_field2">CEP*:</label>
            <input class="form-control" value="{{$contact->cep}}" required placeholder="CEP" name="cep" type="text" id="cep">
          </div>
        </div>

        <div class="col-md-6">
          <div class="form-group">
            {!! Form::label('email', __('business.email') . ':') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-envelope"></i>
              </span>
              {!! Form::text('email', $contact->email, ['class' => 'form-control','placeholder' => __('business.email')]); !!}
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-group">
            {!! Form::label('mobile', 'Celular' . ':') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-mobile"></i>
              </span>
              {!! Form::text('mobile', $contact->mobile, ['class' => 'form-control', 'placeholder' => 'Celular']); !!}
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-group">
            {!! Form::label('alternate_number', 'Telefone alternativo' . ':') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-phone"></i>
              </span>
              {!! Form::text('alternate_number', $contact->alternate_number, ['class' => 'form-control', 'placeholder' => __('contact.alternate_contact_number')]); !!}
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-group">
            {!! Form::label('landline', 'Fixo:') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-phone"></i>
              </span>
              {!! Form::text('landline', $contact->landline, ['class' => 'form-control', 'placeholder' => 'Fixo']); !!}
            </div>
          </div>
        </div>

        <div class="col-md-3" style="display: none">
          <div class="form-group">
            {!! Form::label('city', __('business.city') . ':') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-map-marker"></i>
              </span>
              {!! Form::text('city', $contact->city, ['class' => 'form-control', 'placeholder' => __('business.city')]); !!}
            </div>
          </div>
        </div>
        <div class="col-md-3" style="display: none">
          <div class="form-group">
            {!! Form::label('state', __('business.state') . ':') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-map-marker"></i>
              </span>
              {!! Form::text('state', $contact->state, ['class' => 'form-control', 'placeholder' => __('business.state')]); !!}
            </div>
          </div>
        </div>
        <div class="col-md-3" style="display: none">
          <div class="form-group">
            {!! Form::label('country', __('business.country') . ':') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-globe"></i>
              </span>
              {!! Form::text('country', $contact->country, ['class' => 'form-control', 'placeholder' => __('business.country')]); !!}
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-group">
            {!! Form::label('landmark', __('business.landmark') . ':') !!}
            <div class="input-group">
              <span class="input-group-addon">
                <i class="fa fa-map-marker"></i>
              </span>
              {!! Form::text('landmark', $contact->landmark, ['class' => 'form-control', 'placeholder' => __('business.landmark')]); !!}
            </div>
          </div>
        </div>
        <div class="clearfix"></div>
        <div class="col-md-12">
          <hr/>
        </div>
        @php
        $custom_labels = json_decode(session('business.custom_labels'), true);
        $contact_custom_field1 = !empty($custom_labels['contact']['custom_field_1']) ? $custom_labels['contact']['custom_field_1'] : __('lang_v1.contact_custom_field1');
        $contact_custom_field2 = !empty($custom_labels['contact']['custom_field_2']) ? $custom_labels['contact']['custom_field_2'] : __('lang_v1.contact_custom_field2');
        $contact_custom_field3 = !empty($custom_labels['contact']['custom_field_3']) ? $custom_labels['contact']['custom_field_3'] : __('lang_v1.contact_custom_field3');
        $contact_custom_field4 = !empty($custom_labels['contact']['custom_field_4']) ? $custom_labels['contact']['custom_field_4'] : __('lang_v1.contact_custom_field4');
        @endphp
        <div class="col-md-3">
          <div class="form-group">
            {!! Form::label('custom_field1', $contact_custom_field1 . ':') !!}
            {!! Form::text('custom_field1', $contact->custom_field1, ['class' => 'form-control',
            'placeholder' => $contact_custom_field1]); !!}
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-group">
            {!! Form::label('custom_field2', $contact_custom_field2 . ':') !!}
            {!! Form::text('custom_field2', $contact->custom_field2, ['class' => 'form-control',
            'placeholder' => $contact_custom_field2]); !!}
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-group">
            {!! Form::label('custom_field3', $contact_custom_field3 . ':') !!}
            {!! Form::text('custom_field3', $contact->custom_field3, ['class' => 'form-control',
            'placeholder' => $contact_custom_field3]); !!}
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-group">
            {!! Form::label('custom_field4', $contact_custom_field4 . ':') !!}
            {!! Form::text('custom_field4', $contact->custom_field4, ['class' => 'form-control',
            'placeholder' => $contact_custom_field4]); !!}
          </div>
        </div>
        <div class="clearfix"></div>
        <div class="col-md-12"><hr></div>

        <div class="col-md-12">
          <h5>Endereço de entrega</h5>
        </div>

        <div class="col-md-2">
          <div class="form-group">
            <label for="product_custom_field2">CEP:</label>
            <input class="form-control  featured-field" value="{{$contact->cep_entrega}}" placeholder="CEP" name="cep_entrega" data-mask="00000-000" type="text" id="cep_entrega">
          </div>
        </div>

        <div class="col-md-4">
          <div class="form-group">
            <label for="product_custom_field2">Rua:</label>
            <input class="form-control featured-field" value="{{$contact->rua_entrega}}" placeholder="Rua" name="rua_entrega" type="text" id="rua_entrega">
          </div>
        </div>

        <div class="col-md-2">
          <div class="form-group">
            <label for="product_custom_field2">Nº:</label>
            <input class="form-control featured-field" value="{{$contact->numero_entrega}}" placeholder="Nº" name="numero_entrega" type="text" id="numero_entrega">
          </div>
        </div>

        <div class="col-md-3">
          <div class="form-group">
            <label for="product_custom_field2">Bairro:</label>
            <input class="form-control featured-field" value="{{$contact->bairro_entrega}}" placeholder="Bairro" name="bairro_entrega" type="text" id="bairro_entrega">
          </div>
        </div>

        <div class="col-md-4">
          <div class="form-group">
            {!! Form::label('city_id_entrega', 'Cidade:') !!}
            {!! Form::select('city_id_entrega', $cities, $contact->city_id_entrega, ['id' => 'cidade_entrega', 'class' => 'form-control select2 featured-field']); !!}
          </div>
        </div>
    <!-- <div class="col-md-8 col-md-offset-2" >
      <strong>{{__('lang_v1.shipping_address')}}</strong><br>
      {!! Form::text('shipping_address', $contact->shipping_address, ['class' => 'form-control',
      'placeholder' => __('lang_v1.search_address'), 'id' => 'shipping_address']); !!}
      <div id="map"></div>
    </div> -->
    {!! Form::hidden('position', $contact->position, ['id' => 'position']); !!}

  </div>

</div>

<div class="modal-footer">
  <button type="submit" class="btn btn-primary">@lang( 'messages.update' )</button>
  <button type="button" class="btn btn-default" data-dismiss="modal">@lang( 'messages.close' )</button>
</div>

{!! Form::close() !!}

</div><!-- /.modal-content -->
</div><!-- /.modal-dialog -->
