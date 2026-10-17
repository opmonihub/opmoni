<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CteDocumentoAnterior extends Model
{
    protected $table = 'cte_documentos_anteriores';
    
    protected $fillable = [
        'cte_id',
        'chave_cte',
        'cnpj_emitente',
        'ie_emitente',
        'uf_emitente',
        'nome_emitente'
    ];

    public function cte()
    {
        return $this->belongsTo(Cte::class, 'cte_id');
    }
}
