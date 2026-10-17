<?php

namespace App\Utils;

use App\Models\Cte;

/** Consulta exclusiva da listagem: agregados independentes preservam uma linha por CT-e. */
final class CteListQuery
{
    private $businessId;

    public function __construct($businessId)
    {
        $this->businessId = (int) $businessId;
    }

    public function query()
    {
        $connection = (new Cte)->getConnection();
        // Não presumir que o schema implantado corresponde à migration, nem truncar posições.
        $column = $connection->selectOne("SELECT CHARACTER_MAXIMUM_LENGTH AS max_length FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='chave_nfe'", [$connection->getTablePrefix() . 'ctes']);
        if (!$column || $column->max_length === null || (int) $column->max_length > CteListPresenter::MAX_SERIALIZED_LENGTH) {
            throw new \LengthException('Schema de chave_nfe não suportado na listagem: exige campo de até 450 caracteres.');
        }
        $query = Cte::query()->where('ctes.business_id', $this->businessId)
            ->select([
                'ctes.id','ctes.business_id','ctes.cte_numero','ctes.valor_transporte','ctes.valor_receber','ctes.valor_carga',
                'ctes.produto_predominante','ctes.data_previsata_entrega','ctes.estado','ctes.created_at',
                'ctes.chave_nfe','ctes.tpDoc','ctes.nDoc','ctes.descOutros','ctes.vDocFisc','ctes.tomador',
                'city.nome AS cidade_nome','city.uf AS cidade_uf','taker.name AS tomador_nome',
                'sender.name AS remetente_nome','recipient.name AS destinatario_nome',
            ])
            ->selectRaw('COALESCE(dc.document_count,0) AS document_count')
            ->selectRaw("CASE WHEN w.valid_count=1 AND w.invalid_count=0 THEN w.kg END AS peso_valor")
            ->selectRaw("CASE WHEN w.valid_count>1 THEN 'multiple' WHEN w.invalid_count>0 THEN 'invalid' WHEN w.valid_count=1 THEN 'single' ELSE 'missing' END AS weight_status")
            ->selectRaw($this->roleSql() . ' AS tomador_papel')
            ->leftJoinSub('SELECT d.cte_id,COUNT(*) AS document_count FROM (' . $this->documentsSql() . ') d GROUP BY d.cte_id', 'dc', 'dc.cte_id', '=', 'ctes.id')
            ->leftJoinSub($this->weightsSql(), 'w', 'w.cte_id', '=', 'ctes.id')
            ->leftJoin('cities AS city', 'city.id', '=', 'ctes.municipio_fim');
        $contact = 'CASE ctes.tomador WHEN 0 THEN ctes.remetente_id WHEN 1 THEN ctes.expedidor_id WHEN 2 THEN ctes.recebedor_id WHEN 3 THEN ctes.destinatario_id WHEN 4 THEN ctes.tomador_outros_id END';
        foreach (['taker'=>$contact, 'sender'=>'ctes.remetente_id', 'recipient'=>'ctes.destinatario_id'] as $alias=>$id) {
            $query->leftJoin('contacts AS ' . $alias, function ($join) use ($alias,$id,$connection) {
                $join->on($alias . '.id', '=', $connection->raw($id))
                    ->on($alias . '.business_id', '=', 'ctes.business_id')->whereNull($alias . '.deleted_at');
            });
        }
        // Eloquent só carrega anteriores da página, em uma consulta, sem carregar o original.
        return $query->with(['documentosAnteriores'=>function ($query) {
            $query->select(['id','cte_id','chave_cte','nome_emitente'])->orderBy('id');
        }]);
    }

    public function configure($table)
    {
        // Todos os campos pesquisáveis usam o mesmo escape literal; deixar parte
        // para o LIKE padrão do Yajra reintroduziria curingas na busca global.
        $filters = ['documentos','cidade_destino','peso_kg','tomador_servico','numero_cte','remetente','destinatario',
            'valor_transporte','valor_receber','valor_carga','produto_predominante','data_previsata_entrega','estado','created_at'];
        foreach ($filters as $column) {
            $table->filterColumn($column, function ($query,$keyword) use ($column) { $this->filter($query,$column,$keyword); });
            $table->orderColumn($column, function ($query,$direction) use ($column) { $this->order($query,$column,$direction); });
        }
        // Aplicar todos os critérios solicitados primeiro; ID é apenas o último desempate.
        $table->order(function ($query) {
            $request = app('datatables.request');
            $query->reorder();
            foreach ($request->orderableColumns() as $order) {
                $this->order($query, $request->columnName($order['column']), $order['direction']);
            }
            $query->orderBy('ctes.id','desc');
        });
        // Evita executar o agregado de documentos na contagem total não filtrada.
        $table->setTotalRecords(Cte::where('business_id',$this->businessId)->count());
        return $table;
    }

    public function filter($query,$column,$keyword)
    {
        $pattern = '%' . strtr((string)$keyword, ['!'=>'!!','%'=>'!%','_'=>'!_']) . '%';
        if ($column === 'documentos') {
            $query->where(function ($query) use ($pattern) {
                $query->whereRaw('ctes.id IN (SELECT d.cte_id FROM (' . $this->documentsSql() . ') d WHERE ' . $this->searchText('d.search_text') . " LIKE ? ESCAPE '!')", [$pattern]);
                $query->orWhereExists(function ($previous) use ($pattern) {
                    $previous->selectRaw('1')->from('cte_documentos_anteriores AS issuer_doc')
                        ->whereColumn('issuer_doc.cte_id','ctes.id')
                        ->whereRaw('OCTET_LENGTH(' . $this->compact("COALESCE(issuer_doc.chave_cte,'')") . ')>0')
                        ->whereRaw($this->searchText('issuer_doc.nome_emitente') . " LIKE ? ESCAPE '!'", [$pattern])
                        // Mesmo emitente escolhido pelo apresentador: menor ID não vazio por chave binária.
                        ->whereNotExists(function ($earlier) {
                            $earlier->selectRaw('1')->from('cte_documentos_anteriores AS earlier_doc')
                                ->whereColumn('earlier_doc.cte_id','issuer_doc.cte_id')
                                ->whereColumn('earlier_doc.id','<','issuer_doc.id')
                                ->whereRaw('CAST(' . $this->compact("COALESCE(earlier_doc.chave_cte,'')") . ' AS BINARY)=CAST(' . $this->compact("COALESCE(issuer_doc.chave_cte,'')") . ' AS BINARY)')
                                ->whereRaw('OCTET_LENGTH(' . $this->trimmed("COALESCE(earlier_doc.nome_emitente,'')") . ')>0');
                        });
                });
            });
            return;
        }
        if ($column === 'peso_kg') {
            $number = CteListPresenter::numericSearch($keyword);
            if ($number === null || strlen(explode('.',$number)[0]) > 15) { $query->whereRaw('1=0'); return; }
            $query->whereRaw('w.valid_count=1 AND w.invalid_count=0 AND w.kg=CAST(? AS DECIMAL(19,4))', [$number]);
            return;
        }
        $expressions = [
            'cidade_destino'=>['city.nome','city.uf'],
            'tomador_servico'=>['taker.name',$this->roleSql()],
            'numero_cte'=>['ctes.cte_numero'], 'remetente'=>['sender.name'], 'destinatario'=>['recipient.name'],
            'valor_transporte'=>['ctes.valor_transporte'], 'valor_receber'=>['ctes.valor_receber'],
            'valor_carga'=>['ctes.valor_carga'], 'produto_predominante'=>['ctes.produto_predominante'],
            'data_previsata_entrega'=>['ctes.data_previsata_entrega'], 'estado'=>['ctes.estado'], 'created_at'=>['ctes.created_at'],
        ];
        if (!isset($expressions[$column])) { return; }
        $query->where(function ($query) use ($expressions,$column,$pattern) {
            foreach ($expressions[$column] as $expression) { $query->orWhereRaw($this->searchText($expression) . " LIKE ? ESCAPE '!'", [$pattern]); }
        });
    }

    public function order($query,$column,$direction)
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        $columns = [
            'documentos'=>['document_count'], 'cidade_destino'=>['city.nome','city.uf'],
            'tomador_servico'=>['taker.name','tomador_papel'], 'numero_cte'=>['ctes.cte_numero'],
            'remetente'=>['sender.name'], 'destinatario'=>['recipient.name'],
        ];
        if ($column === 'peso_kg') {
            $query->orderByRaw('(peso_valor IS NULL) ASC')->orderBy('peso_valor',$direction);
            return;
        }
        if (in_array($column,['cte_numero','valor_transporte','valor_receber','valor_carga','produto_predominante','data_previsata_entrega','estado','created_at'],true)) {
            $columns[$column] = ['ctes.' . $column];
        }
        foreach ($columns[$column] ?? [] as $expression) { $query->orderBy($expression,$direction); }
    }

    private function roleSql()
    {
        $sql = 'CASE ctes.tomador';
        foreach (CteListPresenter::ROLES as $code=>$role) { $sql .= ' WHEN ' . $code . " THEN '" . $role . "'"; }
        return $sql . ' END';
    }

    private function searchText($expression)
    {
        // Busca humana não herda a collation binária usada exclusivamente na identidade.
        return 'CONVERT(' . $expression . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci';
    }

    private function compact($expression)
    {
        foreach ([32,9,10,13,11,12] as $ascii) { $expression = 'REPLACE(' . $expression . ',CHAR(' . $ascii . "),'')"; }
        return $expression;
    }

    private function trimmed($expression)
    {
        foreach ([9,10,13,11,12] as $ascii) { $expression = 'REPLACE(' . $expression . ',CHAR(' . $ascii . "),' ')"; }
        return 'TRIM(' . $expression . ')';
    }

    private function documentsSql()
    {
        $tenant = $this->businessId;
        $serialized = 'REPLACE(' . $this->compact("CONVERT(COALESCE(c.chave_nfe,'') USING utf8mb4)") . ",',',';')";
        // LIMIT máximo bloqueia derived-merge: normalizar uma vez ANTES de expandir posições.
        $prepared = 'SELECT normalized.*,1+CHAR_LENGTH(normalized.serialized)-CHAR_LENGTH(REPLACE(normalized.serialized,\';\',\'\')) AS segment_count FROM (SELECT c.id,' . $serialized . ' AS serialized FROM ctes c WHERE c.business_id=' . $tenant . ' LIMIT 18446744073709551615) normalized LIMIT 18446744073709551615';
        $digits = '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        // Podar centenas e dezenas ANTES de expandir unidades. O derived ordinal
        // anterior era fundido pelo otimizador e gerava 500 combinações por CT-e.
        // O schema validado de 450 caracteres continua cobrindo até 451 segmentos.
        $hundreds = '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4)';
        $position = '(100*h.d+10*t.d+u.d+1)';
        $nfe = "SELECT p.id AS cte_id,'nfe_carga' AS categoria,SUBSTRING_INDEX(SUBSTRING_INDEX(p.serialized,';'," . $position . "),';',-1) COLLATE utf8mb4_bin AS chave FROM (" . $prepared . ') p'
            . ' STRAIGHT_JOIN ' . $hundreds . ' h ON 100*h.d<p.segment_count'
            . ' STRAIGHT_JOIN ' . $digits . ' t ON 100*h.d+10*t.d<p.segment_count'
            . ' STRAIGHT_JOIN ' . $digits . ' u ON ' . $position . '<=p.segment_count';
        $previous = "SELECT a.cte_id,'cte_anterior' AS categoria," . $this->compact("CONVERT(COALESCE(a.chave_cte,'') USING utf8mb4)") . ' COLLATE utf8mb4_bin AS chave FROM cte_documentos_anteriores a JOIN ctes owner ON owner.id=a.cte_id AND owner.business_id=' . $tenant;
        $dedup = 'SELECT k.cte_id,k.categoria,k.chave FROM (' . $nfe . ' UNION ALL ' . $previous . ') k WHERE OCTET_LENGTH(k.chave)>0 GROUP BY k.cte_id,k.categoria,k.chave';
        $valid = "CHAR_LENGTH(k.chave)=44 AND k.chave REGEXP '^[0-9]{44}$' AND SUBSTRING(k.chave,21,2)=CASE k.categoria WHEN 'nfe_carga' THEN '55' ELSE '57' END";
        $number = "COALESCE(NULLIF(TRIM(LEADING '0' FROM SUBSTRING(k.chave,26,9)),''),'0')";
        $series = "COALESCE(NULLIF(TRIM(LEADING '0' FROM SUBSTRING(k.chave,23,3)),''),'0')";
        $classified = 'SELECT k.*,CASE WHEN ' . $valid . ' THEN ' . $number . ' END AS numero,CASE WHEN ' . $valid . ' THEN ' . $series . ' END AS serie FROM (' . $dedup . ') k';
        $keys = "SELECT d.cte_id,CONCAT(CASE d.categoria WHEN 'nfe_carga' THEN 'NF-e' ELSE 'CT-e anterior' END,' ',COALESCE(d.numero,''),' ',COALESCE(d.serie,''),' ',d.chave) AS search_text FROM (" . $classified . ') d';
        $type = 'CASE CAST(c.tpDoc AS BINARY)';
        foreach (CteListPresenter::OTHER_TYPES as $code=>$label) { $type .= " WHEN '" . $code . "' THEN '" . $label . "'"; }
        $type .= " ELSE CONCAT('Tipo desconhecido (',COALESCE(c.tpDoc,''),')') END";
        $other = "SELECT c.id AS cte_id,CONCAT(" . $type . ",' ',COALESCE(NULLIF(c.nDoc,0),''),' ',COALESCE(c.descOutros,''),' ',COALESCE(NULLIF(c.vDocFisc,0),'')) AS search_text FROM ctes c WHERE c.business_id=" . $tenant . ' AND (COALESCE(c.nDoc,0)<>0 OR COALESCE(c.vDocFisc,0)<>0 OR OCTET_LENGTH(' . $this->trimmed("COALESCE(c.descOutros,'')") . ')>0)';
        return $keys . ' UNION ALL ' . $other;
    }

    private function weightsSql()
    {
        // Validar ASCII antes de UPPER: Unicode como ſ não pode virar o S fiscal.
        $type = 'CONVERT(' . $this->trimmed('m.tipo_medida') . ' USING utf8mb4)';
        $mass = 'OCTET_LENGTH(' . $type . ')=CHAR_LENGTH(' . $type . ') AND CAST(UPPER(' . $type . ") AS BINARY)='PESO BRUTO' AND CAST(m.cod_unidade AS BINARY) IN ('01','02')";
        $valid = $mass . ' AND m.quantidade_carga>=0';
        return 'SELECT m.cte_id,SUM(CASE WHEN ' . $valid . ' THEN 1 ELSE 0 END) AS valid_count,SUM(CASE WHEN ' . $mass . ' AND (m.quantidade_carga<0 OR m.quantidade_carga IS NULL) THEN 1 ELSE 0 END) AS invalid_count,MAX(CASE WHEN ' . $valid . " THEN CAST(m.quantidade_carga*CASE CAST(m.cod_unidade AS BINARY) WHEN '02' THEN 1000 ELSE 1 END AS DECIMAL(15,4)) END) AS kg FROM medida_ctes m JOIN ctes owner ON owner.id=m.cte_id AND owner.business_id=" . $this->businessId . ' GROUP BY m.cte_id';
    }
}
