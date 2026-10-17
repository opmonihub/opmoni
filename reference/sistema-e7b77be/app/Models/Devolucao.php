<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Devolucao extends Model
{
    protected $fillable = [
        'contact_id', 'natureza_id', 'valor_integral', 
        'valor_devolvido', 'motivo', 'observacao', 'estado', 'devolucao_parcial', 
        'chave_nf_entrada', 'nNf', 'vFrete', 'vDesc', 'chave_gerada', 'numero_gerado', 'business_id', 'location_id', 'tipo', 'vSeguro', 'vOutro', 'sequencia_cce',
        'transportadora_nome', 'transportadora_cidade', 
        'transportadora_uf', 'transportadora_cpf_cnpj', 'transportadora_ie', 
        'transportadora_endereco', 'frete_quantidade', 'frete_especie', 'frete_marca',
        'frete_numero', 'frete_tipo', 'veiculo_placa', 'veiculo_uf', 'frete_peso_bruto', 
        'frete_peso_liquido'
    ];

    public function lastNFe(){

        $fistLocation = BusinessLocation::where('business_id', $this->business_id)->first();
        if($this->location_id != null && $this->location_id != $fistLocation->id){
            $config = BusinessLocation::where('id', $this->location_id)->first(); 
        } else {
            $config = Business::find($this->business_id);
        } 

        $transation = Transaction::
        where('numero_nfe', '>', 0)
        ->where('business_id', $this->business_id)
        ->where('location_id', $this->location_id)
        ->orderBy('numero_nfe', 'desc')
        ->first();

        $devolucao = Devolucao::
        where('numero_gerado', '>', 0)
        ->where('business_id', $this->business_id)
        ->where('location_id', $this->location_id)
        ->orderBy('numero_gerado', 'desc')
        ->first();
        // $config = Business::find($business_id);

        $numero_saida = $transation != null ? $transation->numero_nfe : 0;
        $numero_devolucao = $devolucao != null ? $devolucao->numero_gerado : 0;

        if($numero_saida > $config->ultimo_numero_nfe && $numero_saida > $numero_devolucao){
            return $numero_saida;
        }
        else if($numero_devolucao > $config->ultimo_numero_nfe && $numero_devolucao > $numero_saida){
            return $numero_devolucao;
        }
        else{
            return $config->ultimo_numero_nfe;
        } 
    }

    public function business(){
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function contact(){
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function natureza(){
        return $this->belongsTo(NaturezaOperacao::class, 'natureza_id');
    }

    public function itens(){
        return $this->hasMany('App\Models\ItemDevolucao', 'devolucao_id', 'id');
    }

    public function estado(){
        if($this->estado == 0){
            return 'NOVO';
        }
        else if($this->estado == 1){
            return 'APROVADO';
        }
        else if($this->estado == 2){
            return 'REJEITADO';
        }else{
            return 'CANCELADO';
        }
    }

    public static function getTrib($objeto){

        // ICMS — lê o primeiro grupo filho (ICMS00, ICMS10, ICMSSNxxx, ...)
        // via children() para não depender da ordem das chaves do cast.
        $icmsNode = null;
        if (isset($objeto->ICMS)) {
            foreach ($objeto->ICMS->children() as $node) { $icmsNode = $node; break; }
            if ($icmsNode === null) {
                $arr = array_values((array) $objeto->ICMS);
                $icmsNode = (isset($arr[0]) && is_object($arr[0])) ? $arr[0] : null;
            }
        }
        $cst = (string) ($icmsNode->CST ?? $icmsNode->CSOSN ?? '');

        $pICMS = (float) ($icmsNode->pICMS ?? 0);
        $vICMS = (float) ($icmsNode->vICMS ?? 0);
        $pRedBC = (float) ($icmsNode->pRedBC ?? 0);
        $modBC = (int) ($icmsNode->modBC ?? 3);
        $vBCSTRet = (float) ($icmsNode->vBCSTRet ?? 0);
        $modBCST = (float) ($icmsNode->modBCST ?? 0);
        $vBCST = (float) ($icmsNode->vBCST ?? 0);
        $pICMSST = (float) ($icmsNode->pICMSST ?? 0);
        $vICMSST = (float) ($icmsNode->vICMSST ?? 0);
        $pMVAST = (float) ($icmsNode->pMVAST ?? 0);
        $pST = (float) ($icmsNode->pST ?? 0);
        $vICMSSubstituto = (float) ($icmsNode->vICMSSubstituto ?? 0);
        $vICMSSTRet = (float) ($icmsNode->vICMSSTRet ?? 0);
        $orig = (int) ($icmsNode->orig ?? 0);

        $vBC = (float) ($icmsNode->vBC ?? 0);

        // PIS — PISAliq / PISQtde / PISNT / PISOutr
        $pisNode = null;
        if (isset($objeto->PIS)) {
            foreach ($objeto->PIS->children() as $node) { $pisNode = $node; break; }
            if ($pisNode === null) {
                $arr = array_values((array) $objeto->PIS);
                $pisNode = (isset($arr[0]) && is_object($arr[0])) ? $arr[0] : null;
            }
        }

        $pis = (string) ($pisNode->CST ?? '');
        $pPIS = (float) ($pisNode->pPIS ?? 0);
        $vbcPis = (float) ($pisNode->vBC ?? 0);

        // COFINS — COFINSAliq / COFINSQtde / COFINSNT / COFINSOutr
        $cofinsNode = null;
        if (isset($objeto->COFINS)) {
            foreach ($objeto->COFINS->children() as $node) { $cofinsNode = $node; break; }
            if ($cofinsNode === null) {
                $arr = array_values((array) $objeto->COFINS);
                $cofinsNode = (isset($arr[0]) && is_object($arr[0])) ? $arr[0] : null;
            }
        }
        $cofins = (string) ($cofinsNode->CST ?? '');
        $pCOFINS = (float) ($cofinsNode->pCOFINS ?? 0);
        $vbcCofins = (float) ($cofinsNode->vBC ?? 0);


        // IPI — IPITrib (tributado) ou IPINT (não tributado)
        $ipiNode = isset($objeto->IPI) ? $objeto->IPI : null;
        $ipiTrib = ($ipiNode && isset($ipiNode->IPITrib)) ? $ipiNode->IPITrib : null;
        $ipiNT = ($ipiNode && isset($ipiNode->IPINT)) ? $ipiNode->IPINT : null;

        $vbcIpi = 0;
        $vIPI = 0;
        $cEnq = (string) ($ipiNode->cEnq ?? '999');
        if ($ipiTrib !== null) {
            $ipi = (string) ($ipiTrib->CST ?? '50');
            $pIPI = (float) ($ipiTrib->pIPI ?? 0);
            $vbcIpi = (float) ($ipiTrib->vBC ?? 0);
            $vIPI = (float) ($ipiTrib->vIPI ?? 0);
        } elseif ($ipiNT !== null) {
            $ipi = (string) ($ipiNT->CST ?? '99');
            $pIPI = 0;
        } else {
            $ipi = '99';
            $pIPI = 0;
        }

        $data = [
            'cst_csosn' => (string)$cst,
            'pICMS' => (float)$pICMS,
            'cst_pis' => (string)$pis,
            'pPIS' => (float)$pPIS,
            'cst_cofins' => (string)$cofins,
            'pCOFINS' => (float)$pCOFINS,
            'cst_ipi' => (string)$ipi,
            'pIPI' => (float)$pIPI,
            'pRedBC' => (float)$pRedBC,
            'modBC' => (int)$modBC,
            'vBCSTRet' => (float)$vBCSTRet,
            'vBC' => (float)$vBC,
            'vICMS' => (float)$vICMS,
            'modBCST' => (float)$modBCST,
            'vBCST' => (float)$vBCST,
            'pICMSST' => (float)$pICMSST,
            'vICMSST' => (float)$vICMSST,
            'pMVAST' => (float)$pMVAST,
            'pST' => (float)$pST,
            'vICMSSubstituto' => (float)$vICMSSubstituto,
            'vICMSSTRet' => (float)$vICMSSTRet,
            'orig' => (int)$orig,

            'vbcPis' => (float)$vbcPis,
            'vbcCofins' => (float)$vbcCofins,
            'vbcIpi' => (float)$vbcIpi,
            'vIPI' => (float)$vIPI,
            'cEnq' => (string)$cEnq,
        ];

        return $data;

    }

    /**
     * Mapeia CFOP de saída da nota de origem para CFOP de
     * devolução (entrada). Ex.: 6101 (venda fora do estado)
     * vira 1202 (devolução de venda fora do estado).
     */
    public static function cfopDevolucao($cfopOrigem){
        $cfop = preg_replace('/[^0-9]/', '', (string) $cfopOrigem);
        if (strlen($cfop) !== 4) {
            return $cfopOrigem;
        }
        $mapa = [
            '5101' => '1201', '5102' => '1202', '5103' => '1201',
            '5104' => '1202', '5105' => '1201', '5106' => '1202',
            '5401' => '1401', '5403' => '1403', '5405' => '1407',
            '5501' => '1501', '5502' => '1502', '5503' => '1503',
            '6101' => '1202', '6102' => '1202', '6103' => '1202',
            '6104' => '1202', '6105' => '1202', '6106' => '1202',
            '6107' => '1202', '6108' => '1202', '6401' => '1401',
            '6403' => '1403', '6405' => '1407', '6501' => '1501',
        ];
        if (isset($mapa[$cfop])) {
            return $mapa[$cfop];
        }
        // Regra geral: saída dentro do estado (5xxx) => 1201,
        // saída fora do estado (6xxx) => 1202.
        if ($cfop[0] === '5') {
            return '1201';
        }
        if ($cfop[0] === '6') {
            return '1202';
        }
        return $cfopOrigem;
    }
}
