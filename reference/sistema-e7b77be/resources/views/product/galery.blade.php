@extends('layouts.app')
@section('title', 'Galeria Produto')

@section('content')

<section class="content-header">
	<h1>Produto <strong>{{$product->name}}</strong></h1>

</section>

<!-- Main content -->
<section class="content">

	<div class="row">
		<div class="col-sm-12">

			<img width="200" src="{{$product->image_url}}">
			<p>Imagem principal</p>
			<a class="btn btn-warning" href="/products/{{$product->id}}/edit">Editar</a>
		</div>
	</div>
	<hr>
	<div class="row">
		<div class="col-sm-12">
			<form method="post" enctype="multipart/form-data" action="/products/galerySave">
				@csrf
				<div class="row">
					<div class="col-sm-4">

						<div class="form-group">
							<input type="hidden" value="{{$product->id}}" name="id">
							{!! Form::label('image', 'Imagem' . ':') !!}
							{!! Form::file('image', ['id' => 'upload_image', 'accept' => 'image/*']); !!}
							<small><p class="help-block">@lang('purchase.max_file_size', ['size' => (config('constants.document_size_limit') / 1000000)]) <br> @lang('lang_v1.aspect_ratio_should_be_1_1')</p></small>
						</div>
					</div>
				</div>
				<div class="row">
					<div class="col-sm-4">

						<button class="btn btn-primary">Salvar</button>
					</div>
				</div>
			</form>
			<div class="row">
				<div class="col-sm-12">

					<h4>Imagens secundarias</h4>

					@foreach($product->imagens as $i)
					<div class="col-sm-4">

						<img width="200" src="{{$i->image_url}}">
						<p>
							<a class="btn btn-danger" href="/products/galeryDelete/{{$i->id}}">Remover</a>
						</p>
					</div>
					@endforeach
				</div>
			</div>

		</div>
	</div>
</section>

@section('javascript')
<script type="text/javascript">
	var img_fileinput_setting = {
		showUpload: false,
		showPreview: true,
		browseLabel: LANG.file_browse_label,
		removeLabel: LANG.remove,
		previewSettings: {
			image: { width: '150px', height: '150px', 'max-width': '100%', 'max-height': '100%' },
		},
	};
	$('#upload_image').fileinput(img_fileinput_setting);
</script>
@endsection

@endsection
