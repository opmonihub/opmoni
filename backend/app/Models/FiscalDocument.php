<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\FiscalDeliveryOrigin;
use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalProcessingReason;
use App\Enums\FiscalProcessingState;
use App\Enums\FiscalProviderNoteStatus;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Enums\FiscalStorageState;
use Database\Factories\FiscalDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_id', 'source', 'model', 'kind', 'stage', 'chave_acesso', 'numero', 'serie', 'event_id', 'nsu', 'emitente_cnpj', 'destinatario_cnpj', 'valor_total', 'emissao_at', 'authorized_at', 'provider_note_status', 'delivery_origin', 'delivery_environment', 'queried_tax_id', 'delivery_received_at', 'delivery_correlation_id', 'evento_ocorrido_em_at', 'schema', 'storage_path', 'sha256', 'digval', 'digval_confere', 'xml_bytes', 'storage_state', 'storage_verified_at', 'processing_state', 'processing_reason', 'next_attempt_at', 'processing_attempts', 'processing_started_at', 'processing_completed_at', 'processing_claim_expires_at', 'mascarado', 'captured_at'])]
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

    /**
     * A completude do XML da chave, derivada e sem coluna: `complete` quando a
     * distribuição entregou o documento autorizado, `summary_awaiting_xml`
     * quando só o resumo chegou. `null` quando a pergunta não se aplica à
     * linha: o CT-e (que não depende de manifestação do destinatário), a linha
     * de evento e a NF-e que o próprio cliente emitiu — nela não há terceiro
     * que destrave XML nenhum. Preenchido pelo serviço de leitura, no mesmo
     * molde de `situacao`.
     */
    public ?string $completude = null;

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
            'authorized_at' => 'datetime',
            'provider_note_status' => FiscalProviderNoteStatus::class,
            'delivery_origin' => FiscalDeliveryOrigin::class,
            'delivery_received_at' => 'datetime',
            'storage_state' => FiscalStorageState::class,
            'storage_verified_at' => 'datetime',
            'processing_state' => FiscalProcessingState::class,
            'processing_reason' => FiscalProcessingReason::class,
            'next_attempt_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processing_completed_at' => 'datetime',
            'processing_claim_expires_at' => 'datetime',
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
