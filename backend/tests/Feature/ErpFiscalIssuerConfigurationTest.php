<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Erp\ErpCompanyPermissions;
use App\Erp\Fiscal\ErpFiscalActionCatalog;
use App\Erp\Fiscal\ErpFiscalNumberAllocator;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Fiscal\ErpFiscalCertificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ErpFiscalIssuerConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CNPJ = '30288513000100';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
            'erp.release_enabled' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'session.store' => null,
        ]);

        $this->withHeaders([
            'X-Opmoni-Ingress-Key' => 'test-key',
            'X-Opmoni-Client-IP' => '127.0.0.1',
            'X-Opmoni-Client-Proto' => 'http',
            'Origin' => 'http://localhost:3000',
        ]);
    }

    public function test_configuration_and_certificate_actions_are_explicit_company_permissions(): void
    {
        $permissions = [
            'erp.fiscal.configuration.show' => false,
            'erp.fiscal.configuration.update' => true,
            'erp.fiscal.certificates.index' => false,
            'erp.fiscal.certificates.show' => false,
            'erp.fiscal.certificates.store' => true,
            'erp.fiscal.certificates.rotate' => true,
        ];

        foreach ($permissions as $permission => $write) {
            $this->assertContains($permission, ErpCompanyPermissions::names());
            $this->assertSame($write, collect(ErpCompanyPermissions::catalog())->firstWhere('permission', $permission)['write']);
            $this->assertTrue(ErpCompanyPermissions::delegatedAllows($permission, $write, [$permission], 'admin'));
            $this->assertFalse(ErpCompanyPermissions::delegatedAllows($permission, $write, [], 'admin'));
        }

        $this->assertFalse(ErpCompanyPermissions::delegatedAllows('erp.fiscal.configuration.update', true, ['*'], 'user'));

        $rows = collect(ErpFiscalActionCatalog::rows());
        $this->assertTrue($rows->contains(fn (array $row): bool => str_contains($row['route'], '/configuration/{establishment}')));
        $this->assertTrue($rows->contains(fn (array $row): bool => str_contains($row['route'], '/certificates')));
    }

    public function test_owner_reads_safe_configuration_for_the_authorized_issuer_establishment(): void
    {
        $context = $this->ownerContext();

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/fiscal/configuration/'.$context['establishment']->id)
            ->assertOk()
            ->assertJsonPath('data.establishment.id', $context['establishment']->id)
            ->assertJsonPath('data.establishment.cnpj', self::VALID_CNPJ)
            ->assertJsonMissingPath('data.certificate_content')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.storage_path');
    }

    public function test_configuration_cannot_replace_the_establishments_authoritative_cnpj(): void
    {
        $context = $this->ownerContext();
        $csrfToken = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $csrfToken])
            ->patchJson('/api/v1/erp/fiscal/configuration/'.$context['establishment']->id, [
                'environment' => 'homologation',
                'issuer' => ['cnpj' => '11222333000181'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('issuer.cnpj');

        $this->assertSame(self::VALID_CNPJ, $context['establishment']->fresh()->cnpj);
    }

    public function test_owner_replaces_fiscal_configuration_with_an_immutable_revision(): void
    {
        $context = $this->ownerContext();
        $csrfToken = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $headers = [...$context['headers'], 'X-CSRF-Token' => $csrfToken];
        $configuration = [
            'environment' => 'homologation',
            'issuer' => [
                'tax_regime' => 1,
                'state_registration' => '110042490114',
                'uf' => 'SP',
                'address' => [
                    'street' => 'Rua das Flores',
                    'number' => '100',
                    'district' => 'Centro',
                    'city_code' => '3550308',
                    'city_name' => 'São Paulo',
                    'zip_code' => '01001000',
                ],
            ],
            'models' => ['55' => ['series' => 1, 'next_number' => 1, 'operation_nature_id' => null]],
        ];

        $first = $this->withHeaders($headers)
            ->patchJson('/api/v1/erp/fiscal/configuration/'.$context['establishment']->id, $configuration)
            ->assertOk()
            ->assertJsonPath('data.establishment.cnpj', self::VALID_CNPJ)
            ->assertJsonPath('data.environment', 'homologation')
            ->assertJsonMissingPath('data.certificate_content')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.storage_path');
        $firstRevision = $first->json('data.revision_id');

        $second = $this->withHeaders($headers)
            ->patchJson('/api/v1/erp/fiscal/configuration/'.$context['establishment']->id, [
                ...$configuration,
                'issuer' => [...$configuration['issuer'], 'state_registration' => '110042490115'],
            ])
            ->assertOk()
            ->assertJsonPath('data.issuer.state_registration', '110042490115');

        $this->assertNotSame($firstRevision, $second->json('data.revision_id'));
        $this->assertDatabaseCount('erp_fiscal_configuration_revisions', 2);
        $this->assertSame(self::VALID_CNPJ, $context['establishment']->fresh()->cnpj);
    }

    public function test_readiness_exposes_live_shared_number_and_model_blockers_without_transport_source_details(): void
    {
        $context = $this->ownerContext();
        $csrfToken = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $csrfToken])
            ->patchJson('/api/v1/erp/fiscal/configuration/'.$context['establishment']->id, [
                'environment' => 'homologation',
                'issuer' => [
                    'tax_regime' => 1, 'state_registration' => '110042490114', 'uf' => 'SP',
                    'address' => ['street' => 'Rua Um', 'number' => '1', 'district' => 'Centro', 'city_code' => '3550308', 'city_name' => 'São Paulo', 'zip_code' => '01001000'],
                ],
                'models' => [
                    '55' => ['series' => 2, 'next_number' => 4],
                    '58' => ['series' => 1, 'next_number' => 1, 'transport' => ['driver' => ['cpf' => '12345678909', 'name' => 'Sensitive test driver']]],
                ],
            ])
            ->assertOk();

        $allocator = app(ErpFiscalNumberAllocator::class);
        $this->assertSame(4, $allocator->allocate($context['company']->id, '55', 'homologation', 2, 4));
        $response = $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/fiscal/configuration/'.$context['establishment']->id)
            ->assertOk()
            ->assertJsonPath('data.models.55.next_number', 5)
            ->assertJsonPath('data.models.55.initial_next_number', 4)
            ->assertJsonPath('data.models.55.shared_next_number', 5)
            ->assertJsonPath('data.models.55.readiness.status', 'blocked')
            ->assertJsonPath('data.models.58.readiness.status', 'blocked')
            ->assertJsonPath('data.release_gates.production_transmission_enabled', false)
            ->assertJsonMissingPath('data.models.58.transport');

        $this->assertContains('certificate_missing', $response->json('data.models.55.readiness.blockers'));
        $this->assertContains('operation_nature_missing', $response->json('data.models.55.readiness.blockers'));
        $this->assertContains('typed_transport_manifest_missing', $response->json('data.models.58.readiness.blockers'));
        $this->assertStringNotContainsString('Sensitive test driver', $response->getContent());
        $this->assertStringNotContainsString('12345678909', $response->getContent());
    }

    public function test_foreign_establishment_configuration_is_not_disclosed(): void
    {
        $context = $this->ownerContext();
        $foreignCompany = ErpCompany::factory()->create();
        $foreignEstablishment = ErpEstablishment::factory()->create([
            'erp_company_id' => $foreignCompany->id,
            'cnpj' => '11222333000181',
        ]);

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/fiscal/configuration/'.$foreignEstablishment->id)
            ->assertNotFound();
    }

    public function test_certificate_upload_requires_password_presence_and_caps_multipart_bytes(): void
    {
        $context = $this->ownerContext();
        $csrfToken = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $headers = [...$context['headers'], 'X-CSRF-Token' => $csrfToken];

        $this->withHeaders($headers)
            ->post('/api/v1/erp/fiscal/certificates', [
                'label' => 'Certificado matriz',
                'erp_establishment_id' => $context['establishment']->id,
                'certificate' => UploadedFile::fake()->createWithContent('issuer.p12', 'small-invalid-certificate'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->withHeaders($headers)
            ->post('/api/v1/erp/fiscal/certificates', [
                'label' => 'Certificado matriz',
                'erp_establishment_id' => $context['establishment']->id,
                'password' => '',
                'certificate' => UploadedFile::fake()->createWithContent('issuer.p12', str_repeat('x', 2 * 1024 * 1024 + 1)),
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('erp_fiscal_certificates', 0);
    }

    public function test_empty_password_upload_accepts_a_usable_pkcs12_matching_the_establishment(): void
    {
        $context = $this->ownerContext();
        $csrfToken = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $csrfToken])
            ->post('/api/v1/erp/fiscal/certificates', [
                'label' => 'Certificado matriz',
                'erp_establishment_id' => $context['establishment']->id,
                'password' => '',
                'certificate' => UploadedFile::fake()->createWithContent('issuer.p12', $this->pkcs12ForCnpj(self::VALID_CNPJ)),
            ])
            ->assertCreated()
            ->assertJsonPath('data.cnpj', self::VALID_CNPJ)
            ->assertJsonPath('data.revision', 1)
            ->assertJsonMissingPath('data.encrypted_certificate')
            ->assertJsonMissingPath('data.encrypted_password');

        $this->assertDatabaseCount('erp_fiscal_certificate_revisions', 1);
    }

    public function test_certificate_rotation_rejects_a_different_issuer_and_preserves_the_active_revision(): void
    {
        $context = $this->ownerContext();
        $csrfToken = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $created = $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $csrfToken])
            ->post('/api/v1/erp/fiscal/certificates', [
                'label' => 'Certificado matriz',
                'erp_establishment_id' => $context['establishment']->id,
                'password' => '',
                'certificate' => UploadedFile::fake()->createWithContent('issuer.p12', $this->pkcs12ForCnpj(self::VALID_CNPJ)),
            ])
            ->assertCreated();
        $certificateId = $created->json('data.id');
        $oldFingerprint = $created->json('data.certificate_fingerprint');
        $oldEncryptedMaterial = ErpFiscalCertificate::query()->findOrFail($certificateId)->encrypted_certificate;

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $csrfToken])
            ->post('/api/v1/erp/fiscal/certificates/'.$certificateId.'/rotate', [
                'password' => '',
                'certificate' => UploadedFile::fake()->createWithContent('different-issuer.p12', $this->pkcs12ForCnpj('11222333000181')),
            ])
            ->assertUnprocessable();

        $activeCertificate = ErpFiscalCertificate::query()->findOrFail($certificateId);
        $this->assertSame($oldFingerprint, $activeCertificate->certificate_fingerprint);
        $this->assertSame(1, (int) $activeCertificate->revision);
        $this->assertSame($oldEncryptedMaterial, $activeCertificate->encrypted_certificate);
        $this->assertDatabaseCount('erp_fiscal_certificate_revisions', 1);
    }

    public function test_failed_certificate_rotation_preserves_the_active_revision(): void
    {
        $context = $this->ownerContext();
        $certificate = ErpFiscalCertificate::query()->create([
            'erp_company_id' => $context['company']->id,
            'erp_establishment_id' => $context['establishment']->id,
            'label' => 'Certificado matriz',
            'cnpj' => self::VALID_CNPJ,
            'encrypted_certificate' => Crypt::encryptString('current-certificate-bytes'),
            'encrypted_password' => Crypt::encryptString('current-password'),
            'certificate_fingerprint' => hash('sha256', 'current-certificate-bytes'),
            'revision' => 3,
            'status' => 'active',
        ]);
        $csrfToken = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $csrfToken])
            ->postJson('/api/v1/erp/fiscal/certificates/'.$certificate->id.'/rotate', [
                'certificate_content' => '%%%invalid-base64%%%',
                'password' => '',
            ])
            ->assertUnprocessable();

        $this->assertSame(3, (int) $certificate->fresh()->revision);
        $this->assertSame('current-certificate-bytes', Crypt::decryptString($certificate->fresh()->encrypted_certificate));
    }

    /** @return array{company: ErpCompany, establishment: ErpEstablishment, headers: array<string, string>} */
    private function ownerContext(): array
    {
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create([
            'cnpj' => self::VALID_CNPJ,
            'operating_settings' => ['uf' => 'SP'],
        ]);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
            'status' => 'active',
        ]);
        $establishment = ErpEstablishment::factory()->create([
            'erp_company_id' => $company->id,
            'cnpj' => self::VALID_CNPJ,
        ]);
        $this->login($user);

        return [
            'company' => $company,
            'establishment' => $establishment,
            'headers' => ['X-Company-Id' => (string) $company->id],
        ];
    }

    private function login(User $user): void
    {
        $csrfToken = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');

        $this->withHeader('X-CSRF-Token', $csrfToken)
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }

    private function pkcs12ForCnpj(string $cnpj): string
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $this->assertNotFalse($privateKey);

        $request = openssl_csr_new(['commonName' => $cnpj, 'countryName' => 'BR'], $privateKey, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($request);
        $certificate = openssl_csr_sign($request, null, $privateKey, 2, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($certificate);

        $pkcs12 = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pkcs12, $privateKey, ''));

        return $pkcs12;
    }
}
