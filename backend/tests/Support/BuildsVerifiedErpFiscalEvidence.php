<?php

namespace Tests\Support;

use App\Erp\Fiscal\ErpFiscalArtifactStore;
use App\Erp\Fiscal\ErpFiscalAttemptService;
use App\Erp\Fiscal\ErpFiscalCorrelationValidator;
use App\Erp\Fiscal\ErpFiscalPayloadBuilder;
use App\Erp\Fiscal\ErpFiscalSchemaValidator;
use App\Erp\Fiscal\ErpFiscalXmlSigner;
use App\Erp\Fiscal\Nfe55DocumentBuilder;
use App\Models\Erp\Fiscal\ErpFiscalCertificate;
use App\Models\Erp\Fiscal\ErpFiscalCertificateRevision;
use App\Models\Erp\Fiscal\ErpFiscalConfiguration;
use App\Models\Erp\Fiscal\ErpFiscalConfigurationRevision;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Tests\Feature\ErpFiscalModelBuilderSchemaTest;

trait BuildsVerifiedErpFiscalEvidence
{
    /** Complete locally signed evidence; no provider binding or dispatch. */
    private function attachVerifiedFiscalEvidence(ErpFiscalDocument $document): string
    {
        $issuer = $document->establishment;
        $cnpj = $issuer->cnpj ?? $document->company->cnpj;
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NF-e 55 posted sale'][2];
        $source['issuer']['cnpj'] = $cnpj;
        $source['identity'] = [...$source['identity'], 'environment' => $document->environment, 'series' => $document->series, 'number' => $document->number];
        $source['identity']['access_key'] = app(ErpFiscalPayloadBuilder::class)->buildAccessKey([
            'uf' => 'SP', 'cnpj' => $cnpj, 'model' => '55', 'series' => $document->series, 'number' => $document->number,
            'issued_at' => $source['identity']['issued_at'], 'cnf' => '12345678',
        ]);
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => $cnpj], $privateKey, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $privateKey, 2, ['digest_alg' => 'sha256']);
        openssl_x509_export($certificate, $publicCertificate);
        openssl_pkey_export($privateKey, $privateKeyPem);
        openssl_pkcs12_export($certificate, $pkcs12, $privateKey, '');
        $owner = ErpFiscalCertificate::query()->create([
            'erp_company_id' => $document->erp_company_id, 'erp_establishment_id' => $issuer->id, 'label' => 'Local accounting evidence',
            'cnpj' => $cnpj, 'encrypted_certificate' => Crypt::encryptString($pkcs12), 'encrypted_password' => Crypt::encryptString(''),
            'certificate_fingerprint' => hash('sha256', $pkcs12), 'status' => 'active', 'revision' => 1,
        ]);
        $revision = ErpFiscalCertificateRevision::query()->create([
            'erp_fiscal_certificate_id' => $owner->id, 'revision' => 1, 'cnpj' => $cnpj,
            'encrypted_certificate' => $owner->encrypted_certificate, 'encrypted_password' => $owner->encrypted_password,
            'certificate_fingerprint' => $owner->certificate_fingerprint, 'valid_from' => now()->subMinute(), 'valid_until' => now()->addDay(),
        ]);
        $owner->update(['current_revision_id' => $revision->id]);
        $configuration = ErpFiscalConfiguration::query()->firstOrCreate(['erp_company_id' => $document->erp_company_id, 'erp_establishment_id' => $issuer->id]);
        $configurationRevision = ErpFiscalConfigurationRevision::query()->create([
            'erp_fiscal_configuration_id' => $configuration->id, 'revision' => $configuration->revisions()->count() + 1,
            'environment' => $document->environment, 'configuration' => [], 'configuration_sha256' => hash('sha256', '[]'),
        ]);
        $configuration->update(['current_revision_id' => $configurationRevision->id]);
        $document->update(['access_key' => $source['identity']['access_key'], 'erp_fiscal_certificate_id' => $owner->id]);
        $signed = app(ErpFiscalXmlSigner::class)->sign(app(Nfe55DocumentBuilder::class)->build($source), $privateKeyPem, $publicCertificate);
        $signedDocument = new \DOMDocument;
        $signedDocument->loadXML($signed);
        $digest = (new \DOMXPath($signedDocument))->query('//*[local-name()="DigestValue"]')->item(0)->textContent;
        $protocol = '135260000000001';
        $xml = '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'.preg_replace('/^<\?xml[^>]*>\s*/', '', $signed)
            .'<protNFe versao="4.00"><infProt><tpAmb>'.($document->environment === 'production' ? '1' : '2').'</tpAmb><verAplic>TESTE</verAplic><chNFe>'.$document->access_key.'</chNFe><dhRecbto>2026-10-21T10:00:01-03:00</dhRecbto><nProt>'.$protocol.'</nProt><digVal>'.$digest.'</digVal><cStat>100</cStat><xMotivo>Autorizado o uso</xMotivo></infProt></protNFe></nfeProc>';
        app(ErpFiscalSchemaValidator::class)->assertValid($xml, '55', 'processed');
        app(ErpFiscalCorrelationValidator::class)->assertProcessedMatches($xml, ['model' => '55', 'environment' => $document->environment, 'access_key' => $document->access_key, 'issuer_cnpj' => $cnpj, 'protocol' => $protocol, 'series' => $document->series, 'number' => $document->number]);
        $artifact = app(ErpFiscalArtifactStore::class)->persistAuthorizedXml($document, $xml);
        $identity = [...$document->only(['erp_company_id', 'erp_establishment_id', 'model', 'environment', 'series', 'number']),
            'erp_fiscal_document_id' => $document->id, 'actor_user_id' => User::factory()->create()->id,
            'erp_fiscal_configuration_revision_id' => $configurationRevision->id, 'erp_fiscal_certificate_revision_id' => $revision->id,
            'schema_release' => 'local-verified-fixture', 'request' => ['source' => $source]];
        $attempts = app(ErpFiscalAttemptService::class);
        $attempts->createIntent([...$identity, 'action' => 'document:draft', 'idempotency_key' => 'accounting-draft-'.$document->id]);
        $attempt = $attempts->createIntent([...$identity, 'action' => 'document:transmit', 'idempotency_key' => 'accounting-accept-'.$document->id]);
        $attempt->forceFill(['status' => 'accepted', 'outbox_state' => 'completed', 'provider_protocol' => $protocol,
            'safe_result' => ['artifact_ready' => true, 'artifact_sha256' => $artifact['artifact_sha256'], 'artifact_byte_length' => $artifact['artifact_byte_length']]])->save();
        $document->update([...$artifact, 'status' => 'authorized', 'authorized_at' => now(), 'metadata' => [...($document->metadata ?? []), 'provider_protocol' => $protocol]]);

        return $xml;
    }
}
