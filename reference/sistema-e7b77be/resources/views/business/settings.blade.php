@extends('layouts.app')
@section('title', __('business.business_settings'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>@lang('business.business_settings')</h1>
    <br>
    @include('layouts.partials.search_settings')
</section>

<!-- Main content -->
<section class="content">
    {!! Form::open(['url' => action('BusinessController@postBusinessSettings'), 'method' => 'post', 'id' => 'bussiness_edit_form',
    'files' => true ]) !!}
    <div class="row">
        <div class="col-xs-12">
           <!--  <pos-tab-container> -->
            <div class="col-xs-12 pos-tab-container">
                <div class="col-lg-2 col-md-2 col-sm-2 col-xs-2 pos-tab-menu">
                    <div class="list-group">
                        <a href="#" class="list-group-item text-center active">@lang('business.business')</a>
                        <a href="#" class="list-group-item text-center">Taxas @show_tooltip(__('tooltip.business_tax'))</a>
                        <a href="#" class="list-group-item text-center">@lang('business.product')</a>
                        <a href="#" class="list-group-item text-center">@lang('business.sale')</a>
                        <a href="#" class="list-group-item text-center">PDV</a>
                        <a href="#" class="list-group-item text-center">@lang('purchase.purchases')</a>
                        <a href="#" class="list-group-item text-center">Outros</a>
                        <a href="#" class="list-group-item text-center">Sistema</a>
                        <a href="#" class="list-group-item text-center">@lang('lang_v1.prefixes')</a>
                        <a href="#" class="list-group-item text-center">@lang('lang_v1.email_settings')</a>
                        <a href="#" class="list-group-item text-center">@lang('lang_v1.sms_settings')</a>
                        <a href="#" class="list-group-item text-center">@lang('lang_v1.reward_point_settings')</a>
                        <a href="#" class="list-group-item text-center">@lang('lang_v1.modules')</a>
                        <a href="#" class="list-group-item text-center">@lang('lang_v1.custom_labels')</a>
                    </div>
                </div>
                <div class="col-lg-10 col-md-10 col-sm-10 col-xs-10 pos-tab">
                    <!-- tab 1 start -->
                    @include('business.partials.settings_business')
                    <!-- tab 1 end -->
                    <!-- tab 2 start -->
                    @include('business.partials.settings_tax')
                    <!-- tab 2 end -->
                    <!-- tab 3 start -->
                    @include('business.partials.settings_product')
                    <!-- tab 3 end -->
                    <!-- tab 4 start -->
                    @include('business.partials.settings_sales')
                    @include('business.partials.settings_pos')
                    <!-- tab 4 end -->
                    <!-- tab 5 start -->
                    @include('business.partials.settings_purchase')
                    <!-- tab 5 end -->
                    <!-- tab 6 start -->
                    @include('business.partials.settings_dashboard')
                    <!-- tab 6 end -->
                    <!-- tab 7 start -->
                    @include('business.partials.settings_system')
                    <!-- tab 7 end -->
                    <!-- tab 8 start -->
                    @include('business.partials.settings_prefixes')
                    <!-- tab 8 end -->
                    <!-- tab 9 start -->
                    @include('business.partials.settings_email')
                    <!-- tab 9 end -->
                    <!-- tab 10 start -->
                    @include('business.partials.settings_sms')
                    <!-- tab 10 end -->
                    <!-- tab 11 start -->
                    @include('business.partials.settings_reward_point')
                    <!-- tab 11 end -->
                    <!-- tab 12 start -->
                    @include('business.partials.settings_modules')
                    <!-- tab 12 end -->
                    @include('business.partials.settings_custom_labels')
                </div>
            </div>
            <!--  </pos-tab-container> -->
        </div>
    </div>

    <div class="row">
        <div class="col-sm-12">
            <button class="btn btn-danger pull-right" type="submit">@lang('business.update_settings')</button>

        </div>
    </div>
    {!! Form::close() !!}
</section>
<!-- /.content -->
@stop
@section('javascript')
<script type="text/javascript">
    $(document).on('ifToggled', '#use_superadmin_settings', function() {
        if ($('#use_superadmin_settings').is(':checked')) {
            $('#toggle_visibility').addClass('hide');
            $('.test_email_btn').addClass('hide');
        } else {
            $('#toggle_visibility').removeClass('hide');
            $('.test_email_btn').removeClass('hide');
        }
    });

    $('#test_email_btn').click( function() {
        var data = {
            mail_driver: $('#mail_driver').val(),
            mail_host: $('#mail_host').val(),
            mail_port: $('#mail_port').val(),
            mail_username: $('#mail_username').val(),
            mail_password: $('#mail_password').val(),
            mail_encryption: $('#mail_encryption').val(),
            mail_from_address: $('#mail_from_address').val(),
            mail_from_name: $('#mail_from_name').val(),
        };
        $.ajax({
            method: 'post',
            data: data,
            url: "{{ action('BusinessController@testEmailConfiguration') }}",
            dataType: 'json',
            success: function(result) {
                console.log(result)
                if (result.success == true) {
                    swal({
                        text: result.msg,
                        icon: 'success'
                    });
                } else {
                    swal({
                        text: result.msg,
                        icon: 'error'
                    });
                }
            },
        });
    });

    $('#test_sms_btn').click( function() {
        var test_number = $('#test_number').val();
        if (test_number.trim() == '') {
            toastr.error('{{__("lang_v1.test_number_is_required")}}');
            $('#test_number').focus();

            return false;
        }

        var data = {
            url: $('#sms_settings_url').val(),
            send_to_param_name: $('#send_to_param_name').val(),
            msg_param_name: $('#msg_param_name').val(),
            request_method: $('#request_method').val(),
            param_1: $('#sms_settings_param_key1').val(),
            param_2: $('#sms_settings_param_key2').val(),
            param_3: $('#sms_settings_param_key3').val(),
            param_4: $('#sms_settings_param_key4').val(),
            param_5: $('#sms_settings_param_key5').val(),
            param_6: $('#sms_settings_param_key6').val(),
            param_7: $('#sms_settings_param_key7').val(),
            param_8: $('#sms_settings_param_key8').val(),
            param_9: $('#sms_settings_param_key9').val(),
            param_10: $('#sms_settings_param_key10').val(),

            param_val_1: $('#sms_settings_param_val1').val(),
            param_val_2: $('#sms_settings_param_val2').val(),
            param_val_3: $('#sms_settings_param_val3').val(),
            param_val_4: $('#sms_settings_param_val4').val(),
            param_val_5: $('#sms_settings_param_val5').val(),
            param_val_6: $('#sms_settings_param_val6').val(),
            param_val_7: $('#sms_settings_param_val7').val(),
            param_val_8: $('#sms_settings_param_val8').val(),
            param_val_9: $('#sms_settings_param_val9').val(),
            param_val_10: $('#sms_settings_param_val10').val(),
            test_number: test_number
        };

        $.ajax({
            method: 'post',
            data: data,
            url: "{{ action('BusinessController@testSmsConfiguration') }}",
            dataType: 'json',
            success: function(result) {
                if (result.success == true) {
                    swal({
                        text: result.msg,
                        icon: 'success'
                    });
                } else {
                    swal({
                        text: result.msg,
                        icon: 'error'
                    });
                }
            },
        });
    });

    $('.cpf_cnpj').blur((event) => {
        let cnpj = $('#cnpj').val().replace(/[^0-9]/g,'')
        if(cnpj.length == 14){

            fetch('https://publica.cnpj.ws/cnpj/' + cnpj)
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => { throw err; });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data != null) {

                        let ie = ''
                        if (data.estabelecimento.inscricoes_estaduais.length > 0) {
                            ie = data.estabelecimento.inscricoes_estaduais[0].inscricao_estadual
                            if(data.estabelecimento.inscricoes_estaduais[0].ativo == false){
                                swal({
                                    icon: 'warning',
                                    title: 'ALERTA!',
                                    text: 'Inscrição Estadual não habilitada ou irregular para NFe.',
                                })                
                            }
                        }

                        $('#ie').val(ie)
                        $('#name').val(data.estabelecimento.nome_fantasia)
                        $('#razao_social').val(data.razao_social)
                        $("#rua").val(data.estabelecimento.tipo_logradouro + " " + data.estabelecimento.logradouro)
                        $('#numero').val(data.estabelecimento.numero)
                        $("#bairro").val(data.estabelecimento.bairro);

                        var cep = data.estabelecimento.cep.replace(/[^\d]+/g, '');

                        $('#cep').val(cep.substring(0, 5) + '-' + cep.substring(5, 9))

                        var $option = $("<option selected></option>").val(data.estabelecimento.cidade.id).text(data.estabelecimento.cidade.nome + " (" + data.estabelecimento.estado.sigla + ")");

                        $('#cidade_id').append($option).trigger('change');

                    }
                })
                .catch(err => {
                    let msg = err?.detalhes || 'Erro ao consultar CNPJ';
                    swal('Algo deu errado', msg, 'warning')
                })
        }
    })
</script>

<script type="text/javascript">

  $('#tipo').change((val) => {

    let t = $('#tipo').val()

    if(t == 'j'){
        $('#cnpj').mask('00.000.000/0000-00')
    }else{
        $('#cnpj').mask('000.000.000-00')

    }
})
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.11/jquery.mask.min.js"></script>

@endsection