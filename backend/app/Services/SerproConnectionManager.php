<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\AccountContractingCertificate;
use App\Models\SerproConnection;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class SerproConnectionManager
{
    public function __construct(
        private SerproCertificateIdentity $identity,
        private SerproTokenProvider $tokens,
        private SerproCredentialLifecycle $lifecycle,
    ) {}

    public function save(
        string $key,
        ?string $secret,
        ?UploadedFile $certificate,
        ?string $password,
        bool $useAccountCertificate = false,
        ?string $certificateSource = null,
        ?int $contractingAccountId = null,
    ): SerproConnection {
        if (($useAccountCertificate && ($certificate !== null || $certificateSource !== null)) || ($certificateSource === 'account' && $certificate !== null)) {
            throw ValidationException::withMessages(['certificate' => 'Escolha apenas uma fonte do certificado contratante.']);
        }
        if ($certificateSource !== null && ! in_array($certificateSource, ['upload', 'account'], true)) {
            throw ValidationException::withMessages(['certificate_source' => 'Fonte inválida.']);
        }
        if ($certificateSource === 'account' && $contractingAccountId === null) {
            throw ValidationException::withMessages(['contracting_account_id' => 'Selecione a conta contratante.']);
        }
        $uploaded = $certificate === null ? [] : $this->uploadAttributes($certificate, $password);
        $sourceAccountId = $useAccountCertificate ? Account::query()->orderBy('id')->value('id') : ($certificateSource === 'account' ? $contractingAccountId : null);

        try {
            return $this->lifecycle->locked($sourceAccountId === null ? [] : [$sourceAccountId], function (?SerproConnection $connection, Collection $accounts) use ($key, $secret, $certificate, $password, $useAccountCertificate, $certificateSource, $sourceAccountId, $uploaded): SerproConnection {
                $attributes = $uploaded;
                if ($useAccountCertificate || $certificateSource === 'account') {
                    if ($sourceAccountId === null || $accounts->get($sourceAccountId)?->status !== 'active') {
                        throw ValidationException::withMessages(['contracting_account_id' => 'Selecione uma conta ativa.']);
                    }
                    $attributes = $this->linkedAttributes($sourceAccountId, $useAccountCertificate);
                } elseif ($certificateSource === 'upload' && $certificate === null && ($connection === null || $connection->contracting_account_id !== null || $connection->certificate_encrypted === null)) {
                    throw ValidationException::withMessages(['certificate' => 'Envie o certificado do contratante.']);
                }
                if ($connection === null) {
                    $missing = [];
                    if ($key === '') {
                        $missing['consumer_key'] = 'Informe a chave de integração do Integra Contador.';
                    }
                    if ($secret === null || $secret === '') {
                        $missing['consumer_secret'] = 'Informe o segredo da chave de integração.';
                    }
                    if ($attributes === []) {
                        $missing['certificate'] = 'Envie o certificado do contratante ou selecione uma fonte disponível.';
                    }
                    if ($missing !== []) {
                        throw ValidationException::withMessages($missing);
                    }
                    $connection = SerproConnection::forceCreate($attributes + [
                        'consumer_key' => $key, 'consumer_secret_encrypted' => Crypt::encryptString($secret), 'revision' => 1,
                    ]);
                    DB::afterCommit(fn () => $this->tokens->forget());

                    return $connection;
                }
                if ($key !== '' && $key !== $connection->consumer_key) {
                    $attributes['consumer_key'] = $key;
                }
                if ($secret !== null && $secret !== '' && $secret !== $connection->consumerSecret()) {
                    $attributes['consumer_secret_encrypted'] = Crypt::encryptString($secret);
                }
                if ($certificate === null && ! $useAccountCertificate && $certificateSource !== 'account' && $password !== null && $password !== '') {
                    $this->confirmPassword($connection, $password);
                    if ($password !== $connection->certificatePassword()) {
                        $attributes['certificate_password_encrypted'] = Crypt::encryptString($password);
                    }
                }
                $connection->forceFill($attributes);
                if ($connection->isDirty()) {
                    $connection->revision++;
                    $connection->save();
                    DB::afterCommit(fn () => $this->tokens->forget());
                }

                return $connection;
            });
        } catch (UniqueConstraintViolationException $collision) {
            Log::warning('Outra gravação da credencial foi recusada pelo índice singleton.', ['exception' => $collision::class]);
            throw ValidationException::withMessages(['consumer_key' => 'Outra gravação chegou primeiro. Recarregue a credencial.']);
        }
    }

    private function linkedAttributes(int $accountId, bool $legacy): array
    {
        $certificate = $legacy
            ? AccountCertificate::query()->withoutGlobalScope('account')->where('account_id', $accountId)->whereNull('replaced_at')->whereNull('removed_at')->orderByDesc('id')->lockForUpdate()->first()
            : AccountContractingCertificate::query()->where('account_id', $accountId)->lockForUpdate()->first();
        if ($certificate === null) {
            throw ValidationException::withMessages(['certificate' => 'A conta selecionada não tem certificado contratante disponível.']);
        }
        if ($certificate->valid_from->isFuture() || $certificate->valid_until->isPast()) {
            throw ValidationException::withMessages(['certificate' => 'O certificado contratante não está vigente.']);
        }

        return [
            'contracting_account_id' => $accountId,
            'account_contracting_certificate_id' => $legacy ? null : $certificate->id,
            'contratante_numero' => $certificate->document,
            'contratante_tipo' => SerproEnvelope::tipo($certificate->document),
            'certificate_encrypted' => null, 'certificate_password_encrypted' => null,
            'certificate_subject' => $legacy ? null : mb_substr($certificate->subject, 0, 255),
            'certificate_serial_number' => $legacy ? null : $certificate->serial_number,
            'certificate_valid_from' => $legacy ? null : $certificate->valid_from,
            'certificate_valid_until' => $legacy ? null : $certificate->valid_until,
        ];
    }

    private function uploadAttributes(UploadedFile $certificate, ?string $password): array
    {
        if ($password === null) {
            throw ValidationException::withMessages(['password' => 'Informe a senha do certificado, inclusive quando vazia.']);
        }
        $bytes = $certificate->get();
        if (strlen($bytes) > 2097152) {
            throw ValidationException::withMessages(['certificate' => 'O certificado deve ter no máximo 2 MiB.']);
        }
        $parsed = $this->identity->parse($bytes, $password);

        return [
            'contracting_account_id' => null, 'account_contracting_certificate_id' => null,
            'contratante_numero' => $parsed['document'], 'contratante_tipo' => SerproEnvelope::tipo($parsed['document']),
            'certificate_encrypted' => Crypt::encryptString($bytes),
            'certificate_password_encrypted' => Crypt::encryptString($password),
            'certificate_subject' => mb_substr($parsed['subject'], 0, 255), 'certificate_serial_number' => $parsed['serial'],
            'certificate_valid_from' => $parsed['valid_from'], 'certificate_valid_until' => $parsed['valid_until'],
        ];
    }

    private function confirmPassword(SerproConnection $connection, string $password): void
    {
        if ($connection->certificate_encrypted === null) {
            throw ValidationException::withMessages(['password' => 'Não há certificado do contratante gravado para conferir esta senha.']);
        }
        try {
            $bytes = (string) $connection->certificateBytes();
        } catch (DecryptException) {
            throw ValidationException::withMessages(['password' => 'Não foi possível conferir a senha: o certificado do contratante gravado não pôde ser lido. Envie o certificado de novo.']);
        }
        $this->identity->document($bytes, $password);
    }
}
