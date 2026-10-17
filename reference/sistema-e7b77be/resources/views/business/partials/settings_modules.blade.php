<div class="pos-tab-content">
	<div class="row">
    @if(!empty($modules))
    <h4>@lang('lang_v1.enable_disable_modules')</h4>
    @foreach($modules as $k => $v)
    @if(!in_array($v['name'], $not_in_package))
    <div class="col-sm-4">
      <div class="form-group">
        <div class="checkbox">
          <br>
          <label>
            {!! Form::checkbox('enabled_modules[]', $k,  in_array($k, $enabled_modules), 
            ['class' => 'input-icheck']); !!} {{$v['name']}}
          </label>
          @if(!empty($v['tooltip'])) @show_tooltip($v['tooltip']) @endif
        </div>
      </div>
    </div>
    @endif

    @endforeach
    @endif
  </div>
  <div class="row">

    <h4>Módulos não inclusos neste plano</h4>
    @foreach($not_in_package as $k => $v)
    <div class="col-sm-4">
      <div class="form-group">
        <div class="">
          <br>
          <label>
            {{$v}}
          </label>
        </div>
      </div>
    </div>
    @endforeach
  </div>
</div>