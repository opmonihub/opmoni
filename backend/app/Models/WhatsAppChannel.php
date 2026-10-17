<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Services\WhatsApp\WhatsAppChannelEffects;
use App\Support\CurrentAccount;
use App\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * O Canal WhatsApp do escritório: uma linha por conta, espelho do que a
 * instância wzap informa.
 *
 * **`instance_api_key_encrypted` nunca sai.** A apikey da instância é a
 * credencial com que o wzap autentica cada chamada, e o que este modelo
 * serializa (API, log estruturado, `toArray()` em debug) não pode carregar
 * nem a cifra: o `#[Hidden]` é a segunda rede, caso alguém serialize a linha
 * sem recurso dedicado. A leitura em claro é `instanceApiKey()` — um método,
 * não um accessor, porque quem a usa é o `WzapClient` e nada mais.
 *
 * `status` repete os quatro estados da spec (`disconnected`, `pairing`,
 * `connected`, `error`) e é a autoridade local para habilitar Disparo.
 */
#[Fillable([
    'wzap_instance_id',
    'status',
    'pairing_started_at',
    'connected_at',
    'last_error',
])]
#[Hidden(['instance_api_key_encrypted', 'last_projected_effect_id', 'last_projected_credential_effect_id'])]
class WhatsAppChannel extends Model
{
    /** @use HasFactory<WhatsAppChannel> */
    use BelongsToAccount, HasFactory;

    /**
     * O plural padrão do Eloquent quebraria em `whats_app_channels`; a
     * tabela é a do contrato, `whatsapp_channels`.
     */
    protected $table = 'whatsapp_channels';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_PAIRING = 'pairing';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    private bool $projectingReceipt = false;

    protected static function booted(): void
    {
        $stampObservedReceipts = static function (self $channel): void {
            if ($channel->projectingReceipt) {
                return;
            }

            // Ordinary observations supersede receipts already visible on the primary.
            // The marker and business fields share the caller's transaction/fencing.
            $receipts = $channel->getConnection()->table('external_effect_journal')
                ->where('account_id', $channel->account_id)
                ->where('resource_hash', WhatsAppChannelEffects::resource((int) $channel->account_id))
                ->where('state', 'confirmed')->where('receipt', 'applied');
            $effectId = (clone $receipts)->whereIn('step', ['whatsapp.connect', 'whatsapp.disconnect'])->max('id');
            $channel->last_projected_effect_id = max((int) $channel->last_projected_effect_id, (int) $effectId);
            if ($channel->isDirty(['wzap_instance_id', 'instance_api_key_encrypted']) || ! $channel->exists) {
                $credentialId = $receipts->whereIn('step', ['whatsapp.instance.create', 'whatsapp.instance.rotate'])->max('id');
                $channel->last_projected_credential_effect_id = max((int) $channel->last_projected_credential_effect_id, (int) $credentialId);
            }
        };
        // BelongsToAccount sets and validates account_id in its creating listener first.
        static::creating($stampObservedReceipts);
        static::saving(static function (self $channel) use ($stampObservedReceipts): void {
            if ($channel->exists) {
                $stampObservedReceipts($channel);
            }
        });
        static::saved(function (self $channel): void {
            $channel->projectingReceipt = false;
        });
    }

    /** Mark only the receipt actually observed; never acknowledge a later receipt on save. */
    public function projectEffect(int $effectId, bool $credential = false): void
    {
        $marker = $credential ? 'last_projected_credential_effect_id' : 'last_projected_effect_id';
        $this->{$marker} = max((int) $this->{$marker}, $effectId);
        $this->projectingReceipt = true;
    }

    protected function casts(): array
    {
        return [
            'last_projected_effect_id' => 'integer',
            'last_projected_credential_effect_id' => 'integer',
            'pairing_started_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * O canal desta conta, pelo `account_id` explícito.
     *
     * A garantia é "nunca o canal de outra conta", não "ignora o tenant":
     * `self::query()` carrega o escopo global de `BelongsToAccount`, que
     * não filtra nada no console e na fila — lá o `where` explícito é a
     * única barreira. Com um `CurrentTenant` ativo os dois filtros se
     * compõem (fail-closed: um tenant errado devolve `null`, jamais o
     * canal alheio). Quem resolve o canal pelo webhook ou pelo job passa
     * a conta que está servindo, nunca o que sobrou no singleton.
     */
    public static function forAccount(int $accountId): ?self
    {
        $context = request()->attributes->get(CurrentAccount::class);
        $currentId = $context instanceof CurrentAccount ? $context->account->id : resolve(CurrentTenant::class)->accountId;
        if ($currentId !== null && (int) $currentId !== $accountId) {
            return null;
        }

        return resolve(CurrentTenant::class)->run($accountId, fn () => self::query()->where('account_id', $accountId)->first());
    }

    /**
     * Grava a apikey da instância cifrada, sem passar pelo `Fillable`.
     *
     * O segredo não é `Fillable` de propósito: a única porta de escrita é
     * este método, para que nenhum mass-assignment de request consiga
     * plantar a coluna.
     */
    public function storeInstanceApiKey(string $plainKey): void
    {
        $this->forceFill([
            'instance_api_key_encrypted' => Crypt::encryptString($plainKey),
        ])->save();
    }

    /**
     * A apikey da instância em claro, para o `WzapClient` — uso interno.
     *
     * `null` quando a linha ainda não tem chave (instância não criada ou
     * chave perdida, caso que o `WzapClient::rotateInstanceKey()` cobre).
     * Nunca exponha este valor em JSON, log ou resposta de API.
     */
    public function instanceApiKey(): ?string
    {
        return $this->instance_api_key_encrypted === null
            ? null
            : Crypt::decryptString($this->instance_api_key_encrypted);
    }

    /**
     * Se o canal aceita Disparo agora — a única pergunta que a regra de
     * negócio faz ao estado.
     */
    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }
}
