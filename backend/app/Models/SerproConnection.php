<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

#[Fillable([
    'consumer_key',
    'consumer_secret_encrypted',
    'certificate_encrypted',
    'certificate_password_encrypted',
    'certificate_subject',
    'certificate_serial_number',
    'certificate_valid_from',
    'certificate_valid_until',
    'contratante_numero',
    'contratante_tipo',
])]
class SerproConnection extends Model
{
    /** @use HasFactory<SerproConnection> */
    use HasFactory;

    /**
     * A credencial é da plataforma, não de uma conta: exatamente uma linha.
     */
    public static function current(): ?self
    {
        return self::query()->first();
    }

    public function consumerSecret(): string
    {
        return Crypt::decryptString($this->consumer_secret_encrypted);
    }

    public function certificateBytes(): ?string
    {
        return $this->certificate_encrypted === null
            ? null
            : Crypt::decryptString($this->certificate_encrypted);
    }

    public function certificatePassword(): ?string
    {
        return $this->certificate_password_encrypted === null
            ? null
            : Crypt::decryptString($this->certificate_password_encrypted);
    }

    /**
     * @return array<string, mixed>
     */
    public function safeMetadata(): array
    {
        return [
            'configured' => $this->consumer_key !== '' && $this->consumer_secret_encrypted !== null,
            'certificate_subject' => $this->certificate_subject,
            'certificate_serial_number' => $this->certificate_serial_number,
            'certificate_valid_from' => $this->certificate_valid_from?->toISOString(),
            'certificate_valid_until' => $this->certificate_valid_until?->toISOString(),
            'contratante_numero' => $this->contratante_numero,
            'contratante_tipo' => $this->contratante_tipo,
        ];
    }
}
