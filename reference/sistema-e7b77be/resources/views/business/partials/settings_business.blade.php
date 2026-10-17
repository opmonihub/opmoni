<div class="pos-tab-content active">
    <div class="row">

        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('cnpj', 'CPF/CNPJ' . ':*') !!}
                {!! Form::text('cnpj', $business->cnpj, ['class' => 'form-control cpf_cnpj', 'required', 
                'placeholder' => 'CNPJ']); !!}
            </div>
        </div>

        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('ie', 'IE' . ':*') !!}
                {!! Form::text('ie', $business->ie, ['class' => 'form-control', 'required',
                'placeholder' => 'IE']); !!}
            </div>
        </div>
        
        <div class="col-sm-4">
            <div class="form-group">
                {!! Form::label('razao_social',__('business.business_razao') . ':*') !!}
                {!! Form::text('razao_social', $business->razao_social, ['class' => 'form-control', 'required',
                'placeholder' => __('business.business_razao'), 'minlength' => '10']); !!}

            </div>
        </div>

        <div class="col-sm-4">
            <div class="form-group">
                {!! Form::label('name',__('business.business_name') . ':*') !!}
                {!! Form::text('name', $business->name, ['class' => 'form-control', 'required',
                'placeholder' => __('business.business_name')]); !!}
            </div>
        </div>

        <div class="col-sm-4">
            <div class="form-group">
                {!! Form::label('start_date', 'Data de início:') !!}
                <div class="input-group">
                    <span class="input-group-addon">
                        <i class="fa fa-calendar"></i>
                    </span>
                    
                    {!! Form::text('start_date', @format_date($business->start_date), ['class' => 'form-control start-date-picker','placeholder' => __('business.start_date'), 'readonly']); !!}
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="form-group">
                {!! Form::label('default_profit_percent', __('business.default_profit_percent') . ':*') !!} @show_tooltip(__('tooltip.default_profit_percent'))
                <div class="input-group">
                    <span class="input-group-addon">
                        <i class="fa fa-plus-circle"></i>
                    </span>
                    {!! Form::text('default_profit_percent', number_format($business->default_profit_percent, 2, ',', '.'), ['class' => 'form-control percentage']); !!}
                </div>
            </div>
        </div>

        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('casas_decimais_valor', 'Casas decimais valor produto:*') !!} 
                @show_tooltip('Casas decimais para o valor de produto')

                {!! Form::select('casas_decimais_valor', ['2' => '2 casas decimais', '3' => '3 casas decimais', '4' => '4 casas decimais'], $business->casas_decimais_valor, ['class' => 'form-control']); !!}

            </div>
        </div>
        <div class="clearfix"></div>
        <div class="col-sm-4">
            <div class="form-group">
                {!! Form::label('currency_id', __('business.currency') . ':') !!}
                <div class="input-group">
                    <span class="input-group-addon">
                        <i class="fas fa-money-bill-alt"></i>
                    </span>
                    {!! Form::select('currency_id', $currencies, $business->currency_id, ['class' => 'form-control select2','placeholder' => __('business.currency'), 'required']); !!}
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                {!! Form::label('currency_symbol_placement', __('lang_v1.currency_symbol_placement') . ':') !!}
                {!! Form::select('currency_symbol_placement', ['before' => __('lang_v1.before_amount'), 'after' => __('lang_v1.after_amount')], $business->currency_symbol_placement, ['class' => 'form-control select2', 'required']); !!}
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                {!! Form::label('time_zone', __('business.time_zone') . ':') !!}
                <div class="input-group">
                    <span class="input-group-addon">
                        <i class="fas fa-clock"></i>
                    </span>
                    {!! Form::select('time_zone', $timezone_list, $business->time_zone, ['class' => 'form-control select2', 'required']); !!}
                </div>
            </div>
        </div>
        <div class="clearfix"></div>
        <div class="col-sm-4">
            <div class="form-group">
                {!! Form::label('business_logo', __('business.upload_logo') . ':') !!}
                {!! Form::file('business_logo', ['accept' => 'image/jpeg']); !!}
                <p class="help-block"><i> @lang('business.logo_help')</i></p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                {!! Form::label('fy_start_month', __('business.fy_start_month') . ':') !!} @show_tooltip(__('tooltip.fy_start_month'))
                <div class="input-group">
                    <span class="input-group-addon">
                        <i class="fa fa-calendar"></i>
                    </span>
                    {!! Form::select('fy_start_month', $months, $business->fy_start_month, ['class' => 'form-control select2', 'required']); !!}
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="form-group">
                {!! Form::label('accounting_method', __('business.accounting_method') . ':*') !!}
                @show_tooltip(__('tooltip.accounting_method'))
                <div class="input-group">
                    <span class="input-group-addon">
                        <i class="fa fa-calculator"></i>
                    </span>
                    {!! Form::select('accounting_method', $accounting_methods, $business->accounting_method, ['class' => 'form-control select2', 'required']); !!}
                </div>
            </div>
        </div>
        <div class="clearfix"></div>
        <div class="col-sm-4">
            <div class="form-group">
                {!! Form::label('transaction_edit_days', __('business.transaction_edit_days') . ':*') !!}
                @show_tooltip(__('tooltip.transaction_edit_days'))
                <div class="input-group">
                    <span class="input-group-addon">
                        <i class="fa fa-edit"></i>
                    </span>
                    {!! Form::number('transaction_edit_days', $business->transaction_edit_days, ['class' => 'form-control','placeholder' => __('business.transaction_edit_days'), 'required']); !!}
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="form-group">
                {!! Form::label('date_format', __('lang_v1.date_format') . ':*') !!}
                <div class="input-group">
                    <span class="input-group-addon">
                        <i class="fa fa-calendar"></i>
                    </span>
                    {!! Form::select('date_format', $date_formats, $business->date_format, ['class' => 'form-control select2', 'required']); !!}
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="form-group">
                {!! Form::label('time_format', __('lang_v1.time_format') . ':*') !!}
                <div class="input-group">
                    <span class="input-group-addon">
                        <i class="fas fa-clock"></i>
                    </span>
                    {!! Form::select('time_format', [12 => __('lang_v1.12_hour'), 24 => __('lang_v1.24_hour')], $business->time_format, ['class' => 'form-control select2', 'required']); !!}
                </div>
            </div>
        </div>

        <!-- <div class="col-md-2">
            <div class="form-group">
                {!! Form::label('tipo', 'Tipo' . ':') !!}
                <div class="input-group" style="width: 100%;">

                    {!! Form::select('tipo', ['j' => 'Juridica', 'f' => 'Fisica'], $pessoa, ['class' => 'form-control']); !!}
                </div>
            </div>
        </div> -->

        <!-- <div class="col-sm-4">
            <div class="form-group">
                {!! Form::label('ie', 'IE' . ':*') !!}
                {!! Form::text('ie', $business->ie, ['class' => 'form-control', 'required',
                'placeholder' => 'IE']); !!}
            </div>
        </div> -->

        <div class="clearfix"></div>
        <div class="col-sm-4">
            <div class="form-group">
                <label for="certificado">Certificado:</label>
                <input name="certificado" type="file" id="certificado">
                <p class="help-block"><i>O Certificado anterior (se existir) será substituído</i></p>
            </div>
        </div>

        @if($infoCertificado != null && $infoCertificado != -1)
        <h5>Serial: <strong>{{$infoCertificado['serial']}}</strong></h5>
        <h5>Expiração: <strong>{{$infoCertificado['expiracao']}}</strong></h5>
        <h5>ID: <strong>{{$infoCertificado['id']}}</strong></h5>
        <div class="col-sm-4">
            <a class="btn btn-warning" href="{{ route('contigencia.index') }}">Contigência</a>
        </div>
        <br>
        @endif

        @if($infoCertificado == -1)
        <h5 style="color: red">Erro na leitura do certificado, verifique a senha e outros dados, e realize o upload novamente!!</h5>
        @endif
<!-- 

        <div class="clearfix"></div>

        <div class="col-sm-2">
            <div class="form-group">
                {!! Form::label('senha_certificado', 'Senha' . ':*') !!}
                {!! Form::text('senha_certificado', '', ['class' => 'form-control',
                'placeholder' => 'Senha']); !!}
            </div>
        </div> -->

        <!-- <div class="col-sm-2">
            <br>
            <div class="form-group">
                <a class="btn btn-success" href="{{ route('business.download-certificado') }}">Baixar Certificado</a>
            </div>
        </div> -->

        <div class="clearfix"></div>
        <br>
        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('senha_certificado', 'Senha' . ':*') !!}
                <div class="input-group">
                    {!! Form::input('password','senha_certificado', $business->getPwdCertificado(), ['class' => 'form-control',
                    'placeholder' => 'Senha']); !!}
                    <span onclick="exibePwdCertificado()" class="input-group-addon btn">
                        <i class="ver">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-eye-fill" viewBox="0 0 16 16">
                                <path d="M10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z"/>
                                <path d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8zm8 3.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/>
                            </svg>
                        </i>
                        <i class="nao-ver" hidden>
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-eye-slash-fill" viewBox="0 0 16 16">
                                <path d="m10.79 12.912-1.614-1.615a3.5 3.5 0 0 1-4.474-4.474l-2.06-2.06C.938 6.278 0 8 0 8s3 5.5 8 5.5a7.029 7.029 0 0 0 2.79-.588zM5.21 3.088A7.028 7.028 0 0 1 8 2.5c5 0 8 5.5 8 5.5s-.939 1.721-2.641 3.238l-2.062-2.062a3.5 3.5 0 0 0-4.474-4.474L5.21 3.089z"/>
                                <path d="M5.525 7.646a2.5 2.5 0 0 0 2.829 2.829l-2.83-2.829zm4.95.708-2.829-2.83a2.5 2.5 0 0 1 2.829 2.829zm3.171 6-12-12 .708-.708 12 12-.708.708z"/>
                            </svg>
                        </i>
                    </span>
                </div>

            </div>
        </div>
        
        @if ($business->certificado_urn)

        <div class="col-sm-6">
            <a class="btn btn-success" style="margin-top: 22px" href="{{ route('business.download-certificado')}}" target="_blank"> Baixar Certificado
            </a>
        </div>

        @endif

        <div class="clearfix"></div>

        <div class="col-sm-6">
            <div class="form-group">
                {!! Form::label('rua', 'Rua' . ':*') !!}
                {!! Form::text('rua', $business->rua, ['class' => 'form-control', 'required',
                'placeholder' => 'Rua']); !!}
            </div>
        </div>

        <div class="col-sm-2">
            <div class="form-group">
                {!! Form::label('numero', 'Número' . ':*') !!}
                {!! Form::text('numero', $business->numero, ['class' => 'form-control', 'required',
                'placeholder' => 'Número']); !!}
            </div>
        </div>
        <div class="col-md-4 customer_fields">
            <div class="form-group">
                {!! Form::label('cidade_id', 'Cidade:*') !!}
                {!! Form::select('cidade_id', $cities, $business->cidade_id, ['class' => 'form-control select2', 'required']); !!}
            </div>
        </div>

        <div class="clearfix"></div>

        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('bairro', 'Bairro' . ':*') !!}
                {!! Form::text('bairro', $business->bairro, ['class' => 'form-control', 'required',
                'placeholder' => 'Bairro']); !!}
            </div>
        </div>

        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('cep', 'CEP' . ':*') !!}
                {!! Form::text('cep', $business->cep, ['class' => 'form-control', 'required', 'data-mask="00000-000"',
                'placeholder' => 'CEP']); !!}
            </div>
        </div>

        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('telefone', 'Telefone' . ':*') !!}
                {!! Form::text('telefone', $business->telefone, ['class' => 'form-control', 'required', 'data-mask="00 000000000"',
                'placeholder' => 'Telefone']); !!}
            </div>
        </div>


        <div class="col-md-3">
            <div class="form-group">

                {!! Form::label('regime', 'Regime' . ':') !!}
                {!! Form::select('regime', ['1' => 'Simples', '3' => 'Normal'], $business->regime, ['class' => 'form-control select2', 'required']); !!}
            </div>
        </div>

        <div class="clearfix"></div>

        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('ultimo_numero_nfe', 'Ultimo Núm. NFe' . ':*') !!}
                {!! Form::text('ultimo_numero_nfe', $business->ultimo_numero_nfe, ['class' => 'form-control', 'required',
                'placeholder' => 'Ultimo Núm. NFe']); !!}
            </div>
        </div>

        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('ultimo_numero_nfce', 'Ultimo Núm. NFCe' . ':*') !!}
                {!! Form::text('ultimo_numero_nfce', $business->ultimo_numero_nfce, ['class' => 'form-control', 'required',
                'placeholder' => 'Ultimo Núm. NFCe']); !!}
            </div>
        </div>

        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('ultimo_numero_cte', 'Ultimo Núm. CTe' . ':*') !!}
                {!! Form::text('ultimo_numero_cte', $business->ultimo_numero_cte, ['class' => 'form-control', 'required',
                'placeholder' => 'Ultimo Núm. CTe']); !!}
            </div>
        </div>

        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('ultimo_numero_mdfe', 'Ultimo Núm. MDFe' . ':*') !!}
                {!! Form::text('ultimo_numero_mdfe', $business->ultimo_numero_mdfe, ['class' => 'form-control', 'required',
                'placeholder' => 'Ultimo Núm. MDFe']); !!}
            </div>
        </div>

        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('inscricao_municipal', 'Inscrição municipal' . ':*') !!}
                {!! Form::text('inscricao_municipal', $business->inscricao_municipal, ['class' => 'form-control',
                'placeholder' => 'Inscrição municipal']); !!}
            </div>
        </div>

        <div class="col-sm-2">
            <div class="form-group">
                {!! Form::label('numero_serie_nfe', 'Núm. Série NFe' . ':*') !!}
                {!! Form::text('numero_serie_nfe', $business->numero_serie_nfe, ['class' => 'form-control', 'required',
                'placeholder' => 'Núm. Série NFe']); !!}
            </div>
        </div>

        <div class="col-sm-2">
            <div class="form-group">
                {!! Form::label('numero_serie_nfce', 'Núm. Série NFCe' . ':*') !!}
                {!! Form::text('numero_serie_nfce', $business->numero_serie_nfce, ['class' => 'form-control', 'required',
                'placeholder' => 'Núm. Série NFCe']); !!}
            </div>
        </div>

        <div class="col-sm-2">
            <div class="form-group">
                {!! Form::label('numero_serie_cte', 'Núm. Série CTe' . ':*') !!}
                {!! Form::text('numero_serie_cte', $business->numero_serie_cte, ['class' => 'form-control', 'required',
                'placeholder' => 'Núm. Série CTe']); !!}
            </div>
        </div>

        <div class="col-sm-2">
            <div class="form-group">
                {!! Form::label('numero_serie_mdfe', 'Núm. Série MDFe' . ':*') !!}
                {!! Form::text('numero_serie_mdfe', $business->numero_serie_mdfe, ['class' => 'form-control', 'required',
                'placeholder' => 'Núm. Série MDFe']); !!}
            </div>
        </div>

        <div class="col-md-3">
            <div class="form-group">

                {!! Form::label('ambiente', 'Ambiente' . ':') !!}
                {!! Form::select('ambiente', ['1' => 'Produção', '2' => 'Homologação'], $business->ambiente, ['class' => 'form-control select2', 'required']); !!}
            </div>
        </div>

        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('csc_id', 'CSCID' . ':*') !!}
                {!! Form::text('csc_id', $business->csc_id, ['class' => 'form-control', 'required', 
                'placeholder' => 'CSCID']); !!}
            </div>
        </div>

        <div class="col-sm-5">
            <div class="form-group">
                {!! Form::label('csc', 'CSC' . ':*') !!}
                {!! Form::text('csc', $business->csc, ['class' => 'form-control', 'required', 
                'placeholder' => 'CSC']); !!}
            </div>
        </div>
        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('aut_xml', 'AUT XML' . ':') !!}
                {!! Form::text('aut_xml', $business->aut_xml, ['class' => 'form-control cnpj', 
                'placeholder' => 'AUT XML']); !!}
            </div>
        </div>

    </div>
</div>

<script>

    function exibePwdCertificado() {
        if ($('#senha_certificado').attr('type') == 'password') {
            $('#senha_certificado').attr('type', 'text');
            $('.nao-ver').attr('hidden', false);
            $('.ver').attr('hidden', true);

        } else {
            $('#senha_certificado').attr('type', 'password');

            $('.nao-ver').attr('hidden', true);
            $('.ver').attr('hidden', false);
        }
    }
</script>

