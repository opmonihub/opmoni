<?php

namespace App\Models;

use Database\Factories\AccountContractingCertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

#[Hidden(['certificate_encrypted', 'password_encrypted'])]
class AccountContractingCertificate extends Model
{
    /** @use HasFactory<AccountContractingCertificateFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['valid_from' => 'datetime', 'valid_until' => 'datetime', 'revision' => 'integer'];
    }

    public function certificateBytes(): string
    {
        return Crypt::decryptString($this->certificate_encrypted);
    }

    public function certificatePassword(): string
    {
        return Crypt::decryptString($this->password_encrypted);
    }
}
