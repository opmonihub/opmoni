<div class="pos-tab-content">
     <div class="row">
        <div class="col-sm-4">
            <div class="form-group">
                {!! Form::label('stock_expiry_alert_days', 'Veja o alerta de expiração da estoque para' . ':*') !!}
                <div class="input-group">
                <span class="input-group-addon">
                    <i class="fas fa-calendar-times"></i>
                </span>
                {!! Form::number('stock_expiry_alert_days', $business->stock_expiry_alert_days, ['class' => 'form-control','required']); !!}
                <span class="input-group-addon">
                    Dias
                </span>
                </div>
            </div>
        </div>
    </div>
</div>