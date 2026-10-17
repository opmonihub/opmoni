<?php

namespace App\Helpers;

use App\Models\Bank;
use App\Models\Revenue;
use App\Models\Remessa;
use App\Models\Business;
use App\Models\RemessaBoleto;
use Illuminate\Support\Str;

class BoletoHelper {
	
	public function gerar($boleto){

		$revenue = $boleto->revenue;

		$beneficiario = $this->getBeneficiario($boleto);
		$pagador = $this->getPagador($revenue);

		$boletoAux = $boleto;

		$config = Business::findOrFail($boleto->bank->business_id);
		
		if($boletoAux->logo){

			if($config->logo){
				$logo = public_path('uploads/business_logos/' . $config->logo);
			}else{
				$logo = '';
			}
		}else{
			$logo = '';
		}

		$dataBoleto = [
			'logo' => $logo,
			'dataVencimento' => \Carbon\Carbon::parse($boleto->revenue->vencimento),
			'valor' => $boleto->revenue->valor_total,
			'numero' => $boleto->numero,
			'numeroDocumento' => $boleto->numero_documento,
			'pagador' => $pagador,
			'beneficiario' => $beneficiario,
			'carteira' => $boleto->carteira,
			'agencia' => $boleto->bank->agencia,
			'convenio' => $boleto->convenio,
			'conta' => $boleto->bank->conta,
			'multa' => $boleto->multa, 
			'juros' => $boleto->juros, 
			'jurosApos' => $boleto->juros_apos,
			'descricaoDemonstrativo' => [],
			'instrucoes' => [$boleto->instrucoes],
		];

		try{
			$boleto = $this->geraBoleto($dataBoleto, $boletoAux);

			$pdf = new \Eduardokum\LaravelBoleto\Boleto\Render\Pdf();
			$pdf->addBoleto($boleto);
			$pdf->showPrint();
			$pdf->hideInstrucoes();

			$fileName = $pdf->setBoleto();

			$boletoAux->nome_arquivo = $fileName;
			$boletoAux->linha_digitavel = $boleto->getCampoCodigoBarras();

			$boletoAux->save();
			return $fileName . ".pdf";

		}catch(\Exception $e){

			// echo $e->getMessage();
			// die;
			return [
				'erro' => true,
				'mensagem' => $e->getMessage()
			];
		}

	}

	private function getBeneficiario($boleto){
		// $config = $this->empresa->configNota;
		$beneficiario = new \Eduardokum\LaravelBoleto\Pessoa([
			'documento' => $boleto->bank->cnpj,
			'nome'      => $boleto->bank->titular,
			'cep'       => $boleto->bank->cep,
			'endereco'  => $boleto->bank->endereco,
			'bairro' 	=> $boleto->bank->bairro,
			'uf'        => $boleto->bank->city->uf,
			'cidade'    => $boleto->bank->city->nome,
		]);
		return $beneficiario;
	}

	private function getPagador($revenue){
		
		$client = $revenue->contact;

		$pagador = new \Eduardokum\LaravelBoleto\Pessoa([
			'documento' => $client->cpf_cnpj,
			'nome'      => $client->name,
			'cep'       => $client->cep,
			'endereco'  => "$client->rua, $client->numero",
			'bairro' 	=> $client->bairro,
			'uf'        => $client->cidade->uf,
			'cidade'    => $client->cidade->nome,
		]);

		return $pagador;
	}

	private function geraBoleto($data, $boletoAux){
		$boleto = null;

		if($boletoAux->bank->banco == '001'){
			$boleto	= new \Eduardokum\LaravelBoleto\Boleto\Banco\Bb($data);
		}else if($boletoAux->bank->banco == '341'){
			$boleto	= new \Eduardokum\LaravelBoleto\Boleto\Banco\Itau($data);
		}else if($boletoAux->bank->banco == '237'){
			$boleto	= new \Eduardokum\LaravelBoleto\Boleto\Banco\Bradesco($data);
		}else if($boletoAux->bank->banco == '756'){
			$boleto	= new \Eduardokum\LaravelBoleto\Boleto\Banco\Bancoob($data);
		}else if($boletoAux->bank->banco == '748'){

			$data['posto'] = $boletoAux->posto;
			$data['byte'] = 2;
			$data['codigoCliente'] = $boletoAux->codigo_cliente;
			$boleto	= new \Eduardokum\LaravelBoleto\Boleto\Banco\Sicredi($data);
		}else if($boletoAux->bank->banco == '104'){
			// $data['posto'] = $boletoAux->posto;
			// $data['byte'] = 2;

			$data['codigoCliente'] = $boletoAux->codigo_cliente;
			$boleto	= new \Eduardokum\LaravelBoleto\Boleto\Banco\Caixa($data);
		}

		else if($boletoAux->bank->banco == '033'){
			// $data['posto'] = $boletoAux->posto;
			// $data['byte'] = 2;

			$data['codigoCliente'] = $boletoAux->codigo_cliente;
			$boleto	= new \Eduardokum\LaravelBoleto\Boleto\Banco\Santander($data);
		}

		return $boleto;
	}

	public function simular($boletos){
		$resposta = true;
		foreach($boletos as $b){

			$contaReceber = ContaReceber::find($b['conta_id']);
			$beneficiario = $this->getBeneficiarioSimulacao($b['banco_id']);
			$pagador = $this->getPagador($contaReceber);

			$config = $this->empresa->configNota;
			if($config->logo){
				$logo = public_path('logos'). '/'.$config->logo;
			}else{
				$logo = '';
			}

			$banco = ContaBancaria::find($b['banco_id']);

			$dataBoleto = [
				'logo' => $logo,
				'dataVencimento' => \Carbon\Carbon::parse($contaReceber->data_vencimento),
				'valor' => $contaReceber->valor_integral,
				'numero' => $b['numero'],
				'numeroDocumento' => $b['numero_documento'],
				'pagador' => $pagador,
				'beneficiario' => $beneficiario,
				'carteira' => $b['carteira'],
				'agencia' => $banco->agencia,
				'convenio' => $b['convenio'],
				'conta' => $banco->conta,
				'multa' => $b['multa'], 
				'juros' => $b['juros'], 
				'jurosApos' => $b['juros_apos'],
				'descricaoDemonstrativo' => [],
				'instrucoes' => [],
			];

			try{
				$boleto = $this->geraBoletoSimulacao($dataBoleto, $b);
				$pdf = new \Eduardokum\LaravelBoleto\Boleto\Render\Pdf();
				$pdf->addBoleto($boleto);
				$pdf->showPrint();

				$fileName = $pdf->setBoletoAux();
			}catch(\Exception $e){
				return [
					'erro' => true,
					'mensagem' => $e->getMessage()
				];
			}
			
		}

		return true;

	}

	private function getBeneficiarioSimulacao($bancoId){
		// $config = $this->empresa->configNota;
		$banco = ContaBancaria::find($bancoId);
		$beneficiario = new \Eduardokum\LaravelBoleto\Pessoa([
			'documento' => $banco->cnpj,
			'nome'      => $banco->titular,
			'cep'       => $banco->cep,
			'endereco'  => $banco->endereco,
			'bairro' 	=> $banco->bairro,
			'uf'        => $banco->cidade->uf,
			'cidade'    => $banco->cidade->nome,
		]);
		return $beneficiario;
	}

	private function geraBoletoSimulacao($data, $b){
		$banco = ContaBancaria::find($b['banco_id']);

		$boleto = null;
		if($banco->banco == 'Banco do Brasil'){
			$boleto	= new \Eduardokum\LaravelBoleto\Boleto\Banco\Bb($data);
		}else if($banco->banco == 'Itau'){
			$boleto	= new \Eduardokum\LaravelBoleto\Boleto\Banco\Itau($data);
		}else if($banco->banco == 'Bradesco'){
			$boleto	= new \Eduardokum\LaravelBoleto\Boleto\Banco\Bradesco($data);
		}else if($banco->banco == 'Sicoob'){
			$boleto	= new \Eduardokum\LaravelBoleto\Boleto\Banco\Bancoob($data);
		}else if($banco->banco == 'Sicredi'){
			$data['posto'] = $b['posto'];
			$data['byte'] = 2;
			$data['codigoCliente'] = $b['codigo_cliente'];
			$boleto	= new \Eduardokum\LaravelBoleto\Boleto\Banco\Sicredi($data);
		}else if($banco->banco == 'Caixa Econônica Federal'){
			// $data['posto'] = $b['posto'];
			// $data['byte'] = 2;
			$data['codigoCliente'] = $b['codigo_cliente'];
			$boleto	= new \Eduardokum\LaravelBoleto\Boleto\Banco\Caixa($data);
		}else if($banco->banco == 'Santander'){
			// $data['posto'] = $b['posto'];
			// $data['byte'] = 2;
			$data['codigoCliente'] = $b['codigo_cliente'];
			$boleto	= new \Eduardokum\LaravelBoleto\Boleto\Banco\Santander($data);
		}

		return $boleto;
	}

	public function gerarMulti($boletos){
		$resposta = true;
		foreach($boletos as $b){

			$contaReceber = ContaReceber::find($b['conta_id']);
			$beneficiario = $this->getBeneficiarioSimulacao($b['banco_id']);
			$pagador = $this->getPagador($contaReceber);

			$config = $this->empresa->configNota;
			if($config->logo){
				$logo = public_path('logos'). '/'.$config->logo;
			}else{
				$logo = '';
			}

			$banco = ContaBancaria::find($b['banco_id']);

			$dataBoleto = [
				'logo' => $logo,
				'dataVencimento' => \Carbon\Carbon::parse($contaReceber->data_vencimento),
				'valor' => $contaReceber->valor_integral,
				'numero' => $b['numero'],
				'numeroDocumento' => $b['numero_documento'],
				'pagador' => $pagador,
				'beneficiario' => $beneficiario,
				'carteira' => $b['carteira'],
				'agencia' => $banco->agencia,
				'convenio' => $b['convenio'],
				'conta' => $banco->conta,
				'multa' => $b['multa'], 
				'juros' => $b['juros'], 
				'jurosApos' => $b['juros_apos'],
				'descricaoDemonstrativo' => [],
				'instrucoes' => [],
			];

			try{
				$boleto = $this->geraBoletoSimulacao($dataBoleto, $b);
			}catch(\Exception $e){
				return [
					'erro' => true,
					'mensagem' => $e->getMessage()
				];
			}
			
		}

		return true;
	}

	public function gerarRemessa($boleto){
		
		$boletoRemessa = $boleto->itemRemessa;

		if($boletoRemessa != null){
			$nomeArquivo = $boletoRemessa->remessa->nome_arquivo;
			$file = public_path('remessas')."/$nomeArquivo.txt";
			if(file_exists($file)){
				$file = public_path('remessas')."/$nomeArquivo.txt";

				header('Content-Type: application/txt');
				header('Content-Disposition: attachment; filename='.$nomeArquivo.'.txt"');
				readfile($file);

				die();
			}else{
				Remessa::find($boletoRemessa->remessa_id)->delete();
			}

		}

		$config = Business::findOrFail($boleto->bank->business_id);
		
		if($config->logo){
			$logo = public_path('uploads/business_logos/' . $config->logo);
		}else{
			$logo = '';
		}
		$revenue = $boleto->revenue;

		$beneficiario = $this->getBeneficiario($boleto);
		$pagador = $this->getPagador($revenue);

		$dataBoleto = [
			'logo' => $logo,
			'dataVencimento' => \Carbon\Carbon::parse($boleto->revenue->vencimento),
			'valor' => $boleto->revenue->valor_total,
			'numero' => $boleto->numero,
			'numeroDocumento' => $boleto->numero_documento,
			'pagador' => $pagador,
			'beneficiario' => $beneficiario,
			'carteira' => $boleto->carteira,
			'agencia' => $boleto->bank->agencia,
			'convenio' => $boleto->convenio,
			'conta' => $boleto->bank->conta,
			'multa' => $boleto->multa, 
			'juros' => $boleto->juros, 
			'jurosApos' => $boleto->juros_apos,
			'descricaoDemonstrativo' => [],
			'instrucoes' => [$boleto->instrucoes],
		];

		$boletoAux = $this->geraBoleto($dataBoleto, $boleto);

		$sendArray = [
			'beneficiario' => $beneficiario,
			'carteira' => $boleto->carteira,
			'agencia' => $boleto->bank->agencia,
			'convenio' => $boleto->convenio,
			'variacaoCarteira' => $boleto->carteira,
			'conta' => $boleto->bank->conta
		];

		if($boleto->bank->banco == '237' || $boleto->bank->banco == '748'){
			$sendArray['idremessa'] = rand(0,10000);
		}

		$send = $this->setTipoRemessa($sendArray, $boleto);
		$send->addBoleto($boletoAux);
		$send->gerar();

		if(!is_dir(public_path('remessas'))){
			mkdir(public_path('remessas'), 0777, true);
		}

		try{
			$nameFile = Str::random(32);

			// echo $nameFile;
			$result = Remessa::create([
				'nome_arquivo' => $nameFile,
				'business_id' => $boleto->bank->business_id
			]);

			RemessaBoleto::create(
				[
					'remessa_id' => $result->id,
					'boleto_id' => $boleto->id,
				]
			);

			$send->save(public_path('remessas'). "/$nameFile.txt");
			$send->download("$nameFile.txt");
		}catch(\Exception $e){
			echo $e->getMessage();
		}
	}


	private function setTipoRemessa($sendArray, $boleto){
		$remessa = null;
		$tipo = $boleto->tipo;
		if($boleto->bank->banco == '001'){
			if($tipo == 'Cnab400'){	
				$remessa = new \Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab400\Banco\Bb($sendArray);
			}else{
				$remessa = new \Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab240\Banco\Bb($sendArray);
			}
		}else if($boleto->bank->banco == '341'){
			if($tipo == 'Cnab400'){	
				$remessa = new \Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab400\Banco\Itau($sendArray);
			}else{
				$remessa = new \Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab240\Banco\Itau($sendArray);
			}
		}else if($boleto->bank->banco == '237'){
			if($tipo == 'Cnab400'){	
				$remessa = new \Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab400\Banco\Bradesco($sendArray);
			}else{
				$remessa = new \Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab240\Banco\Bradesco($sendArray);
			}
		}else if($boleto->bank->banco == '748'){
			if($tipo == 'Cnab400'){	
				$remessa = new \Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab400\Banco\Sicredi($sendArray);
			}else{
				$remessa = new \Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab240\Banco\Sicredi($sendArray);
			}
		}
		else if($boleto->bank->banco == '104'){
			if($tipo == 'Cnab400'){	
				$remessa = new \Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab400\Banco\Caixa($sendArray);
			}else{
				$remessa = new \Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab240\Banco\Caixa($sendArray);
			}
		}
		else if($boleto->bank->banco == '033'){
			if($tipo == 'Cnab400'){	
				$remessa = new \Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab400\Banco\Santander($sendArray);
			}else{
				$remessa = new \Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab240\Banco\Santander($sendArray);
			}
		}
		else if($boleto->bank->banco == '756'){
			if($tipo == 'Cnab400'){	
				$remessa = new \Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab400\Banco\Bancoob($sendArray);
			}else{
				$remessa = new \Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab240\Banco\Bancoob($sendArray);
			}
		}

		return $remessa;
	}

	public function gerarRemessaMulti($boletos){

		$multiBoletos = [];

		$config = Business::findOrFail($boletos[0]->bank->business_id);

		if($config->logo){
			$logo = public_path('uploads/business_logos/' . $config->logo);
		}else{
			$logo = '';
		}

		if(!is_dir(public_path('remessas'))){
			mkdir(public_path('remessas'), 0777, true);
		}

		foreach($boletos as $boleto){

			$revenue = $boleto->revenue;

			$beneficiario = $this->getBeneficiario($boleto);
			$pagador = $this->getPagador($revenue);

			$dataBoleto = [
				'logo' => $logo,
				'dataVencimento' => \Carbon\Carbon::parse($boleto->revenue->vencimento),
				'valor' => $boleto->revenue->valor_total,
				'numero' => $boleto->numero,
				'numeroDocumento' => $boleto->numero_documento,
				'pagador' => $pagador,
				'beneficiario' => $beneficiario,
				'carteira' => $boleto->carteira,
				'agencia' => $boleto->bank->agencia,
				'convenio' => $boleto->convenio,
				'conta' => $boleto->bank->conta,
				'multa' => $boleto->multa, 
				'juros' => $boleto->juros, 
				'jurosApos' => $boleto->juros_apos,
				'descricaoDemonstrativo' => [],
				'instrucoes' => [$boleto->instrucoes],
			];

			$boletoAux = $this->geraBoleto($dataBoleto, $boleto);
			array_push($multiBoletos, $boletoAux);
		}


		$sendArray = [
			'beneficiario' => $beneficiario,
			'carteira' => $boleto->carteira,
			'agencia' => $boleto->bank->agencia,
			'convenio' => $boleto->convenio,
			'variacaoCarteira' => $boleto->carteira,
			'conta' => $boleto->bank->conta
		];

		// if($boletos[0]->banco->banco == 'Bradesco'){
		// 	$sendArray['idremessa'] = rand(0,10000);
		// }

		if($boletos[0]->bank->banco == '237' || $boletos[0]->bank->banco == '748'){
			$sendArray['idremessa'] = rand(0,10000);
		}

		if($boletos[0]->bank->banco == '104'){
			$sendArray['idremessa'] = rand(0,10000);
			$sendArray['codigoCliente'] = $boletos[0]->codigo_cliente;
		}

		if($boletos[0]->bank->banco == '756'){
			$sendArray['idremessa'] = rand(0,10000);
		}

		if($boletos[0]->bank->banco == '033'){
			$sendArray['codigoCliente'] = $boletos[0]->codigo_cliente;
		}

		$send = $this->setTipoRemessa($sendArray, $boletos[0]);
		$send->addBoletos($multiBoletos);
		$send->gerar();

		try{
			$nameFile = Str::random(32);

			$result = Remessa::create([
				'nome_arquivo' => $nameFile,
				'business_id' => $boleto->bank->business_id
			]);

			foreach($boletos as $boleto){
				RemessaBoleto::create(
					[
						'remessa_id' => $result->id,
						'boleto_id' => $boleto->id,
					]
				);
			}

			$send->save(public_path('remessas'). "/$nameFile.txt");
			$send->download("$nameFile.txt");
		}catch(\Exception $e){
			echo $e->getMessage();
		}

	}

}
