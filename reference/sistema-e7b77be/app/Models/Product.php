<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    protected $appends = ['image_url'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'sub_unit_ids' => 'array',
    ];
    
    /**
     * Get the products image.
     *
     * @return string
     */
    public function getImageUrlAttribute()
    {
        if (!empty($this->image)) {
            $image_url = asset('/uploads/img/' . rawurlencode($this->image));
        } else {
            $image_url = asset('/img/default.png');
        }
        return $image_url;
    }

    /**
    * Get the products image path.
    *
    * @return string
    */
    public function getImagePathAttribute()
    {
        if (!empty($this->image)) {
            $image_path = public_path('uploads') . '/' . config('constants.product_img_path') . '/' . $this->image;
        } else {
            $image_path = null;
        }
        return $image_path;
    }

    public function product_variations()
    {
        return $this->hasMany(\App\Models\ProductVariation::class);
    }

    public function imagens()
    {
        return $this->hasMany(\App\Models\ProdutoImagem::class, 'produto_id', 'id');
    }
    
    /**
     * Get the brand associated with the product.
     */
    public function brand()
    {
        return $this->belongsTo(\App\Models\Brands::class);
    }

    public function business()
    {
        return $this->belongsTo(\App\Models\Business::class);
    }
    
    /**
    * Get the unit associated with the product.
    */
    public function unit()
    {
        return $this->belongsTo(\App\Models\Unit::class);
    }
    /**
     * Get category associated with the product.
     */
    public function category()
    {
        return $this->belongsTo(\App\Models\Category::class);
    }


    /**
     * Get sub-category associated with the product.
     */
    public function sub_category()
    {
        return $this->belongsTo(\App\Models\Category::class, 'sub_category_id', 'id');
    }
    
    /**
     * Get the brand associated with the product.
     */
    public function product_tax()
    {
        return $this->belongsTo(\App\Models\TaxRate::class, 'tax', 'id');
    }

    /**
     * Get the variations associated with the product.
     */
    public function variations()
    {
        return $this->hasMany(\App\Models\Variation::class);
    }

    /**
     * If product type is modifier get products associated with it.
     */
    public function modifier_products()
    {
        return $this->belongsToMany(\App\Models\Product::class, 'res_product_modifier_sets', 'modifier_set_id', 'product_id');
    }

    /**
     * If product type is modifier get products associated with it.
     */
    public function modifier_sets()
    {
        return $this->belongsToMany(\App\Models\Product::class, 'res_product_modifier_sets', 'product_id', 'modifier_set_id');
    }

    /**
     * Get the purchases associated with the product.
     */
    public function purchase_lines()
    {
        return $this->hasMany(\App\Models\PurchaseLine::class);
    }

    /**
     * Scope a query to only include active products.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('products.is_inactive', 0);
    }

    /**
     * Scope a query to only include inactive products.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInactive($query)
    {
        return $query->where('products.is_inactive', 1);
    }

    /**
     * Scope a query to only include products for sales.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProductForSales($query)
    {
        return $query->where('not_for_selling', 0);
    }

    /**
     * Scope a query to only include products for sales.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProductEcommerce($query)
    {
        return $query->where('products.ecommerce', 1);
    }

    /**
     * Scope a query to only include products not for sales.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProductNotForSales($query)
    {
        return $query->where('not_for_selling', 1);
    }

    public function product_locations()
    {
        return $this->belongsToMany(\App\Models\BusinessLocation::class, 'product_locations', 'product_id', 'location_id');
    }

    /**
     * Scope a query to only include products available for a location.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForLocation($query, $location_id)
    {
        return $query->where(function ($q) use ($location_id) {
            $q->whereHas('product_locations', function ($query) use ($location_id) {
                $query->where('product_locations.location_id', $location_id);
            });
        });
    }

    /**
     * Get warranty associated with the product.
     */
    public function warranty()
    {
        return $this->belongsTo(\App\Models\Warranty::class);
    }

    public static function unidadesMedida(){
        return [
            "AMPOLA" => "AMPOLA",
            "BALDE" => "BALDE",
            "BANDEJ" => "BANDEJ",
            "BARRA" => "BARRA",
            "BISNAG" => "BISNAG",
            "BLOCO" => "BLOCO",
            "BOBINA" => "BOBINA",
            "BOMB" => "BOMB",
            "CAPS" => "CAPS",
            "CART" => "CART",
            "CENTO" => "CENTO",
            "CJ" => "CJ",
            "CM" => "CM",
            "CM2" => "CM2",
            "CX" => "CX",
            "CX2" => "CX2",
            "CX3" => "CX3",
            "CX5" => "CX5",
            "CX10" => "CX10",
            "CX15" => "CX15",
            "CX20" => "CX20",
            "CX25" => "CX25",
            "CX50" => "CX50",
            "CX100" => "CX100",
            "DISP" => "DISP",
            "DUZIA" => "DUZIA",
            "EMBAL" => "EMBAL",
            "FARDO" => "FARDO",
            "FOLHA" => "FOLHA",
            "FRASCO" => "FRASCO",
            "GALAO" => "GALAO",
            "GF" => "GF",
            "GRAMAS" => "GRAMAS",
            "JOGO" => "JOGO",
            "KG" => "KG",
            "KIT" => "KIT",
            "LATA" => "LATA",
            "LITRO" => "LITRO",
            "M" => "M",
            "M2" => "M2",
            "M3" => "M3",
            "MILHEI" => "MILHEI",
            "ML" => "ML",
            "MWH" => "MWH",
            "PACOTE" => "PACOTE",
            "PALETE" => "PALETE",
            "PARES" => "PARES",
            "PC" => "PC",
            "POTE" => "POTE",
            "K" => "K",
            "RESMA" => "RESMA",
            "ROLO" => "ROLO",
            "SACO" => "SACO",
            "SACOLA" => "SACOLA",
            "TAMBOR" => "TAMBOR",
            "TANQUE" => "TANQUE",
            "TON" => "TON",
            "TUBO" => "TUBO",
            "UNID" => "UNID",
            "VASIL" => "VASIL",
            "VIDRO" => "VIDRO"
        ];
    }

    public static function listaCSTCSOSN(){
        return [
            '00' => '00 - Tributa integralmente',
            '10' => '10 - Tributada e com cobrança do ICMS por substituição tributária',
            '20' => '20 - Com redução da Base de Calculo',
            '30' => '30 - Isenta / não tributada e com cobrança do ICMS por substituição tributária',
            '40' => '40 - Isenta',
            '41' => '41 - Não tributada',
            '50' => '50 - Com suspensão',
            '51' => '51 - Com diferimento',
            '60' => '60 - ICMS cobrado anteriormente por substituição tributária',
            '61' => '61 - ICMS Monofásico',
            '70' => '70 - Com redução da BC e cobrança do ICMS por substituição tributária',
            '90' => '90 - Outras',

            '101' => '101 - Tributada pelo Simples Nacional com permissão de crédito',
            '102' => '102 - Tributada pelo Simples Nacional sem permissão de crédito',
            '103' => '103 - Isenção do ICMS no Simples Nacional para faixa de receita bruta',
            '201' => '201 - Tributada pelo Simples Nacional com permissão de crédito e com cobrança do ICMS por substituição tributária',
            '202' => '202 - Tributada pelo Simples Nacional sem permissão de crédito e com cobrança do ICMS por substituição tributária',
            '203' => '203 - Isenção do ICMS no Simples Nacional para faixa de receita bruta e com cobrança do ICMS por substituição tributária',
            '300' => '300 - Imune',
            '400' => '400 - Não tributada pelo Simples Nacional',
            '500' => '500 - ICMS cobrado anteriormente por substituição tributária (substituído) ou por antecipação',
            '900' => '900 - Outros',
        ];
    }

    public static function listaCST_PIS_COFINS(){
        return [
            '01' => '01 - Operação Tributável com Alíquota Básica',
            '02' => '02 - Operação Tributável com Alíquota por Unidade de Medida de Produto',
            '03' => '03 - Operação Tributável com Alíquota por Unidade de Medida de Produto',
            '04' => '04 - Operação Tributável Monofásica – Revenda a Alíquota Zero',
            '05' => '05 - Operação Tributável por Substituição Tributária',
            '06' => '06 - Operação Tributável a Alíquota Zero', 
            '07' => '07 - Operação Isenta da Contribuição', 
            '08' => '08 - Operação sem Incidência da Contribuição', 
            '09' => '09 - Operação com Suspensão da Contribuição', 
            '49' => '49 - Outras Operações de Saída',
            '50'=> '50 - Operação com Direito a Crédito – Vinculado Exclusivamente a Receita Tributada no Mercado Interno',
            '51'=> '51 - Operação com Direito a Crédito – Vinculado Exclusivamente a Receita Não Tributada no Mercado Interno',
            '52'=> '52 - Operação com Direito a Crédito – Vinculado Exclusivamente a Receita de Exportação',
            '53'=> '53 - Operação com Direito a Crédito – Vinculado a Receitas Tributadas e Não-Tributadas no Mercado Interno',
            '54' => '54 - Operação com Direito a Crédito – Vinculado a Receitas Tributadas no Mercado Interno e de Exportação',
            '55' => '55 - Operação com Direito a Crédito – Vinculado a Receitas Não-Tributadas no Mercado Interno e de Exportação',
            '56' => '56 - Operação com Direito a Crédito – Vinculado a Receitas Tributadas e Não-Tributadas no Mercado Interno, e de Exportação',
            '60' => '60 - Crédito Presumido – Operação de Aquisição Vinculada Exclusivamente a Receita Tributada no Mercado Interno',
            '61' => '61 - Crédito Presumido – Operação de Aquisição Vinculada Exclusivamente a Receita Não-Tributada no Mercado Interno',
            '62' => '62 - Crédito Presumido – Operação de Aquisição Vinculada Exclusivamente a Receita de Exportação',
            '63' => '63 - Crédito Presumido – Operação de Aquisição Vinculada a Receitas Tributadas e Não-Tributadas no Mercado Interno',
            '64' => '64 - Crédito Presumido – Operação de Aquisição Vinculada a Receitas Tributadas no Mercado Interno e de Exportação',
            '65' => '65 - Crédito Presumido – Operação de Aquisição Vinculada a Receitas Não-Tributadas no Mercado Interno e de Exportação',
            '66' => '66 - Crédito Presumido – Operação de Aquisição Vinculada a Receitas Tributadas e Não-Tributadas no Mercado Interno, e de Exportação',
            '67' => '67 - Crédito Presumido – Outras Operações',
            '70' => '70 - Operação de Aquisição sem Direito a Crédito',
            '71' => '71 - Operação de Aquisição com Isenção',
            '72' => '72 - Operação de Aquisição com Suspensão',
            '73' => '73 - Operação de Aquisição a Alíquota Zero',
            '74' => '74 - Operação de Aquisição sem Incidência da Contribuição',
            '75' => '75 - Operação de Aquisição por Substituição Tributária',
            '98' => '98 - Outras Operações de Entrada',
            '99' => '99 - Outras Operações',
        ];
    }

    public static function listaCST_IPI(){
        return [
            '50' => '50 - Saída Tributada',
            '51' => '51 - Saída Tributável com Alíquota Zero',
            '52' => '52 - Saída Isenta',
            '53' => '53 - Saída Não Tributada',
            '54' => '54 - Saída Imune',
            '55' => '55 - Saída com Suspensão',
            '99' => '99 - Outras Saídas'
        ];
    }

    public static function firstNatureza(){
        return NaturezaOperacao::first();
    }

    public static function lista_ANP(){
        return [
            '' => 'Selecione',
            '210101001' =>  'GAS COMBUSTIVEL',
            '420301002' =>  'OUTROS OLEOS DIESEL',
            '210201001' =>  'PROPANO',
            '420301003' =>  'OLEO DIESEL FORA DE ESPECIFICACAO',
            '210201002' =>  'PROPANO ESPECIAL',
            '510101001' =>  'OLEO COMBUSTIVEL A1',
            '210201003' =>  'PROPENO',
            '510101002' =>  'OLEO COMBUSTIVEL A2',
            '210202001' =>  'BUTANO',
            '510101003' =>  'OLEO COMBUSTIVEL A FORA DE ESPECIFICACAO',
            '210202002' =>  'BUTANO ESPECIAL',
            '510102001' =>  'OLEO COMBUSTIVEL B1',
            '210202003' =>  'BUTADIENO',
            '510102002' =>  'OLEO COMBUSTIVEL B2',
            '210203001' =>  'GLP', 
            '510102003' =>  'OLEO COMBUSTIVEL B FORA DE ESPECIFICACAO',
            '210203002' =>  'GLP FORA DE ESPECIFICACAO',
            '510201001' =>  'OLEO COMBUSTIVEL MARITIMO',
            '210204001' =>  'GAS LIQUEFEITO INTERMEDIARIO', 
            '510201002' =>  'OLEO COMBUSTIVEL MARÍTIMO FORA DE ESPECIFICACAO',
            '210204002' =>  'OUTROS GASES LIQUEFEITOS',
            '510201003' =>  'OLEO COMBUSTIVEL MARÍTIMO MISTURA (MF)',
            '210301001' =>  'ETANO',
            '510301001' =>  'OUTROS OLEOS COMBUSTIVEIS',
            '210301002' =>  'ETENO',
            '510301002' =>  'ÓLEOS COMBUSTIVEIS PARA EXPORTACAO',
            '210302001' =>  'OUTROS GASES   ',
            '510301003' =>  'OLEO COMBUSTIVEL PARA GERAAOO ELETRICA',
            '210302002' =>  'GAS INTERMEDIARIO',    
            '540101001' =>  'COQUE VERDE',
            '210302003' =>  'GAS DE XISTO', 
            '540101002' =>  'COQUE CALCINADO',
            '210302004' =>  'GAS ACIDO',
            '810101001' =>  'ETANOL HIDRATADO COMUM',
            '220101001' =>  'GAS NATURAL UMIDO',    
            '810101002' =>  'ETANOL HIDRATADO ADITIVADO',
            '220101002' =>  'GAS NATURAL SECO', 
            '810101003' =>  'ETANOL HIDRATADO FORA DE ESPECIFICACAO',
            '220101003' =>  'GAS NATURAL COMPRIMIDO',   
            '810102001' =>  'ETANOL ANIDRO',
            '220101004' =>  'GAS NATURAL LIQUEFEITO',   
            '810102002' =>  'ETANOL ANIDRO FORA DE ESPECIFICACAO',
            '220101005' =>  'GAS NATURAL VEICULAR', 
            '810102003' =>  'ETANOL ANIDRO PADRAO',
            '220101006' =>  'GAS NATURAL VEICULAR PADRAO',  
            '810102004' =>  'ETANOL ANIDRO COM CORANTE',
            '220102001' =>  'GASOLINA NATURAL (C5+)',   
            '810201001' =>  'ALCOOL METILICO',
            '220102002' =>  'LIQUIDO DE GAS NATURAL',   
            '810201002' =>  'OUTROS ALCOOIS',
            '320101001' =>  'GASOLINA A COMUM', 
            '820101001' =>  'BIODIESEL B100', 
            '320101002' =>  'GASOLINA A PREMIUM ', 
            '820101002' =>  'DIESEL B4 S1800 - COMUM', 
            '320101003' =>  'GASOLINA A FORA DE ESPECIFICACAO',     
            '820101003' =>  'OLEO DIESEL B S1800 - COMUM', 
            '320102001' =>  'GASOLINA C COMUM',     
            '820101004' =>  'DIESEL B10', 
            '320102002' =>  'GASOLINA C ADITIVADA',     
            '820101005' =>  'DIESEL B15', 
            '320102003' =>  'GASOLINA C PREMIUM', 
            '820101006' =>  'DIESEL B20 S1800 - COMUM', 
            '320102004' =>  'GASOLINA C FORA DE ESPECIFICACAO',     
            '820101007' =>  'DIESEL B4 S1800 - ADITIVADO',
            '320103001' =>  'GASOLINA AUTOMOTIVA PADRAO ',
            '820101008' =>  'DIESEL B4 S500 - COMUM',
            '320103002' =>  'OUTRAS GASOLINAS AUTOMOTIVAS',
            '820101009' =>  'DIESEL B4 S500 - ADITIVADO',
            '320201001' =>  'GASOLINA DE AVIACAO',
            '820101010' =>  'BIODIESEL FORA DE ESPECIFICACAO',
            '320201002' =>  'GASOLINA DE AVIAÇÃO FORA DE ESPECIFICACAO',
            '820101011' =>  'OLEO DIESEL B S1800 - ADITIVADO',
            '320301001' =>  'OUTRAS GASOLINAS',
            '820101012' =>  'OLEO DIESEL B S500 - COMUM',
            '320301002' =>  'GASOLINA PARA EXPORTACAO', 
            '820101013' =>  'OLEO DIESEL B S500 - ADITIVADO',
            '410101001' =>  'QUEROSENE DE AVIACAO', 
            '820101014' =>  'DIESEL B20 S1800 - ADITIVADO',
            '410101002' =>  'QUEROSENE DE AVIAÇÃO FORA DE ESPECIFICACAO ',
            '820101015' =>  'DIESEL B20 S500 - COMUM',
            '410102001' =>  'QUEROSENE ILUMINANTE   ',
            '820101016' =>  'DIESEL B20 S500 - ADITIVADO',
            '410102002' =>  'QUEROSENE ILUMINANTE FORA DE ESPECIFICACAO ',
            '820101017' =>  'DIESEL MARÍTIMO - DMA B2',
            '410103001' =>  'OUTROS QUEROSENES  ',
            '820101018' =>  'DIESEL MARITIMO - DMA B5',
            '420101003' =>  'OLEO DIESEL A S1800 - FORA DE ESPECIFICACAO',  
            '820101019' =>  'DIESEL MARITIMO - DMB B2',
            '420101004' =>  'OLEO DIESEL A S1800 - COMUM',  
            '820101020' =>  'DIESEL MARITIMO - DMB B5',
            '420101005' =>  'OLEO DIESEL A S1800 - ADITIVADO',  
            '820101021' =>  'DIESEL NAUTICO B2 ESPECIAL - 200 PPM ENXOFRE',
            '420102003' =>  'OLEO DIESEL A S500 - FORA DE ESPECIFICACAO',   
            '820101022' =>  'DIESEL B2 ESPECIAL - 200 PPM ENXOFRE',
            '420102004' =>  'OLEO DIESEL A S500 - COMUM',   
            '820101025' =>  'DIESEL B30',
            '420102005' =>  'OLEO DIESEL A S500 - ADITIVADO ',
            '820101026' =>  'DIESEL B S1800 PARA GERACAO DE ENERGIA ELETRICA',
            '420102006' =>  'OLEO DIESEL A S50  ',
            '820101027' =>  'DIESEL B S500 PARA GERACAO DE ENERGIA ELETRICA',
            '420104001' =>  'OLEO DIESEL AUTOMOTIVO ESPECIAL - ENXOFRE 200 PPM  ',
            '820101028' =>  'OLEO DIESEL B S50 - ADITIVADO',
            '420105001' =>  'OLEO DIESEL A S10',    
            '820101029' =>  'OLEO DIESEL B S50 - COMUM',
            '420201001' =>  'DMA - MGO',    
            '820101030' =>  'DIESEL B20 S50 COMUM',
            '420201002' =>  'OLEO DIESEL MARITIMO FORA DE ESPECIFICACAO',   
            '820101031' =>  'DIESEL B20 S50 ADITIVADO',
            '420201003' =>  'DMB - MDO',    
            '820101032' =>  'DIESEL B S50 PARA GERACAO DE ENERGIA ELETRICA',
            '420202001' =>  'OLEO DIESEL NAUTICO ESPECIAL - ENXOFRE 200 PPM',   
            '820101033' =>  'OLEO DIESEL B S10 - ADITIVADO',
            '420301001' =>  'OLEO DIESEL PADRAO',   
            '820101034' =>  'OLEO DIESEL B S10 - COMUM'
        ];
    }

    public function getDescricaoAnp(){
        $lista = $this->lista_ANP();
        return $lista[$this->codigo_anp];   
    }

    public static function listaOrigem(){
        return [
            '0' => '0 - Nacional, exceto as indicadas nos códigos 3, 4, 5 e 8',
            '1' => '1 - Estrangeira – Importação direta, exceto a indicada no código 6',
            '2' => '2 - Estrangeira – Adquirida no mercado interno, exceto a indicada no código 7',
            '3' => '3 - Nacional, mercadoria ou bem com Conteúdo de Importação superior a 40% (quarenta por cento) e inferior ou igual a 70% (setenta por cento)',
            '4' => '4 - Nacional, cuja produção tenha sido feita em conformidade com os processos produtivos',
            '5' => '5 - Nacional, mercadoria ou bem com Conteúdo de Importação inferior ou igual a 40%',
            '6' => '6 - Estrangeira – Importação direta, sem similar nacional, constante em lista de Resolução CAMEX e gás natural',
            '7' => '7 - Estrangeira – Adquirida no mercado interno, sem similar nacional, constante em lista de Resolução CAMEX e gás natural',
            '8' => '8 - Nacional, mercadoria ou bem com Conteúdo de Importação superior a 70%',

        ];
    }

    public static function listaCenqIPI()
    {
        return [
            '' => 'Selecione uma opção',
            '001' => '001 - Imunidade - Livros, jornais, periódicos e o papel destinado à sua impressão - Art. 18 Inciso I do Decreto 7.212/2010',
            '002' => '002 - Imunidade - Produtos industrializados destinados ao exterior - Art. 18 Inciso II do Decreto 7.212/2010',
            '003' => '003 - Imunidade - Ouro, definido em lei como ativo financeiro ou instrumento cambial - Art. 18 Inciso III do Decreto 7.212/2010',
            '004' => '004 - Imunidade - Energia elétrica, derivados de petróleo, combustíveis e minerais do País - Art. 18 Inciso IV do Decreto 7.212/2010',
            '005' => '005 - Imunidade - Exportação de produtos nacionais - sem saída do território brasileiro - venda para empresa sediada no exterior - atividades de pesquisa ou lavra de jazidas de petróleo e de gás natural- Art. 19 Inciso I do Decreto 7.212/2010',
            '006' => '006 - Imunidade - Exportação de produtos nacionais - sem saída do território brasileiro - venda para empresa sediada no exterior - incorporados a produto final exportado para o Brasil - Art. 19 Inciso II do Decreto 7.212/2010',
            '007' => '007 - Imunidade - Exportação de produtos nacionais - sem saída do território brasileiro - venda para órgão ou entidade de governo estrangeiro ou organismo internacional de que o Brasil seja membro,para ser entregue, no País, à ordem do comprador - Art. 19 Inciso III do Decreto 7.212/2010',
            '101' => '101 - Suspensão - Óleo de menta em bruto, produzido por lavradores - Art. 43 Inciso I do Decreto 7.212/2010',
            '102' => '102 - Suspensão - Produtos remetidos à exposição em feiras de amostras e promoções semelhantes - Art. 43 Inciso II do Decreto 7.212/2010',
            '103' => '103 - Suspensão - Produtos remetidos a depósitos fechados ou armazéns-gerais, bem assim aqueles devolvidos ao remetente - Art. 43 Inciso III do Decreto 7.212/2010',
            '104' => '104 - Suspensão - Produtos industrializados, que com matérias-primas (MP), produtos intermediários (PI) e material de embalagem (ME) importados submetidos a regime aduaneiro especial (drawback- suspensão/isenção), remetidos diretamente a empresas industriais exportadoras - Art. 43 Inciso IV do Decreto 7.212/2010',
            '105' => '105 - Suspensão - Produtos, destinados à exportação, que saiam do estabelecimento industrial para empresas comerciais exportadoras, com o fim específico de exportação - Art. 43, Inciso V, alínea "a" do Decreto 7.212/2010',
            '106' => '106 - Suspensão - Produtos, destinados à exportação, que saiam do estabelecimento industrial para recintos alfandegados onde se processe o despacho aduaneiro de exportação - Art. 43, Inciso V,alíneas "b" do Decreto 7.212/2010',
            '107' => '107 - Suspensão - Produtos, destinados à exportação, que saiam do estabelecimento industrial para outros locais onde se processe o despacho aduaneiro de exportação - Art. 43, Inciso V, alíneas "c"do Decreto 7.212/2010',
            '108' => '108 - Suspensão - Matérias-primas (MP), produtos intermediários (PI) e material de embalagem (ME) destinados ao executor de industrialização por encomenda - Art. 43 Inciso VI do Decreto 7.212/2010',
            '109' => '109 - Suspensão - Produtos industrializados por encomenda remetidos ao estabelecimento de origem - Art. 43 Inciso VII do Decreto 7.212/2010',
            '110' => '110 - Suspensão - Matérias-primas ou produtos intermediários remetidos para emprego em operação industrial realizada pelo remetente fora do estabelecimento - Art. 43 Inciso VIII do Decreto 7.212/2010',
            '111' => '111 - Suspensão - Veículo, aeronave ou embarcação destinados a emprego em provas de engenharia pelo fabricante - Art. 43 Inciso IX do Decreto 7.212/2010',
            '112' => '112 - Suspensão - Produtos remetidos, para industrialização ou comércio, de um para outro estabelecimento da mesma firma - Art. 43 Inciso X do Decreto 7.212/2010',
            '113' => '113 - Suspensão - Bens do ativo permanente remetidos a outro estabelecim ento da mesma firma, para seremutilizados no processo industrial do recebedor - Art. 43 Inciso XI do Decreto 7.212/2010',
            '114' => '114 - Suspensão - Bens do ativo permanente remetidos a outro estabelecimento, para serem utilizados no processo industrial de produtos encomendados pelo remetente - Art. 43 Inciso XII do Decreto 7.212/2010',
            '115' => '115 - Suspensão - Partes e peças destinadas ao reparo de produtos com defeito de fabricação, quando a operação for executada gratuitamente, em virtude de garantia - Art. 43 Inciso XIII do Decreto 7.212/2010',
            '116' => '116 - Suspensão - Matérias-primas (MP), produtos intermediários (PI) e material de embalagem (ME) de fabricação nacional, vendidos a estabelecimento industrial, para industrialização de produtos destinados à exportação ou a estabelecimento comercial, para industrialização em outro estabelecimento da mesma firma ou de terceiro, de produto destinado à exportação - Art. 43 Inciso XIV do Decreto 7.212/2010',
            '117' => '117 - Suspensão - Produtos para emprego ou consumo na industrialização ou elaboração de produto a ser exportado, adquiridos no mercado interno ou importados - Art. 43 Inciso X V do Decreto 7.212/2010',
            '118' => '118 - Suspensão - Bebidas alcoólicas e demais produtos de produção nacional acondicionados em recipientes de capacidade superior ao limite máximo permitido para venda a varejo - Art. 44 do Decreto 7.212/2010',
            '119' => '119 - Suspensão - Produtos classificados NCM 21.06.90.10 Ex 02, 22.01, 22.02, exceto os Ex 01 e Ex 02 do Código 22.02.90.00 e 22.03 saídos de estabelecimento industrial destinado a comercial equiparado a industrial - Art. 45 Inciso I do Decreto 7.212/2010',
            '120' => '120 - Suspensão - Produtos classificados NCM 21.06.90.10 Ex 02, 22.01, 22.02, exceto os Ex 01 e Ex 02 do Código 22.02.90.00 e 22.03 saídos de estabelecimento comercial equiparado a industrial destinado a equiparado a industrial - Art. 45 Inciso II do Decreto 7.212/2010',
            '121' => '121 - Suspensão - Produtos classificados NCM 21.06.90.10 Ex 02, 22.01, 22.02, exceto os Ex 01 e Ex 02 do Código 22.02.90.00 e 22.03 saídos de importador destinado a equiparado a industrial - Art. 45 Inciso III do Decreto 7.212/2010',
            '122' => '122 - Suspensão - Matérias-primas (MP), produtos intermediários (PI) e material de embalagem (ME) destinados a estabelecimento que se dedique à elaboração de produtos classificados nos códigos previstos no art. 25 da Lei 10.684/2003 - Art. 46 Inciso I do Decreto 7.212/2010',
            '123' => '123 - Suspensão - Matérias-primas (MP), produtos intermediários (PI) e material de embalagem (ME) adquiridos por estabelecimentos industriais fabricantes de partes e peças destinadas a estabelecimento industrial fabricante de produto classificado no Capítulo 88 da Tipi - Art. 46 Inciso II do Decreto 7.212/2010',
            '124' => '124 - Suspensão - Matérias-primas (MP), produtos intermediários (PI) e material de embalagem (ME) adquiridos por pessoas jurídicas preponderantemente exportadoras - Art. 46 Inciso III do Decreto 7.212/2010',
            '125' => '125 - Suspensão - Materiais e equipamentos destinados a embarcações pré-registradas ou registradas no Registro Especial Brasileira - REB quando adquiridos por estaleiros navais brasileiros - Art. 46 Inciso IV do Decreto 7.212/2010',
            '126' => '126 - Suspensão - Aquisição por beneficiário de regime aduaneiro suspensivo do imposto, destinado a industrialização para exportação - Art. 47 do Decreto 7.212/2010',
            '127' => '127 - Suspensão - Desembaraço de produtos de procedência estrangeira importados por lojas francas - Art. 48 Inciso I do Decreto 7.212/2010',
            '128' => '128 - Suspensão - Desembaraço de maquinas, equipamentos, veículos, aparelhos e instrumentos sem similar nacional importados por empresas nacionais de engenharia, destinados à execução de obras no exterior - Art. 48 Inciso II do Decreto 7.212/2010',
            '129' => '129 - Suspensão - Desembaraço de produtos de procedência estrangeira com saída de repartições aduaneiras com suspensão do Imposto de Importação - Art. 48 Inciso III do Decreto 7.212/2010',
            '130' => '130 - Suspensão - Desembaraço de matérias-primas, produtos intermediários e materiais de embalagem, importados diretamente por estabelecimento de que tratam os incisos I a III do caput do Decreto 7.212/2010 - Art. 48 Inciso IV do Decreto 7.212/2010',
            '131' => '131 - Suspensão - Remessa de produtos para a ZFM destinados ao seu consumo interno, utilização ou industrialização - Art. 84 do Decreto 7.212/2010',
            '132' => '132 - Suspensão - Remessa de produtos para a ZFM destinados à exportação - Art. 85 Inciso I do Decreto 7.212/2010',
            '133' => '133 - Suspensão - Produtos que, antes de sua remessa à ZFM, forem enviados pelo seu fabricante a outro estabelecimento, para industrialização adicional, por conta e ordem do destinatário - Art. 85 Inciso II do Decreto 7.212/2010',
            '134' => '134 - Suspensão - Desembaraço de produtos de procedência estrangeira importados pela ZFM quando ali consumidos ou utilizados, exceto armas, munições, fumo, bebidas alcoólicas e automóveis de passageiros. - Art. 86 do Decreto 7.212/2010',
            '135' => '135 - Suspensão - Remessa de produtos para a Amazônia Ocidental destinados ao seu consumo interno ou utilização - Art. 96 do Decreto 7.212/2010',
            '136' => '136 - Suspensão - Entrada de produtos estrangeiros na Área de Livre Comércio de Tabatinga - ALCT destinados ao seu consumo interno ou utilização - Art. 106 do Decreto 7.212/2010',
            '137' => '137 - Suspensão - Entrada de produtos estrangeiros na Área de Livre Comércio de Guajará-Mirim - ALCGM destinados ao seu consumo interno ou utilização - Art. 109 do Decreto 7.212/2010',
            '138' => '138 - Suspensão - Entrada de produtos estrangeiros nas Áreas de Livre Comércio de Boa Vista - ALCBV e Bomfim - ALCB destinados a seu consumo interno ou utilização - Art. 112 do Decreto 7.212/2010',
            '139' => '139 - Suspensão - Entrada de produtos estrangeiros na Área de Livre Comércio de Macapá e Santana - ALCMS destinados a seu consumo interno ou utilização - Art. 116 do Decreto 7.212/2010',
            '140' => '140 - Suspensão - Entrada de produtos estrangeiros nas Áreas de Livre Comércio de Brasiléia - ALCB e de Cruzeiro do Sul - ALCCS destinados a seu consumo interno ou utilização - Art. 119 do Decreto 7.212/2010',
            '141' => '141 - Suspensão - Remessa para Zona de Processamento de Exportação - ZPE - Art. 121 do Decreto 7.212/2010',
            '142' => '142 - Suspensão - Setor Automotivo - Desembaraço aduaneiro, chassis e outros - regime aduaneiro especial - industrialização 87.01 a 87.05 - Art. 1 3 6 , 1 do Decreto 7.212/2010',
            '143' => '143 - Suspensão - Setor Automotivo - Do estabelecimento industrial produtos 87.01 a 87.05 da TIPI - mercado interno - empresa comercial atacadista controlada por PJ encomendante do exterior. - Art.136, II do Decreto 7.212/2010',
            '144' => '144 - Suspensão - Setor Automotivo - Do estabelecimento industrial - chassis e outros classificados nas posições 84.29, 84.32, 84.33, 87.01 a 87.06 e 87.11 da TIPI. - Art. 136, III do Decreto 7.212/2010',
            '145' => '145 - Suspensão - Setor Automotivo - Desembaraço aduaneiro, chassis e outros classificados nas posições 84.29, 84.32, 84.33, 87.01 a 87.06 e 87.11 da TIPI quando importados diretamente por estabelecimento industrial - Art. 136, IV do Decreto 7.212/2010',
            '146' => '146 - Suspensão - Setor Automotivo - do estabelecimento industrial matérias-primas, os produtos intermediários e os materiais de embalagem, adquiridos por fabricantes, preponderantemente, decomponentes, chassis e outros classificados nos Códigos 84.29, 8432.40.00, 8432.80.00,8433.20, 8433.30.00, 8433.40.00, 8433.5 e 87.01 a 87.06 da TIPI - Art. 136, V do Decreto 7.212/2010',
            '147' => '147 - Suspensão - Setor Automotivo - Desembaraço aduaneiro, as matérias-primas, os produtos intermediários e os materiais de embalagem, importados diretamente por fabricantes, preponderantemente,de componentes, chassis e outros classificados nos Códigos 84.29, 8432.40.00, 8432.80.00,8433.20, 8433.30.00, 8433.40.00, 8433.5 e 87.01 a 87.06 da TIPI - Art. 136, VI do Decreto 7.212/2010',
            '148' => '148 - Suspensão - Bens de Informática e Automação - matérias-primas, os produtos intermediários e os materiais de embalagem, quando adquiridos por estabelecimentos industriais fabricantes dos referidos bens. - Art. 148 do Decreto 7.212/2010',
            '149' => '149 - Suspensão - Reporto - Saída de Estabelecimento de máquinas e outros quando adquiridos por beneficiários do REPORTO - Art. 166, I do Decreto 7.212/2010',
            '150' => '150 - Suspensão - Reporto - Desembaraço aduaneiro de máquinas e outros quando adquiridos por beneficiários do REPORTO - Art. 166, II do Decreto 7.212/2010',
            '151' => '151 - Suspensão - Repes - Desembaraço aduaneiro - bens sem sim ilar nacional importados por beneficiários do REPES - Art. 171 do Decreto 7.212/2010',
            '152' => '152 - Suspensão - Recine - Saída para beneficiário do regime - Art. 14, III da Lei 12.599/2012',
            '153' => '153 - Suspensão - Recine - Desembaraço aduaneiro por beneficiário do regime - Art. 14, IV da Lei 12.599/2012',
            '154' => '154 - Suspensão - Reif- Saída para beneficiário do regime - Lei 12.794/1013, art. 8, III',
            '155' => '155 - Suspensão - Reif - Desembaraço aduaneiro por beneficiário do regime - Lei 12.794/1013, art. 8, IV',
            '156' => '156 - Suspensão - Repnbl-Redes - Saída para beneficiário do regime - Lei n° 12.715/2012, art. 30, II',
            '157' => '157 - Suspensão - Recompe - Saída de matérias-primas e produtos intermediários para beneficiários do regime - Decreto n° 7.243/2010, art. 5o, I',
            '158' => '158 - Suspensão - Recompe - Saída de matérias-primas e produtos intermediários destinados a industrialização de equipamentos - Program a Estímulo Universidade-Empresa - Apoio à Inovação - Decreto n° 7.243/2010, art. 5o, III',
            '159' => '159 - Suspensão - Rio 2016 - Produtos nacionais, duráveis, uso e consumo dos eventos, adquiridos pelas pessoas jurídicas mencionadas no § 2o do art. 4o da Lei n° 12.780/2013 - Lei n° 12.780/2013, Art. 13',
            '160' => '160 - Suspensão - Regime Especial de Admissão Temporária nos Term os do Art. 2o da IN 1361/2013',
            '161' => '161 - Suspensão - Regime Especial de Admissão Temporária nos term os do art. 5o da IN 1361/2013',
            '162' => '162 - Suspensão - Regime Especial de Admissão Temporária nos term os do art. 7o da IN 1361/2013(Suspensão com pagamento de tributos diferidos até a duração do regime, limitado a 100%do valor original)',
            '163' => '163 - Suspensão - REPETRO-Industrialização Venda no mercado interno de matérias-primas, produtos intermediários e materiais de embalagem para serem utilizados integralmente no processo de industrialização de produto final destinado às atividades de exploração, de desenvolvimento e de produção de petróleo, de gás natural e de outros hidrocarbonetos fluidos à PJ habilitada no Repetro-Industrialização. - Instrução Normativa RFB nº 1901, de 17 de julho de 2019.',
            '164' => '164 - Suspensão - REPETRO-SPED Venda dos produtos finais destinados às atividades de exploração, de desenvolvimento e de produção de petróleo, de gás natural e de outros hidrocarbonetos fluidos previstas na Lei nº 9.478, de 6 de agosto de 1997 , na Lei nº 12.276, de 30 de junho de 2010, e na Lei nº 12.351, de 22 de dezembro de 2010, por fabricantes desses, beneficiários do Repetro-Industrialização, quando diretamente adquiridos por pessoa jurídica habilitada no Repetro-Sped.- Instrução Normativa RFB nº 1901, de 17 de julho de 2019.',
            '165' => '165 - Suspensão - O transportador com relação aos produtos tributados que transportar desacompanhados da documentação comprobatória de sua procedência; qualquer possuidor - com relação aos produtos tributados cuja posse mantiver para fins de venda ou industrialização; o industrial ou equiparado, mediante requerimento, nas operações anteriores, concomitantes ou posteriores às saídas que promover, nas hipóteses e condições estabelecidas pela Secretaria da Receita Federal, nos termos da IN RFB nº 1.081/2010.',

            '301' => '301 - Isenção - Produtos industrializados por instituições de educação ou de assistência social, destinados auso próprio ou a distribuição gratuita a seus educandos ou assistidos - Art. 54 Inciso I doDecreto 7.212/2010',
            '302' => '302 - Isenção - Produtos industrializados por estabelecimentos públicos e autárquicos da União, dos Estados, do Distrito Federal e dos Municípios, não destinados a comércio - Art. 54 Inciso II do Decreto 7.212/2010',
            '303' => '303 - Isenção - Amostras de produtos para distribuição gratuita, de diminuto ou nenhum valor comercial - Art. 54 Inciso III do Decreto 7.212/2010',
            '304' => '304 - Isenção - Amostras de tecidos sem valor comercial - Art. 54 Inciso IV do Decreto 7.212/2010',
            '305' => '305 - Isenção - Pés isolados de calçados - Art. 54 Inciso V do Decreto 7.212/2010',
            '306' => '306 - Isenção - Aeronaves de uso militar e suas partes e peças, vendidas à União - Art. 54 Inciso VI do Decreto 7.212/2010',
            '307' => '307 - Isenção - Caixões funerários - Art. 54 Inciso VII do Decreto 7.212/2010',
            '308' => '308 - Isenção - Papel destinado à impressão de músicas - Art. 54 Inciso VIII do Decreto 7.212/2010',
            '309' => '309 - Isenção - Panelas e outros artefatos semelhantes, de uso doméstico, de fabricação rústica, de pedra ou barro bruto - Art. 54 Inciso IX do Decreto 7.212/2010',
            '310' => '310 - Isenção - Chapéus, roupas e proteção, de couro, próprios para tropeiros - Art. 54 Inciso X do Decreto 7.212/2010',
            '311' => '311 - Isenção - Material bélico, de uso privativo das Forças Armadas, vendido à União - Art. 54 Inciso XI do Decreto 7.212/2010',
            '312' => '312 - Isenção - Automóvel adquirido diretamente a fabricante nacional, pelas missões diplomáticas e repartições consulares de caráter permanente, ou seus integrantes, bem assim pelas representações internacionais ou regionais de que o Brasil seja membro, e seus funcionários,peritos, técnicos e consultores, de nacionalidade estrangeira, que exerçam funções de caráter permanente - Art. 54 Inciso XII do Decreto 7.212/2010',
            '313' => '313 - Isenção - Veículo de fabricação nacional adquirido por funcionário das missões diplomáticas acreditadas junto ao Governo Brasileiro - Art. 54 Inciso XIII do Decreto 7.212/2010',
            '314' => '314 - Isenção - Produtos nacionais saídos diretamente para Lojas Francas - Art. 54 Inciso XIV do Decreto 7.212/2010',
            '315' => '315 - Isenção - Materiais e equipamentos destinados a Itaipu Binacional - Art. 54 Inciso X V do Decreto 7.212/2010',
            '316' => '316 - Isenção - Produtos Importados por missões diplomáticas, consulados ou organismo internacional - Art. 54 Inciso XVI do Decreto 7.212/2010',
            '317' => '317 - Isenção - Bagagem de passageiros desembaraçada com isenção do II. - Art. 54 Inciso XVII do Decreto 7.212/2010',
            '318' => '318 - Isenção - Bagagem de passageiros desembaraçada com pagamento do II. - Art. 54 Inciso XVIII do Decreto 7.212/2010',
            '319' => '319 - Isenção - Remessas postais internacionais sujeitas a tributação simplificada. - Art. 54 Inciso XIX do Decreto 7.212/2010',
            '320' => '320 - Isenção - Máquinas e outros destinados à pesquisa científica e tecnológica - Art. 54 Inciso XX do Decreto 7.212/2010',
            '321' => '321 - Isenção - Produtos de procedência estrangeira, isentos do II conforme Lei n° 8032/1990. - Art. 54 Inciso XXI do Decreto 7.212/2010',
            '322' => '322 - Isenção - Produtos de procedência estrangeira utilizados em eventos esportivos - Art. 54 Inciso XXII do Decreto 7.212/2010',
            '323' => '323 - Isenção - Veículos automotores, máquinas, equipamentos, bem assim suas partes e peças separadas, destinadas à utilização nas atividades dos Corpos de Bombeiros - Art. 54 Inciso XXIII do Decreto 7.212/2010',
            '324' => '324 - Isenção - Produtos importados para consumo em congressos, feiras e exposições - Art. 54 Inciso XXIV do Decreto 7.212/2010',
            '325' => '325 - Isenção - Bens de informática, Matéria Prima, produtos intermediários e embalagem destinados a Urnas eletrônicas - TSE - Art. 54 Inciso XXV do Decreto 7.212/2010',
            '326' => '326 - Isenção - Materiais, equipamentos, máquinas, aparelhos e instrumentos, bem assim os respectivos acessórios, sobressalentes e ferramentas, que os acompanhem, destinados à construção do Gasoduto Brasil - Bolívia - Art. 54 Inciso )0',
            '327' => '327 - Isenção - Partes, peças e componentes, adquiridos por estaleiros navais brasileiros, destinados ao emprego na conservação, modernização, conversão ou reparo de embarcações registradas no Registro Especial Brasileiro - REB - Art. 54 Inciso XXVII do Decreto 7.212/2010',
            '328' => '328 - Isenção - Aparelhos transmissores e receptores de radiotelefonia e radiotelegrafia; veículos para patrulhamento policial; armas e munições, destinados a órgãos de segurança pública da União, dos Estados e do Distrito Federal - Art. 54 Inciso XXVIII do Decreto 7.212/2010',
            '329' => '329 - Isenção - Automóveis de passageiros de fabricação nacional destinados à utilização como táxi adquiridos por motoristas profissionais - Art. 55 Inciso I do Decreto 7.212/2010',
            '330' => '330 - Isenção - Automóveis de passageiros de fabricação nacional destinados à utilização como táxi por impedidos de exercer atividade por destruição, furto ou roubo do veículo adquiridos por motoristas profissionais. - Art. 55 Inciso II do Decreto 7.212/2010',
            '331' => '331 - Isenção - Automóveis de passageiros de fabricação nacional destinados à utilização como táxi adquiridos por cooperativas de trabalho. - Art. 55 Inciso II do Decreto 7.212/2010',
            '332' => '332 - Isenção - Automóveis de passageiros de fabricação nacional, destinados a pessoas portadoras de deficiência física, visual, mental severa ou profunda, ou autistas - Art. 55 Inciso IV do Decreto 7.212/2010',
            '333' => '333 - Isenção - Produtos estrangeiros, recebidos em doação de representações diplomáticas estrangeiras sediadas no País, vendidos em feiras, bazares e eventos semelhantes por entidades beneficentes - Art. 67 do Decreto 7.212/2010',
            '334' => '334 - Isenção - Produtos industrializados na Zona Franca de Manaus - ZFM, destinados ao seu consumo interno - Art. 81 Inciso I do Decreto 7.212/2010',
            '335' => '335 - Isenção - Produtos industrializados na ZFM, por estabelecimentos com projetos aprovados pela SUFRAMA, destinados a comercialização em qualquer outro ponto do Território Nacional -Art. 81 Inciso II do Decreto 7.212/2010',
            '336' => '336 - Isenção - Produtos nacionais destinados à entrada na ZFM, para seu consumo interno, utilização ou industrialização, ou ainda, para serem remetidos, por intermédio de seus entrepostos, à Amazônia Ocidental - Art. 81 Inciso III do Decreto 7.212/2010',
            '337' => '337 - Isenção - Produtos industrializados por estabelecimentos com projetos aprovados pela SUFRAMA, consumidos ou utilizados na Amazônia Ocidental, ou adquiridos através da ZFM ou de seus entrepostos na referida região - Art. 95 Inciso I do Decreto 7.212/2010',
            '338' => '338 - Isenção - Produtos de procedência estrangeira, relacionados na legislação, oriundos da ZFM e que derem entrada na Amazônia Ocidental para ali serem consumidos ou utilizados: - Art. 95 Inciso II do Decreto 7.212/2010',
            '339' => '339 - Isenção - Produtos elaborados com matérias-primas agrícolas e extrativas vegetais de produção regional, por estabelecimentos industriais localizados na Amazônia Ocidental, com projetos aprovados pela SUFRAM A - Art. 95 Inciso III do Decreto 7.212/2010',
            '340' => '340 - Isenção - Produtos industrializados em Área de Livre Comércio - Art. 105 do Decreto 7.212/2010',
            '341' => '341 - Isenção - Produtos nacionais ou nacionalizados, destinados à entrada na Área de Livre Comércio de Tabatinga - ALCT - Art. 107 do Decreto 7.212/2010',
            '342' => '342 - Isenção - Produtos nacionais ou nacionalizados, destinados à entrada na Área de Livre Comércio de Guaiará-Mirim - ALCGM - Art. 110 do Decreto 7.212/2010',
            '343' => '343 - Isenção - Produtos nacionais ou nacionalizados, destinados à entrada nas Áreas de Livre Comércio de Boa Vista - ALCBV e Bonfim - ALCB - Art. 113 do Decreto 7.212/2010',
            '344' => '344 - Isenção - Produtos nacionais ou nacionalizados, destinados à entrada na Área de Livre Comércio de Macapá e Santana - ALCMS - Art. 117 do Decreto 7.212/2010',
            '345' => '345 - Isenção - Produtos nacionais ou nacionalizados, destinados à entrada nas Áreas de Livre Comércio de Brasiléia - ALCB e de Cruzeiro do Sul - ALCCS - Art. 120 do Decreto 7.212/2010',
            '346' => '346 - Isenção - Recompe - equipamentos de informática - de beneficiário do regime para escolas das redes públicas de ensino federal, estadual, distrital, municipal ou nas escolas sem fins lucrativos de atendimento a pessoas com deficiência - Decreto n° 7.243/2010, art. 7o',
            '347' => '347 - Isenção - Rio 2016 - Importação de materiais para os jogos (medalhas, troféus, impressos, bens não duráveis, etc.) - Lei n° 12.780/2013, Art. 4o, §1°, I',
            '348' => '348 - Isenção - Rio 2016 - Suspensão convertida em Isenção - Lei n° 12.780/2013, Art. 6o, I',
            '349' => '349 - Isenção - Rio 2016 - Empresas vinculadas ao CIO - Lei n° 12.780/2013, Art. 9o, I, d',
            '350' => '350 - Isenção - Rio 2016 - Saída de produtos importados pelo RIO 2016 - Lei n° 12.780/2013, Art. 10, I, d',
            '351' => '351 - Isenção - Rio 2016 - Produtos nacionais, não duráveis, uso e consumo dos eventos, adquiridos pelas pessoas jurídicas mencionadas no § 2o do art. 4o da Lei n° 12.780/2013 - Lei n° 12.780/2013,Art. 12',



            '601' => '601 - Redução - Equipamentos e outros destinados à pesquisa e ao desenvolvimento tecnológico - Art. 72 do Decreto 7.212/2010',
            '602' => '602 - Redução - Equipamentos e outros destinados à empresas habilitadas no PDTI e PDTA utilizados em pesquisa e ao desenvolvimento tecnológico - Art. 73 do Decreto 7.212/2010 ',
            '603' => '603 - Redução - Microcomputadores e outros de até R$11.000,00, unidades de disco, circuitos, etc, destinados a bens de informática ou automação. Centro-Oeste SUDAM SUDENE - Art. 142,I do Decreto 7.212/2010',
            '604' => '604 - Redução - Microcomputadores e outros de até R$11.000,00, unidades de disco, circuitos, etc, destinados a bens de informática ou automação. - Art. 1 4 2 ,1 do Decreto 7.212/2010',
            '605' => '605 - Redução - Bens de informática não incluídos no art. 142 do Decreto 7.212/2010 - Produzidos no Centro-Oeste, SUDAM, SUDENE - Art. 1 4 3 ,1 do Decreto 7.212/2010',
            '606' => '606 - Redução - Bens de informática não incluídos no art. 142 do Decreto 7.212/2010 - Art. 143, II do Decreto 7.212/2010',
            '607' => '607 - Redução - Padis - Art. 150 do Decreto 7.212/2010',
            '608' => '608 - Redução - Patvd - Art. 158 do Decreto 7.212/2010',
            '999' => '999 - Outros - Tributação normal IPI; Outros;',

        ];
    }

    public static function modalidadesDeterminacao(){
        return [
            '0' => 'Margem Valor Agregado (%)',
            '1' => 'Pauta (Valor)',
            '2' => 'Preço Tabelado Máx. (valor)',
            '3' => 'Valor da operação'
        ];
    }

    public static function modalidadesDeterminacaoST(){
        return [
            '0' => 'Preço tabelado ou máximo sugerido',
            '1' => 'Lista Negativa (valor)',
            '2' => 'Lista Positiva (valor)',
            '3' => 'Lista Neutra (valor)',
            '4' => 'Margem Valor Agregado (%)',
            '5' => 'Pauta (valor)',
            '6' => 'Valor da Operação'
        ];
    }
    
    public static function getUFs(){
        return [
            '11' => 'RO',
            '12' => 'AC',
            '13' => 'AM',
            '14' => 'RR',
            '15' => 'PA',
            '16' => 'AP',
            '17' => 'TO',
            '21' => 'MA',
            '22' => 'PI',
            '23' => 'CE',
            '24' => 'RN',
            '25' => 'PB',
            '26' => 'PE',
            '27' => 'AL',
            '28' => 'SE',
            '29' => 'BA',
            '31' => 'MG',
            '32' => 'ES',
            '33' => 'RJ',
            '35' => 'SP',
            '41' => 'PR',
            '42' => 'SC',
            '43' => 'RS',
            '50' => 'MS',
            '51' => 'MT',
            '52' => 'GO',
            '53' => 'DF'
        ];

    }
}
