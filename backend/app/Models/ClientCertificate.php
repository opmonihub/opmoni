<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Database\Factories\ClientCertificateFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['client_id', 'subject', 'serial_number', 'valid_from', 'valid_until', 'original_filename', 'storage_path', 'sha256', 'password_encrypted', 'replaced_at', 'removed_at'])]
class ClientCertificate extends Model
{
    /** @use HasFactory<ClientCertificateFactory> */
    use BelongsToAccount, HasFactory;

    /**
     * A senha é segredo do titular do certificado: nunca sai em `toArray()`.
     *
     * @var list<string>
     */
    protected $hidden = ['password_encrypted'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'replaced_at' => 'datetime',
            'removed_at' => 'datetime',
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

    /**
     * A senha do PKCS#12 em claro, ou `null` quando o certificado não tem senha
     * utilizável. `null` cobre os dois casos que exigem novo upload: coluna
     * vazia e coluna presente mas indecifrável (APP_KEY rotacionado, valor
     * truncado, lixo antigo). Um `DecryptException` aqui viraria erro cru na
     * captura, justamente onde o painel precisa dizer "reenvie o certificado".
     */
    public function certificatePassword(): ?string
    {
        if ($this->password_encrypted === null) {
            return null;
        }

        try {
            return Crypt::decryptString($this->password_encrypted);
        } catch (DecryptException) {
            return null;
        }
    }
}
