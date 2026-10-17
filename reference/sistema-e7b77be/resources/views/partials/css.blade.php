<link rel="stylesheet" href="{{ asset('css/vendor.css?v='.$asset_v) }}">

@if( in_array(session()->get('user.language', config('app.locale')), config('constants.langs_rtl')) )
<link rel="stylesheet" href="{{ asset('css/rtl.css?v='.$asset_v) }}">
@endif

@yield('css')

@php

$images = ["home-bg.jpg", "home-bg1.png", "home-bg2.png", "home-bg3.png"];
$backHome = $images[rand(0, sizeof($images)-1)];
@endphp
<!-- app css -->
<link rel="stylesheet" href="{{ asset('css/app.css?v='.$asset_v) }}">

<style type="text/css">
	.left-col {
		background: linear-gradient(0deg,rgba(0, 0, 0, 0.76),rgba(51, 51, 51, 0.32)),url(../img/{{$backHome}}); 
		text-align: center;
		background-size: cover;
		background-position: center;
	}
</style>

@if(isset($pos_layout) && $pos_layout)
<style type="text/css">
	.content{
		padding-bottom: 0px !important;
	}
</style>
@endif

@if(!empty($__system_settings['additional_css']))
{!! $__system_settings['additional_css'] !!}
@endif