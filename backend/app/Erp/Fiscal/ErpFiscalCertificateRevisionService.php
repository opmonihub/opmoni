<?php

namespace App\Erp\Fiscal;

use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\Fiscal\ErpFiscalCertificate;
use App\Models\Erp\Fiscal\ErpFiscalCertificateRevision;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ErpFiscalCertificateRevisionService
{
    public function __construct(private ErpFiscalCertificateValidator $validator) {}

    public function store(ErpEstablishment $establishment, string $label, string $rawCertificate, string $password, ?int $actorUserId): ErpFiscalCertificate
    {
        $this->assertSize($rawCertificate);
        $identity = $this->validator->validatePkcs12($rawCertificate, $password, $establishment->cnpj);

        return DB::transaction(function () use ($establishment, $label, $rawCertificate, $password, $actorUserId, $identity): ErpFiscalCertificate {
            $certificate = ErpFiscalCertificate::query()->create([
                'erp_company_id' => $establishment->erp_company_id,
                'erp_establishment_id' => $establishment->id,
                'label' => $label,
                'cnpj' => $establishment->cnpj,
                'encrypted_certificate' => Crypt::encryptString($rawCertificate),
                'encrypted_password' => Crypt::encryptString($password),
                'certificate_fingerprint' => hash('sha256', $rawCertificate),
                'revision' => 0,
                'status' => 'active',
                'valid_from' => $identity['valid_from'],
                'valid_until' => $identity['valid_until'],
                'uploaded_by_user_id' => $actorUserId,
            ]);

            return $this->appendRevision($certificate, $rawCertificate, $password, $identity, $actorUserId);
        });
    }

    public function rotate(ErpFiscalCertificate $certificate, string $rawCertificate, string $password, ?int $actorUserId): ErpFiscalCertificate
    {
        $this->assertSize($rawCertificate);
        $issuer = $certificate->establishment;
        if (! $issuer instanceof ErpEstablishment || $issuer->status !== 'active') {
            throw new InvalidArgumentException('O certificado precisa pertencer a um estabelecimento emissor ativo.');
        }
        $identity = $this->validator->validatePkcs12($rawCertificate, $password, $issuer->cnpj);

        return DB::transaction(function () use ($certificate, $rawCertificate, $password, $actorUserId, $identity): ErpFiscalCertificate {
            $locked = ErpFiscalCertificate::query()->whereKey($certificate->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->erp_establishment_id !== (int) $certificate->erp_establishment_id) {
                throw new InvalidArgumentException('A configuração do emissor mudou durante a rotação.');
            }

            return $this->appendRevision($locked, $rawCertificate, $password, $identity, $actorUserId);
        });
    }

    /** @return array{certificate: string, password: string} */
    public function material(ErpFiscalCertificateRevision $revision, ErpEstablishment $establishment): array
    {
        $owner = $revision->certificate;
        if (! $owner || (int) $owner->erp_company_id !== (int) $establishment->erp_company_id || (int) $owner->erp_establishment_id !== (int) $establishment->id || $revision->cnpj !== $establishment->cnpj) {
            throw new InvalidArgumentException('Revisão do certificado pertence a outro emissor.');
        }
        $certificate = Crypt::decryptString($revision->encrypted_certificate);
        if (! hash_equals((string) $revision->certificate_fingerprint, hash('sha256', $certificate))) {
            throw new InvalidArgumentException('Material do certificado não corresponde à revisão fixada.');
        }
        $password = Crypt::decryptString($revision->encrypted_password);
        $this->validator->validatePkcs12($certificate, $password, $establishment->cnpj);

        return ['certificate' => $certificate, 'password' => $password];
    }

    private function appendRevision(ErpFiscalCertificate $certificate, string $rawCertificate, string $password, array $identity, ?int $actorUserId): ErpFiscalCertificate
    {
        $revisionNumber = (int) $certificate->revision + 1;
        $certificate->revision = $revisionNumber;
        $certificate->encrypted_certificate = Crypt::encryptString($rawCertificate);
        $certificate->encrypted_password = Crypt::encryptString($password);
        $certificate->certificate_fingerprint = hash('sha256', $rawCertificate);
        $certificate->cnpj = $certificate->establishment?->cnpj;
        $certificate->valid_from = $identity['valid_from'];
        $certificate->valid_until = $identity['valid_until'];
        $certificate->uploaded_by_user_id = $actorUserId;
        $certificate->status = 'active';
        $certificate->save();

        $revision = ErpFiscalCertificateRevision::query()->create([
            'erp_fiscal_certificate_id' => $certificate->id,
            'revision' => $revisionNumber,
            'encrypted_certificate' => Crypt::encryptString($rawCertificate),
            'encrypted_password' => Crypt::encryptString($password),
            'certificate_fingerprint' => hash('sha256', $rawCertificate),
            'cnpj' => $certificate->establishment?->cnpj,
            'subject' => $identity['subject'] ?? null,
            'valid_from' => $identity['valid_from'],
            'valid_until' => $identity['valid_until'],
            'uploaded_by_user_id' => $actorUserId,
        ]);

        $certificate->current_revision_id = $revision->id;
        $certificate->save();

        return $certificate->fresh();
    }

    private function assertSize(string $rawCertificate): void
    {
        if ($rawCertificate === '' || strlen($rawCertificate) > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('O arquivo PKCS#12 precisa ter até 2 MiB.');
        }
    }
}
