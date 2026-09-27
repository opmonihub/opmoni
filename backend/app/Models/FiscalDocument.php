<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use Database\Factories\FiscalDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_id', 'source', 'model', 'kind', 'chave_acesso', 'event_id', 'nsu', 'emitente_cnpj', 'destinatario_cnpj', 'valor_total', 'emissao_at', 'evento_ocorrido_em_at', 'schema', 'storage_path', 'sha256', 'xml_bytes', 'mascarado', 'captured_at'])]
class FiscalDocument extends Model
{
    /** @use HasFactory<FiscalDocumentFactory> */
    use BelongsToAccount, HasFactory;

    protected function casts(): array
    {
        return [
            'source' => FiscalSource::class,
            'model' => FiscalModel::class,
            'kind' => FiscalKind::class,
            'nsu' => 'integer',
            'valor_total' => 'decimal:2',
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
        return $this->belongsTo(Client::class);
    }
}
