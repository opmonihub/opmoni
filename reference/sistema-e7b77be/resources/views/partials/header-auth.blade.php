@inject('request', 'Illuminate\Http\Request')

<div class="container-fluid">

	<!-- Language changer -->
	<div class="row">
		<div class="col-md-6">
			<div class="pull-left mt-10">
		        <select style="visibility: hidden;" class="form-control input-sm" id="change_lang">
		            @foreach(config('constants.langs') as $key => $val)
		                <option value="{{$key}}" 
		                	@if( (empty(request()->lang) && config('app.locale') == $key) 
		                	|| request()->lang == $key) 
		                		selected 
		                	@endif
		                >
		                	{{$val['full_name']}}
		                </option>
		            @endforeach
		        </select>
	    	</div>
		</div>
		<div class="col-md-6">
			<div class="pull-right text-white">
	        	@if(!($request->segment(1) == 'business' && $request->segment(2) == 'register'))

	        		<!-- Register Url -->
		        	@if(config('constants.allow_registration'))
		            	<a 
		            		href="{{ route('business.getRegister') }}@if(!empty(request()->lang)){{'?lang=' . request()->lang}} @endif"
		            		class="btn bg-maroon btn-flat margin" 
		            	><b>Ainda não é registrado?</b> {{ __('business.register_now') }}</a>

		            	<!-- pricing url -->
			            @if(Route::has('pricing') && config('app.env') != 'demo' && $request->segment(1) != 'pricing')
		                	<a href="{{ action('\Modules\Superadmin\Http\Controllers\PricingController@index') }}">@lang('superadmin::lang.pricing')</a>
		            	@endif
		            @endif
		        @endif

		        @if(!($request->segment(1) == 'business' && $request->segment(2) == 'register') && $request->segment(1) != 'login')
		        	{{ __('business.already_registered')}} <a href="{{ action('Auth\LoginController@login') }}@if(!empty(request()->lang)){{'?lang=' . request()->lang}} @endif">{{ 'Login' }}</a>
		        @endif
	        </div>
		</div>
	</div>
</div>