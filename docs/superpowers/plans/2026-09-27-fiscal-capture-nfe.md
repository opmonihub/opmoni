# Fiscal Document Capture — NF-e End-to-End Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capturar NF-e emitidas por terceiros contra o CNPJ dos clientes do escritório, usando o A1 que o escritório já guarda, e apresentá-las num painel e numa tabela unificada sob `/fiscal`.

**Architecture:** Uma interface `FiscalConnector` com uma implementação por serviço do fisco. O conector fala com o serviço e faz o parse do envelope; não escreve no banco. Um `FiscalDocumentWriter` único faz o upsert idempotente por `(client_id, chave_acesso, event_id)`, e um `FiscalCaptureService` orquestra credencial → cursor → conector → escrita → avanço do cursor, nessa ordem, para que uma interrupção só perca trabalho e nunca documento.

**Tech Stack:** Laravel 13 / PHP 8.3, PostgreSQL, Redis (cache + fila), PHPUnit 12, Nuxt 4 + Vue 3 + Nuxt UI v4, `@tanstack/table-core`, `unovis`, `node --test`.

**Spec:** `openspec/changes/add-fiscal-document-capture/` — `proposal.md`, `design.md`, `tasks.md`, e os deltas em `specs/{fiscal-capture,fiscal-documents-ui,client-fiscal-access}/spec.md`.

## Global Constraints

- **Nenhuma dependência nova.** `backend/composer.json` mantém exatamente quatro dependências de runtime: `php ^8.3`, `laravel/framework ^13.17`, `laravel/sanctum ^4.0`, `laravel/tinker ^3.0`. `backend/AGENTS.md:33` proíbe alterar dependências sem aprovação.
- **Nenhuma assinatura XMLDSig.** O serviço de Distribuição DF-e não assina a requisição e o XSD a rejeita com `cStat 215` se for injetada. Autenticação é mTLS com o A1.
- **Sem base folder novo em `app/`.** Tudo entra sob `app/Services/Fiscal/`, espelhando os 22 serviços já planos em `app/Services/`.
- **Verificação de TLS permanece ligada.** `verify => true` e bundle ICP-Brasil vendorizado em `CURLOPT_CAINFO`. Nunca replicar o `CURLOPT_SSL_VERIFYHOST => 0` do `sped-common`.
- **`vendor/bin/pint --dirty --format agent`** após toda edição de PHP. Nunca `--test`.
- **Testes usam sqlite `:memory:`** via `phpunit.xml`. `RefreshDatabase` é opt-in por classe (`tests/TestCase.php` não o traz). Nunca usar sqlite como banco de dev.
- **Nomes de teste:** snake_case com vocabulário de domínio em português, prefixo `test_`. Criar com `php artisan make:test --phpunit Nome` (sem `Feature/` no nome).
- **Nunca `ultNSU += 1`.** A posição é sempre o valor devolvido pelo serviço.
- **Nunca manifestar.** Nenhum caminho de código envia evento ao fisco.
- **Nunca logar** senha de certificado, conteúdo bruto de XML, ou payload de `docZip`.
- **Frontend:** `pnpm lint`, `pnpm typecheck`, e testes com `node --test tests/`. Não existe vitest no projeto.

---

## File Structure

### Backend — criar

| Arquivo | Responsabilidade |
|---|---|
| `app/Enums/FiscalSource.php` | Fonte de captura (`nfe_distribuicao`, `cte_distribuicao`) |
| `app/Enums/FiscalModel.php` | Modelo do documento (`nfe`, `nfce`, `cte`, `nfse`) |
| `app/Enums/FiscalKind.php` | `document` ou `event` |
| `app/Enums/FiscalFailure.php` | Taxonomia de resultado e rejeição do serviço |
| `app/Enums/FiscalSkipReason.php` | Por que uma captura não rodou |
| `app/Models/FiscalDocument.php` | Documento capturado |
| `app/Models/FiscalCursor.php` | Posição e bloqueio por cliente e fonte |
| `app/Services/Fiscal/Support/ClientCertificateMaterializer.php` | PFX efêmero para mTLS |
| `app/Services/Fiscal/Support/DocZipDecoder.php` | base64 → descompressão → XML |
| `app/Services/Fiscal/Support/DfeResponseParser.php` | Lê `retDistDFeInt` |
| `app/Services/Fiscal/Support/DfeResponse.php` | Resultado do parse |
| `app/Services/Fiscal/Support/DfeEntry.php` | Uma entrada do lote |
| `app/Services/Fiscal/Support/DfeSoapEnvelope.php` | Monta o envelope SOAP 1.2 |
| `app/Services/Fiscal/Support/FiscalXmlValidator.php` | Valida contra XSD local |
| `app/Services/Fiscal/Support/FiscalXmlMetadata.php` | Extrai metadados e valida a chave |
| `app/Services/Fiscal/Support/FiscalXmlMetadataResult.php` | Resultado da extração |
| `app/Services/Fiscal/Contracts/FiscalConnector.php` | Contrato do conector |
| `app/Services/Fiscal/Contracts/PulledDocument.php` | Documento vindo do serviço |
| `app/Services/Fiscal/Contracts/PullResult.php` | Resultado de um pull |
| `app/Services/Fiscal/Nfe/NfeDistributionConnector.php` | Driver de NF-e |
| `app/Services/Fiscal/Capture/FiscalDocumentWriter.php` | Upsert idempotente + storage |
| `app/Services/Fiscal/Capture/FiscalCaptureService.php` | Orquestra a captura |
| `app/Services/Fiscal/Capture/FiscalCaptureOutcome.php` | Resultado de uma captura |
| `app/Jobs/CaptureFiscalDocumentsJob.php` | Execução em fila |
| `app/Console/Commands/CaptureFiscalDocuments.php` | `fiscal:capture` |
| `app/Http/Controllers/Tenant/FiscalDocumentController.php` | API |
| `app/Http/Requests/Tenant/IndexFiscalDocumentRequest.php` | Filtros |
| `app/Http/Resources/FiscalDocumentResource.php` | Resposta |
| `app/Policies/FiscalDocumentPolicy.php` | Autorização |

### Backend — modificar

| Arquivo | Mudança |
|---|---|
| `app/Models/ClientCertificate.php` | `password_encrypted` no `#[Fillable]`, accessor, cast |
| `app/Services/ClientCertificateVault.php` | Grava a senha criptografada; apaga na substituição e na remoção |
| `database/factories/ClientCertificateFactory.php` | Senha nos estados |
| `routes/api.php` | Rotas de `/fiscal`; remover `apiResource documents` |
| `routes/console.php` | Agendar `fiscal:capture` |
| `config/filesystems.php` | Disco `fiscal` |
| `app/Providers/AppServiceProvider.php` | `Gate::policy` do fiscal; remover o de `Document` |
| `app/Models/Account.php` | Remover a relação `documents()` |

### Backend — remover

`app/Models/Document.php`, `app/Http/Controllers/Tenant/DocumentController.php`, `app/Http/Requests/Tenant/{Store,Update}DocumentRequest.php`, `app/Http/Resources/DocumentResource.php`, `app/Policies/DocumentPolicy.php`, `database/factories/DocumentFactory.php`, `database/migrations/2026_01_01_000008_create_documents_table.php`, e a migration de drop.

### Frontend — criar

| Arquivo | Responsabilidade |
|---|---|
| `app/utils/fiscalNav.ts` | Abas e filhos da sidebar |
| `app/types/fiscal.ts` | Contrato de rede |
| `app/composables/useFiscal.ts` | Chamadas de API |
| `app/pages/fiscal.vue` | Invólucro com abas |
| `app/pages/fiscal/index.vue` | Painel |
| `app/pages/fiscal/documentos.vue` | Tabela |
| `app/utils/fiscalPresentation.ts` | Funções puras de apresentação |
| `tests/fiscalPresentation.test.ts` | Testes das funções puras |

### Frontend — modificar

`app/layouts/default.vue` (entrada "Fiscal" e grupo de busca).

### Fixtures de teste

`backend/tests/Fixtures/fiscal/` — `retDistDFeInt_138.xml`, `retDistDFeInt_137.xml`, `retDistDFeInt_656_com_nsu.xml`, `retDistDFeInt_589.xml`, `resNFe.xml`, `procNFe.xml`, `distDFeInt_v1.01.xsd`.

---

## Task 1: Coluna da senha do certificado

**Files:**
- Create: `backend/database/migrations/2026_09_28_000001_add_password_to_client_certificates_table.php`
- Modify: `backend/app/Models/ClientCertificate.php`
- Modify: `backend/database/factories/ClientCertificateFactory.php`
- Test: `backend/tests/Unit/ClientCertificatePasswordTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `ClientCertificate::certificatePassword(): ?string` — senha em claro ou `null` quando não armazenada.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\ClientCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ClientCertificatePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_decrypted_password(): void
    {
        $certificate = ClientCertificate::factory()->create([
            'password_encrypted' => Crypt::encryptString('segredo'),
        ]);

        $this->assertSame('segredo', $certificate->certificatePassword());
    }

    public function test_returns_null_when_password_absent(): void
    {
        $certificate = ClientCertificate::factory()->create(['password_encrypted' => null]);

        $this->assertNull($certificate->certificatePassword());
    }

    public function test_password_is_not_serialized(): void
    {
        $certificate = ClientCertificate::factory()->create([
            'password_encrypted' => Crypt::encryptString('segredo'),
        ]);

        $this->assertArrayNotHasKey('password_encrypted', $certificate->toArray());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ClientCertificatePasswordTest`
Expected: FAIL — coluna inexistente / método indefinido.

- [ ] **Step 3: Write the migration and the model change**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_certificates', function (Blueprint $table): void {
            $table->text('password_encrypted')->nullable()->after('sha256');
        });
    }

    public function down(): void
    {
        Schema::table('client_certificates', function (Blueprint $table): void {
            $table->dropColumn('password_encrypted');
        });
    }
};
```

Em `ClientCertificate`, adicionar `password_encrypted` ao `#[Fillable]`, ao `casts()` como `'password_encrypted' => 'encrypted'`, e o accessor:

```php
public function certificatePassword(): ?string
{
    return $this->password_encrypted === null ? null : $this->password_encrypted;
}
```

Usar o cast `encrypted` do Eloquent em vez de `Crypt` manual deixa o segredo fora de `toArray()` por padrão e centraliza a criptografia. Na factory, adicionar o estado:

```php
public function withPassword(string $password = 'senha'): static
{
    return $this->state(fn (): array => ['password_encrypted' => $password]);
}

public function withoutPassword(): static
{
    return $this->state(fn (): array => ['password_encrypted' => null]);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ClientCertificatePasswordTest`
Expected: PASS — 3 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations backend/app/Models/ClientCertificate.php backend/database/factories/ClientCertificateFactory.php backend/tests/Unit/ClientCertificatePasswordTest.php
git commit -m "feat(fiscal): persist client certificate password encrypted"
```

---

## Task 2: O vault grava e apaga a senha

**Files:**
- Modify: `backend/app/Services/ClientCertificateVault.php`
- Test: `backend/tests/Feature/Tenancy/ClientCertificatePasswordVaultTest.php`

**Interfaces:**
- Consumes: `ClientCertificate::certificatePassword()` da Task 1.
- Produces: `ClientCertificateVault::replace()` grava a senha; `remove()` e a substituição apagam a anterior.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientCertificatePasswordVaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('certificates');
    }

    public function test_upload_stores_password_usable_later(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account), 'sanctum');

        ['file' => $file] = $this->pfxUpload('cliente.pfx', 'secret');

        $this->post("/api/clients/{$client->getKey()}/certificate", [
            'certificate' => $file,
            'password' => 'secret',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonMissingPath('data.certificate.password_encrypted');

        $this->assertSame('secret', ClientCertificate::sole()->certificatePassword());
    }

    public function test_replacing_certificate_drops_previous_password(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account), 'sanctum');

        ['file' => $first] = $this->pfxUpload('a.pfx', 'primeira');
        $this->post("/api/clients/{$client->getKey()}/certificate", ['certificate' => $first, 'password' => 'primeira'], ['Accept' => 'application/json']);

        $previous = ClientCertificate::sole();

        ['file' => $second] = $this->pfxUpload('b.pfx', 'segunda');
        $this->post("/api/clients/{$client->getKey()}/certificate", ['certificate' => $second, 'password' => 'segunda'], ['Accept' => 'application/json']);

        $this->assertNull($previous->refresh()->certificatePassword());
        $this->assertSame('segunda', ClientCertificate::whereNull('replaced_at')->sole()->certificatePassword());
    }

    public function test_removing_certificate_drops_password(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $this->actingAs($this->memberOf($account), 'sanctum');

        ['file' => $file] = $this->pfxUpload('a.pfx', 'secret');
        $this->post("/api/clients/{$client->getKey()}/certificate", ['certificate' => $file, 'password' => 'secret'], ['Accept' => 'application/json']);

        $certificate = ClientCertificate::sole();

        $this->delete("/api/clients/{$client->getKey()}/certificate", [], ['Accept' => 'application/json'])->assertNoContent();

        $this->assertNull($certificate->refresh()->certificatePassword());
    }

    /** @return array{file: UploadedFile} */
    private function pfxUpload(string $name, string $password): array
    {
        $config = file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];
        $key = openssl_pkey_new(array_merge(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA], $config));
        $csr = openssl_csr_new(['CN' => 'Teste'], $key, array_merge(['digest_alg' => 'sha256'], $config));
        $cert = openssl_csr_sign($csr, null, $key, 365, array_merge(['digest_alg' => 'sha256'], $config));
        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($cert, $pfx, $key, $password));

        return ['file' => UploadedFile::fake()->createWithContent($name, $pfx)];
    }

    private function memberOf(Account $account, string $role = 'operador'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ClientCertificatePasswordVaultTest`
Expected: FAIL — `certificatePassword()` devolve `null` no primeiro teste.

- [ ] **Step 3: Implement**

Em `ClientCertificateVault::replace()`, acrescentar `'password_encrypted' => $password` ao array `$attributes` que já monta (linha ~41). A limpeza no `finally` já existe e permanece.

Na substituição, ao marcar o certificado anterior com `replaced_at`, limpar também a senha:

```php
if ($current !== null) {
    $oldPath = $current->storage_path;
    $current->forceFill([
        'replaced_at' => now(),
        'storage_path' => null,
        'password_encrypted' => null,
    ])->save();
}
```

Em `remove()`, o mesmo `forceFill` que já anula `storage_path` passa a anular `password_encrypted`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ClientCertificatePasswordVaultTest`
Expected: PASS — 3 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/ClientCertificateVault.php backend/tests/Feature/Tenancy/ClientCertificatePasswordVaultTest.php
git commit -m "feat(fiscal): store and clear certificate password in vault"
```

---

## Task 3: Materializador do certificado do cliente

**Files:**
- Create: `backend/app/Services/Fiscal/Support/ClientCertificateMaterializer.php`
- Test: `backend/tests/Feature/Fiscal/ClientCertificateMaterializerTest.php`

**Interfaces:**
- Consumes: `ClientCertificate::certificatePassword()` (Task 1).
- Produces: `ClientCertificateMaterializer::withCertificate(ClientCertificate $certificate, Closure $callback): mixed` — invoca `$callback(string $pfxPath)` com o arquivo em disco, e garante remoção.

Espelha `SerproCertificateMaterializer` (`app/Services/SerproCertificateMaterializer.php:23-65`), que é o padrão já revisado do repo.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Fiscal;

use App\Models\ClientCertificate;
use App\Services\Fiscal\Support\ClientCertificateMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientCertificateMaterializerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_callback_receives_readable_file_and_file_is_removed(): void
    {
        $certificate = ClientCertificate::factory()->withPassword()->create();
        $seen = null;

        $result = resolve(ClientCertificateMaterializer::class)->withCertificate(
            $certificate,
            function (string $path) use (&$seen): string {
                $seen = $path;

                return 'resultado';
            },
        );

        $this->assertSame('resultado', $result);
        $this->assertNotNull($seen);
        $this->assertFileDoesNotExist($seen);
    }

    public function test_file_is_removed_when_callback_throws(): void
    {
        $certificate = ClientCertificate::factory()->withPassword()->create();
        $seen = null;

        try {
            resolve(ClientCertificateMaterializer::class)->withCertificate(
                $certificate,
                function (string $path) use (&$seen): never {
                    $seen = $path;
                    throw new \RuntimeException('falha');
                },
            );
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertNotNull($seen);
        $this->assertFileDoesNotExist($seen);
    }

    public function test_two_calls_use_distinct_files(): void
    {
        $certificate = ClientCertificate::factory()->withPassword()->create();
        $paths = [];

        foreach (range(1, 2) as $ignored) {
            resolve(ClientCertificateMaterializer::class)->withCertificate(
                $certificate,
                function (string $path) use (&$paths): void {
                    $paths[] = $path;
                },
            );
        }

        $this->assertCount(2, $paths);
        $this->assertNotSame($paths[0], $paths[1]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ClientCertificateMaterializerTest`
Expected: FAIL — classe não encontrada.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Fiscal\Support;

use App\Models\ClientCertificate;
use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class ClientCertificateMaterializer
{
    /**
     * O cURL aceita apenas caminho de arquivo para o certificado, então o
     * PKCS#12 é gravado num arquivo efêmero e apagado em `finally`. A senha
     * vive no escopo do método e some junto com ele.
     *
     * @template TReturn
     *
     * @param  Closure(string): TReturn  $callback
     * @return TReturn
     */
    public function withCertificate(ClientCertificate $certificate, Closure $callback): mixed
    {
        $bytes = $this->bytes($certificate);

        if ($bytes === null) {
            throw new RuntimeException('Certificado do cliente não está disponível.');
        }

        $password = $certificate->certificatePassword();

        if ($password === null) {
            throw new RuntimeException('A senha do certificado do cliente não está armazenada.');
        }

        $relative = 'fiscal-tmp/'.Str::uuid().'.pfx';
        $disk = Storage::disk('local');

        if ($disk->put($relative, $bytes) === false) {
            throw new RuntimeException('Não foi possível gravar o certificado no diretório temporário.');
        }

        $path = null;

        try {
            $path = $disk->path($relative);
            @chmod($path, 0600);

            return $callback($path);
        } finally {
            if ($path !== null) {
                @unlink($path);
            }

            $password = str_repeat("\0", strlen($password));
            $bytes = str_repeat("\0", strlen($bytes));
            unset($password, $bytes);
        }
    }

    private function bytes(ClientCertificate $certificate): ?string
    {
        if ($certificate->storage_path === null) {
            return null;
        }

        $disk = Storage::disk('certificates');

        if (! $disk->exists($certificate->storage_path)) {
            return null;
        }

        $stored = $disk->get($certificate->storage_path);

        return $stored === null ? null : base64_decode($stored, true) ?: null;
    }
}
```

O arquivo é gravado no disco `local` (raiz `storage/app/private`), que já existe e é gravável pelo `www-data` realinhado pelo entrypoint.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ClientCertificateMaterializerTest`
Expected: PASS — 3 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal backend/tests/Feature/Fiscal
git commit -m "feat(fiscal): materialize client certificate for outbound calls"
```

---

## Task 4: Enums do domínio fiscal

**Files:**
- Create: `backend/app/Enums/FiscalSource.php`, `backend/app/Enums/FiscalModel.php`, `backend/app/Enums/FiscalKind.php`, `backend/app/Enums/FiscalSkipReason.php`
- Test: `backend/tests/Unit/FiscalEnumsTest.php`

**Interfaces:**
- Produces: `FiscalSource::{NfeDistribuicao,CteDistribuicao}`, `FiscalModel::{Nfe,Nfce,Cte,Nfse}`, `FiscalKind::{Document,Event}`, `FiscalSkipReason::{Blocked,NoCertificate,Interrupted}` — todos `string`-backed.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSkipReason;
use App\Enums\FiscalSource;
use PHPUnit\Framework\TestCase;

class FiscalEnumsTest extends TestCase
{
    public function test_source_values(): void
    {
        $this->assertSame('nfe_distribuicao', FiscalSource::NfeDistribuicao->value);
        $this->assertSame('cte_distribuicao', FiscalSource::CteDistribuicao->value);
    }

    public function test_model_values(): void
    {
        $this->assertSame('nfe', FiscalModel::Nfe->value);
        $this->assertSame('nfce', FiscalModel::Nfce->value);
        $this->assertSame('cte', FiscalModel::Cte->value);
        $this->assertSame('nfse', FiscalModel::Nfse->value);
    }

    public function test_model_is_derived_from_the_fiscal_document_model_code(): void
    {
        $this->assertSame(FiscalModel::Nfe, FiscalModel::fromDocumentModel('55'));
        $this->assertSame(FiscalModel::Nfce, FiscalModel::fromDocumentModel('65'));
        $this->assertSame(FiscalModel::Cte, FiscalModel::fromDocumentModel('57'));
        $this->assertNull(FiscalModel::fromDocumentModel('99'));
    }

    public function test_kind_and_skip_reason_values(): void
    {
        $this->assertSame('document', FiscalKind::Document->value);
        $this->assertSame('event', FiscalKind::Event->value);
        $this->assertSame('blocked', FiscalSkipReason::Blocked->value);
        $this->assertSame('no_certificate', FiscalSkipReason::NoCertificate->value);
        $this->assertSame('interrupted', FiscalSkipReason::Interrupted->value);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=FiscalEnumsTest`
Expected: FAIL — enums não encontrados.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Enums;

enum FiscalSource: string
{
    case NfeDistribuicao = 'nfe_distribuicao';
    case CteDistribuicao = 'cte_distribuicao';

    public function label(): string
    {
        return match ($this) {
            self::NfeDistribuicao => 'Distribuição NF-e',
            self::CteDistribuicao => 'Distribuição CT-e',
        };
    }
}
```

```php
<?php

namespace App\Enums;

enum FiscalModel: string
{
    case Nfe = 'nfe';
    case Nfce = 'nfce';
    case Cte = 'cte';
    case Nfse = 'nfse';

    /**
     * O código de modelo do documento fiscal (campo `mod` da chave de acesso).
     */
    public static function fromDocumentModel(string $model): ?self
    {
        return match ($model) {
            '55' => self::Nfe,
            '65' => self::Nfce,
            '57' => self::Cte,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Nfe => 'NF-e',
            self::Nfce => 'NFC-e',
            self::Cte => 'CT-e',
            self::Nfse => 'NFS-e',
        };
    }
}
```

```php
<?php

namespace App\Enums;

enum FiscalKind: string
{
    case Document = 'document';
    case Event = 'event';
}
```

```php
<?php

namespace App\Enums;

enum FiscalSkipReason: string
{
    case Blocked = 'blocked';
    case NoCertificate = 'no_certificate';
    case Interrupted = 'interrupted';
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=FiscalEnumsTest`
Expected: PASS — 4 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Enums backend/tests/Unit/FiscalEnumsTest.php
git commit -m "feat(fiscal): add domain enums"
```

---

## Task 5: Taxonomia de falhas

**Files:**
- Create: `backend/app/Enums/FiscalFailure.php`
- Test: `backend/tests/Unit/FiscalFailureTest.php`

**Interfaces:**
- Produces: `FiscalFailure::classify(int $httpStatus, string $cStat): self`, `FiscalFailure::retryable(): bool`, `FiscalFailure::blocksForAnHour(): bool`.

A lista de rejeições é a **do serviço de distribuição**, não a tabela geral de NF-e. Códigos como `297` e `539` pertencem ao serviço de autorização e nunca aparecem aqui — ver `design.md` decisão 11.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Enums\FiscalFailure;
use PHPUnit\Framework\TestCase;

class FiscalFailureTest extends TestCase
{
    public function test_documents_found_is_not_a_failure(): void
    {
        $this->assertSame(FiscalFailure::DocumentsFound, FiscalFailure::classify(200, '138'));
        $this->assertFalse(FiscalFailure::DocumentsFound->retryable());
        $this->assertFalse(FiscalFailure::DocumentsFound->blocksForAnHour());
    }

    public function test_no_documents_blocks_for_an_hour(): void
    {
        $this->assertSame(FiscalFailure::NoDocuments, FiscalFailure::classify(200, '137'));
        $this->assertTrue(FiscalFailure::NoDocuments->blocksForAnHour());
        $this->assertFalse(FiscalFailure::NoDocuments->retryable());
    }

    public function test_improper_consumption_blocks_for_an_hour(): void
    {
        $this->assertSame(FiscalFailure::Blocked, FiscalFailure::classify(200, '656'));
        $this->assertTrue(FiscalFailure::Blocked->blocksForAnHour());
        $this->assertTrue(FiscalFailure::Blocked->retryable());
    }

    public function test_service_outage_is_blocked_and_retryable(): void
    {
        $this->assertSame(FiscalFailure::Blocked, FiscalFailure::classify(200, '108'));
        $this->assertSame(FiscalFailure::Blocked, FiscalFailure::classify(200, '109'));
        $this->assertTrue(FiscalFailure::Blocked->retryable());
    }

    public function test_cursor_ahead_is_not_retryable(): void
    {
        $this->assertSame(FiscalFailure::CursorAhead, FiscalFailure::classify(200, '589'));
        $this->assertFalse(FiscalFailure::CursorAhead->retryable());
    }

    public function test_credential_mismatch_is_unauthorized(): void
    {
        $this->assertSame(FiscalFailure::Unauthorized, FiscalFailure::classify(200, '593'));
        $this->assertSame(FiscalFailure::Unauthorized, FiscalFailure::classify(200, '472'));
        $this->assertFalse(FiscalFailure::Unauthorized->retryable());
    }

    public function test_document_not_addressed_to_the_cnpj_is_not_interested(): void
    {
        $this->assertSame(FiscalFailure::NotInterested, FiscalFailure::classify(200, '640'));
        $this->assertSame(FiscalFailure::NotInterested, FiscalFailure::classify(200, '641'));
    }

    public function test_schema_and_encoding_rejections_are_our_bug(): void
    {
        foreach (['215', '402', '404', '238', '239', '252', '214', '236', '217'] as $cStat) {
            $this->assertSame(FiscalFailure::Rejected, FiscalFailure::classify(200, $cStat), $cStat);
        }
    }

    public function test_transport_failure_is_upstream(): void
    {
        $this->assertSame(FiscalFailure::Upstream, FiscalFailure::classify(503, ''));
        $this->assertSame(FiscalFailure::Upstream, FiscalFailure::classify(0, ''));
        $this->assertTrue(FiscalFailure::Upstream->retryable());
    }

    public function test_authorization_codes_never_reach_this_service(): void
    {
        // 297 e 539 pertencem ao serviço de autorização e não têm ramo aqui.
        $this->assertSame(FiscalFailure::Rejected, FiscalFailure::classify(200, '297'));
        $this->assertSame(FiscalFailure::Rejected, FiscalFailure::classify(200, '539'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=FiscalFailureTest`
Expected: FAIL — enum não encontrado.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Enums;

enum FiscalFailure: string
{
    case DocumentsFound = 'documents_found';
    case NoDocuments = 'no_documents';
    case Blocked = 'blocked';
    case CursorAhead = 'cursor_ahead';
    case Unauthorized = 'unauthorized';
    case NotInterested = 'not_interested';
    case Rejected = 'rejected';
    case Upstream = 'upstream';

    /**
     * A lista de rejeições do serviço de distribuição, que é bem menor que a
     * tabela geral de NF-e: códigos do serviço de autorização (297, 539, 225)
     * não têm ramo aqui de propósito.
     */
    public static function classify(int $httpStatus, string $cStat): self
    {
        return match ($cStat) {
            '138' => self::DocumentsFound,
            '137' => self::NoDocuments,
            '108', '109', '656', '678' => self::Blocked,
            '589' => self::CursorAhead,
            '593', '472', '473' => self::Unauthorized,
            '640', '641' => self::NotInterested,
            '215', '402', '404', '238', '239', '252', '214', '236', '217', '632', '653', '654', '999' => self::Rejected,
            default => $httpStatus === 0 || $httpStatus >= 500 ? self::Upstream : self::Rejected,
        };
    }

    public function retryable(): bool
    {
        return match ($this) {
            self::Upstream, self::Blocked => true,
            default => false,
        };
    }

    /**
     * Retomar antes de completar uma hora zera a contagem do fisco e a
     * reinicia, então a única saída é parada absoluta.
     */
    public function blocksForAnHour(): bool
    {
        return match ($this) {
            self::Blocked, self::NoDocuments => true,
            default => false,
        };
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=FiscalFailureTest`
Expected: PASS — 10 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Enums/FiscalFailure.php backend/tests/Unit/FiscalFailureTest.php
git commit -m "feat(fiscal): add service failure taxonomy"
```

---

## Task 6: Esquema do banco

**Files:**
- Create: `backend/database/migrations/2026_09_28_000002_create_fiscal_documents_table.php`, `backend/database/migrations/2026_09_28_000003_create_fiscal_cursors_table.php`
- Create: `backend/app/Models/FiscalDocument.php`, `backend/app/Models/FiscalCursor.php`
- Create: `backend/database/factories/FiscalDocumentFactory.php`, `backend/database/factories/FiscalCursorFactory.php`
- Modify: `backend/config/filesystems.php`
- Test: `backend/tests/Feature/Fiscal/FiscalSchemaTest.php`

**Interfaces:**
- Produces: models `FiscalDocument` e `FiscalCursor` com `BelongsToAccount`; disco `fiscal`.

`event_id` é `NOT NULL` com default `''`. No Postgres `NULL != NULL`, então uma coluna nullable anularia silenciosamente a restrição única para documentos sem evento — ver `design.md` decisão 4.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FiscalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_is_unique_per_client_and_access_key(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        FiscalDocument::factory()->create($this->documentAttributes($account, $client));

        $this->expectException(QueryException::class);

        FiscalDocument::factory()->create($this->documentAttributes($account, $client));
    }

    public function test_many_documents_without_event_coexist(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        FiscalDocument::factory()->create($this->documentAttributes($account, $client, '1'));
        FiscalDocument::factory()->create($this->documentAttributes($account, $client, '2'));

        $this->assertSame(2, FiscalDocument::count());
    }

    public function test_event_and_document_share_an_access_key(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        FiscalDocument::factory()->create($this->documentAttributes($account, $client));
        FiscalDocument::factory()->event()->create($this->documentAttributes($account, $client));

        $this->assertSame(2, FiscalDocument::count());
    }

    public function test_cursor_is_unique_per_client_and_source(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        FiscalCursor::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
        ]);

        $this->expectException(QueryException::class);

        FiscalCursor::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
        ]);
    }

    public function test_fiscal_disk_is_private(): void
    {
        $this->assertFalse(config('filesystems.disks.fiscal.serve'));
        Storage::disk('fiscal')->put('probe.txt', 'x');
        $this->assertTrue(Storage::disk('fiscal')->exists('probe.txt'));
        Storage::disk('fiscal')->delete('probe.txt');
    }

    /**
     * @return array<string, mixed>
     */
    private function documentAttributes(Account $account, Client $client, string $nsu = '1'): array
    {
        return [
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'model' => FiscalModel::Nfe,
            'kind' => FiscalKind::Document,
            'chave_acesso' => str_pad($nsu, 44, '0', STR_PAD_LEFT),
            'event_id' => '',
            'nsu' => (int) $nsu,
        ];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=FiscalSchemaTest`
Expected: FAIL — tabelas inexistentes.

- [ ] **Step 3: Implement**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('model');
            $table->string('kind');
            $table->char('chave_acesso', 44);
            // NOT NULL com default vazio: no Postgres NULL != NULL, então uma
            // coluna nullable anularia a unicidade para documentos sem evento.
            $table->string('event_id')->default('');
            $table->unsignedBigInteger('nsu');
            $table->string('emitente_cnpj', 14)->nullable();
            $table->string('destinatario_cnpj', 14)->nullable();
            $table->decimal('valor_total', 14, 2)->nullable();
            $table->timestamp('emissao_at')->nullable();
            $table->timestamp('evento_ocorrido_em_at')->nullable();
            $table->string('schema')->nullable();
            $table->string('storage_path');
            $table->char('sha256', 64);
            $table->unsignedInteger('xml_bytes');
            $table->boolean('mascarado')->default(false);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['client_id', 'chave_acesso', 'event_id']);
            $table->index(['client_id', 'model', 'nsu']);
            $table->index(['account_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
    }
};
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_cursors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->unsignedBigInteger('last_nsu')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            // A contagem de consumo indevido reinicia se retomada antes de
            // completar uma hora, então a parada precisa sobreviver entre
            // execuções: coluna, não variável de processo.
            $table->timestamp('blocked_until')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_cursors');
    }
};
```

`FiscalDocument` e `FiscalCursor` seguem o padrão de `ClientCertificate`: `#[Fillable([...])]` como atributo PHP, `use BelongsToAccount, HasFactory`, `casts()` com `source`/`model`/`kind` para os enums e as datas para `datetime`.

No `config/filesystems.php`, adicionar ao array `disks`, logo após `certificates`:

```php
'fiscal' => [
    'driver' => 'local',
    'root' => storage_path('app/private/fiscal'),
    'serve' => false,
    'throw' => true,
    'report' => true,
],
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=FiscalSchemaTest`
Expected: PASS — 5 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations backend/app/Models backend/database/factories backend/config/filesystems.php backend/tests/Feature/Fiscal
git commit -m "feat(fiscal): add document and cursor schema"
```

---

## Task 7: Remover o scaffolding `documents`

**Files:**
- Delete: `backend/app/Models/Document.php`, `backend/app/Http/Controllers/Tenant/DocumentController.php`, `backend/app/Http/Requests/Tenant/StoreDocumentRequest.php`, `backend/app/Http/Requests/Tenant/UpdateDocumentRequest.php`, `backend/app/Http/Resources/DocumentResource.php`, `backend/app/Policies/DocumentPolicy.php`, `backend/database/factories/DocumentFactory.php`
- Create: `backend/database/migrations/2026_09_28_000004_drop_documents_table.php`
- Modify: `backend/routes/api.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/app/Models/Account.php`
- Test: `backend/tests/Feature/Fiscal/DocumentsRemovedTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: nada. Libera o nome `documents` para não competir com `fiscal_documents`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Fiscal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentsRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_table_is_gone(): void
    {
        $this->assertFalse(Schema::hasTable('documents'));
    }

    public function test_documents_routes_are_gone(): void
    {
        $this->assertFalse(Route::has('documents.index'));
        $this->assertFalse(Route::has('documents.store'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=DocumentsRemovedTest`
Expected: FAIL — a tabela e as rotas ainda existem.

- [ ] **Step 3: Implement**

Criar a migration de drop:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('documents');
    }

    public function down(): void
    {
        // A tabela era scaffolding do template, sem consumidor. Recriá-la no
        // rollback não é necessário; ver design.md decisão 12.
    }
};
```

Remover o `use App\Http\Controllers\Tenant\DocumentController;` e a linha `Route::apiResource('documents', DocumentController::class);` de `routes/api.php`. Remover o `Gate::policy(Document::class, DocumentPolicy::class);` de `AppServiceProvider` e o import. Remover o método `documents()` de `Account`. Apagar os arquivos listados. Apagar a migration original `2026_01_01_000008_create_documents_table.php` e a entrada de `DocumentFactory` em qualquer seeder que a use (`grep -rn 'Document' database/seeders/`).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=DocumentsRemovedTest && grep -rn "Document::class\|DocumentController\|DocumentFactory" backend/app backend/routes backend/database`
Expected: PASS — 2 testes; grep sem resultado.

- [ ] **Step 5: Commit**

```bash
git add -A backend
git commit -m "chore(fiscal): remove unused documents scaffolding"
```

---

## Task 8: Decodificador do lote comprimido

**Files:**
- Create: `backend/app/Services/Fiscal/Support/DocZipDecoder.php`
- Create fixtures: `backend/tests/Fixtures/fiscal/`
- Test: `backend/tests/Unit/DocZipDecoderTest.php`

**Interfaces:**
- Produces: `DocZipDecoder::decode(string $payload): string` — XML descomprimido; lança `RuntimeException` com o magic em hex quando falha.

Higienizar o base64 antes de decodificar é obrigatório: o payload do fisco vem com quebras de linha, e é a causa mais relatada de erro de descompressão.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Services\Fiscal\Support\DocZipDecoder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DocZipDecoderTest extends TestCase
{
    public function test_decodes_gzip_payload(): void
    {
        $xml = '<resNFe xmlns="http://www.portalfiscal.inf.br/nfe"><chNFe>1</chNFe></resNFe>';
        $payload = base64_encode(gzencode($xml));

        $this->assertSame($xml, (new DocZipDecoder)->decode($payload));
    }

    public function test_decodes_payload_with_surrounding_whitespace(): void
    {
        $xml = '<a/>';
        $payload = chunk_split(base64_encode(gzencode($xml)), 20, "\n");

        $this->assertSame($xml, (new DocZipDecoder)->decode($payload));
    }

    public function test_decodes_zlib_payload(): void
    {
        $xml = '<a/>';
        $payload = base64_encode(gzcompress($xml));

        $this->assertSame($xml, (new DocZipDecoder)->decode($payload));
    }

    public function test_decodes_zip_payload_tolerated_in_production(): void
    {
        $xml = '<a/>';
        $path = tempnam(sys_get_temp_dir(), 'dz');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('doc.xml', $xml);
        $zip->close();
        $payload = base64_encode((string) file_get_contents($path));
        unlink($path);

        $this->assertSame($xml, (new DocZipDecoder)->decode($payload));
    }

    public function test_throws_with_magic_when_payload_is_corrupt(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/magic=/');

        (new DocZipDecoder)->decode(base64_encode('nao e um zip'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=DocZipDecoderTest`
Expected: FAIL — classe não encontrada.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Fiscal\Support;

use RuntimeException;
use ZipArchive;

final class DocZipDecoder
{
    /**
     * O formato documentado é gZip. O formato alternativo é aceito por
     * liberalidade de implementação observada em produção, então é detectado
     * por magic bytes em vez de assumido.
     */
    public function decode(string $payload): string
    {
        $binary = base64_decode(preg_replace('/\s+/', '', $payload) ?? '', true);

        if ($binary === false || $binary === '') {
            throw new RuntimeException('docZip: base64 inválido.');
        }

        if (str_starts_with($binary, "PK\x03\x04")) {
            return $this->fromZip($binary);
        }

        $xml = @gzdecode($binary);

        if ($xml === false) {
            throw new RuntimeException('docZip: falha ao descompactar (magic='.bin2hex(substr($binary, 0, 4)).').');
        }

        return $xml;
    }

    private function fromZip(string $binary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'doczip');

        if ($path === false) {
            throw new RuntimeException('docZip: não foi possível criar arquivo temporário.');
        }

        try {
            file_put_contents($path, $binary);

            $zip = new ZipArchive;

            if ($zip->open($path) !== true) {
                throw new RuntimeException('docZip: arquivo compactado ilegível.');
            }

            $name = $zip->getNameIndex(0);

            if ($name === false) {
                $zip->close();

                throw new RuntimeException('docZip: arquivo compactado vazio.');
            }

            $xml = $zip->getFromName($name);
            $zip->close();

            if ($xml === false) {
                throw new RuntimeException('docZip: entrada ilegível no arquivo compactado.');
            }

            return $xml;
        } finally {
            @unlink($path);
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=DocZipDecoderTest`
Expected: PASS — 5 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Support/DocZipDecoder.php backend/tests/Unit/DocZipDecoderTest.php
git commit -m "feat(fiscal): decode compressed document payloads"
```

---

## Task 9: Parser da resposta e envelope SOAP

**Files:**
- Create: `backend/app/Services/Fiscal/Support/DfeEntry.php`, `backend/app/Services/Fiscal/Support/DfeResponse.php`, `backend/app/Services/Fiscal/Support/DfeResponseParser.php`, `backend/app/Services/Fiscal/Support/DfeSoapEnvelope.php`
- Create fixture: `backend/tests/Fixtures/fiscal/retDistDFeInt_138.xml`
- Test: `backend/tests/Unit/DfeResponseParserTest.php`, `backend/tests/Unit/DfeSoapEnvelopeTest.php`

**Interfaces:**
- Produces:
  - `DfeEntry::__construct(int $nsu, string $schema, string $payload)`
  - `DfeResponse::__construct(string $cStat, string $xMotivo, int $ultNsu, ?int $maxNsu, array $entries)`
  - `DfeResponseParser::parse(string $soapResponse): DfeResponse`
  - `DfeSoapEnvelope::build(string $serviceNamespace, string $payloadNamespace, string $version, string $soapAction, string $cnpj, string $cUf, int $fromNsu, string $method): string`

A busca do `retDistDFeInt` é por `local-name()` para ser imune a prefixo de namespace.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Services\Fiscal\Support\DfeResponseParser;
use App\Services\Fiscal\Support\DfeSoapEnvelope;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DfeResponseParserTest extends TestCase
{
    public function test_parses_response_with_entries(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/retDistDFeInt_138.xml'));

        $response = (new DfeResponseParser)->parse($xml);

        $this->assertSame('138', $response->cStat);
        $this->assertSame(200, $response->ultNsu);
        $this->assertSame(200, $response->maxNsu);
        $this->assertCount(1, $response->entries);
        $this->assertSame(200, $response->entries[0]->nsu);
        $this->assertSame('resNFe_v1.01.xsd', $response->entries[0]->schema);
        $this->assertNotSame('', $response->entries[0]->payload);
    }

    public function test_is_immune_to_namespace_prefix(): void
    {
        $xml = <<<'XML'
        <soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">
          <soap:Body>
            <nfeDistDFeInteresseResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe">
              <nfeDistDFeInteresseResult>
                <retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
                  <cStat>137</cStat>
                  <xMotivo>Nenhum documento localizado</xMotivo>
                  <ultNSU>000000000000000</ultNSU>
                  <maxNSU>000000000000000</maxNSU>
                </retDistDFeInt>
              </nfeDistDFeInteresseResult>
            </nfeDistDFeInteresseResponse>
          </soap:Body>
        </soap:Envelope>
        XML;

        $response = (new DfeResponseParser)->parse($xml);

        $this->assertSame('137', $response->cStat);
        $this->assertSame(0, $response->ultNsu);
        $this->assertSame([], $response->entries);
    }

    public function test_rejection_carries_the_cursor_inside_the_body(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/retDistDFeInt_656_com_nsu.xml'));

        $response = (new DfeResponseParser)->parse($xml);

        $this->assertSame('656', $response->cStat);
        $this->assertSame(1678, $response->ultNsu);
    }

    public function test_throws_when_body_has_no_ret_dist_dfe_int(): void
    {
        $this->expectException(RuntimeException::class);

        (new DfeResponseParser)->parse('<html><body>502 Bad Gateway</body></html>');
    }
}

class DfeSoapEnvelopeTest extends TestCase
{
    public function test_builds_soap_12_envelope_without_header(): void
    {
        $envelope = (new DfeSoapEnvelope)->build(
            serviceNamespace: 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe',
            payloadNamespace: 'http://www.portalfiscal.inf.br/nfe',
            version: '1.01',
            soapAction: 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe/nfeDistDFeInteresse',
            cnpj: '00000000000191',
            cUf: '35',
            fromNsu: 0,
            method: 'nfeDistDFeInteresse',
        );

        $this->assertStringContainsString('http://www.w3.org/2003/05/soap-envelope', $envelope);
        $this->assertStringContainsString('<nfeDistDFeInteresse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe">', $envelope);
        $this->assertStringContainsString('<distDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">', $envelope);
        $this->assertStringContainsString('<cUFAutor>35</cUFAutor>', $envelope);
        $this->assertStringContainsString('<CNPJ>00000000000191</CNPJ>', $envelope);
        $this->assertStringContainsString('<ultNSU>000000000000000</ultNSU>', $envelope);
        $this->assertStringNotContainsString('<soap:Header', $envelope);
        $this->assertStringNotContainsString('Signature', $envelope);
    }

    public function test_pads_the_cursor_to_fifteen_digits(): void
    {
        $envelope = (new DfeSoapEnvelope)->build(
            serviceNamespace: 'ns',
            payloadNamespace: 'ns2',
            version: '1.01',
            soapAction: 'action',
            cnpj: '00000000000191',
            cUf: '35',
            fromNsu: 42,
            method: 'nfeDistDFeInteresse',
        );

        $this->assertStringContainsString('<ultNSU>000000000000042</ultNSU>', $envelope);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="DfeResponseParserTest|DfeSoapEnvelopeTest"`
Expected: FAIL — classes não encontradas.

- [ ] **Step 3: Implement**

Criar `tests/Fixtures/fiscal/retDistDFeInt_138.xml` com uma resposta real de produção de um documento (o payload de `docZip` é `base64(gzip(xml))`, então gerar com `base64_encode(gzencode('<resNFe .../>'))`), e `retDistDFeInt_656_com_nsu.xml` com `<cStat>656</cStat>`, `<xMotivo>Rejeicao: Consumo Indevido...</xMotivo>` e `<ultNSU>000000000001678</ultNSU>`.

```php
<?php

namespace App\Services\Fiscal\Support;

final readonly class DfeEntry
{
    public function __construct(
        public int $nsu,
        public string $schema,
        public string $payload,
    ) {}
}
```

```php
<?php

namespace App\Services\Fiscal\Support;

final readonly class DfeResponse
{
    /**
     * @param  list<DfeEntry>  $entries
     */
    public function __construct(
        public string $cStat,
        public string $xMotivo,
        public int $ultNsu,
        public ?int $maxNsu,
        public array $entries,
    ) {}
}
```

```php
<?php

namespace App\Services\Fiscal\Support;

use DOMDocument;
use DOMXPath;
use RuntimeException;

final class DfeResponseParser
{
    /**
     * Localiza o corpo por nome local para não depender de prefixo nem do
     * nome do elemento de resposta, que varia entre implementações.
     */
    public function parse(string $soapResponse): DfeResponse
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($soapResponse);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('A resposta do serviço não é um XML legível.');
        }

        $xpath = new DOMXPath($dom);
        $body = $xpath->query('//*[local-name()="retDistDFeInt"]')->item(0);

        if (! $body instanceof \DOMElement) {
            throw new RuntimeException('A resposta do serviço não contém retDistDFeInt.');
        }

        return new DfeResponse(
            cStat: $this->text($xpath, $body, 'cStat') ?? '',
            xMotivo: $this->text($xpath, $body, 'xMotivo') ?? '',
            ultNsu: (int) ($this->text($xpath, $body, 'ultNSU') ?? '0'),
            maxNsu: ($max = $this->text($xpath, $body, 'maxNSU')) === null ? null : (int) $max,
            entries: $this->entries($xpath, $body),
        );
    }

    /**
     * @return list<DfeEntry>
     */
    private function entries(DOMXPath $xpath, \DOMElement $body): array
    {
        $entries = [];

        foreach ($xpath->query('.//*[local-name()="docZip"]', $body) ?: [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $entries[] = new DfeEntry(
                nsu: (int) $node->getAttribute('NSU'),
                schema: $node->getAttribute('schema'),
                payload: $node->textContent,
            );
        }

        return $entries;
    }

    private function text(DOMXPath $xpath, \DOMElement $scope, string $localName): ?string
    {
        $node = $xpath->query('.//*[local-name()="'.$localName.'"]', $scope)->item(0);

        return $node?->textContent;
    }
}
```

```php
<?php

namespace App\Services\Fiscal\Support;

final class DfeSoapEnvelope
{
    /**
     * O serviço de distribuição não usa cabeçalho SOAP e não assina a
     * requisição: a autenticação é o certificado no transporte.
     */
    public function build(
        string $serviceNamespace,
        string $payloadNamespace,
        string $version,
        string $soapAction,
        string $cnpj,
        string $cUf,
        int $fromNsu,
        string $method,
    ): string {
        $cursor = str_pad((string) $fromNsu, 15, '0', STR_PAD_LEFT);

        $payload = '<distDFeInt xmlns="'.$payloadNamespace.'" versao="'.$version.'">'
            .'<tpAmb>'.(config('fiscal.environment') === 'producao' ? '1' : '2').'</tpAmb>'
            .'<cUFAutor>'.$cUf.'</cUFAutor>'
            .'<CNPJ>'.$cnpj.'</CNPJ>'
            .'<distNSU><ultNSU>'.$cursor.'</ultNSU></distNSU>'
            .'</distDFeInt>';

        $inner = '<'.$method.' xmlns="'.$serviceNamespace.'">'
            .'<nfeDadosMsg xmlns="'.$serviceNamespace.'">'.$payload.'</nfeDadosMsg>'
            .'</'.$method.'>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">'
            .'<soap:Body>'.$inner.'</soap:Body>'
            .'</soap:Envelope>';
    }
}
```

O `soapAction` entra no cabeçalho `Content-Type` da chamada, não no corpo — ver Task 14.

Criar `config/fiscal.php`:

```php
<?php

return [
    /*
     * `producao` usa o ambiente nacional de produção; `homologacao` usa o de
     * homologação, que é notoriamente vazio para este serviço.
     */
    'environment' => env('FISCAL_ENVIRONMENT', 'producao'),

    'timeout' => (int) env('FISCAL_TIMEOUT', 60),

    /*
     * Quantos documentos o serviço devolve por lote. O fisco não aceita
     * parametrizar; o valor é informativo e usado nos testes.
     */
    'batch_limit' => 50,

    /*
     * Após este número de dias sem captura bem-sucedida o fisco interrompe a
     * geração de posições sem retroativa, então paramos de consultar e
     * reportamos o histórico como interrompido.
     */
    'continuity_days' => (int) env('FISCAL_CONTINUITY_DAYS', 60),

    'continuity_alert_days' => (int) env('FISCAL_CONTINUITY_ALERT_DAYS', 45),

    /*
     * O fisco bloqueia o CNPJ por uma hora após consumo indevido, e retomar
     * antes disso zera a contagem e reinicia.
     */
    'block_minutes' => (int) env('FISCAL_BLOCK_MINUTES', 60),

    'consulta_hourly_limit' => 20,

    'endpoints' => [
        'nfe_distribuicao' => [
            'producao' => 'https://www1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx',
            'homologacao' => 'https://hom1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx',
            'namespace' => 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe',
            'payload_namespace' => 'http://www.portalfiscal.inf.br/nfe',
            'version' => '1.01',
            'method' => 'nfeDistDFeInteresse',
            'soap_action' => 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe/nfeDistDFeInteresse',
            'holder' => 'nfeDadosMsg',
        ],
    ],

    'ca_bundle' => storage_path('app/icp-brasil/ca-bundle.pem'),
];
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter="DfeResponseParserTest|DfeSoapEnvelopeTest"`
Expected: PASS — 6 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Support backend/config/fiscal.php backend/tests/Unit backend/tests/Fixtures/fiscal
git commit -m "feat(fiscal): parse service responses and build SOAP envelope"
```

---

## Task 10: Metadados do XML e dígito verificador

**Files:**
- Create: `backend/app/Services/Fiscal/Support/FiscalXmlMetadataResult.php`, `backend/app/Services/Fiscal/Support/FiscalXmlMetadata.php`
- Create fixtures: `backend/tests/Fixtures/fiscal/resNFe.xml`, `backend/tests/Fixtures/fiscal/procNFe.xml`
- Test: `backend/tests/Unit/FiscalXmlMetadataTest.php`

**Interfaces:**
- Produces:
  - `FiscalXmlMetadata::extract(string $xml, FiscalModel $model): FiscalXmlMetadataResult`
  - `FiscalXmlMetadata::isValidChave(string $chave): bool` — módulo 11
  - `FiscalXmlMetadataResult::__construct(string $chave, FiscalModel $model, FiscalKind $kind, string $eventId, ?string $emitenteCnpj, ?string $destinatarioCnpj, ?string $valorTotal, ?CarbonImmutable $emissaoAt, ?CarbonImmutable $eventoOcorridoEmAt, string $schema)`

Validar o DV é o parse mais barato que detecta corrupção de identidade.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use PHPUnit\Framework\TestCase;

class FiscalXmlMetadataTest extends TestCase
{
    public function test_accepts_a_valid_access_key(): void
    {
        // Chave real de NF-e com DV 3, módulo 11.
        $this->assertTrue(FiscalXmlMetadata::isValidChave('35220499999999999999550010020000001240556603'));
    }

    public function test_rejects_wrong_check_digit(): void
    {
        $this->assertFalse(FiscalXmlMetadata::isValidChave('35220499999999999999550010020000001240556604'));
    }

    public function test_rejects_wrong_length_and_non_digits(): void
    {
        $this->assertFalse(FiscalXmlMetadata::isValidChave('123'));
        $this->assertFalse(FiscalXmlMetadata::isValidChave(str_repeat('A', 44)));
    }

    public function test_extracts_summary_metadata(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/resNFe.xml'));

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        $this->assertSame(FiscalModel::Nfe, $result->model);
        $this->assertSame(FiscalKind::Document, $result->kind);
        $this->assertSame('35220499999999999999550010020000001240556603', $result->chave);
        $this->assertSame('99999999999999', $result->emitenteCnpj);
        $this->assertSame('710.00', $result->valorTotal);
        $this->assertNotNull($result->emissaoAt);
        $this->assertSame('', $result->eventId);
    }

    public function test_extracts_authorized_document_metadata(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/procNFe.xml'));

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        $this->assertSame(FiscalKind::Document, $result->kind);
        $this->assertNotNull($result->destinatarioCnpj);
    }

    public function test_extracts_event_identity(): void
    {
        $xml = <<<'XML'
        <procEventoNFe xmlns="http://www.portalfiscal.inf.br/nfe">
          <evento versao="1.00">
            <infEvento Id="ID1101113522049999999999999955001002000000124055660301">
              <tpEvento>110111</tpEvento>
              <nSeqEvento>1</nSeqEvento>
              <dhEvento>2022-04-04T11:54:49-03:00</dhEvento>
            </infEvento>
          </evento>
        </procEventoNFe>
        XML;

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        $this->assertSame(FiscalKind::Event, $result->kind);
        $this->assertSame('110111', $result->eventId);
        $this->assertNotNull($result->eventoOcorridoEmAt);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=FiscalXmlMetadataTest`
Expected: FAIL — classe não encontrada.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Fiscal\Support;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use Carbon\CarbonImmutable;

final readonly class FiscalXmlMetadataResult
{
    public function __construct(
        public string $chave,
        public FiscalModel $model,
        public FiscalKind $kind,
        public string $eventId,
        public string $schema,
        public ?string $emitenteCnpj,
        public ?string $destinatarioCnpj,
        public ?string $valorTotal,
        public ?CarbonImmutable $emissaoAt,
        public ?CarbonImmutable $eventoOcorridoEmAt,
    ) {}
}
```

```php
<?php

namespace App\Services\Fiscal\Support;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use RuntimeException;

final class FiscalXmlMetadata
{
    public function extract(string $xml, FiscalModel $model): FiscalXmlMetadataResult
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('O documento capturado não é um XML legível.');
        }

        $xpath = new DOMXPath($dom);
        $schema = $this->schemaOf($dom);

        $chave = $this->firstText($xpath, ['chNFe', 'chCTe'])
            ?? $this->chaveFromId($xpath)
            ?? throw new RuntimeException('O documento capturado não expõe chave de acesso.');

        if (! self::isValidChave($chave)) {
            throw new RuntimeException("Chave de acesso com dígito verificador inválido: {$chave}.");
        }

        $tpEvento = $this->firstText($xpath, ['tpEvento']);
        $isEvent = $tpEvento !== null;

        return new FiscalXmlMetadataResult(
            chave: $chave,
            model: $model,
            kind: $isEvent ? FiscalKind::Event : FiscalKind::Document,
            eventId: $isEvent ? $tpEvento.'-'.($this->firstText($xpath, ['nSeqEvento']) ?? '1') : '',
            schema: $schema,
            emitenteCnpj: $this->firstText($xpath, ['emit/CNPJ', 'prest/CNPJ', 'CNPJ']),
            destinatarioCnpj: $this->firstText($xpath, ['dest/CNPJ', 'toma/CNPJ', 'destinatario/CNPJ']),
            valorTotal: $this->firstText($xpath, ['vNF', 'vTPrest', 'vLiq']),
            emissaoAt: $this->toDate($this->firstText($xpath, ['dhEmi', 'dhRecbto'])),
            eventoOcorridoEmAt: $this->toDate($this->firstText($xpath, ['dhEvento'])),
        );
    }

    /**
     * Dígito verificador módulo 11 sobre os 43 primeiros dígitos, pesos
     * cíclicos de 2 a 9 da direita para a esquerda.
     */
    public static function isValidChave(string $chave): bool
    {
        if (preg_match('/^\d{44}$/', $chave) !== 1) {
            return false;
        }

        $weights = [2, 3, 4, 5, 6, 7, 8, 9];
        $sum = 0;

        for ($i = 42, $w = 0; $i >= 0; $i--, $w++) {
            $sum += ((int) $chave[$i]) * $weights[$w % 8];
        }

        $mod = $sum % 11;
        $expected = $mod < 2 ? 0 : 11 - $mod;

        return $expected === (int) $chave[43];
    }

    private function schemaOf(DOMDocument $dom): string
    {
        $root = $dom->documentElement;

        return $root === null ? '' : $root->nodeName;
    }

    /**
     * @param  list<string>  $paths
     */
    private function firstText(DOMXPath $xpath, array $paths): ?string
    {
        foreach ($paths as $path) {
            $node = $xpath->query('//*[local-name()="'.$path.'"]')->item(0);

            if ($node !== null && trim($node->textContent) !== '') {
                return trim($node->textContent);
            }
        }

        return null;
    }

    private function chaveFromId(DOMXPath $xpath): ?string
    {
        $node = $xpath->query('//*[@Id]')->item(0);

        if (! $node instanceof \DOMElement) {
            return null;
        }

        $id = $node->getAttribute('Id');

        return preg_match('/(\d{44})/', $id, $matches) === 1 ? $matches[1] : null;
    }

    private function toDate(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
```

Criar as fixtures `resNFe.xml` (com `<chNFe>35220499999999999999550010020000001240556603</chNFe>`, `<CNPJ>`, `<dhEmi>`, `<vNF>710.00</vNF>`) e `procNFe.xml` (com `<infNFe Id="NFe35220499999999999999550010020000001240556603">` e `<dest><CNPJ>`).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=FiscalXmlMetadataTest`
Expected: PASS — 6 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Support backend/tests/Unit/FiscalXmlMetadataTest.php backend/tests/Fixtures/fiscal
git commit -m "feat(fiscal): extract document metadata and validate access key"
```

---

## Task 11: Validador de XSD e bundle de AC

**Files:**
- Create: `backend/app/Services/Fiscal/Support/FiscalXmlValidator.php`
- Create: `backend/resources/xsd/nfe/distDFeInt_v1.01.xsd`, `backend/resources/xsd/nfe/tiposDistDFe_v1.01.xsd`
- Create: `backend/storage/app/icp-brasil/ca-bundle.pem`
- Test: `backend/tests/Unit/FiscalXmlValidatorTest.php`

**Interfaces:**
- Produces: `FiscalXmlValidator::validate(string $xml, string $schemaName): void` — lança `RuntimeException` com o primeiro erro do libxml.

Validar antes de enviar elimina a classe inteira de `215`/`402`/`404` por cerca de trinta linhas.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Services\Fiscal\Support\FiscalXmlValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FiscalXmlValidatorTest extends TestCase
{
    public function test_accepts_a_well_formed_request(): void
    {
        $xml = '<distDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            .'<tpAmb>1</tpAmb><cUFAutor>35</cUFAutor><CNPJ>00000000000191</CNPJ>'
            .'<distNSU><ultNSU>000000000000000</ultNSU></distNSU></distDFeInt>';

        (new FiscalXmlValidator)->validate($xml, 'distDFeInt');

        $this->assertTrue(true);
    }

    public function test_rejects_a_namespace_prefixed_request(): void
    {
        $xml = '<nfe:distDFeInt xmlns:nfe="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            .'<nfe:tpAmb>1</nfe:tpAmb><nfe:cUFAutor>35</nfe:cUFAutor>'
            .'<nfe:CNPJ>00000000000191</nfe:CNPJ>'
            .'<nfe:distNSU><nfe:ultNSU>000000000000000</nfe:ultNSU></nfe:distNSU></nfe:distDFeInt>';

        $this->expectException(RuntimeException::class);

        (new FiscalXmlValidator)->validate($xml, 'distDFeInt');
    }

    public function test_rejects_an_unsupported_version(): void
    {
        $xml = '<distDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">'
            .'<tpAmb>1</tpAmb><cUFAutor>35</cUFAutor><CNPJ>00000000000191</CNPJ>'
            .'<distNSU><ultNSU>000000000000000</ultNSU></distNSU></distDFeInt>';

        $this->expectException(RuntimeException::class);

        (new FiscalXmlValidator)->validate($xml, 'distDFeInt');
    }

    public function test_rejects_a_cursor_that_is_not_fifteen_digits(): void
    {
        $xml = '<distDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            .'<tpAmb>1</tpAmb><cUFAutor>35</cUFAutor><CNPJ>00000000000191</CNPJ>'
            .'<distNSU><ultNSU>42</ultNSU></distNSU></distDFeInt>';

        $this->expectException(RuntimeException::class);

        (new FiscalXmlValidator)->validate($xml, 'distDFeInt');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=FiscalXmlValidatorTest`
Expected: FAIL — classe não encontrada.

- [ ] **Step 3: Implement**

Baixar o `distDFeInt_v1.01.xsd` e o `tiposDistDFe_v1.01.xsd` do pacote oficial de schemas da NF-e (`PL_009_V4`) e colocá-los em `backend/resources/xsd/nfe/`. O `distDFeInt_v1.01.xsd` importa o `tiposDistDFe_v1.01.xsd` por `schemaLocation` relativo, então os dois precisam estar no mesmo diretório.

```php
<?php

namespace App\Services\Fiscal\Support;

use DOMDocument;
use RuntimeException;

final class FiscalXmlValidator
{
    /**
     * Valida a requisição contra o XSD local antes de enviar. A validação
     * remove a classe inteira de rejeições de forma (schema, codificação,
     * prefixo de namespace) sem ida à rede.
     */
    public function validate(string $xml, string $schemaName): void
    {
        $schema = resource_path("xsd/nfe/{$schemaName}_v1.01.xsd");

        if (! is_file($schema)) {
            throw new RuntimeException("XSD não encontrado: {$schemaName}.");
        }

        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);

        if (! $dom->loadXML($xml)) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            throw new RuntimeException('XML malformado: '.trim($errors[0]->message ?? 'erro desconhecido'));
        }

        $valid = $dom->schemaValidate($schema);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $valid) {
            throw new RuntimeException('Requisição rejeitada pelo schema: '.trim($errors[0]->message ?? 'erro desconhecido'));
        }
    }
}
```

Vendorizar o bundle de AC da ICP-Brasil em `backend/storage/app/icp-brasil/ca-bundle.pem` e garantir que o caminho está em `.gitignore` se for grande — mas o bundle precisa ser versionado para o deploy funcionar. Adicionar a exceção no `.gitignore` da raiz se necessário.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=FiscalXmlValidatorTest`
Expected: PASS — 4 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Support/FiscalXmlValidator.php backend/resources/xsd backend/storage/app/icp-brasil backend/tests/Unit/FiscalXmlValidatorTest.php
git commit -m "feat(fiscal): validate outbound request against local XSD"
```

---

## Task 12: Contrato do conector e value objects

**Files:**
- Create: `backend/app/Services/Fiscal/Contracts/FiscalConnector.php`, `backend/app/Services/Fiscal/Contracts/PulledDocument.php`, `backend/app/Services/Fiscal/Contracts/PullResult.php`
- Test: `backend/tests/Unit/FiscalContractsTest.php`

**Interfaces:**
- Produces:
  - `PulledDocument::__construct(FiscalModel $model, FiscalKind $kind, string $chave, string $eventId, int $nsu, string $schema, ?CarbonImmutable $emissaoAt, ?CarbonImmutable $eventoOcorridoEmAt, string $xml)`
  - `PullResult::__construct(array $documents, int $lastNsu, ?int $maxNsu, bool $more, ?CarbonImmutable $blockedUntil)`
  - `FiscalConnector::source(): FiscalSource`, `::pull(Client $client, int $fromNsu, int $limit): PullResult`, `::fetchByChave(Client $client, string $chave): ?PulledDocument`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use PHPUnit\Framework\TestCase;

class FiscalContractsTest extends TestCase
{
    public function test_pulled_document_holds_its_identity(): void
    {
        $document = new PulledDocument(
            model: FiscalModel::Nfe,
            kind: FiscalKind::Document,
            chave: str_repeat('1', 44),
            eventId: '',
            nsu: 10,
            schema: 'resNFe_v1.01.xsd',
            emissaoAt: null,
            eventoOcorridoEmAt: null,
            xml: '<a/>',
        );

        $this->assertSame(FiscalModel::Nfe, $document->model);
        $this->assertSame(10, $document->nsu);
        $this->assertSame('', $document->eventId);
    }

    public function test_pull_result_reports_its_cursor_and_block(): void
    {
        $result = new PullResult(
            documents: [],
            lastNsu: 200,
            maxNsu: 200,
            more: false,
            blockedUntil: null,
        );

        $this->assertSame([], $result->documents);
        $this->assertSame(200, $result->lastNsu);
        $this->assertFalse($result->more);
        $this->assertNull($result->blockedUntil);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=FiscalContractsTest`
Expected: FAIL — classes não encontradas.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Fiscal\Contracts;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use Carbon\CarbonImmutable;

final readonly class PulledDocument
{
    public function __construct(
        public FiscalModel $model,
        public FiscalKind $kind,
        public string $chave,
        public string $eventId,
        public int $nsu,
        public string $schema,
        public ?CarbonImmutable $emissaoAt,
        public ?CarbonImmutable $eventoOcorridoEmAt,
        public string $xml,
    ) {}
}
```

```php
<?php

namespace App\Services\Fiscal\Contracts;

use Carbon\CarbonImmutable;

final readonly class PullResult
{
    /**
     * @param  list<PulledDocument>  $documents
     */
    public function __construct(
        public array $documents,
        public int $lastNsu,
        public ?int $maxNsu,
        public bool $more,
        public ?CarbonImmutable $blockedUntil,
    ) {}
}
```

```php
<?php

namespace App\Services\Fiscal\Contracts;

use App\Enums\FiscalSource;
use App\Models\Client;

interface FiscalConnector
{
    public function source(): FiscalSource;

    public function pull(Client $client, int $fromNsu, int $limit): PullResult;

    public function fetchByChave(Client $client, string $chave): ?PulledDocument;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=FiscalContractsTest`
Expected: PASS — 2 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Contracts backend/tests/Unit/FiscalContractsTest.php
git commit -m "feat(fiscal): add connector contract and value objects"
```

---

## Task 13: Driver de NF-e

**Files:**
- Create: `backend/app/Services/Fiscal/Nfe/NfeDistributionConnector.php`
- Test: `backend/tests/Feature/Fiscal/NfeDistributionConnectorTest.php`

**Interfaces:**
- Consumes: `ClientCertificateMaterializer` (Task 3), `DfeSoapEnvelope` e `DfeResponseParser` (Task 9), `DocZipDecoder` (Task 8), `FiscalXmlMetadata` (Task 10), `FiscalXmlValidator` (Task 11), `FiscalFailure` (Task 5), os contratos (Task 12).
- Produces: `NfeDistributionConnector` implementando `FiscalConnector`, mais `NfeDistributionConnector::fetchByChave(Client $client, string $chave): ?PulledDocument`.

O teste usa `Http::fake()` — nenhuma chamada de rede.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Services\Fiscal\Nfe\NfeDistributionConnector;
use App\Services\Fiscal\Support\DocZipDecoder;
use App\Services\Fiscal\Support\DfeResponseParser;
use App\Services\Fiscal\Support\DfeSoapEnvelope;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use App\Services\Fiscal\Support\FiscalXmlValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NfeDistributionConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('certificates');
        Storage::fake('local');
    }

    public function test_source_is_nfe_distribution(): void
    {
        $this->assertSame(FiscalSource::NfeDistribuicao, $this->connector()->source());
    }

    public function test_pull_returns_documents_and_advances_the_cursor(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake([
            '*' => Http::response($this->responseWithOneDocument(), 200),
        ]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertCount(1, $result->documents);
        $this->assertSame(FiscalModel::Nfe, $result->documents[0]->model);
        $this->assertSame(200, $result->lastNsu);
        $this->assertNull($result->blockedUntil);
        $this->assertFalse($result->more);
    }

    public function test_pull_blocks_for_an_hour_when_no_document_is_located(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake([
            '*' => Http::response($this->responseWith('137', 'Nenhum documento localizado', 0), 200),
        ]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame([], $result->documents);
        $this->assertNotNull($result->blockedUntil);
        $this->assertTrue($result->blockedUntil->isAfter(now()->addMinutes(50)));
    }

    public function test_improper_consumption_adopts_the_cursor_from_the_rejection_body(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake([
            '*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/fiscal/retDistDFeInt_656_com_nsu.xml')),
                200,
            ),
        ]);

        $result = $this->connector()->pull($client, 0, 50);

        $this->assertSame(1678, $result->lastNsu);
        $this->assertNotNull($result->blockedUntil);
    }

    public function test_sends_no_signature_in_the_request(): void
    {
        $client = $this->clientWithCertificate();

        Http::fake(['*' => Http::response($this->responseWith('137', 'x', 0), 200)]);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(function ($request): bool {
            return ! str_contains($request->body(), 'Signature')
                && str_contains($request->body(), '<distDFeInt');
        });
    }

    public function test_uses_the_clients_own_cnpj(): void
    {
        $client = $this->clientWithCertificate('12345678000199');

        Http::fake(['*' => Http::response($this->responseWith('137', 'x', 0), 200)]);

        $this->connector()->pull($client, 0, 50);

        Http::assertSent(fn ($request): bool => str_contains($request->body(), '<CNPJ>12345678000199</CNPJ>'));
    }

    private function connector(): NfeDistributionConnector
    {
        return new NfeDistributionConnector(
            resolve(DfeSoapEnvelope::class),
            resolve(DfeResponseParser::class),
            resolve(DocZipDecoder::class),
            resolve(FiscalXmlMetadata::class),
            resolve(FiscalXmlValidator::class),
            resolve(\App\Services\Fiscal\Support\ClientCertificateMaterializer::class),
        );
    }

    private function clientWithCertificate(string $taxId = '00000000000191'): Client
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create([
            'account_id' => $account->getKey(),
            'tax_id' => $taxId,
            'state' => 'SP',
        ]);

        ClientCertificate::factory()->withPassword()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
        ]);

        return $client->refresh();
    }

    private function responseWith(string $cStat, string $xMotivo, int $ultNsu): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<nfeDistDFeInteresseResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe">'
            .'<nfeDistDFeInteresseResult>'
            .'<retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            ."<cStat>{$cStat}</cStat><xMotivo>{$xMotivo}</xMotivo>"
            .'<ultNSU>'.str_pad((string) $ultNsu, 15, '0', STR_PAD_LEFT).'</ultNSU>'
            .'<maxNSU>000000000000200</maxNSU>'
            .'</retDistDFeInt></nfeDistDFeInteresseResult>'
            .'</nfeDistDFeInteresseResponse></soap:Body></soap:Envelope>';
    }

    private function responseWithOneDocument(): string
    {
        $document = '<resNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            .'<chNFe>35220499999999999999550010020000001240556603</chNFe>'
            .'<CNPJ>99999999999999</CNPJ>'
            .'<dhEmi>2022-04-04T11:54:49-03:00</dhEmi>'
            .'<vNF>710.00</vNF>'
            .'</resNFe>';

        $payload = base64_encode(gzencode($document));

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<nfeDistDFeInteresseResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe">'
            .'<nfeDistDFeInteresseResult>'
            .'<retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            .'<cStat>138</cStat><xMotivo>Documento(s) localizado(s)</xMotivo>'
            .'<ultNSU>000000000000200</ultNSU><maxNSU>000000000000200</maxNSU>'
            .'<loteDistDFeInt>'
            .'<docZip NSU="000000000000200" schema="resNFe_v1.01.xsd">'.$payload.'</docZip>'
            .'</loteDistDFeInt></retDistDFeInt></nfeDistDFeInteresseResult>'
            .'</nfeDistDFeInteresseResponse></soap:Body></soap:Envelope>';
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=NfeDistributionConnectorTest`
Expected: FAIL — classe não encontrada.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Fiscal\Nfe;

use App\Enums\FiscalFailure;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Models\Client;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use App\Services\Fiscal\Support\ClientCertificateMaterializer;
use App\Services\Fiscal\Support\DocZipDecoder;
use App\Services\Fiscal\Support\DfeResponse;
use App\Services\Fiscal\Support\DfeResponseParser;
use App\Services\Fiscal\Support\DfeSoapEnvelope;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use App\Services\Fiscal\Support\FiscalXmlValidator;
use App\Services\SerproException;
use App\Enums\SerproFailure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class NfeDistributionConnector implements FiscalConnector
{
    public function __construct(
        private DfeSoapEnvelope $envelope,
        private DfeResponseParser $parser,
        private DocZipDecoder $decoder,
        private FiscalXmlMetadata $metadata,
        private FiscalXmlValidator $validator,
        private ClientCertificateMaterializer $materializer,
    ) {}

    public function source(): FiscalSource
    {
        return FiscalSource::NfeDistribuicao;
    }

    public function pull(Client $client, int $fromNsu, int $limit): PullResult
    {
        $certificate = $client->currentCertificate;

        if ($certificate === null) {
            throw new RuntimeException('Cliente sem certificado A1 vigente.');
        }

        $endpoint = $this->endpoint();

        $response = $this->materializer->withCertificate(
            $certificate,
            fn (string $path): string => $this->send($endpoint, $path, $client, $fromNsu),
        );

        $parsed = $this->parser->parse($response);
        $failure = FiscalFailure::classify(200, $parsed->cStat);

        return match ($failure) {
            FiscalFailure::DocumentsFound => $this->collect($parsed),
            FiscalFailure::NoDocuments => new PullResult([], $parsed->ultNsu, $parsed->maxNsu, false, $this->blockUntil()),
            FiscalFailure::Blocked => new PullResult([], $parsed->ultNsu, $parsed->maxNsu, false, $this->blockUntil()),
            default => throw new SerproException(
                $parsed->xMotivo === '' ? 'O serviço de distribuição rejeitou a consulta.' : $parsed->xMotivo,
                $failure->retryable() ? SerproFailure::Upstream : SerproFailure::DoNotRetry,
                0,
                $parsed->cStat,
            ),
        };
    }

    public function fetchByChave(Client $client, string $chave): ?PulledDocument
    {
        $certificate = $client->currentCertificate;

        if ($certificate === null) {
            throw new RuntimeException('Cliente sem certificado A1 vigente.');
        }

        $endpoint = $this->endpoint();

        $response = $this->materializer->withCertificate(
            $certificate,
            fn (string $path): string => $this->sendByChave($endpoint, $path, $client, $chave),
        );

        $parsed = $this->parser->parse($response);

        if (FiscalFailure::classify(200, $parsed->cStat) !== FiscalFailure::DocumentsFound) {
            return null;
        }

        $result = $this->collect($parsed);

        return $result->documents[0] ?? null;
    }

    private function collect(DfeResponse $parsed): PullResult
    {
        $documents = [];

        foreach ($parsed->entries as $entry) {
            $xml = $this->decoder->decode($entry->payload);
            $extracted = $this->metadata->extract($xml, FiscalModel::Nfe);

            $documents[] = new PulledDocument(
                model: $extracted->model,
                kind: $extracted->kind,
                chave: $extracted->chave,
                eventId: $extracted->eventId,
                nsu: $entry->nsu,
                schema: $entry->schema,
                emissaoAt: $extracted->emissaoAt,
                eventoOcorridoEmAt: $extracted->eventoOcorridoEmAt,
                xml: $xml,
            );
        }

        return new PullResult(
            documents: $documents,
            lastNsu: $parsed->ultNsu,
            maxNsu: $parsed->maxNsu,
            more: $parsed->maxNsu !== null && $parsed->ultNsu < $parsed->maxNsu,
            blockedUntil: null,
        );
    }

    /**
     * @param  array<string, string>  $endpoint
     */
    private function send(array $endpoint, string $certificatePath, Client $client, int $fromNsu): string
    {
        $body = $this->envelope->build(
            serviceNamespace: $endpoint['namespace'],
            payloadNamespace: $endpoint['payload_namespace'],
            version: $endpoint['version'],
            soapAction: $endpoint['soap_action'],
            cnpj: (string) $client->tax_id,
            cUf: $this->ufCode((string) $client->state),
            fromNsu: $fromNsu,
            method: $endpoint['method'],
        );

        $this->validator->validate(
            $this->extractPayload($body),
            'distDFeInt',
        );

        return $this->request($endpoint, $certificatePath, $body);
    }

    /**
     * @param  array<string, string>  $endpoint
     */
    private function sendByChave(array $endpoint, string $certificatePath, Client $client, string $chave): string
    {
        if (! \App\Services\Fiscal\Support\FiscalXmlMetadata::isValidChave($chave)) {
            throw new RuntimeException("Chave de acesso inválida: {$chave}.");
        }

        $body = $this->envelope->build(
            serviceNamespace: $endpoint['namespace'],
            payloadNamespace: $endpoint['payload_namespace'],
            version: $endpoint['version'],
            soapAction: $endpoint['soap_action'],
            cnpj: (string) $client->tax_id,
            cUf: $this->ufCode((string) $client->state),
            fromNsu: 0,
            method: $endpoint['method'],
        );

        $body = str_replace(
            '<distNSU><ultNSU>'.str_pad('0', 15, '0', STR_PAD_LEFT).'</ultNSU></distNSU>',
            '<consChNFe><chNFe>'.$chave.'</chNFe></consChNFe>',
            $body,
        );

        return $this->request($endpoint, $certificatePath, $body);
    }

    /**
     * @param  array<string, string>  $endpoint
     */
    private function request(array $endpoint, string $certificatePath, string $body): string
    {
        $url = $endpoint[config('fiscal.environment') === 'producao' ? 'producao' : 'homologacao'];

        $response = Http::withHeaders([
            'Content-Type' => 'application/soap+xml; charset=utf-8; action="'.$endpoint['soap_action'].'"',
        ])
            ->withOptions([
                'verify' => config('fiscal.ca_bundle'),
                'curl' => [
                    CURLOPT_SSLCERT => $certificatePath,
                    CURLOPT_SSLCERTTYPE => 'P12',
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                ],
            ])
            ->timeout((int) config('fiscal.timeout', 60))
            ->connectTimeout(15)
            ->withBody($body, 'application/soap+xml; charset=utf-8')
            ->post($url);

        if ($response->failed() && $response->status() !== 200) {
            throw new SerproException(
                'O serviço de distribuição está indisponível.',
                SerproFailure::Upstream,
                503,
            );
        }

        return $response->body();
    }

    /**
     * @return array<string, string>
     */
    private function endpoint(): array
    {
        /** @var array<string, array<string, string>> $endpoints */
        $endpoints = (array) config('fiscal.endpoints', []);

        if (! isset($endpoints[$this->source()->value])) {
            throw new RuntimeException('Endpoint de distribuição não configurado.');
        }

        return $endpoints[$this->source()->value];
    }

    private function extractPayload(string $body): string
    {
        $start = strpos($body, '<distDFeInt');
        $end = strpos($body, '</distDFeInt>');

        if ($start === false || $end === false) {
            throw new RuntimeException('Envelope sem payload distDFeInt.');
        }

        return substr($body, $start, $end - $start + strlen('</distDFeInt>'));
    }

    private function blockUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes((int) config('fiscal.block_minutes', 60));
    }

    private function ufCode(string $acronym): string
    {
        return (string) (\NFePHP\Common\UFList::getCodeByUF($acronym) ?? 35);
    }
}
```

> **Atenção:** `NFePHP\Common\UFList` **não existe** neste projeto — não há dependência `nfephp-org/*`. Substituir o método `ufCode()` por um mapa local:

```php
private const UF_CODES = [
    'AC' => 12, 'AL' => 27, 'AP' => 16, 'AM' => 13, 'BA' => 29, 'CE' => 23,
    'DF' => 53, 'ES' => 32, 'GO' => 52, 'MA' => 21, 'MT' => 51, 'MS' => 50,
    'MG' => 31, 'PA' => 15, 'PB' => 25, 'PR' => 41, 'PE' => 26, 'PI' => 22,
    'RJ' => 33, 'RN' => 24, 'RS' => 43, 'RO' => 11, 'RR' => 14, 'SC' => 42,
    'SP' => 35, 'SE' => 28, 'TO' => 17,
];

private function ufCode(string $acronym): string
{
    return (string) (self::UF_CODES[strtoupper($acronym)] ?? 35);
}
```

Registrar o binding em `AppServiceProvider::register()`:

```php
$this->app->bind(FiscalConnector::class, NfeDistributionConnector::class);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=NfeDistributionConnectorTest`
Expected: PASS — 6 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal backend/app/Providers/AppServiceProvider.php backend/tests/Feature/Fiscal
git commit -m "feat(fiscal): add NF-e distribution connector"
```

---

## Task 14: Writer idempotente

**Files:**
- Create: `backend/app/Services/Fiscal/Capture/FiscalDocumentWriter.php`
- Test: `backend/tests/Feature/Fiscal/FiscalDocumentWriterTest.php`

**Interfaces:**
- Consumes: `PulledDocument` (Task 12), disco `fiscal` (Task 6).
- Produces: `FiscalDocumentWriter::store(Client $client, FiscalSource $source, PulledDocument $document): FiscalDocument`.

Reprocessar o mesmo lote não pode criar linha extra.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Capture\FiscalDocumentWriter;
use App\Services\Fiscal\Contracts\PulledDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FiscalDocumentWriterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fiscal');
    }

    public function test_stores_the_document_with_its_xml_on_the_private_disk(): void
    {
        [$account, $client] = $this->tenant();

        $document = $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled('1234'));

        $this->assertSame($account->getKey(), $document->account_id);
        $this->assertSame($client->getKey(), $document->client_id);
        $this->assertSame(FiscalModel::Nfe, $document->model);
        $this->assertSame(FiscalKind::Document, $document->kind);
        $this->assertTrue(Storage::disk('fiscal')->exists($document->storage_path));
        $this->assertSame(hash('sha256', '<resNFe/>'), $document->sha256);
        $this->assertSame(9, $document->xml_bytes);
    }

    public function test_reprocessing_the_same_document_overwrites_instead_of_duplicating(): void
    {
        [, $client] = $this->tenant();

        $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled('1234'));
        $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled('1234'));

        $this->assertSame(1, FiscalDocument::count());
    }

    public function test_event_is_stored_alongside_its_document(): void
    {
        [, $client] = $this->tenant();

        $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled('1234'));
        $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled('1234', '110111-1', FiscalKind::Event));

        $this->assertSame(2, FiscalDocument::count());
    }

    public function test_does_not_expose_the_internal_storage_path(): void
    {
        [, $client] = $this->tenant();

        $document = $this->writer()->store($client, FiscalSource::NfeDistribuicao, $this->pulled('1234'));

        $this->assertArrayNotHasKey('storage_path', $document->toArray() === [] ? [] : (new \App\Http\Resources\FiscalDocumentResource($document))->resolve());
    }

    private function writer(): FiscalDocumentWriter
    {
        return resolve(FiscalDocumentWriter::class);
    }

    /** @return array{0: Account, 1: Client} */
    private function tenant(): array
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        return [$account, $client];
    }

    private function pulled(string $nsu, string $eventId = '', FiscalKind $kind = FiscalKind::Document): PulledDocument
    {
        return new PulledDocument(
            model: FiscalModel::Nfe,
            kind: $kind,
            chave: str_pad($nsu, 44, '0', STR_PAD_LEFT),
            eventId: $eventId,
            nsu: (int) $nsu,
            schema: 'resNFe_v1.01.xsd',
            emissaoAt: now()->toImmutable(),
            eventoOcorridoEmAt: null,
            xml: '<resNFe/>',
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=FiscalDocumentWriterTest`
Expected: FAIL — classe não encontrada.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Fiscal\Capture;

use App\Enums\FiscalSource;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Contracts\PulledDocument;
use Illuminate\Support\Facades\Storage;

final class FiscalDocumentWriter
{
    /**
     * O único caminho de escrita. A chave composta
     * (client_id, chave_acesso, event_id) é a identidade: reprocessar
     * sobrescreve, nunca duplica.
     */
    public function store(Client $client, FiscalSource $source, PulledDocument $document): FiscalDocument
    {
        $path = $this->path($client, $document);

        Storage::disk('fiscal')->put($path, $document->xml);

        return FiscalDocument::updateOrCreate(
            [
                'client_id' => $client->getKey(),
                'chave_acesso' => $document->chave,
                'event_id' => $document->eventId,
            ],
            [
                'account_id' => $client->account_id,
                'source' => $source,
                'model' => $document->model,
                'kind' => $document->kind,
                'nsu' => $document->nsu,
                'schema' => $document->schema,
                'emissao_at' => $document->emissaoAt,
                'evento_ocorrido_em_at' => $document->eventoOcorridoEmAt,
                'storage_path' => $path,
                'sha256' => hash('sha256', $document->xml),
                'xml_bytes' => strlen($document->xml),
                'captured_at' => now(),
            ],
        );
    }

    private function path(Client $client, PulledDocument $document): string
    {
        $suffix = $document->eventId === '' ? '' : '-'.$document->eventId;

        return sprintf(
            '%d/%d/%s/%s%s.xml',
            $client->account_id,
            $client->getKey(),
            $document->emissaoAt?->format('Y-m') ?? 'sem-data',
            $document->chave,
            $suffix,
        );
    }
}
```

Criar `app/Http/Resources/FiscalDocumentResource.php` já nesta task, porque o teste a referencia:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FiscalDocument */
class FiscalDocumentResource extends JsonResource
{
    /**
     * O caminho interno do arquivo nunca é exposto.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'client_id' => $this->client_id,
            'source' => $this->source?->value,
            'model' => $this->model?->value,
            'kind' => $this->kind?->value,
            'chave_acesso' => $this->chave_acesso,
            'event_id' => $this->event_id,
            'nsu' => $this->nsu,
            'emitente_cnpj' => $this->emitente_cnpj,
            'destinatario_cnpj' => $this->destinatario_cnpj,
            'valor_total' => $this->valor_total,
            'emissao_at' => $this->emissao_at?->toISOString(),
            'evento_ocorrido_em_at' => $this->evento_ocorrido_em_at?->toISOString(),
            'xml_bytes' => $this->xml_bytes,
            'mascarado' => $this->mascarado,
            'captured_at' => $this->captured_at?->toISOString(),
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=FiscalDocumentWriterTest`
Expected: PASS — 4 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Capture backend/app/Http/Resources/FiscalDocumentResource.php backend/tests/Feature/Fiscal/FiscalDocumentWriterTest.php
git commit -m "feat(fiscal): add idempotent document writer"
```

---

## Task 15: Serviço de captura

**Files:**
- Create: `backend/app/Services/Fiscal/Capture/FiscalCaptureOutcome.php`, `backend/app/Services/Fiscal/Capture/FiscalCaptureService.php`
- Test: `backend/tests/Feature/Fiscal/FiscalCaptureServiceTest.php`

**Interfaces:**
- Consumes: `FiscalConnector` (Task 12), `FiscalDocumentWriter` (Task 14), `FiscalFailure` (Task 5), `FiscalSkipReason` (Task 4), `ClientCertificate` (Task 1).
- Produces:
  - `FiscalCaptureOutcome::__construct(bool $ran, ?FiscalSkipReason $skipReason, int $stored, int $fromNsu, int $toNsu)`
  - `FiscalCaptureService::capture(Client $client, FiscalSource $source): FiscalCaptureOutcome`

O conector é injetado como `FiscalConnector` e trocado por um falso no teste — nenhuma rede.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSkipReason;
use App\Enums\FiscalSource;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Capture\FiscalCaptureService;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class FiscalCaptureServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fiscal');
    }

    public function test_captures_documents_and_advances_the_cursor(): void
    {
        [$client] = $this->tenant(withCertificate: true);
        $this->bindConnector(fn (): PullResult => new PullResult(
            [$this->pulled(100), $this->pulled(200)],
            lastNsu: 200,
            maxNsu: 200,
            more: false,
            blockedUntil: null,
        ));

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $this->assertTrue($outcome->ran);
        $this->assertSame(2, $outcome->stored);
        $this->assertSame(200, $outcome->toNsu);
        $this->assertSame(2, FiscalDocument::count());
        $this->assertSame(200, $this->cursor($client)->last_nsu);
    }

    public function test_repeated_capture_is_idempotent(): void
    {
        [$client] = $this->tenant(withCertificate: true);
        $this->bindConnector(fn (): PullResult => new PullResult([$this->pulled(100)], 100, 100, false, null));

        $this->service()->capture($client, FiscalSource::NfeDistribuicao);
        $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $this->assertSame(1, FiscalDocument::count());
    }

    public function test_skips_a_client_without_certificate_and_does_not_call_out(): void
    {
        [$client] = $this->tenant(withCertificate: false);
        $called = false;
        $this->bindConnector(function () use (&$called): PullResult {
            $called = true;

            return new PullResult([], 0, null, false, null);
        });

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $this->assertFalse($called);
        $this->assertFalse($outcome->ran);
        $this->assertSame(FiscalSkipReason::NoCertificate, $outcome->skipReason);
    }

    public function test_skips_a_client_whose_certificate_has_no_stored_password(): void
    {
        [$client] = $this->tenant(withCertificate: false);
        ClientCertificate::factory()->withoutPassword()->create([
            'account_id' => $client->account_id,
            'client_id' => $client->getKey(),
        ]);

        $outcome = $this->service()->capture($client->refresh(), FiscalSource::NfeDistribuicao);

        $this->assertSame(FiscalSkipReason::NoCertificate, $outcome->skipReason);
    }

    public function test_skips_a_client_inside_a_block_window(): void
    {
        [$client] = $this->tenant(withCertificate: true);
        $called = false;
        $this->bindConnector(function () use (&$called): PullResult {
            $called = true;

            return new PullResult([], 0, null, false, null);
        });

        $this->cursor($client)->forceFill(['blocked_until' => now()->addMinutes(30)])->save();

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $this->assertFalse($called);
        $this->assertSame(FiscalSkipReason::Blocked, $outcome->skipReason);
    }

    public function test_skips_a_client_whose_history_is_interrupted(): void
    {
        [$client] = $this->tenant(withCertificate: true);
        $called = false;
        $this->bindConnector(function () use (&$called): PullResult {
            $called = true;

            return new PullResult([], 0, null, false, null);
        });

        $this->cursor($client)->forceFill([
            'last_seen_at' => now()->subDays((int) config('fiscal.continuity_days') + 1),
        ])->save();

        $outcome = $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $this->assertFalse($called);
        $this->assertSame(FiscalSkipReason::Interrupted, $outcome->skipReason);
    }

    public function test_does_not_advance_the_cursor_when_storing_fails(): void
    {
        [$client] = $this->tenant(withCertificate: true);
        $this->cursor($client)->forceFill(['last_nsu' => 50])->save();

        $this->bindConnector(fn (): PullResult => new PullResult([$this->pulled(100)], 100, 100, false, null));

        $this->partialMock(\App\Services\Fiscal\Capture\FiscalDocumentWriter::class, function ($mock): void {
            $mock->shouldReceive('store')->once()->andThrow(new RuntimeException('disco cheio'));
        });

        try {
            $this->service()->capture($client, FiscalSource::NfeDistribuicao);
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertSame(50, $this->cursor($client)->refresh()->last_nsu);
        $this->assertSame(0, FiscalDocument::count());
    }

    public function test_a_block_from_the_connector_is_persisted(): void
    {
        [$client] = $this->tenant(withCertificate: true);
        $this->bindConnector(fn (): PullResult => new PullResult(
            [],
            lastNsu: 1678,
            maxNsu: null,
            more: false,
            blockedUntil: CarbonImmutable::now()->addHour(),
        ));

        $this->service()->capture($client, FiscalSource::NfeDistribuicao);

        $cursor = $this->cursor($client)->refresh();
        $this->assertSame(1678, $cursor->last_nsu);
        $this->assertNotNull($cursor->blocked_until);
    }

    private function service(): FiscalCaptureService
    {
        return resolve(FiscalCaptureService::class);
    }

    private function bindConnector(callable $pull): void
    {
        $this->app->bind(FiscalConnector::class, fn (): FiscalConnector => new class($pull) implements FiscalConnector
        {
            public function __construct(private $pull) {}

            public function source(): FiscalSource
            {
                return FiscalSource::NfeDistribuicao;
            }

            public function pull(Client $client, int $fromNsu, int $limit): PullResult
            {
                return ($this->pull)($client, $fromNsu, $limit);
            }

            public function fetchByChave(Client $client, string $chave): ?PulledDocument
            {
                return null;
            }
        });
    }

    /** @return array{0: Client} */
    private function tenant(bool $withCertificate): array
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        if ($withCertificate) {
            ClientCertificate::factory()->withPassword()->create([
                'account_id' => $account->getKey(),
                'client_id' => $client->getKey(),
            ]);
        }

        return [$client->refresh()];
    }

    private function cursor(Client $client): FiscalCursor
    {
        return FiscalCursor::firstOrCreate(
            ['client_id' => $client->getKey(), 'source' => FiscalSource::NfeDistribuicao],
            ['account_id' => $client->account_id],
        );
    }

    private function pulled(int $nsu): PulledDocument
    {
        return new PulledDocument(
            model: FiscalModel::Nfe,
            kind: FiscalKind::Document,
            chave: str_pad((string) $nsu, 44, '0', STR_PAD_LEFT),
            eventId: '',
            nsu: $nsu,
            schema: 'resNFe_v1.01.xsd',
            emissaoAt: now()->toImmutable(),
            eventoOcorridoEmAt: null,
            xml: '<resNFe/>',
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=FiscalCaptureServiceTest`
Expected: FAIL — classe não encontrada.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Fiscal\Capture;

use App\Enums\FiscalSkipReason;

final readonly class FiscalCaptureOutcome
{
    public function __construct(
        public bool $ran,
        public ?FiscalSkipReason $skipReason,
        public int $stored,
        public int $fromNsu,
        public int $toNsu,
    ) {
        public static function skipped(FiscalSkipReason $reason, int $fromNsu): self
        {
            return new self(false, $reason, 0, $fromNsu, $fromNsu);
        }
    }
}
```

```php
<?php

namespace App\Services\Fiscal\Capture;

use App\Enums\FiscalSkipReason;
use App\Enums\FiscalSource;
use App\Models\Client;
use App\Models\FiscalCursor;
use App\Services\Fiscal\Contracts\FiscalConnector;
use Illuminate\Support\Facades\Cache;

final class FiscalCaptureService
{
    public function __construct(
        private FiscalConnector $connector,
        private FiscalDocumentWriter $writer,
    ) {}

    public function capture(Client $client, FiscalSource $source): FiscalCaptureOutcome
    {
        $lock = Cache::lock($this->lockKey($client, $source), (int) config('fiscal.timeout', 60) + 30);

        if (! $lock->get()) {
            return FiscalCaptureOutcome::skipped(FiscalSkipReason::Blocked, 0);
        }

        try {
            return $this->run($client, $source);
        } finally {
            $lock->release();
        }
    }

    private function run(Client $client, FiscalSource $source): FiscalCaptureOutcome
    {
        $cursor = $this->cursor($client, $source);
        $from = (int) $cursor->last_nsu;

        if ($this->certificateIsUnusable($client)) {
            return FiscalCaptureOutcome::skipped(FiscalSkipReason::NoCertificate, $from);
        }

        if ($cursor->blocked_until !== null && $cursor->blocked_until->isFuture()) {
            return FiscalCaptureOutcome::skipped(FiscalSkipReason::Blocked, $from);
        }

        if ($this->historyIsInterrupted($cursor)) {
            return FiscalCaptureOutcome::skipped(FiscalSkipReason::Interrupted, $from);
        }

        $cursor->forceFill(['last_run_at' => now(), 'last_seen_at' => now()])->save();

        try {
            $result = $this->connector->pull($client, $from, (int) config('fiscal.batch_limit', 50));
        } catch (\Throwable $exception) {
            $cursor->forceFill(['last_error' => $exception->getMessage()])->save();

            throw $exception;
        }

        // Persistir o lote inteiro ANTES de avançar. Uma interrupção entre os
        // dois só repete trabalho; a ordem inversa perde documento.
        foreach ($result->documents as $document) {
            $this->writer->store($client, $source, $document);
        }

        $cursor->forceFill([
            'last_nsu' => $result->lastNsu,
            'last_success_at' => now(),
            'last_error' => null,
            'blocked_until' => $result->blockedUntil,
        ])->save();

        return new FiscalCaptureOutcome(
            ran: true,
            skipReason: null,
            stored: count($result->documents),
            fromNsu: $from,
            toNsu: $result->lastNsu,
        );
    }

    private function cursor(Client $client, FiscalSource $source): FiscalCursor
    {
        return FiscalCursor::firstOrCreate(
            ['client_id' => $client->getKey(), 'source' => $source],
            ['account_id' => $client->account_id],
        );
    }

    private function certificateIsUnusable(Client $client): bool
    {
        $certificate = $client->currentCertificate;

        if ($certificate === null) {
            return true;
        }

        return $certificate->certificatePassword() === null
            || $certificate->valid_until->isPast();
    }

    private function historyIsInterrupted(FiscalCursor $cursor): bool
    {
        if ($cursor->last_seen_at === null) {
            return false;
        }

        return $cursor->last_seen_at->lt(now()->subDays((int) config('fiscal.continuity_days', 60)));
    }

    private function lockKey(Client $client, FiscalSource $source): string
    {
        return "fiscal:capture:{$client->getKey()}:{$source->value}";
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=FiscalCaptureServiceTest`
Expected: PASS — 8 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Capture backend/tests/Feature/Fiscal/FiscalCaptureServiceTest.php
git commit -m "feat(fiscal): orchestrate incremental capture"
```

---

## Task 16: Job e comando

**Files:**
- Create: `backend/app/Jobs/CaptureFiscalDocumentsJob.php`, `backend/app/Console/Commands/CaptureFiscalDocuments.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/Fiscal/CaptureFiscalDocumentsCommandTest.php`

**Interfaces:**
- Consumes: `FiscalCaptureService` (Task 15).
- Produces: `CaptureFiscalDocumentsJob::__construct(int $clientId, FiscalSource $source)`, comando `fiscal:capture`.

Um job por cliente e fonte. O `$timeout` do job fica abaixo do `--timeout=120` do worker.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalSource;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Jobs\CaptureFiscalDocumentsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CaptureFiscalDocumentsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fiscal');
        Bus::fake();
    }

    public function test_dispatches_one_job_per_capturable_client(): void
    {
        $account = Account::factory()->create();

        foreach (range(1, 3) as $ignored) {
            $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
            ClientCertificate::factory()->withPassword()->create([
                'account_id' => $account->getKey(),
                'client_id' => $client->getKey(),
            ]);
        }

        $this->artisan('fiscal:capture')->assertSuccessful();

        Bus::assertDispatchedTimes(CaptureFiscalDocumentsJob::class, 3);
    }

    public function test_does_not_dispatch_for_a_client_without_a_usable_certificate(): void
    {
        $account = Account::factory()->create();
        Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        $this->artisan('fiscal:capture')->assertSuccessful();

        Bus::assertNotDispatched(CaptureFiscalDocumentsJob::class);
    }

    public function test_job_timeout_stays_below_the_worker_timeout(): void
    {
        $job = new CaptureFiscalDocumentsJob(1, FiscalSource::NfeDistribuicao);

        $this->assertLessThan(120, $job->timeout);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CaptureFiscalDocumentsCommandTest`
Expected: FAIL — comando e job não existem.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Jobs;

use App\Enums\FiscalSource;
use App\Models\Client;
use App\Services\Fiscal\Capture\FiscalCaptureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CaptureFiscalDocumentsJob implements ShouldQueue
{
    use Queueable;

    /**
     * O worker roda com --timeout=120 e o redis tem retry_after=90. Um lote
     * com mTLS não cabe com folga, então o job fica abaixo dos dois e a
     * idempotência por chave torna uma execução duplicada inofensiva.
     */
    public int $timeout = 90;

    public int $tries = 1;

    public function __construct(
        public int $clientId,
        public FiscalSource $source,
    ) {}

    public function handle(FiscalCaptureService $capture): void
    {
        $client = Client::withoutGlobalScopes()->find($this->clientId);

        if ($client === null) {
            return;
        }

        $capture->capture($client, $this->source);
    }
}
```

```php
<?php

namespace App\Console\Commands;

use App\Enums\FiscalSource;
use App\Jobs\CaptureFiscalDocumentsJob;
use App\Models\Client;
use Illuminate\Console\Command;

final class CaptureFiscalDocuments extends Command
{
    protected $signature = 'fiscal:capture {--source=nfe_distribuicao} {--client=}';

    protected $description = 'Dispara a captura de documentos fiscais por cliente';

    public function handle(): int
    {
        $source = FiscalSource::tryFrom((string) $this->option('source'));

        if ($source === null) {
            $this->error('Fonte inválida.');

            return self::FAILURE;
        }

        $query = Client::query()->with('currentCertificate');

        if ($this->option('client') !== null) {
            $query->whereKey((int) $this->option('client'));
        }

        $dispatched = 0;

        $query->chunkById(100, function ($clients) use ($source, &$dispatched): void {
            foreach ($clients as $client) {
                $certificate = $client->currentCertificate;

                if ($certificate === null || $certificate->certificatePassword() === null) {
                    continue;
                }

                if ($certificate->valid_until->isPast()) {
                    continue;
                }

                CaptureFiscalDocumentsJob::dispatch($client->getKey(), $source);
                $dispatched++;
            }
        });

        $this->info("Capturas despachadas: {$dispatched}");

        return self::SUCCESS;
    }
}
```

Em `routes/console.php`:

```php
Schedule::command('fiscal:capture')->hourly()->withoutOverlapping();
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=CaptureFiscalDocumentsCommandTest`
Expected: PASS — 3 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Jobs/CaptureFiscalDocumentsJob.php backend/app/Console/Commands/CaptureFiscalDocuments.php backend/routes/console.php backend/tests/Feature/Fiscal/CaptureFiscalDocumentsCommandTest.php
git commit -m "feat(fiscal): add capture job and command"
```

---

## Task 17: API de listagem, resumo e cobertura

**Files:**
- Create: `backend/app/Http/Requests/Tenant/IndexFiscalDocumentRequest.php`, `backend/app/Http/Controllers/Tenant/FiscalDocumentController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Tenancy/FiscalDocumentListingTest.php`

**Interfaces:**
- Consumes: `FiscalDocument` (Task 6), `FiscalDocumentResource` (Task 14).
- Produces: `GET /api/fiscal/summary`, `GET /api/fiscal/documents`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Tenancy;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalDocumentListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_documents_of_the_current_account(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        FiscalDocument::factory()->count(3)->create($this->attributes($account, $client));
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->getJson('/api/fiscal/documents')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_does_not_leak_documents_across_accounts(): void
    {
        $mine = Account::factory()->create();
        $theirs = Account::factory()->create();
        $theirClient = Client::factory()->individual()->create(['account_id' => $theirs->getKey()]);
        FiscalDocument::factory()->create($this->attributes($theirs, $theirClient));

        $this->actingAs($this->memberOf($mine), 'sanctum');

        $this->getJson('/api/fiscal/documents')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filters_by_model(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        FiscalDocument::factory()->create($this->attributes($account, $client));
        FiscalDocument::factory()->create(array_merge($this->attributes($account, $client, '2'), [
            'model' => FiscalModel::Cte,
        ]));
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->getJson('/api/fiscal/documents?model[]=cte')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.model', 'cte');
    }

    public function test_rejects_an_unknown_model_filter(): void
    {
        $account = Account::factory()->create();
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->getJson('/api/fiscal/documents?model[]=inexistente')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('model.0');
    }

    public function test_summary_reports_coverage_by_reason(): void
    {
        $account = Account::factory()->create();

        Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        $withCertificate = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        ClientCertificate::factory()->withPassword()->create([
            'account_id' => $account->getKey(),
            'client_id' => $withCertificate->getKey(),
        ]);

        $withoutPassword = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        ClientCertificate::factory()->withoutPassword()->create([
            'account_id' => $account->getKey(),
            'client_id' => $withoutPassword->getKey(),
        ]);

        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->getJson('/api/fiscal/summary')
            ->assertOk()
            ->assertJsonPath('data.coverage.capturable', 1)
            ->assertJsonPath('data.coverage.no_certificate', 1)
            ->assertJsonPath('data.coverage.no_password', 1);
    }

    public function test_summary_lists_the_clients_behind_each_attention_reason(): void
    {
        $account = Account::factory()->create();

        $withoutPassword = Client::factory()->individual()->create([
            'account_id' => $account->getKey(),
            'name' => 'Alvo Sem Senha',
        ]);
        ClientCertificate::factory()->withoutPassword()->create([
            'account_id' => $account->getKey(),
            'client_id' => $withoutPassword->getKey(),
        ]);

        $this->actingAs($this->memberOf($account), 'sanctum');

        $response = $this->getJson('/api/fiscal/summary')->assertOk();

        $reason = collect($response->json('data.attention'))
            ->firstWhere('code', 'no_password');

        $this->assertNotNull($reason);
        $this->assertSame(1, $reason['count']);
        $this->assertSame('Alvo Sem Senha', $reason['clients'][0]['name']);
    }

    public function test_summary_reports_blocked_and_interrupted_captures(): void
    {
        $account = Account::factory()->create();

        $blocked = Client::factory()->individual()->create([
            'account_id' => $account->getKey(),
            'name' => 'Cliente Bloqueado',
        ]);
        ClientCertificate::factory()->withPassword()->create([
            'account_id' => $account->getKey(),
            'client_id' => $blocked->getKey(),
        ]);
        FiscalCursor::create([
            'account_id' => $account->getKey(),
            'client_id' => $blocked->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'blocked_until' => now()->addMinutes(30),
        ]);

        $interrupted = Client::factory()->individual()->create([
            'account_id' => $account->getKey(),
            'name' => 'Cliente Interrompido',
        ]);
        ClientCertificate::factory()->withPassword()->create([
            'account_id' => $account->getKey(),
            'client_id' => $interrupted->getKey(),
        ]);
        FiscalCursor::create([
            'account_id' => $account->getKey(),
            'client_id' => $interrupted->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'last_seen_at' => now()->subDays((int) config('fiscal.continuity_days') + 1),
        ]);

        $this->actingAs($this->memberOf($account), 'sanctum');

        $response = $this->getJson('/api/fiscal/summary')->assertOk();
        $attention = collect($response->json('data.attention'));

        $this->assertSame('Cliente Bloqueado', $attention->firstWhere('code', 'blocked')['clients'][0]['name']);
        $this->assertSame('Cliente Interrompido', $attention->firstWhere('code', 'interrupted')['clients'][0]['name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Account $account, Client $client, string $nsu = '1'): array
    {
        return [
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'model' => FiscalModel::Nfe,
            'kind' => FiscalKind::Document,
            'chave_acesso' => str_pad($nsu, 44, '0', STR_PAD_LEFT),
            'event_id' => '',
            'nsu' => (int) $nsu,
        ];
    }

    private function memberOf(Account $account, string $role = 'operador'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=FiscalDocumentListingTest`
Expected: FAIL — rotas inexistentes (404).

- [ ] **Step 3: Implement**

Adicionar primeiro a relação em `app/Models/Client.php`, ao lado de `currentCertificate()`:

```php
public function fiscalCursor(): HasOne
{
    return $this->hasOne(FiscalCursor::class)->where('source', FiscalSource::NfeDistribuicao);
}
```

```php
<?php

namespace App\Http\Requests\Tenant;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Models\FiscalDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class IndexFiscalDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', FiscalDocument::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'model' => ['sometimes', 'array', 'max:4'],
            'model.*' => ['string', Rule::in(array_column(FiscalModel::cases(), 'value'))],
            'client_id' => ['sometimes', 'integer'],
            'emitente_cnpj' => ['sometimes', 'string', 'max:14'],
            'destinatario_cnpj' => ['sometimes', 'string', 'max:14'],
            'kind' => ['sometimes', 'string', Rule::in(array_column(FiscalKind::cases(), 'value'))],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'sort' => ['sometimes', Rule::in(['emissao_at', 'captured_at', 'valor_total'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', Rule::in([25, 50, 100])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
```

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\FiscalModel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\IndexFiscalDocumentRequest;
use App\Http\Resources\FiscalDocumentResource;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class FiscalDocumentController extends Controller
{
    public function index(IndexFiscalDocumentRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $documents = $this->filtered($filters)
            ->with('client:id,name,tax_id')
            ->orderBy($filters['sort'] ?? 'emissao_at', $filters['direction'] ?? 'desc')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return FiscalDocumentResource::collection($documents);
    }

    public function summary(): JsonResponse
    {
        $byModel = FiscalDocument::query()
            ->selectRaw('model, count(*) as total')
            ->groupBy('model')
            ->pluck('total', 'model');

        return response()->json(['data' => [
            'total' => (int) $byModel->sum(),
            'by_model' => collect(FiscalModel::cases())
                ->mapWithKeys(fn (FiscalModel $model): array => [$model->value => (int) ($byModel[$model->value] ?? 0)])
                ->all(),
            'coverage' => $this->coverage(),
            'attention' => $this->attention(),
            'last_capture_at' => FiscalDocument::query()->max('captured_at'),
        ]]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<FiscalDocument>
     */
    private function filtered(array $filters): Builder
    {
        return FiscalDocument::query()
            ->when(isset($filters['model']), fn (Builder $query): Builder => $query->whereIn('model', $filters['model']))
            ->when(isset($filters['client_id']), fn (Builder $query): Builder => $query->where('client_id', (int) $filters['client_id']))
            ->when(isset($filters['emitente_cnpj']), fn (Builder $query): Builder => $query->where('emitente_cnpj', $filters['emitente_cnpj']))
            ->when(isset($filters['destinatario_cnpj']), fn (Builder $query): Builder => $query->where('destinatario_cnpj', $filters['destinatario_cnpj']))
            ->when(isset($filters['kind']), fn (Builder $query): Builder => $query->where('kind', $filters['kind']))
            ->when(isset($filters['date_from']), fn (Builder $query): Builder => $query->where('emissao_at', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn (Builder $query): Builder => $query->where('emissao_at', '<=', $filters['date_to']));
    }

    /**
     * A cobertura separa quem pode ser capturado de quem não pode, com o
     * motivo. Sem isso, uma tabela de documentos vazia é ambígua entre
     * "nada chegou" e "não dá para capturar".
     *
     * @return array<string, int>
     */
    private function coverage(): array
    {
        $capturable = 0;
        $noCertificate = 0;
        $noPassword = 0;
        $expired = 0;

        foreach (Client::query()->with('currentCertificate')->get() as $client) {
            $certificate = $client->currentCertificate;

            if ($certificate === null) {
                $noCertificate++;

                continue;
            }

            if ($certificate->certificatePassword() === null) {
                $noPassword++;

                continue;
            }

            if ($certificate->valid_until->isPast()) {
                $expired++;

                continue;
            }

            $capturable++;
        }

        return [
            'total' => Client::query()->count(),
            'capturable' => $capturable,
            'no_certificate' => $noCertificate,
            'no_password' => $noPassword,
            'expired' => $expired,
        ];
    }

    /**
     * Os clientes por trás de cada motivo, não só a contagem: o painel precisa
     * nomear quem exige ação. Bloqueio e interrupção vêm do cursor, porque são
     * estado de captura e não de cobertura.
     *
     * @return list<array{code: string, count: int, clients: list<array{id: int, name: string}>}>
     */
    private function attention(): array
    {
        $clients = Client::query()->with(['currentCertificate', 'fiscalCursor'])->get();

        $buckets = [
            'no_certificate' => [],
            'no_password' => [],
            'expired' => [],
            'blocked' => [],
            'interrupted' => [],
        ];

        foreach ($clients as $client) {
            $certificate = $client->currentCertificate;

            if ($certificate === null) {
                $buckets['no_certificate'][] = $client;

                continue;
            }

            if ($certificate->certificatePassword() === null) {
                $buckets['no_password'][] = $client;

                continue;
            }

            if ($certificate->valid_until->isPast()) {
                $buckets['expired'][] = $client;

                continue;
            }

            $cursor = $client->fiscalCursor;

            if ($cursor === null) {
                continue;
            }

            if ($cursor->blocked_until !== null && $cursor->blocked_until->isFuture()) {
                $buckets['blocked'][] = $client;

                continue;
            }

            if ($cursor->last_seen_at !== null
                && $cursor->last_seen_at->lt(now()->subDays((int) config('fiscal.continuity_days', 60)))) {
                $buckets['interrupted'][] = $client;
            }
        }

        $result = [];

        foreach ($buckets as $code => $bucket) {
            if ($bucket === []) {
                continue;
            }

            $result[] = [
                'code' => $code,
                'count' => count($bucket),
                'clients' => array_map(
                    fn (Client $client): array => ['id' => $client->getKey(), 'name' => $client->name],
                    $bucket,
                ),
            ];
        }

        return $result;
    }
}
```

Em `routes/api.php`, dentro do grupo `['auth:sanctum', 'tenant']`, adicionar os imports e as rotas:

```php
Route::get('fiscal/summary', [FiscalDocumentController::class, 'summary']);
Route::get('fiscal/documents', [FiscalDocumentController::class, 'index']);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=FiscalDocumentListingTest`
Expected: PASS — 7 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Requests/Tenant/IndexFiscalDocumentRequest.php backend/app/Http/Controllers/Tenant/FiscalDocumentController.php backend/routes/api.php backend/tests/Feature/Tenancy/FiscalDocumentListingTest.php
git commit -m "feat(fiscal): expose document listing and coverage summary"
```

---

## Task 18: Detalhe, download e disparo sob demanda

**Files:**
- Create: `backend/app/Policies/FiscalDocumentPolicy.php`
- Modify: `backend/app/Http/Controllers/Tenant/FiscalDocumentController.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/routes/api.php`
- Test: `backend/tests/Feature/Tenancy/FiscalDocumentDetailTest.php`

**Interfaces:**
- Consumes: tudo da Task 17, mais `FiscalCaptureService` (Task 15).
- Produces: `GET /api/fiscal/documents/{document}`, `GET /api/fiscal/documents/{document}/xml`, `POST /api/fiscal/clients/{client}/sync`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Tenancy;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FiscalDocumentDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fiscal');
    }

    public function test_shows_the_document_without_the_internal_path(): void
    {
        [$account, $client] = $this->tenant();
        $document = $this->document($account, $client);
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->getJson("/api/fiscal/documents/{$document->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.chave_acesso', $document->chave_acesso)
            ->assertJsonMissingPath('data.storage_path');
    }

    public function test_downloads_the_stored_xml(): void
    {
        [$account, $client] = $this->tenant();
        $document = $this->document($account, $client);
        $this->actingAs($this->memberOf($account), 'sanctum');

        $this->get("/api/fiscal/documents/{$document->getKey()}/xml")
            ->assertOk()
            ->assertHeader('content-type', 'application/xml');
    }

    public function test_another_account_cannot_read_or_download(): void
    {
        [$account, $client] = $this->tenant();
        $document = $this->document($account, $client);
        $other = Account::factory()->create();
        $this->actingAs($this->memberOf($other), 'sanctum');

        $this->getJson("/api/fiscal/documents/{$document->getKey()}")->assertNotFound();
        $this->get("/api/fiscal/documents/{$document->getKey()}/xml")->assertNotFound();
    }

    public function test_operador_triggers_a_capture_and_the_job_is_queued(): void
    {
        [$account, $client] = $this->tenant();
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $this->postJson("/api/fiscal/clients/{$client->getKey()}/sync")
            ->assertAccepted()
            ->assertJsonPath('data.queued', true);
    }

    public function test_refuses_a_capture_for_a_blocked_client(): void
    {
        [$account, $client] = $this->tenant();
        FiscalCursor::create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'blocked_until' => now()->addMinutes(30),
        ]);
        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum');

        $this->postJson("/api/fiscal/clients/{$client->getKey()}/sync")
            ->assertStatus(409)
            ->assertJsonPath('data.blocked', true);
    }

    public function test_a_read_only_member_cannot_trigger_a_capture(): void
    {
        [$account, $client] = $this->tenant();
        $this->actingAs($this->memberOf($account, 'user'), 'sanctum');

        $this->postJson("/api/fiscal/clients/{$client->getKey()}/sync")->assertForbidden();
    }

    /** @return array{0: Account, 1: Client} */
    private function tenant(): array
    {
        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);

        return [$account, $client];
    }

    private function document(Account $account, Client $client): FiscalDocument
    {
        $path = '1/1/2022-04/'.str_repeat('1', 44).'.xml';
        Storage::disk('fiscal')->put($path, '<resNFe/>');

        return FiscalDocument::create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'model' => FiscalModel::Nfe,
            'kind' => FiscalKind::Document,
            'chave_acesso' => str_repeat('1', 44),
            'event_id' => '',
            'nsu' => 1,
            'storage_path' => $path,
            'sha256' => hash('sha256', '<resNFe/>'),
            'xml_bytes' => 9,
            'captured_at' => now(),
        ]);
    }

    private function memberOf(Account $account, string $role = 'operador'): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=FiscalDocumentDetailTest`
Expected: FAIL — rotas inexistentes.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Policies;

use App\Models\FiscalDocument;
use App\Models\User;
use App\Policies\Concerns\HasTenantRole;

class FiscalDocumentPolicy
{
    use HasTenantRole;

    public function viewAny(User $user): bool
    {
        return $this->tenantRole($user) !== null;
    }

    public function view(User $user, FiscalDocument $document): bool
    {
        return $this->tenantRole($user) !== null && $this->isTenantModel($document->account_id);
    }

    public function capture(User $user): bool
    {
        return in_array($this->tenantRole($user), ['admin', 'operador'], true);
    }
}
```

Adicionar em `AppServiceProvider`:

```php
Gate::policy(FiscalDocument::class, FiscalDocumentPolicy::class);
```

Adicionar ao controller:

```php
public function show(FiscalDocument $document): FiscalDocumentResource
{
    Gate::authorize('view', $document);

    return new FiscalDocumentResource($document->loadMissing('client:id,name,tax_id'));
}

public function xml(FiscalDocument $document): Response
{
    Gate::authorize('view', $document);

    $contents = Storage::disk('fiscal')->get($document->storage_path);

    abort_if($contents === null, 404);

    return response($contents, 200, [
        'Content-Type' => 'application/xml',
        'Content-Disposition' => 'attachment; filename="'.$document->chave_acesso.'.xml"',
    ]);
}

public function sync(Client $client): JsonResponse
{
    Gate::authorize('capture', FiscalDocument::class);

    $cursor = FiscalCursor::firstOrCreate(
        ['client_id' => $client->getKey(), 'source' => FiscalSource::NfeDistribuicao],
        ['account_id' => $client->account_id],
    );

    if ($cursor->blocked_until !== null && $cursor->blocked_until->isFuture()) {
        return response()->json(['data' => [
            'queued' => false,
            'blocked' => true,
            'blocked_until' => $cursor->blocked_until->toISOString(),
        ]], 409);
    }

    CaptureFiscalDocumentsJob::dispatch($client->getKey(), FiscalSource::NfeDistribuicao);

    return response()->json(['data' => ['queued' => true]], 202);
}
```

Adicionar ao `routes/api.php`:

```php
Route::get('fiscal/documents/{document}', [FiscalDocumentController::class, 'show']);
Route::get('fiscal/documents/{document}/xml', [FiscalDocumentController::class, 'xml']);
Route::post('fiscal/clients/{client}/sync', [FiscalDocumentController::class, 'sync']);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=FiscalDocumentDetailTest`
Expected: PASS — 6 testes.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Policies/FiscalDocumentPolicy.php backend/app/Http/Controllers/Tenant/FiscalDocumentController.php backend/app/Providers/AppServiceProvider.php backend/routes/api.php backend/tests/Feature/Tenancy/FiscalDocumentDetailTest.php
git commit -m "feat(fiscal): add detail, xml download and on-demand capture"
```

---

## Task 19: Navegação, tipos e composable

**Files:**
- Create: `frontend/app/utils/fiscalNav.ts`, `frontend/app/types/fiscal.ts`, `frontend/app/composables/useFiscal.ts`
- Modify: `frontend/app/layouts/default.vue`
- Test: verificação por `pnpm typecheck` e `pnpm lint`

**Interfaces:**
- Produces: `fiscalNav` (abas), `fiscalSidebarChildren(path)`, tipos `FiscalModel`/`FiscalSource`/`FiscalDocumentRow`/`FiscalSummary`/`FiscalCoverage`/`FiscalListParams`, e `useFiscal()` com `summary()`, `list()`, `show()`, `downloadXml()`, `sync()`.

Segue `app/utils/workNav.ts`, `app/types/work.ts` e `app/composables/useWork.ts`.

- [ ] **Step 1: Create `fiscalNav.ts`**

```ts
import type { NavigationMenuItem } from '@nuxt/ui'

export interface FiscalNavItem {
  label: string
  icon: string
  to: string
}

export const fiscalNav: readonly FiscalNavItem[] = [
  { label: 'Painel', icon: 'i-lucide-layout-dashboard', to: '/fiscal' },
  { label: 'Documentos', icon: 'i-lucide-file-text', to: '/fiscal/documentos' }
]

export function fiscalSidebarChildren(path: string): NavigationMenuItem[] {
  return fiscalNav.map(item => ({
    label: item.label,
    to: item.to,
    active: path === item.to || path.startsWith(`${item.to}/`)
  }))
}
```

- [ ] **Step 2: Create `types/fiscal.ts`**

```ts
/**
 * Contrato de rede do módulo fiscal. Os nomes de campo são o `snake_case` do
 * backend, verbatim.
 */

export type FiscalModel = 'nfe' | 'nfce' | 'cte' | 'nfse'
export type FiscalSource = 'nfe_distribuicao' | 'cte_distribuicao'
export type FiscalKind = 'document' | 'event'

/** Por que uma captura não rodou para um cliente. */
export type FiscalSkipReason = 'blocked' | 'no_certificate' | 'interrupted'

export interface FiscalDocumentRow {
  id: number
  client_id: number
  source: FiscalSource
  model: FiscalModel
  kind: FiscalKind
  chave_acesso: string
  event_id: string
  nsu: number
  emitente_cnpj: string | null
  destinatario_cnpj: string | null
  valor_total: string | null
  emissao_at: string | null
  evento_ocorrido_em_at: string | null
  xml_bytes: number
  mascarado: boolean
  captured_at: string | null
  client?: { id: number, name: string, tax_id: string | null }
}

/**
 * A cobertura é uma leitura própria, separada dos totais de documento: no
 * primeiro dia a maioria da carteira não tem certificado utilizável, e
 * "nada capturado" e "não dá para capturar" precisam ser distinguíveis.
 */
export interface FiscalCoverage {
  total: number
  capturable: number
  no_certificate: number
  no_password: number
  expired: number
}

/**
 * Um motivo de atenção com os clientes por trás dele, não só a contagem: o
 * painel precisa nomear quem exige ação. `code` é `string` de propósito — um
 * motivo novo no backend precisa chegar ao painel sem redeploy do frontend.
 */
export interface FiscalAttentionReason {
  code: string
  count: number
  clients: { id: number, name: string }[]
}

export interface FiscalSummary {
  total: number
  by_model: Record<FiscalModel, number>
  coverage: FiscalCoverage
  attention: FiscalAttentionReason[]
  last_capture_at: string | null
}

export interface FiscalListParams {
  model?: FiscalModel[]
  client_id?: number
  emitente_cnpj?: string
  destinatario_cnpj?: string
  kind?: FiscalKind
  date_from?: string
  date_to?: string
  sort?: 'emissao_at' | 'captured_at' | 'valor_total'
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}
```

- [ ] **Step 3: Create `composables/useFiscal.ts`**

```ts
import type { FiscalDocumentRow, FiscalListParams, FiscalSummary } from '~/types/fiscal'
import { queryOf } from '~/composables/useApiQuery'

export function useFiscal() {
  const { $api } = useNuxtApp()

  async function summary() {
    const response = await $api<{ data: FiscalSummary }>('/fiscal/summary')

    return response.data
  }

  async function list(params: FiscalListParams) {
    return $api<{
      data: FiscalDocumentRow[]
      meta?: { total: number, current_page?: number, last_page?: number }
    }>('/fiscal/documents', { query: queryOf(params) })
  }

  async function show(id: number) {
    const response = await $api<{ data: FiscalDocumentRow }>(`/fiscal/documents/${id}`)

    return response.data
  }

  async function downloadXml(id: number) {
    return $api<string>(`/fiscal/documents/${id}/xml`, { responseType: 'blob' })
  }

  async function sync(clientId: number) {
    return $api<{ data: { queued: boolean, blocked?: boolean, blocked_until?: string } }>(
      `/fiscal/clients/${clientId}/sync`,
      { method: 'POST' }
    )
  }

  return { summary, list, show, downloadXml, sync }
}
```

- [ ] **Step 4: Register the navigation entry**

Em `app/layouts/default.vue`, no array `links` (linha ~15-110), inserir após a entrada de Monitoramento:

```ts
}, {
  label: 'Fiscal',
  icon: 'i-lucide-receipt',
  to: '/fiscal',
  type: 'trigger'
}, {
```

E no computed `navLinks` (linha ~112-180), adicionar o ramo que injeta os filhos, ao lado dos de `Work` e `Monitoramento`:

```ts
if (item.label === 'Fiscal') {
  return {
    ...item,
    defaultOpen: route.path.startsWith('/fiscal'),
    children: fiscalSidebarChildren(route.path).map(child => ({
      ...child,
      onSelect: close
    }))
  }
}
```

Importar `fiscalSidebarChildren` de `~/utils/fiscalNav`. Adicionar também ao computed `groups` (linha ~182) para a busca:

```ts
...fiscalNav.map(item => ({ label: item.label, icon: item.icon, to: item.to, onSelect: close }))
```

- [ ] **Step 5: Verify and commit**

Run: `pnpm typecheck && pnpm lint`
Expected: sem erros.

```bash
git add frontend/app/utils/fiscalNav.ts frontend/app/types/fiscal.ts frontend/app/composables/useFiscal.ts frontend/app/layouts/default.vue
git commit -m "feat(fiscal): add navigation, types and api composable"
```

---

## Task 20: Invólucro e painel

**Files:**
- Create: `frontend/app/utils/fiscalPresentation.ts`, `frontend/app/pages/fiscal.vue`, `frontend/app/pages/fiscal/index.vue`
- Test: `frontend/tests/fiscalPresentation.test.ts`

**Interfaces:**
- Consumes: `fiscalNav` (Task 19), `useFiscal()` (Task 19), `types/fiscal.ts` (Task 19).
- Produces: `coverageReading(coverage: FiscalCoverage): { capturableLabel: string, empty: boolean, allBlocked: boolean }` e `attentionReasons(coverage: FiscalCoverage): AttentionReason[]`.

O painel distingue "nenhum documento" de "nenhum cliente capturável". Sem isso, um escritório com a carteira inteira sem certificado vê uma tela vazia e não sabe por quê.

- [ ] **Step 1: Write the failing test**

```ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { attentionReasons, coverageReading } from '../app/utils/fiscalPresentation.ts'

describe('coverageReading', () => {
  it('reports a fully capturable portfolio', () => {
    const reading = coverageReading({ total: 10, capturable: 10, no_certificate: 0, no_password: 0, expired: 0 })

    assert.equal(reading.empty, false)
    assert.equal(reading.allBlocked, false)
    assert.equal(reading.capturableLabel, '10 de 10')
  })

  it('flags a portfolio where nothing can be captured', () => {
    const reading = coverageReading({ total: 5, capturable: 0, no_certificate: 5, no_password: 0, expired: 0 })

    assert.equal(reading.allBlocked, true)
    assert.equal(reading.capturableLabel, '0 de 5')
  })

  it('treats an empty portfolio as empty rather than blocked', () => {
    const reading = coverageReading({ total: 0, capturable: 0, no_certificate: 0, no_password: 0, expired: 0 })

    assert.equal(reading.empty, true)
    assert.equal(reading.allBlocked, false)
  })
})

describe('attentionReasons', () => {
  it('returns nothing when no client needs action', () => {
    assert.deepEqual(attentionReasons([]), [])
  })

  it('labels each reason the backend reports and keeps its clients', () => {
    const reasons = attentionReasons([
      { code: 'no_certificate', count: 2, clients: [{ id: 1, name: 'A' }, { id: 2, name: 'B' }] },
      { code: 'blocked', count: 1, clients: [{ id: 3, name: 'C' }] }
    ])

    assert.deepEqual(reasons.map(r => r.code), ['no_certificate', 'blocked'])
    assert.equal(reasons[0].label, 'Sem certificado')
    assert.equal(reasons[0].clients[0].name, 'A')
    assert.equal(reasons[1].label, 'Captura bloqueada')
  })

  it('falls back to the raw code for a reason this client does not know', () => {
    const reasons = attentionReasons([{ code: 'motivo_novo', count: 1, clients: [] }])

    assert.equal(reasons[0].label, 'motivo_novo')
  })
})
```

O fallback do último caso é deliberado: um motivo novo adicionado no backend precisa chegar ao painel sem um redeploy do frontend.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && node --test tests/fiscalPresentation.test.ts`
Expected: FAIL — `attentionReasons` tem outra assinatura.

- [ ] **Step 3: Implement `fiscalPresentation.ts`**

```ts
import type { FiscalAttentionReason, FiscalCoverage, FiscalDocumentRow, FiscalModel } from '~/types/fiscal'

export interface AttentionReason {
  code: string
  label: string
  count: number
  description: string
  clients: { id: number, name: string }[]
}

export interface CoverageReading {
  capturableLabel: string
  empty: boolean
  allBlocked: boolean
}

/**
 * A cobertura é a leitura primária do painel: no primeiro dia a maior parte da
 * carteira não tem certificado utilizável, e uma tabela de documentos vazia
 * não distingue "nada chegou" de "não dá para capturar".
 */
export function coverageReading(coverage: FiscalCoverage): CoverageReading {
  const empty = coverage.total === 0
  const allBlocked = !empty && coverage.capturable === 0

  return {
    capturableLabel: `${coverage.capturable} de ${coverage.total}`,
    empty,
    allBlocked
  }
}

const REASON_COPY: Record<string, { label: string, description: string }> = {
  no_certificate: {
    label: 'Sem certificado',
    description: 'O cliente não tem A1 cadastrado, então não há como consultar em nome dele.'
  },
  no_password: {
    label: 'Certificado sem senha',
    description: 'O certificado foi enviado antes de a senha passar a ser guardada. Reenvie o PFX para capturar.'
  },
  expired: {
    label: 'Certificado vencido',
    description: 'O certificado expirou. Renove e envie o novo para retomar a captura.'
  },
  blocked: {
    label: 'Captura bloqueada',
    description: 'A captura foi bloqueada por consumo indevido. O desbloqueio é automático ao fim da janela.'
  },
  interrupted: {
    label: 'Histórico interrompido',
    description: 'A captura ficou parada além da janela do fisco. O período perdido não volta ao retomar.'
  }
}

export function attentionReasons(reasons: FiscalAttentionReason[]): AttentionReason[] {
  return reasons.map((reason) => {
    const copy = REASON_COPY[reason.code]

    return {
      code: reason.code,
      label: copy?.label ?? reason.code,
      description: copy?.description ?? '',
      count: reason.count,
      clients: reason.clients
    }
  })
}

const MODEL_LABELS: Record<FiscalModel, string> = {
  nfe: 'NF-e',
  nfce: 'NFC-e',
  cte: 'CT-e',
  nfse: 'NFS-e'
}

export function modelLabel(model: FiscalModel): string {
  return MODEL_LABELS[model]
}

export function modelColor(model: FiscalModel): 'primary' | 'info' | 'warning' | 'neutral' {
  switch (model) {
    case 'nfe': return 'primary'
    case 'nfce': return 'info'
    case 'cte': return 'warning'
    default: return 'neutral'
  }
}

export function modelOptions(rows: Pick<FiscalDocumentRow, 'model'>[]): { label: string, value: FiscalModel }[] {
  const present = [...new Set(rows.map(row => row.model))]

  return present.map(model => ({ label: modelLabel(model), value: model }))
}

export function formatAccessKey(chave: string): string {
  return chave.replace(/(\d{4})(?=\d)/g, '$1 ').trim()
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`

  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && node --test tests/fiscalPresentation.test.ts`
Expected: PASS — 6 testes.

- [ ] **Step 5: Create `fiscal.vue` and `index.vue`**

`fiscal.vue` segue `work.vue` (linhas 1-70) quase literalmente:

```vue
<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'
import { fiscalNav } from '~/utils/fiscalNav'

definePageMeta({ middleware: 'auth' })

const route = useRoute()

const title = computed(() => {
  const current = fiscalNav.find(item => route.path === item.to || route.path.startsWith(`${item.to}/`))
  return current?.label ?? 'Fiscal'
})

const pageTabs = computed<NavigationMenuItem[][]>(() => [[
  ...fiscalNav.map(item => ({
    label: item.label,
    icon: item.icon,
    to: item.to,
    active: route.path === item.to || route.path.startsWith(`${item.to}/`)
  }))
]])
</script>

<template>
  <UDashboardPanel id="fiscal" :ui="{ body: 'min-h-0 flex-1 gap-0 overflow-hidden p-0 sm:p-0' }">
    <template #header>
      <UDashboardNavbar :title="title">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar>
        <template #left>
          <UNavigationMenu :items="pageTabs" highlight class="-mx-1 min-w-0 flex-1" />
        </template>
      </UDashboardToolbar>
    </template>

    <template #body>
      <NuxtPage />
    </template>
  </UDashboardPanel>
</template>
```

`index.vue`:

```vue
<script setup lang="ts">
import { attentionReasons, coverageReading, modelLabel } from '~/utils/fiscalPresentation'
import type { FiscalModel } from '~/types/fiscal'

definePageMeta({ middleware: 'auth' })

const { summary } = useFiscal()

const { data, status, error } = await useAsyncData('fiscal-summary', () => summary(), {
  getCachedData: () => undefined
})

const coverage = computed(() => data.value?.coverage ?? null)
const reading = computed(() => coverage.value ? coverageReading(coverage.value) : null)
const reasons = computed(() => attentionReasons(data.value?.attention ?? []))
const attentionTotal = computed(() => reasons.value.reduce((sum, reason) => sum + reason.count, 0))

const modelRows = computed(() => Object.entries(data.value?.by_model ?? {})
  .map(([model, total]) => ({ model: model as FiscalModel, total }))
  .filter(row => row.total > 0))
</script>

<template>
  <div class="flex-1 overflow-y-auto p-4 sm:p-6">
    <div v-if="status === 'pending'" class="flex justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
    </div>

    <UAlert
      v-else-if="error"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Não foi possível carregar o painel fiscal"
    />

    <div v-else class="flex flex-col gap-6">
      <UPageGrid class="gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <MetricCard
          title="Documentos capturados"
          :value="String(data?.total ?? 0)"
          icon="i-lucide-file-text"
        />
        <MetricCard
          title="Clientes capturáveis"
          :value="reading?.capturableLabel ?? '—'"
          icon="i-lucide-shield-check"
        />
        <MetricCard
          title="Clientes em atenção"
          :value="String(attentionTotal)"
          icon="i-lucide-triangle-alert"
        />
        <MetricCard
          title="Última captura"
          :value="data?.last_capture_at ? new Date(data.last_capture_at).toLocaleDateString('pt-BR') : '—'"
          icon="i-lucide-clock"
        />
      </UPageGrid>

      <UPageCard
        v-if="reading?.empty"
        title="Carteira sem clientes"
        description="Cadastre clientes para começar a capturar documentos fiscais."
        icon="i-lucide-building"
      />

      <UAlert
        v-else-if="reading?.allBlocked"
        color="warning"
        variant="subtle"
        icon="i-lucide-shield-off"
        title="Nenhum cliente pode ser capturado"
        description="Nenhum cliente da carteira tem certificado A1 utilizável. A captura não vai trazer documentos até que ao menos um certificado esteja disponível."
      />

      <UPageCard v-if="modelRows.length > 0" title="Documentos por modelo">
        <div class="flex flex-col gap-2">
          <div v-for="row in modelRows" :key="row.model" class="flex items-center justify-between">
            <span>{{ modelLabel(row.model) }}</span>
            <span class="font-medium tabular-nums">{{ row.total }}</span>
          </div>
        </div>
      </UPageCard>

      <UPageCard title="Precisa de atenção">
        <div v-if="reasons.length === 0" class="text-sm text-muted">
          Nenhum cliente precisa de ação no momento.
        </div>

        <div v-else class="flex flex-col gap-4">
          <div v-for="reason in reasons" :key="reason.code" class="flex flex-col gap-2">
            <div class="flex items-center justify-between">
              <UBadge color="warning" variant="subtle">{{ reason.label }}</UBadge>
              <span class="font-medium tabular-nums">{{ reason.count }}</span>
            </div>
            <p class="text-sm text-muted">{{ reason.description }}</p>
            <div class="flex flex-wrap gap-1">
              <UBadge
                v-for="client in reason.clients"
                :key="client.id"
                color="neutral"
                variant="soft"
                :to="`/customers/empresa/${client.id}`"
              >
                {{ client.name }}
              </UBadge>
            </div>
          </div>
        </div>
      </UPageCard>
    </div>
  </div>
</template>
```

- [ ] **Step 6: Verify and commit**

Run: `pnpm lint && pnpm typecheck`
Expected: sem erros.

```bash
git add frontend/app/utils/fiscalPresentation.ts frontend/app/pages/fiscal.vue frontend/app/pages/fiscal/index.vue frontend/tests/fiscalPresentation.test.ts
git commit -m "feat(fiscal): add dashboard with coverage as primary reading"
```

---

## Task 21: Tabela de documentos

**Files:**
- Create: `frontend/app/pages/fiscal/documentos.vue`
- Modify: `frontend/app/utils/fiscalPresentation.ts`, `frontend/tests/fiscalPresentation.test.ts`
- Test: verificação por `node --test`, `pnpm lint`, `pnpm typecheck`

**Interfaces:**
- Consumes: `useFiscal()` (Task 19), `fiscalPresentation` (Task 20), `sheetTableUi` de `~/components/data-table/sheet`.
- Produces: `modelOptions(rows: FiscalDocumentRow[]): { label: string, value: FiscalModel }[]` — facetado pelos valores presentes no resultado.

- [ ] **Step 1: Write the failing test**

Acrescentar a `frontend/tests/fiscalPresentation.test.ts`:

```ts
describe('modelOptions', () => {
  it('offers only the models present in the result', () => {
    const rows = [
      { model: 'nfe' },
      { model: 'nfe' },
      { model: 'cte' }
    ] as never

    const options = modelOptions(rows)

    assert.deepEqual(options.map(o => o.value), ['nfe', 'cte'])
    assert.deepEqual(options.map(o => o.label), ['NF-e', 'CT-e'])
  })

  it('returns nothing for an empty result', () => {
    assert.deepEqual(modelOptions([]), [])
  })
})
```

Adicionar `modelOptions` ao import do teste.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && node --test tests/fiscalPresentation.test.ts`
Expected: FAIL — `modelOptions` não exportado.

- [ ] **Step 3: Implement `modelOptions`**

```ts
export function modelOptions(rows: Pick<FiscalDocumentRow, 'model'>[]): { label: string, value: FiscalModel }[] {
  const present = [...new Set(rows.map(row => row.model))]

  return present.map(model => ({ label: modelLabel(model), value: model }))
}
```

Adicionar `FiscalDocumentRow` ao import de tipos do arquivo.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && node --test tests/fiscalPresentation.test.ts`
Expected: PASS — 8 testes.

- [ ] **Step 5: Create `documentos.vue`**

```vue
<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { h, resolveComponent } from 'vue'
import { sheetTableUi } from '~/components/data-table/sheet'
import type { FiscalDocumentRow, FiscalListParams, FiscalModel } from '~/types/fiscal'
import { formatAccessKey, formatBytes, modelColor, modelLabel, modelOptions } from '~/utils/fiscalPresentation'

definePageMeta({ middleware: 'auth' })

const { list, downloadXml } = useFiscal()
const { canManageClients } = useAuth()
const toast = useToast()

const route = useRoute()
const router = useRouter()

const selectedModels = ref<FiscalModel[]>(
  Array.isArray(route.query.model) ? route.query.model as FiscalModel[] : []
)
const search = ref(typeof route.query.q === 'string' ? route.query.q : '')
const dateFrom = ref(typeof route.query.date_from === 'string' ? route.query.date_from : '')
const dateTo = ref(typeof route.query.date_to === 'string' ? route.query.date_to : '')
const kind = ref<'document' | 'event' | undefined>(
  route.query.kind === 'document' || route.query.kind === 'event' ? route.query.kind : undefined
)
const page = ref(Number(route.query.page ?? 1))

// O texto livre casa com emitente OU destinatário: o usuário procura por um
// CNPJ sem saber de que lado da nota ele está.
const cnpjFilter = computed(() => search.value.replace(/\D/g, ''))

const params = computed<FiscalListParams>(() => ({
  model: selectedModels.value.length > 0 ? selectedModels.value : undefined,
  emitente_cnpj: cnpjFilter.value.length === 14 ? cnpjFilter.value : undefined,
  destinatario_cnpj: cnpjFilter.value.length === 14 ? cnpjFilter.value : undefined,
  kind: kind.value,
  date_from: dateFrom.value || undefined,
  date_to: dateTo.value || undefined,
  page: page.value,
  per_page: 25
}))

const { data, status, error } = await useAsyncData(
  () => `fiscal-documents-${JSON.stringify(params.value)}`,
  () => list(params.value),
  { watch: [params] }
)

const rows = computed<FiscalDocumentRow[]>(() => data.value?.data ?? [])
const total = computed(() => data.value?.meta?.total ?? rows.value.length)
const options = computed(() => modelOptions(rows.value))
const hasFilters = computed(() => selectedModels.value.length > 0
  || search.value !== ''
  || dateFrom.value !== ''
  || dateTo.value !== ''
  || kind.value !== undefined)

// Os filtros vivem na URL para a view ser compartilhável e sobreviver a reload.
watch([selectedModels, search, dateFrom, dateTo, kind, page], () => {
  const query: Record<string, string | string[]> = {}

  if (selectedModels.value.length > 0) query.model = selectedModels.value
  if (search.value !== '') query.q = search.value
  if (dateFrom.value !== '') query.date_from = dateFrom.value
  if (dateTo.value !== '') query.date_to = dateTo.value
  if (kind.value !== undefined) query.kind = kind.value
  if (page.value > 1) query.page = String(page.value)

  router.replace({ query })
})

const UBadge = resolveComponent('UBadge')

const columns: TableColumn<FiscalDocumentRow>[] = [
  {
    accessorKey: 'model',
    header: 'Modelo',
    cell: ({ row }) => h(UBadge, {
      color: modelColor(row.original.model),
      variant: 'subtle'
    }, () => modelLabel(row.original.model))
  },
  {
    accessorKey: 'chave_acesso',
    header: 'Chave de acesso',
    cell: ({ row }) => h('span', { class: 'font-mono text-xs' }, formatAccessKey(row.original.chave_acesso))
  },
  { accessorKey: 'emitente_cnpj', header: 'Emitente' },
  { accessorKey: 'destinatario_cnpj', header: 'Destinatário' },
  {
    accessorKey: 'valor_total',
    header: 'Valor',
    cell: ({ row }) => row.original.valor_total
      ? new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(row.original.valor_total))
      : '—'
  },
  {
    accessorKey: 'emissao_at',
    header: 'Emissão',
    cell: ({ row }) => row.original.emissao_at
      ? new Date(row.original.emissao_at).toLocaleDateString('pt-BR')
      : '—'
  },
  {
    accessorKey: 'xml_bytes',
    header: 'XML',
    cell: ({ row }) => formatBytes(row.original.xml_bytes)
  },
  {
    id: 'actions',
    header: '',
    cell: ({ row }) => h(resolveComponent('UButton'), {
      icon: 'i-lucide-download',
      color: 'neutral',
      variant: 'ghost',
      size: 'xs',
      'aria-label': 'Baixar XML',
      onClick: () => download(row.original)
    })
  }
]

async function download(row: FiscalDocumentRow) {
  try {
    const blob = await downloadXml(row.id)
    const url = URL.createObjectURL(new Blob([blob as unknown as BlobPart], { type: 'application/xml' }))
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `${row.chave_acesso}.xml`
    anchor.click()
    URL.revokeObjectURL(url)
  } catch {
    toast.add({ title: 'Não foi possível baixar o XML', color: 'error' })
  }
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <div class="flex flex-wrap items-center gap-2 border-b border-default px-4 py-3">
      <USelectMenu
        v-model="selectedModels"
        :items="options"
        multiple
        placeholder="Modelo"
        class="w-44"
      />

      <UInput
        v-model="search"
        icon="i-lucide-search"
        placeholder="CNPJ do emitente ou destinatário"
        class="w-64"
      />

      <UInput v-model="dateFrom" type="date" class="w-40" aria-label="Emissão a partir de" />
      <UInput v-model="dateTo" type="date" class="w-40" aria-label="Emissão até" />

      <USelect
        v-model="kind"
        :items="[
          { label: 'Todos', value: undefined },
          { label: 'Documentos', value: 'document' },
          { label: 'Eventos', value: 'event' }
        ]"
        class="w-40"
      />

      <span class="ml-auto text-sm text-muted">{{ total }} documentos</span>
    </div>

    <UAlert
      v-if="error"
      class="m-4"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Não foi possível carregar os documentos"
    />

    <UEmpty
      v-else-if="status !== 'pending' && rows.length === 0"
      class="m-4"
      :icon="hasFilters ? 'i-lucide-filter-x' : 'i-lucide-file-search'"
      :title="hasFilters ? 'Nenhum documento corresponde aos filtros' : 'Nenhum documento capturado ainda'"
      :description="hasFilters
        ? 'Ajuste ou limpe os filtros para ver mais resultados.'
        : 'Os documentos aparecem aqui assim que a captura rodar para um cliente com certificado utilizável.'"
    />

    <UTable
      v-else
      :data="rows"
      :columns="columns"
      :loading="status === 'pending'"
      sticky
      :ui="sheetTableUi"
      class="min-h-0 flex-1"
    />
  </div>
</template>
```

O detalhe do documento e o disparo de captura entram na task seguinte: esta entrega a listagem com filtros, e a próxima liga a folha de detalhe e as ações.

O disparo de captura por cliente não entra nesta tela: ele pertence ao detalhe do cliente, onde `canManageClients` já governa as ações, e é onde um operador procura por ele.

- [ ] **Step 6: Verify and commit**

Run: `pnpm lint && pnpm typecheck && node --test tests/fiscalPresentation.test.ts`
Expected: sem erros, 7 testes passando.

```bash
git add frontend/app/pages/fiscal/documentos.vue frontend/app/utils/fiscalPresentation.ts frontend/tests/fiscalPresentation.test.ts
git commit -m "feat(fiscal): add unified documents table"
```

---

## Task 22: Detalhe do documento com linha do tempo de eventos

**Files:**
- Create: `frontend/app/components/fiscal/FiscalDocumentSheet.vue`
- Modify: `frontend/app/pages/fiscal/documentos.vue`
- Test: verificação por `pnpm lint` e `pnpm typecheck`

**Interfaces:**
- Consumes: `useFiscal().show()` (Task 19), `fiscalPresentation` (Task 20).
- Produces: `FiscalDocumentSheet` com `v-model:open` e `:document`.

Um documento e seus eventos compartilham a chave de acesso. O detalhe mostra o documento e, abaixo, a linha do tempo dos eventos daquela chave — é a razão de os eventos serem linhas próprias em vez de colunas no documento.

- [ ] **Step 1: Create `FiscalDocumentSheet.vue`**

```vue
<script setup lang="ts">
import type { FiscalDocumentRow } from '~/types/fiscal'
import { formatAccessKey, formatBytes, modelLabel } from '~/utils/fiscalPresentation'

const open = defineModel<boolean>('open', { required: true })

const props = defineProps<{ document: FiscalDocumentRow | null }>()

const { list, downloadXml } = useFiscal()
const toast = useToast()

// Os eventos da mesma chave de acesso são linhas próprias: o detalhe do
// documento é onde eles ganham sentido juntos.
const { data: events } = await useAsyncData(
  () => `fiscal-events-${props.document?.chave_acesso ?? 'none'}`,
  async () => {
    if (!props.document) return { data: [] as FiscalDocumentRow[] }

    const response = await list({ kind: 'event', per_page: 100 })

    return {
      data: response.data.filter(row => row.chave_acesso === props.document?.chave_acesso)
    }
  },
  { watch: [() => props.document?.chave_acesso], default: () => ({ data: [] as FiscalDocumentRow[] }) }
)

const timeline = computed(() => (events.value?.data ?? [])
  .slice()
  .sort((a, b) => (a.evento_ocorrido_em_at ?? '').localeCompare(b.evento_ocorrido_em_at ?? '')))

function formatDateTime(value: string | null): string {
  return value ? new Date(value).toLocaleString('pt-BR') : '—'
}

async function download() {
  if (!props.document) return

  try {
    const blob = await downloadXml(props.document.id)
    const url = URL.createObjectURL(new Blob([blob as unknown as BlobPart], { type: 'application/xml' }))
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `${props.document.chave_acesso}.xml`
    anchor.click()
    URL.revokeObjectURL(url)
  } catch {
    toast.add({ title: 'Não foi possível baixar o XML', color: 'error' })
  }
}
</script>

<template>
  <USlideover v-model:open="open" :title="document ? modelLabel(document.model) : 'Documento'">
    <template #body>
      <div v-if="document" class="flex flex-col gap-6">
        <dl class="grid grid-cols-2 gap-3 text-sm">
          <div class="col-span-2">
            <dt class="text-muted">Chave de acesso</dt>
            <dd class="font-mono text-xs break-all">{{ formatAccessKey(document.chave_acesso) }}</dd>
          </div>
          <div>
            <dt class="text-muted">Emitente</dt>
            <dd>{{ document.emitente_cnpj ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-muted">Destinatário</dt>
            <dd>{{ document.destinatario_cnpj ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-muted">Valor total</dt>
            <dd>
              {{ document.valor_total
                ? new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(document.valor_total))
                : '—' }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">Emissão</dt>
            <dd>{{ formatDateTime(document.emissao_at) }}</dd>
          </div>
          <div>
            <dt class="text-muted">Capturado em</dt>
            <dd>{{ formatDateTime(document.captured_at) }}</dd>
          </div>
          <div>
            <dt class="text-muted">Tamanho do XML</dt>
            <dd>{{ formatBytes(document.xml_bytes) }}</dd>
          </div>
        </dl>

        <UAlert
          v-if="document.mascarado"
          color="info"
          variant="subtle"
          icon="i-lucide-eye-off"
          title="Chaves de transporte mascaradas"
          description="Este documento foi obtido como autorizado a consultar, e o fisco zera as chaves dos documentos relacionados."
        />

        <div>
          <h3 class="mb-2 text-sm font-medium">Eventos</h3>

          <p v-if="timeline.length === 0" class="text-sm text-muted">
            Nenhum evento registrado para esta chave de acesso.
          </p>

          <UTimeline
            v-else
            :items="timeline.map(event => ({
              date: formatDateTime(event.evento_ocorrido_em_at),
              title: event.event_id,
              description: `Capturado em ${formatDateTime(event.captured_at)}`
            }))"
          />
        </div>
      </div>
    </template>

    <template #footer>
      <slot name="actions" />
      <UButton icon="i-lucide-download" color="neutral" variant="subtle" @click="download">
        Baixar XML
      </UButton>
    </template>
  </USlideover>
</template>
```

- [ ] **Step 2: Wire it into the table page**

Em `documentos.vue`, adicionar ao `<script setup>`:

```ts
const detailOpen = ref(false)
const detailDocument = ref<FiscalDocumentRow | null>(null)

function openDetail(row: FiscalDocumentRow) {
  detailDocument.value = row
  detailOpen.value = true
}

const { sync } = useFiscal()

// O disparo é do operador, não de quem só lê: o backend também barra, mas a
// ação não deve nem aparecer para quem não pode usá-la.
async function triggerCapture() {
  if (!detailDocument.value || !canManageClients.value) return

  try {
    const response = await sync(detailDocument.value.client_id)

    if (response.data.blocked) {
      toast.add({
        title: 'Captura bloqueada para este cliente',
        description: response.data.blocked_until
          ? `Retoma em ${new Date(response.data.blocked_until).toLocaleString('pt-BR')}.`
          : undefined,
        color: 'warning'
      })

      return
    }

    toast.add({ title: 'Captura enfileirada', color: 'success' })
  } catch {
    toast.add({ title: 'Não foi possível disparar a captura', color: 'error' })
  }
}
```

E ao `<UTable>`, a ligação da linha:

```vue
@select="(_event: Event, row: FiscalDocumentRow) => openDetail(row)"
```

E ao template, antes do fechamento da `div` raiz:

```vue
<FiscalDocumentSheet v-model:open="detailOpen" :document="detailDocument">
  <template #actions>
    <UButton
      v-if="canManageClients"
      icon="i-lucide-refresh-cw"
      color="neutral"
      variant="subtle"
      @click="triggerCapture"
    >
      Capturar agora
    </UButton>
  </template>
</FiscalDocumentSheet>
```

- [ ] **Step 3: Verify and commit**

Run: `pnpm lint && pnpm typecheck`
Expected: sem erros.

```bash
git add frontend/app/components/fiscal/FiscalDocumentSheet.vue frontend/app/pages/fiscal/documentos.vue
git commit -m "feat(fiscal): add document detail with event timeline"
```

---

## Task 23: Verificação integrada

**Files:** nenhum arquivo novo.

- [ ] **Step 1: Run the backend suite**

Run: `cd backend && composer test`
Expected: PASS. Em especial, `ClientCertificateTest`, `ClientCertificatePasswordVaultTest`, `SerproConnectionTest` e os testes de tenancy continuam verdes.

- [ ] **Step 2: Confirm no unreachable branches, no new dependencies, and no manifestation**

Run:
```bash
cd backend && composer show --direct && grep -rn "Signature\|xmlseclibs\|sped-common" app/Services/Fiscal/ || echo "sem assinatura, sem dependencia"
grep -rn "RecepcaoEvento\|envEvento\|210200\|210210\|tpEvento.*=>" app/Services/Fiscal/ app/Jobs/CaptureFiscalDocumentsJob.php || echo "nenhuma manifestacao enviada"
```
Expected: exatamente 4 pacotes; os dois greps sem resultado. Nenhum caminho de código envia evento ao fisco — a captura é somente leitura.

- [ ] **Step 3: Confirm no secret or raw payload reaches logs or responses**

Run:
```bash
cd backend && grep -rn "password_encrypted\|certificatePassword()" app/Http app/Services/Fiscal | grep -v "=== null" || echo "segredo nao exposto"
grep -rn "Log::" app/Services/Fiscal || echo "sem log no modulo fiscal"
```
Expected: nenhuma exposição; nenhum log.

- [ ] **Step 4: Run the frontend checks**

Run: `cd frontend && pnpm lint && pnpm typecheck && node --test tests/`
Expected: sem erros; toda a suíte de `node --test` verde.

- [ ] **Step 5: Format PHP and confirm clean**

Run: `cd backend && vendor/bin/pint --dirty --format agent`
Expected: sem diff pendente.

- [ ] **Step 6: Validate the OpenSpec change**

Run: `openspec validate add-fiscal-document-capture --strict`
Expected: `Change 'add-fiscal-document-capture' is valid`.

- [ ] **Step 7: Manual smoke test against production restrita**

Rodar `php artisan fiscal:capture --client=<id>` para **um** cliente com certificado real e conferir, no banco:
- `fiscal_cursors.last_nsu` avançou e não é maior que o `maxNSU` observado;
- as linhas de `fiscal_documents` têm `chave_acesso` de 44 dígitos com DV válido;
- o XML existe no disco `fiscal` e o `sha256` confere;
- `fiscal_cursors.blocked_until` está nulo, ou preenchido se a resposta foi `137`/`656`.

Só depois disso ligar o agendamento.

---

## Plan 2 (não neste plano)

O `tasks.md` do change cobre ainda, em grupos separados:

- **Grupo 8 — conector de CT-e.** Reaproveita envelope, parser e writer; muda método SOAP, versão, os cinco valores de `schema`, o código de consumo indevido próprio do CT-e e o mascaramento por autorizado a consultar.
- **Grupo 9 — reconciliação.** Varredura diária de posições faltantes fora do horário comercial.

Nenhum dos dois bloqueia a entrega deste plano: NF-e de ponta a ponta já é um módulo utilizável.
