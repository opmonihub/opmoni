<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountContractingCertificate;
use App\Models\SerproConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountContractingCertificateVault
{
    public function __construct(private SerproCertificateIdentity $identity, private SerproCredentialLifecycle $lifecycle, private SerproTokenProvider $tokens) {}

    public function replace(Account $account, UploadedFile $file, string $password): AccountContractingCertificate
    {
        $bytes = $file->get();
        if (strlen($bytes) > 2097152) {
            throw ValidationException::withMessages(['certificate' => 'O certificado deve ter no máximo 2 MiB.']);
        }
        $metadata = $this->identity->parse($bytes, $password);
        $attributes = [
            'document' => $metadata['document'], 'subject' => $metadata['subject'], 'serial_number' => $metadata['serial'],
            'valid_from' => $metadata['valid_from'], 'valid_until' => $metadata['valid_until'],
            'certificate_encrypted' => Crypt::encryptString($bytes), 'password_encrypted' => Crypt::encryptString($password),
        ];

        return $this->lifecycle->locked([$account->id], function (?SerproConnection $connection, Collection $accounts) use ($account, $attributes): AccountContractingCertificate {
            if ($accounts->get($account->id)?->status !== 'active') {
                throw ValidationException::withMessages(['contracting_account_id' => 'Selecione uma conta ativa.']);
            }
            $certificate = AccountContractingCertificate::query()->where('account_id', $account->id)->lockForUpdate()->first();
            if ($certificate === null) {
                $certificate = AccountContractingCertificate::forceCreate($attributes + ['account_id' => $account->id, 'revision' => 1]);
            } else {
                $certificate->forceFill($attributes + ['revision' => $certificate->revision + 1])->save();
            }
            if ($connection?->account_contracting_certificate_id === $certificate->id) {
                $connection->forceFill(self::connectionMetadata($certificate) + ['revision' => $connection->revision + 1])->save();
                DB::afterCommit(fn () => $this->tokens->forget());
            }

            return $certificate;
        });
    }

    /** @return array<string, mixed> */
    public static function connectionMetadata(AccountContractingCertificate $certificate): array
    {
        return [
            'contratante_numero' => $certificate->document,
            'contratante_tipo' => SerproEnvelope::tipo($certificate->document),
            'certificate_subject' => mb_substr($certificate->subject, 0, 255),
            'certificate_serial_number' => $certificate->serial_number,
            'certificate_valid_from' => $certificate->valid_from,
            'certificate_valid_until' => $certificate->valid_until,
        ];
    }
}
