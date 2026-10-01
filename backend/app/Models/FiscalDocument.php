<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use Database\Factories\FiscalDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_id', 'source', 'model', 'kind', 'stage', 'chave_acesso', 'numero', 'serie', 'event_id', 'nsu', 'emitente_cnpj', 'destinatario_cnpj', 'valor_total', 'emissao_at', 'evento_ocorrido_em_at', 'schema', 'storage_path', 'sha256', 'digval', 'digval_confere', 'xml_bytes', 'mascarado', 'captured_at'])]
class FiscalDocument extends Model
{
    /** @use HasFactory<FiscalDocumentFactory> */
    use BelongsToAccount, HasFactory;

    /**
     * Quantos eventos existem na linha do tempo desta chave de acesso.
     *
     * Não é coluna e não é relação: é um número que depende da página inteira
     * da consulta, e é o serviço de leitura (`FiscalDocuments`) que o preenche
     * antes de a linha chegar ao Resource. Declarado como propriedade — em vez
     * de atributo virtual criado em tempo de consulta — para que o Resource
     * possa tipar o acesso e para que o número não possa sumir calado: sem
     * preenchimento, é zero, que é o número honesto para um documento sem
     * evento.
     */
    public int $event_count = 0;

    /**
     * A situação da linha, derivada e sem coluna: `cancelada` quando existe um
     * evento `110111` na mesma linha do tempo, `autorizada` quando chegou o
     * documento completo, `resumo` quando só o resumo chegou. A linha de evento
     * não é linha de documento, e por isso não tem situação — é nula.
     */
    public ?string $situacao = null;

    protected function casts(): array
    {
        return [
            'source' => FiscalSource::class,
            'model' => FiscalModel::class,
            'kind' => FiscalKind::class,
            'stage' => FiscalStage::class,
            'nsu' => 'integer',
            'valor_total' => 'decimal:2',
            // Nulo é o terceiro estado: "a outra etapa da distribuição ainda não
            // chegou", que não é o mesmo que "os digests divergem".
            'digval_confere' => 'boolean',
            'mascarado' => 'boolean',
            'xml_bytes' => 'integer',
            'emissao_at' => 'datetime',
            'evento_ocorrido_em_at' => 'datetime',
            'captured_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function client(): BelongsTo
    {
        // O cliente removido por logicamente continua sendo dono do documento:
        // sem `withTrashed()` a linha histórica sai órfã, e a tabela que o
        // operador consulta perde o nome e o CNPJ de quem emitiu. A remoção
        // apaga o cliente da carteira, não o documento que ele já capturou.
        return $this->belongsTo(Client::class)->withTrashed();
    }
}
