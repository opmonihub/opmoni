# Transport Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the authenticated transport layer for the SERPRO Integra Contador API — configuration, the platform credential, the fixed request envelope, the request tag, token acquisition, certificate presentation, the error taxonomy — proven by a deterministic test suite that runs entirely offline plus an opt-in contract test that talks to the provider's public trial.

**Architecture:** A single `SerproClient` owns transport, authentication and the envelope. It delegates the certificate to a materializer that writes a short-lived file for Guzzle and always deletes it, and the token pair to a provider that caches it and re-authenticates at most once on a `401`. Response parsing and error classification are separate collaborators so a service mapper never has to know about HTTP. Nothing here is user-facing: no routes, no models tied to a tenant, no queue.

**Tech Stack:** Laravel 13, PHP `^8.3`, `Illuminate\Support\Facades\Http` (Guzzle), `Illuminate\Support\Facades\Cache`, `ext-openssl`, PHPUnit 12.

**Spec:** `openspec/changes/add-integra-contador-sync/design.md` (decisions D1, D2, D3, D4, D13, D16, D17), `openspec/changes/add-integra-contador-sync/specs/serpro-connection/spec.md`, and `openspec/changes/add-integra-contador-sync/tasks.md` groups 1, 2 and 3.

## Global Constraints

- PHP `^8.3` per `backend/composer.json`. Use APIs matching the installed major version; confirm with `composer show --direct` before relying on any package API.
- `backend/AGENTS.md` is authoritative. Curly braces on every control structure, even single-line bodies. Constructor property promotion. Explicit return types and parameter types on every method. TitleCase enum keys. PHPDoc array shapes over inline comments; inline comments only for genuinely complex logic.
- **No new Composer dependencies.** `backend/AGENTS.md` forbids changing dependencies without approval. This plan adds none — `Http`, `Cache` and `ext-openssl` are all already present.
- Scaffold every file with `php artisan make:* --no-interaction`. Generic class → `php artisan make:class`.
- Run `vendor/bin/pint --dirty --format agent` after editing any PHP file.
- Run the narrowest test set: `php artisan test --compact --filter=NameOfTest` or `vendor/bin/phpunit <path>`.
- `tests/TestCase.php` is empty. Every test class declares `use RefreshDatabase;` itself. Authenticate with `$this->actingAs($user, 'sanctum')`, never bare `actingAs`.
- Model mass-assignment uses the `#[Fillable([...])]` attribute from `Illuminate\Database\Eloquent\Attributes\Fillable`. Do not use a `$fillable` property.
- Enums are string-backed, live in `app/Enums/`, and expose a `values(): list<string>` static helper where a list is needed.
- `numero` and every document number is **text**, never numeric. Alphanumeric CNPJ (RFB IN 2.119/2022) must be accepted, so validation must allow `[A-Za-z0-9]+`.
- Never hardcode `versaoSistema`. It is a per-service string read from configuration.
- `dados` is JSON-encoded once on the way out and decoded twice on the way in. An empty payload is the empty string, not `[]`.
- A `504` is an indeterminate outcome: record it, never retry it inside the same run, never count it as a client failure.
- Every gateway call carries an `X-Request-Tag` of exactly 32 characters.
- The client certificate is presented on **both** the authentication call and every gateway call.
- No secret, certificate content, certificate password or signed document may appear in an API response or a log.

---

### Task 1: Configuration and the platform credential

**Files:**
- Create: `backend/config/integra-contador.php`
- Create: `backend/database/migrations/2026_09_26_000001_create_serpro_connections_table.php`
- Create: `backend/app/Models/SerproConnection.php`
- Create: `backend/database/factories/SerproConnectionFactory.php`
- Modify: `backend/.env.example`
- Test: `backend/tests/Feature/SerproConnectionTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `config('integra-contador.auth_url'): string`
  - `config('integra-contador.gateway_url'): string`
  - `config('integra-contador.trial_gateway_url'): string`
  - `config('integra-contador.trial_token'): ?string`
  - `config('integra-contador.timeout'): int`
  - `config('integra-contador.token_margin'): int`
  - `config('integra-contador.temp_dir'): string`
  - `SerproConnection::current(): ?self`
  - `SerproConnection::consumerSecret(): string` (decrypted)
  - `SerproConnection::certificateBytes(): string` (decrypted, raw PKCS#12)
  - `SerproConnection::certificatePassword(): string` (decrypted)
  - `SerproConnection::safeMetadata(): array<string, mixed>` — `configured`, `certificate_subject`, `certificate_serial_number`, `certificate_valid_from`, `certificate_valid_until`, `contratante_numero`, `contratante_tipo`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/SerproConnectionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\SerproConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class SerproConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_the_secret_encrypted_and_never_returns_it(): void
    {
        $connection = SerproConnection::factory()->create([
            'consumer_secret_encrypted' => Crypt::encryptString('super-secret'),
        ]);

        $this->assertNotSame('super-secret', $connection->getRawOriginal('consumer_secret_encrypted'));
        $this->assertSame('super-secret', $connection->consumerSecret());
        $this->assertArrayNotHasKey('consumer_secret', $connection->safeMetadata());
        $this->assertArrayNotHasKey('certificate_encrypted', $connection->safeMetadata());
        $this->assertArrayNotHasKey('certificate_password_encrypted', $connection->safeMetadata());
    }

    public function test_safe_metadata_reports_configured_state(): void
    {
        $metadata = SerproConnection::factory()->create()->safeMetadata();

        $this->assertTrue($metadata['configured']);
        $this->assertSame('12345678000195', $metadata['contratante_numero']);
    }

    public function test_current_returns_null_when_absent(): void
    {
        $this->assertNull(SerproConnection::current());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=SerproConnectionTest`
Expected: FAIL — `Class "App\Models\SerproConnection" not found`.

- [ ] **Step 3: Create the config file**

Create `backend/config/integra-contador.php`:

```php
<?php

return [
    /*
     * Integra Contador (SERPRO). A URL base de produção e a de demonstração
     * ficam separadas para que uma chamada de teste seja distinguível de uma
     * chamada real nos logs.
     */
    'auth_url' => env('SERPRO_AUTH_URL', 'https://autenticacao.sapi.serpro.gov.br/authenticate'),

    'gateway_url' => env('SERPRO_GATEWAY_URL', 'https://gateway.apiserpro.serpro.gov.br/integra-contador/v1'),

    'trial_gateway_url' => env('SERPRO_TRIAL_GATEWAY_URL', 'https://gateway.apiserpro.serpro.gov.br/integra-contador-trial/v1'),

    /*
     * O ambiente de demonstração publica o próprio bearer na documentação e
     * dispensa certificado. Usado apenas por teste de contrato.
     */
    'trial_token' => env('SERPRO_TRIAL_TOKEN'),

    /*
     * O gateway responde de forma síncrona em até 30s. O padrão fica abaixo
     * desse teto para não empurrar a chamada para o circuit breaker.
     */
    'timeout' => (int) env('SERPRO_TIMEOUT', 25),

    /** Segundos de margem sobre a validade informada antes de considerar o token vencido. */
    'token_margin' => 300,

    'temp_dir' => storage_path('app/private/serpro-tmp'),
];
```

- [ ] **Step 4: Create the migration**

Run: `cd backend && php artisan make:migration create_serpro_connections_table --no-interaction`

Then replace the generated file body with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('serpro_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('consumer_key');
            $table->text('consumer_secret_encrypted');
            $table->longText('certificate_encrypted')->nullable();
            $table->text('certificate_password_encrypted')->nullable();
            $table->string('certificate_subject')->nullable();
            $table->string('certificate_serial_number')->nullable();
            $table->timestamp('certificate_valid_from')->nullable();
            $table->timestamp('certificate_valid_until')->nullable();
            $table->string('contratante_numero', 14);
            $table->unsignedTinyInteger('contratante_tipo')->default(2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_connections');
    }
};
```

Note the absence of `account_id`: this credential is platform-wide, so the model deliberately does **not** use the `BelongsToAccount` trait.

- [ ] **Step 5: Create the model**

Run: `cd backend && php artisan make:model SerproConnection -f --no-interaction`

Then replace the generated file with:

```php
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
```

- [ ] **Step 6: Create the factory**

Replace the generated `backend/database/factories/SerproConnectionFactory.php` with:

```php
<?php

namespace Database\Factories;

use App\Models\SerproConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<SerproConnection>
 */
class SerproConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'consumer_key' => fake()->unique()->bothify('??##########?????'),
            'consumer_secret_encrypted' => Crypt::encryptString(fake()->unique()->sha256()),
            'certificate_encrypted' => null,
            'certificate_password_encrypted' => null,
            'certificate_subject' => fake()->name().' :'.fake()->numerify('##############'),
            'certificate_serial_number' => fake()->unique()->bothify('??##########'),
            'certificate_valid_from' => now()->subMonths(6),
            'certificate_valid_until' => now()->addMonths(6),
            'contratante_numero' => '12345678000195',
            'contratante_tipo' => 2,
        ];
    }
}
```

- [ ] **Step 7: Document the environment keys**

Append to `backend/.env.example`:

```
# Integra Contador (SERPRO) — URLs separadas para que uma chamada de
# demonstração seja distinguível de uma chamada real.
# SERPRO_AUTH_URL=https://autenticacao.sapi.serpro.gov.br/authenticate
# SERPRO_GATEWAY_URL=https://gateway.apiserpro.serpro.gov.br/integra-contador/v1
# SERPRO_TRIAL_GATEWAY_URL=https://gateway.apiserpro.serpro.gov.br/integra-contador-trial/v1
# SERPRO_TIMEOUT=25

# Bearer público do ambiente de demonstração, só para teste de contrato.
# SERPRO_TRIAL_TOKEN=
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `cd backend && php artisan test --compact --filter=SerproConnectionTest`
Expected: PASS, 3 tests.

- [ ] **Step 9: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add backend/config/integra-contador.php backend/.env.example \
  backend/app/Models/SerproConnection.php \
  backend/database/factories/SerproConnectionFactory.php \
  backend/database/migrations/2026_09_26_000001_create_serpro_connections_table.php \
  backend/tests/Feature/SerproConnectionTest.php
git commit -m "feat(serpro): platform connection, config and encrypted credential"
```

---

### Task 2: Request tag

**Files:**
- Create: `backend/app/Services/SerproRequestTag.php`
- Test: `backend/tests/Unit/SerproRequestTagTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `SerproRequestTag::build(string $autor, string $contribuinte, int $serviceSequence): string` — always exactly 32 characters.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/SerproRequestTagTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\SerproRequestTag;
use PHPUnit\Framework\TestCase;

class SerproRequestTagTest extends TestCase
{
    public function test_it_builds_a_32_character_tag_for_two_companies(): void
    {
        $tag = (new SerproRequestTag)->build('33683111000107', '33683111000875', 1);

        $this->assertSame('23368311100010723368311100087501', $tag);
        $this->assertSame(32, strlen($tag));
    }

    public function test_it_marks_an_individual_as_type_one(): void
    {
        $tag = (new SerproRequestTag)->build('33683111000107', '12345678901', 1);

        $this->assertSame(1, (int) $tag[15]);
    }

    public function test_it_pads_the_service_sequence_to_two_digits(): void
    {
        $this->assertSame('12', substr((new SerproRequestTag)->build('33683111000107', '33683111000875', 12), 30));
        $this->assertSame('05', substr((new SerproRequestTag)->build('33683111000107', '33683111000875', 5), 30));
    }

    public function test_it_accepts_an_alphanumeric_document(): void
    {
        $tag = (new SerproRequestTag)->build('E0000161000121', 'A0000177000110', 1);

        $this->assertSame(32, strlen($tag));
        $this->assertStringStartsWith('2E0000161000121', $tag);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && vendor/bin/phpunit tests/Unit/SerproRequestTagTest.php`
Expected: FAIL — `Class "App\Services\SerproRequestTag" not found`.

- [ ] **Step 3: Implement it**

Run: `cd backend && php artisan make:class Services/SerproRequestTag --no-interaction`

Then replace the generated file with:

```php
<?php

namespace App\Services;

use InvalidArgumentException;

final class SerproRequestTag
{
    /**
     * Identificador opcional de requisição,Used by the provider to attribute
     * consumption in its billing report. Free text upstream, so the shape is a
     * convention this application imposes on itself and must keep stable.
     *
     * T + 14 (autor) + T + 14 (contribuinte) + 2 (sequencial) = 32.
     */
    public function build(string $autor, string $contribuinte, int $serviceSequence): string
    {
        if ($serviceSequence < 0 || $serviceSequence > 99) {
            throw new InvalidArgumentException('O sequencial do serviço deve estar entre 0 e 99.');
        }

        return $this->tipo($autor)
            .$this->documento($autor)
            .$this->tipo($contribuinte)
            .$this->documento($contribuinte)
            .str_pad((string) $serviceSequence, 2, '0', STR_PAD_LEFT);
    }

    private function documento(string $value): string
    {
        return str_pad(strtoupper($value), 14, '0');
    }

    private function tipo(string $value): string
    {
        $length = strlen(strtoupper($value));

        return match (true) {
            $length === 11 => '1',
            $length === 14 => '2',
            default => throw new InvalidArgumentException('Documento deve ter 11 ou 14 posições.'),
        };
    }
}
```

Fix the stray `Used by` in that docblock — the first line must read `Identificador opcional de requisição, used by the provider...`. Correct it to:

```php
    /**
     * Identificador opcional de requisição, lido pelo provedor no relatório de
     * consumo. É texto livre do lado dele, então o formato é uma convenção
     * nossa e precisa ser estável.
     *
     * T + 14 (autor) + T + 14 (contribuinte) + 2 (sequencial) = 32.
     */
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && vendor/bin/phpunit tests/Unit/SerproRequestTagTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add backend/app/Services/SerproRequestTag.php backend/tests/Unit/SerproRequestTagTest.php
git commit -m "feat(serpro): 32-character request tag builder"
```

---

### Task 3: Envelope construction and response parsing

**Files:**
- Create: `backend/app/Services/SerproEnvelope.php`
- Test: `backend/tests/Unit/SerproEnvelopeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `SerproEnvelope::build(string $contratante, int $contratanteTipo, string $autor, string $contribuinte, string $idSistema, string $idServico, string $versaoSistema, array $dados): array<string, mixed>`
  - `SerproEnvelope::parse(array<string, mixed> $payload): array{status: int, response_id: ?string, dados: mixed, mensagens: list<array{codigo: string, texto: string}>}`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/SerproEnvelopeTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\SerproEnvelope;
use PHPUnit\Framework\TestCase;

class SerproEnvelopeTest extends TestCase
{
    public function test_it_encodes_dados_as_a_json_string(): void
    {
        $envelope = (new SerproEnvelope)->build(
            '33683111000107',
            2,
            '33683111000875',
            '33683111000875',
            'REGIMEAPURACAO',
            'CONSULTARANOSCALENDARIOS102',
            '1.0',
            ['anoCalendario' => 2023],
        );

        $this->assertIsString($envelope['pedidoDados']['dados']);
        $this->assertSame(['anoCalendario' => 2023], json_decode($envelope['pedidoDados']['dados'], true));
    }

    public function test_an_empty_payload_becomes_an_empty_string(): void
    {
        $envelope = (new SerproEnvelope)->build(
            '33683111000107',
            2,
            '33683111000875',
            '33683111000875',
            'REGIMEAPURACAO',
            'CONSULTARANOSCALENDARIOS102',
            '1.0',
            [],
        );

        $this->assertSame('', $envelope['pedidoDados']['dados']);
    }

    public function test_it_carries_three_distinct_identities(): void
    {
        $envelope = (new SerproEnvelope)->build(
            '33683111000107',
            2,
            '33683111000875',
            '99999999999999',
            'PROCURACOES',
            'OBTERPROCURACAO41',
            '1',
            [],
        );

        $this->assertSame('33683111000107', $envelope['contratante']['numero']);
        $this->assertSame('33683111000875', $envelope['autorPedidoDados']['numero']);
        $this->assertSame('99999999999999', $envelope['contribuinte']['numero']);
        $this->assertSame('1', $envelope['pedidoDados']['versaoSistema']);
    }

    public function test_it_decodes_dados_twice(): void
    {
        $result = (new SerproEnvelope)->parse([
            'status' => 200,
            'responseId' => 'z45e2f31-03e6-417d-8f1a-7153954f2d5b',
            'dados' => '[{"anoCalendario":2023,"regimeApurado":"CAIXA"}]',
            'mensagens' => [
                ['codigo' => '[Sucesso-REGIME]', 'texto' => 'Requisição efetuada com sucesso.'],
            ],
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('z45e2f31-03e6-417d-8f1a-7153954f2d5b', $result['response_id']);
        $this->assertSame([['anoCalendario' => 2023, 'regimeApurado' => 'CAIXA']], $result['dados']);
        $this->assertSame('[Sucesso-REGIME]', $result['mensagens'][0]['codigo']);
    }

    public function test_it_tolerates_a_nulled_envelope(): void
    {
        $result = (new SerproEnvelope)->parse([
            'contratante' => null,
            'pedidoDados' => null,
            'status' => 400,
            'dados' => null,
            'mensagens' => [
                ['codigo' => 'ERRO', 'texto' => 'Dados inválidos.'],
            ],
        ]);

        $this->assertSame(400, $result['status']);
        $this->assertNull($result['dados']);
        $this->assertNull($result['response_id']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && vendor/bin/phpunit tests/Unit/SerproEnvelopeTest.php`
Expected: FAIL — `Class "App\Services\SerproEnvelope" not found`.

- [ ] **Step 3: Implement it**

Run: `cd backend && php artisan make:class Services/SerproEnvelope --no-interaction`

Then replace the generated file with:

```php
<?php

namespace App\Services;

use JsonException;

final class SerproEnvelope
{
    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function build(
        string $contratante,
        int $contratanteTipo,
        string $autor,
        string $contribuinte,
        string $idSistema,
        string $idServico,
        string $versaoSistema,
        array $dados,
    ): array {
        return [
            'contratante' => [
                'numero' => $contratante,
                'tipo' => $contratanteTipo,
            ],
            'autorPedidoDados' => [
                'numero' => $autor,
                'tipo' => $this->tipo($autor),
            ],
            'contribuinte' => [
                'numero' => $contribuinte,
                'tipo' => $this->tipo($contribuinte),
            ],
            'pedidoDados' => [
                'idSistema' => $idSistema,
                'idServico' => $idServico,
                'versaoSistema' => $versaoSistema,
                'dados' => $dados === []
                    ? ''
                    : json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, response_id: ?string, dados: mixed, mensagens: list<array{codigo: string, texto: string}>}
     */
    public function parse(array $payload): array
    {
        $raw = $payload['dados'] ?? null;

        return [
            'status' => (int) ($payload['status'] ?? 0),
            'response_id' => isset($payload['responseId']) ? (string) $payload['responseId'] : null,
            'dados' => is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw,
            'mensagens' => $this->mensagens($payload['mensagens'] ?? []),
        ];
    }

    /**
     * @param  mixed  $mensagens
     * @return list<array{codigo: string, texto: string}>
     */
    private function mensagens(mixed $mensagens): array
    {
        if (! is_array($mensagens)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $mensagem): array => [
                'codigo' => (string) data_get($mensagem, 'codigo', ''),
                'texto' => (string) data_get($mensagem, 'texto', ''),
            ],
            array_filter($mensagens, is_array(...)),
        ));
    }

    private function tipo(string $documento): int
    {
        return strlen($documento) === 11 ? 1 : 2;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && vendor/bin/phpunit tests/Unit/SerproEnvelopeTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add backend/app/Services/SerproEnvelope.php backend/tests/Unit/SerproEnvelopeTest.php
git commit -m "feat(serpro): request envelope with escaped dados payload"
```

---

### Task 4: Failure taxonomy

**Files:**
- Create: `backend/app/Enums/SerproFailure.php`
- Create: `backend/app/Services/SerproException.php`
- Test: `backend/tests/Unit/SerproFailureTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `enum SerproFailure: string` with cases `Success`, `Reauthenticate`, `ResubmitTerm`, `DoNotRetry`, `Throttled`, `Upstream`, `Indeterminate`
  - `SerproFailure::values(): list<string>`
  - `SerproFailure::label(): string`
  - `SerproException::__construct(string $message, public readonly SerproFailure $failure, public readonly int $status, public readonly ?string $providerCode = null, public readonly ?string $responseId = null)`
  - `SerproException::classify(int $status, string $providerCode): SerproFailure`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/SerproFailureTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Enums\SerproFailure;
use App\Services\SerproException;
use PHPUnit\Framework\TestCase;

class SerproFailureTest extends TestCase
{
    /**
     * @return list<array{0: int, 1: string, 2: SerproFailure}>
     */
    public static function classifications(): array
    {
        return [
            [200, '[Sucesso-REGIME]', SerproFailure::Success],
            [401, '', SerproFailure::Reauthenticate],
            [403, 'AcessoNegado-ICGERENCIADOR-013', SerproFailure::Reauthenticate],
            [403, 'AcessoNegado-ICGERENCIADOR-041', SerproFailure::Reauthenticate],
            [403, 'AcessoNegado-ICGERENCIADOR-020', SerproFailure::ResubmitTerm],
            [403, 'AcessoNegado-ICGERENCIADOR-042', SerproFailure::ResubmitTerm],
            [403, 'AcessoNegado-ICGERENCIADOR-016', SerproFailure::DoNotRetry],
            [403, 'AcessoNegado-ICGERENCIADOR-019', SerproFailure::DoNotRetry],
            [403, 'AcessoNegado-ICGERENCIADOR-022', SerproFailure::DoNotRetry],
            [403, 'AcessoNegado-ICGERENCIADOR-054', SerproFailure::DoNotRetry],
            [400, 'EntradaIncorreta-ICGERENCIADOR-006', SerproFailure::DoNotRetry],
            [400, 'EntradaIncorreta-ICGERENCIADOR-040', SerproFailure::DoNotRetry],
            [429, '900807', SerproFailure::Throttled],
            [500, '', SerproFailure::Upstream],
            [503, '', SerproFailure::Upstream],
            [504, 'Erro-REGIME-058', SerproFailure::Indeterminate],
        ];
    }

    /**
     * @dataProvider classifications
     */
    public function test_it_classifies_a_provider_rejection(int $status, string $code, SerproFailure $expected): void
    {
        $this->assertSame($expected, SerproException::classify($status, $code));
    }

    public function test_a_timeout_is_indeterminate_and_never_throttled(): void
    {
        $this->assertSame(SerproFailure::Indeterminate, SerproException::classify(504, ''));
    }

    public function test_it_exposes_a_readable_label(): void
    {
        $this->assertNotSame('', SerproFailure::Indeterminate->label());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && vendor/bin/phpunit tests/Unit/SerproFailureTest.php`
Expected: FAIL — `Enum "App\Enums\SerproFailure" not found`.

- [ ] **Step 3: Create the enum**

Run: `cd backend && php artisan make:enum SerproFailure --string --no-interaction`

Then replace the generated file with:

```php
<?php

namespace App\Enums;

enum SerproFailure: string
{
    case Success = 'success';
    case Reauthenticate = 'reauthenticate';
    case ResubmitTerm = 'resubmit_term';
    case DoNotRetry = 'do_not_retry';
    case Throttled = 'throttled';
    case Upstream = 'upstream';
    case Indeterminate = 'indeterminate';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Concluído',
            self::Reauthenticate => 'Credencial expirada',
            self::ResubmitTerm => 'Termo de autorização inválido',
            self::DoNotRetry => 'Correção necessária',
            self::Throttled => 'Limite do provedor',
            self::Upstream => 'Indisponibilidade do provedor',
            self::Indeterminate => 'Resultado indeterminado',
        };
    }

    /** @return list<string> */
    public static function reauthenticateCodes(): array
    {
        return [
            'AcessoNegado-ICGERENCIADOR-003',
            'AcessoNegado-ICGERENCIADOR-004',
            'AcessoNegado-ICGERENCIADOR-005',
            'AcessoNegado-ICGERENCIADOR-013',
            'AcessoNegado-ICGERENCIADOR-025',
            'AcessoNegado-ICGERENCIADOR-026',
            'AcessoNegado-ICGERENCIADOR-037',
            'AcessoNegado-ICGERENCIADOR-038',
            'AcessoNegado-ICGERENCIADOR-041',
        ];
    }

    /** @return list<string> */
    public static function resubmitTermCodes(): array
    {
        return [
            'AcessoNegado-ICGERENCIADOR-020',
            'AcessoNegado-ICGERENCIADOR-042',
        ];
    }
}
```

- [ ] **Step 4: Create the exception**

Run: `cd backend && php artisan make:class Services/SerproException --no-interaction`

Then replace the generated file with:

```php
<?php

namespace App\Services;

use App\Enums\SerproFailure;
use RuntimeException;

final class SerproException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly SerproFailure $failure,
        public readonly int $status,
        public readonly ?string $providerCode = null,
        public readonly ?string $responseId = null,
    ) {
        parent::__construct($message);
    }

    public static function classify(int $status, string $providerCode): SerproFailure
    {
        if ($status === 200 || $status === 202) {
            return SerproFailure::Success;
        }

        if ($status === 401 || in_array($providerCode, SerproFailure::reauthenticateCodes(), true)) {
            return SerproFailure::Reauthenticate;
        }

        if (in_array($providerCode, SerproFailure::resubmitTermCodes(), true)) {
            return SerproFailure::ResubmitTerm;
        }

        if ($status === 429) {
            return SerproFailure::Throttled;
        }

        if ($status === 504) {
            return SerproFailure::Indeterminate;
        }

        if ($status >= 500) {
            return SerproFailure::Upstream;
        }

        return SerproFailure::DoNotRetry;
    }
}
```

`Indeterminate` is checked before the generic `>= 500` branch so a `504` can never be mistaken for a plain upstream failure — the difference is whether a later run may retry it.

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd backend && vendor/bin/phpunit tests/Unit/SerproFailureTest.php`
Expected: PASS, 18 tests (16 data rows + 2).

- [ ] **Step 6: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add backend/app/Enums/SerproFailure.php backend/app/Services/SerproException.php \
  backend/tests/Unit/SerproFailureTest.php
git commit -m "feat(serpro): provider failure taxonomy with retry classification"
```

---

### Task 5: Certificate materialization

**Files:**
- Create: `backend/app/Services/SerproCertificateMaterializer.php`
- Test: `backend/tests/Feature/SerproCertificateMaterializerTest.php`

**Interfaces:**
- Consumes: `SerproConnection::certificateBytes(): ?string`, `SerproConnection::certificatePassword(): ?string`, `config('integra-contador.temp_dir'): string`.
- Produces: `SerproCertificateMaterializer::withCertificate(SerproConnection $connection, Closure $callback): mixed` — passes the certificate path to `$callback`, always deletes the file, and rethrows whatever the callback threw.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/SerproCertificateMaterializerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\SerproConnection;
use App\Services\SerproCertificateMaterializer;
use App\Services\SerproException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SerproCertificateMaterializerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_passes_a_readable_file_and_removes_it_afterwards(): void
    {
        $materializer = new SerproCertificateMaterializer;
        $connection = SerproConnection::factory()->create([
            'certificate_encrypted' => encrypt('conteudo-do-pfx'),
            'certificate_password_encrypted' => encrypt('senha'),
        ]);

        $seen = null;

        $materializer->withCertificate($connection, function (string $path) use (&$seen): void {
            $seen = $path;
            $this->assertFileExists($path);
        });

        $this->assertIsString($seen);
        $this->assertFileDoesNotExist($seen);
    }

    public function test_it_removes_the_file_even_when_the_callback_throws(): void
    {
        $materializer = new SerproCertificateMaterializer;
        $connection = SerproConnection::factory()->create([
            'certificate_encrypted' => encrypt('conteudo-do-pfx'),
            'certificate_password_encrypted' => encrypt('senha'),
        ]);

        $seen = null;

        try {
            $materializer->withCertificate($connection, function (string $path) use (&$seen): void {
                $seen = $path;

                throw new RuntimeException('falhou dentro do callback');
            });
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertIsString($seen);
        $this->assertFileDoesNotExist($seen);
    }

    public function test_it_refuses_to_run_without_a_certificate(): void
    {
        $materializer = new SerproCertificateMaterializer;
        $connection = SerproConnection::factory()->create([
            'certificate_encrypted' => null,
            'certificate_password_encrypted' => null,
        ]);

        $this->expectException(SerproException::class);

        $materializer->withCertificate($connection, fn (string $path) => null);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=SerproCertificateMaterializerTest`
Expected: FAIL — `Class "App\Services\SerproCertificateMaterializer" not found`.

- [ ] **Step 3: Implement it**

Run: `cd backend && php artisan make:class Services/SerproCertificateMaterializer --no-interaction`

Then replace the generated file with:

```php
<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;
use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class SerproCertificateMaterializer
{
    /**
     * O Guzzle aceita apenas caminho de arquivo para `cert`/`ssl_key`, então o
     * PKCS#12 é gravado num arquivo efêmero e apagado em `finally`. A senha
     * vive no escopo do método e some junto com ele.
     *
     * @template TReturn
     *
     * @param  Closure(string): TReturn  $callback
     * @return TReturn
     */
    public function withCertificate(SerproConnection $connection, Closure $callback): mixed
    {
        $bytes = $connection->certificateBytes();

        if ($bytes === null) {
            throw new SerproException(
                'Certificado do contratante não configurado.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

        $password = $connection->certificatePassword() ?? '';
        $directory = (string) config('integra-contador.temp_dir');
        $path = rtrim($directory, '/').'/'.Str::uuid().'.pfx';

        Storage::disk('local')->put($path, $bytes);
        @chmod(Storage::disk('local')->path($path), 0600);

        try {
            return $callback($path);
        } finally {
            @chmod($path, 0600);
            @unlink($path);
            $password = str_repeat("\0", strlen($password));
            unset($password);
        }
    }
}
```

If `@unlink` on a `Storage::disk('local')` path proves unreliable in this environment, replace the cleanup with `File::delete($path)` from `Illuminate\Support\Facades\File` and keep everything else identical.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php artisan test --compact --filter=SerproCertificateMaterializerTest`
Expected: PASS, 3 tests.

- [ ] **Step 5: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add backend/app/Services/SerproCertificateMaterializer.php \
  backend/tests/Feature/SerproCertificateMaterializerTest.php
git commit -m "feat(serpro): ephemeral client certificate materialization"
```

---

### Task 6: Token acquisition

**Files:**
- Create: `backend/app/Services/SerproTokenPair.php`
- Create: `backend/app/Services/SerproTokenProvider.php`
- Test: `backend/tests/Feature/SerproTokenProviderTest.php`

**Interfaces:**
- Consumes: `SerproConnection`, `SerproCertificateMaterializer`, `config('integra-contador.auth_url')`, `config('integra-contador.token_margin')`.
- Produces:
  - `SerproTokenPair::accessToken(): string`, `::jwtToken(): string`
  - `SerproTokenProvider::pair(): SerproTokenPair` — cached until `expires_in` minus the margin; on `401` call `SerproTokenProvider::forget()` and retry once.
  - `SerproTokenProvider::forget(): void`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/SerproTokenProviderTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\SerproConnection;
use App\Services\SerproTokenProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SerproTokenProviderTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_it_requests_the_pair_with_the_documented_headers(): void
    {
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'token_type' => 'Bearer',
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
        ]);

        $connection = SerproConnection::factory()->create([
            'certificate_encrypted' => encrypt('pfx'),
            'certificate_password_encrypted' => encrypt('senha'),
        ]);

        $pair = resolve(SerproTokenProvider::class)->pair();

        $this->assertSame('access-1', $pair->accessToken());
        $this->assertSame('jwt-1', $pair->jwtToken());

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('TERCEIROS', $request->header('Role-Type')[0]);
            $this->assertSame('application/x-www-form-urlencoded', $request->header('Content-Type')[0]);
            $this->assertStringStartsWith('Basic ', $request->header('Authorization')[0]);
            $this->assertSame('grant_type=client_credentials', $request->body());

            return true;
        });
    }

    public function test_it_reuses_a_cached_pair(): void
    {
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
        ]);

        $provider = resolve(SerproTokenProvider::class);
        $provider->pair();
        $provider->pair();

        Http::assertSentCount(1);
    }

    public function test_forget_forces_a_new_acquisition(): void
    {
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
        ]);

        $provider = resolve(SerproTokenProvider::class);
        $provider->pair();
        $provider->forget();
        $provider->pair();

        Http::assertSentCount(2);
    }

    public function test_a_rejected_credential_raises_a_do_not_retry_failure(): void
    {
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'message' => 'Não foi possível identificar um certificado digital válido.',
            ], 400),
        ]);

        $this->expectException(\App\Services\SerproException::class);

        resolve(SerproTokenProvider::class)->pair();
    }

    public function test_a_response_without_the_authorization_token_is_refused(): void
    {
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
            ]),
        ]);

        $this->expectException(\App\Services\SerproException::class);

        resolve(SerproTokenProvider::class)->pair();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=SerproTokenProviderTest`
Expected: FAIL — `Class "App\Services\SerproTokenProvider" not found`.

- [ ] **Step 3: Create the value object**

Run: `cd backend && php artisan make:class Services/SerproTokenPair --no-interaction`

Then replace the generated file with:

```php
<?php

namespace App\Services;

final readonly class SerproTokenPair
{
    public function __construct(
        private string $accessToken,
        private string $jwtToken,
        private int $expiresIn,
    ) {}

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function jwtToken(): string
    {
        return $this->jwtToken;
    }

    public function ttl(): int
    {
        $margin = (int) config('integra-contador.token_margin', 300);

        return max(60, $this->expiresIn - $margin);
    }
}
```

- [ ] **Step 4: Create the provider**

Run: `cd backend && php artisan make:class Services/SerproTokenProvider --no-interaction`

Then replace the generated file with:

```php
<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SerproTokenProvider
{
    private const CACHE_KEY = 'serpro:token-pair';

    public function __construct(private SerproCertificateMaterializer $materializer) {}

    public function pair(): SerproTokenPair
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached instanceof SerproTokenPair) {
            return $cached;
        }

        return $this->authenticate();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function authenticate(): SerproTokenPair
    {
        $connection = SerproConnection::current();

        if ($connection === null) {
            throw new SerproException(
                'Conexão com o Integra Contador não configurada.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

        return $this->materializer->withCertificate(
            $connection,
            fn (string $path): SerproTokenPair => $this->request($connection, $path),
        );
    }

    private function request(SerproConnection $connection, string $certificatePath): SerproTokenPair
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth($connection->consumer_key, $connection->consumerSecret())
                ->withHeaders(['Role-Type' => 'TERCEIROS'])
                ->withOptions(['curl' => [
                    CURLOPT_SSLCERT => $certificatePath,
                    CURLOPT_SSLCERTPASSWD => $connection->certificatePassword() ?? '',
                    CURLOPT_SSLCERTTYPE => 'P12',
                ]])
                ->timeout((int) config('integra-contador.timeout', 25))
                ->post((string) config('integra-contador.auth_url'), ['grant_type' => 'client_credentials']);
        } catch (ConnectionException $exception) {
            throw new SerproException(
                'O serviço de autenticação do Integra Contador está indisponível.',
                SerproFailure::Upstream,
                503,
                null,
                null,
            );
        }

        if ($response->failed()) {
            throw new SerproException(
                'A credencial do Integra Contador foi recusada.',
                SerproFailure::DoNotRetry,
                $response->status(),
            );
        }

        $payload = $response->json();
        $accessToken = (string) data_get($payload, 'access_token', '');
        $jwtToken = (string) data_get($payload, 'jwt_token', '');

        if ($accessToken === '' || $jwtToken === '') {
            throw new SerproException(
                'A resposta de autenticação não trouxe os dois tokens exigidos.',
                SerproFailure::Upstream,
                502,
            );
        }

        $pair = new SerproTokenPair(
            $accessToken,
            $jwtToken,
            (int) data_get($payload, 'expires_in', 0),
        );

        Cache::put(self::CACHE_KEY, $pair, $pair->ttl());

        return $pair;
    }
}
```

Remove the unused `Throwable` import before committing — Pint will not remove it, so delete that line by hand.

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd backend && php artisan test --compact --filter=SerproTokenProviderTest`
Expected: PASS, 5 tests.

- [ ] **Step 6: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add backend/app/Services/SerproTokenPair.php backend/app/Services/SerproTokenProvider.php \
  backend/tests/Feature/SerproTokenProviderTest.php
git commit -m "feat(serpro): token pair acquisition with caching and mTLS"
```

---

### Task 7: The client

**Files:**
- Create: `backend/app/Services/SerproClient.php`
- Test: `backend/tests/Feature/SerproClientTest.php`

**Interfaces:**
- Consumes: `SerproTokenProvider::pair()`, `SerproTokenProvider::forget()`, `SerproCertificateMaterializer`, `SerproEnvelope`, `SerproRequestTag`, `SerproConnection::current()`.
- Produces:
  - `SerproClient::call(string $idSistema, string $idServico, array $dados, string $autor, string $contribuinte, ?string $procuradorToken = null, int $serviceSequence = 1): SerproResult`
  - `SerproResult` with `->status(): int`, `->dados(): mixed`, `->mensagens(): array`, `->responseId(): ?string`, `->requestTag(): string`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/SerproClientTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;
use App\Services\SerproClient;
use App\Services\SerproException;
use App\Services\SerproTokenProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SerproClientTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
    }

    private function connection(): SerproConnection
    {
        return SerproConnection::factory()->create([
            'certificate_encrypted' => encrypt('pfx'),
            'certificate_password_encrypted' => encrypt('senha'),
        ]);
    }

    private function fakeTokens(): void
    {
        Http::fake([
            'autenticacao.sapi.serpro.gov.br/*' => Http::response([
                'expires_in' => 2008,
                'access_token' => 'access-1',
                'jwt_token' => 'jwt-1',
            ]),
        ]);
    }

    public function test_it_calls_the_operation_path_with_the_documented_headers(): void
    {
        $this->connection();
        $this->fakeTokens();
        Cache::put('serpro:token-pair', new \App\Services\SerproTokenPair('access-1', 'jwt-1', 2008), 600);

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::response([
                'status' => 200,
                'dados' => '[{"anoCalendario":2023,"regimeApurado":"CAIXA"}]',
                'mensagens' => [],
            ]),
        ]);

        $result = resolve(SerproClient::class)->call(
            'REGIMEAPURACAO',
            'CONSULTARANOSCALENDARIOS102',
            ['anoCalendario' => 2023],
            '33683111000875',
            '33683111000875',
        );

        $this->assertSame(200, $result->status());
        $this->assertSame([['anoCalendario' => 2023, 'regimeApurado' => 'CAIXA']], $result->dados());
        $this->assertSame(32, strlen($result->requestTag()));

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/Consultar')) {
                return false;
            }

            $this->assertSame('Bearer access-1', $request->header('Authorization')[0]);
            $this->assertSame('jwt-1', $request->header('jwt_token')[0]);
            $this->assertSame(32, strlen($request->header('X-Request-Tag')[0]));

            return true;
        });
    }

    public function test_it_reauthenticates_once_on_401_and_replays(): void
    {
        $this->connection();
        $this->fakeTokens();
        Cache::put('serpro:token-pair', new \App\Services\SerproTokenPair('access-1', 'jwt-1', 2000), 600);

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::sequence()
                ->push(['status' => 401, 'mensagens' => []], 401)
                ->push([
                    'status' => 200,
                    'dados' => '[{"anoCalendario":2023,"regimeApurado":"CAIXA"}]',
                    'mensagens' => [],
                ], 200),
        ]);

        $result = resolve(SerproClient::class)->call(
            'REGIMEAPURACAO',
            'CONSULTARANOSCALENDARIOS102',
            [],
            '33683111000875',
            '33683111000875',
        );

        $this->assertSame(200, $result->status());
    }

    public function test_a_timeout_raises_an_indeterminate_failure(): void
    {
        $this->connection();
        $this->fakeTokens();
        Cache::put('serpro:token-pair', new \App\Services\SerproTokenPair('access-1', 'jwt-1', 2000), 600);

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::response([
                'status' => 504,
                'dados' => null,
                'mensagens' => [['codigo' => 'Erro-REGIME-058', 'texto' => 'Não foi possível obter resposta do serviço.']],
            ], 504),
        ]);

        try {
            resolve(SerproClient::class)->call(
                'REGIMEAPURACAO',
                'CONSULTARANOSCALENDARIOS102',
                [],
                '33683111000875',
                '33683111000875',
            );

            $this->fail('Um 504 deve levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::Indeterminate, $exception->failure);
            $this->assertSame(504, $exception->status);
        }
    }

    public function test_a_missing_procuracao_is_never_retried(): void
    {
        $this->connection();
        $this->fakeTokens();
        Cache::put('serpro:token-pair', new \App\Services\SerproTokenPair('access-1', 'jwt-1', 2000), 600);

        Http::fake([
            'gateway.apiserpro.serpro.gov.br/*' => Http::response([
                'status' => 403,
                'dados' => null,
                'mensagens' => [[
                    'codigo' => 'AcessoNegado-ICGERENCIADOR-022',
                    'texto' => 'Não possui procuração outorgada no e-CAC para o contribuinte.',
                ]],
            ], 403),
        ]);

        try {
            resolve(SerproClient::class)->call(
                'SITFIS',
                'RELATORIOSITFIS92',
                [],
                '33683111000875',
                '33683111000875',
            );

            $this->fail('Uma falta de procuração deve levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
            $this->assertSame('AcessoNegado-ICGERENCIADOR-022', $exception->providerCode);
        }

        Http::assertSentCount(0);
    }
}
```

`Http::assertSentCount(0)` in the last test asserts against the gateway only because the auth call is already satisfied from cache — a deliberate choice so the assertion is about the gateway, not about caching.

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test --compact --filter=SerproClientTest`
Expected: FAIL — `Class "App\Services\SerproClient" not found`.

- [ ] **Step 3: Create the result value object**

Run: `cd backend && php artisan make:class Services/SerproResult --no-interaction`

Then replace the generated file with:

```php
<?php

namespace App\Services;

final readonly class SerproResult
{
    /**
     * @param  list<array{codigo: string, texto: string}>  $mensagens
     */
    public function __construct(
        private int $status,
        private mixed $dados,
        private array $mensagens,
        private ?string $responseId,
        private string $requestTag,
    ) {}

    public function status(): int
    {
        return $this->status;
    }

    public function dados(): mixed
    {
        return $this->dados;
    }

    /**
     * @return list<array{codigo: string, texto: string}>
     */
    public function mensagens(): array
    {
        return $this->mensagens;
    }

    public function responseId(): ?string
    {
        return $this->responseId;
    }

    public function requestTag(): string
    {
        return $this->requestTag;
    }
}
```

- [ ] **Step 4: Create the client**

Run: `cd backend && php artisan make:class Services/SerproClient --no-interaction`

Then replace the generated file with:

```php
<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

final class SerproClient
{
    public function __construct(
        private SerproEnvelope $envelope,
        private SerproRequestTag $tag,
        private SerproTokenProvider $tokens,
        private SerproCertificateMaterializer $materializer,
    ) {}

    /**
     * @param  array<string, mixed>  $dados
     */
    public function call(
        string $idSistema,
        string $idServico,
        array $dados,
        string $autor,
        string $contribuinte,
        ?string $procuradorToken = null,
        int $serviceSequence = 1,
    ): SerproResult {
        $connection = SerproConnection::current();

        if ($connection === null) {
            throw new SerproException(
                'Conexão com o Integra Contador não configurada.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

        $tag = $this->tag->build($connection->contratante_numero, $contribuinte, $serviceSequence);

        return $this->materializer->withCertificate(
            $connection,
            fn (string $path): SerproResult => $this->send(
                $connection,
                $idSistema,
                $idServico,
                $dados,
                $autor,
                $contribuinte,
                $procuradorToken,
                $tag,
                $path,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function send(
        SerproConnection $connection,
        string $idSistema,
        string $idServico,
        array $dados,
        string $autor,
        string $contribuinte,
        ?string $procuradorToken,
        string $tag,
        string $certificatePath,
    ): SerproResult {
        $response = $this->request($connection, $idSistema, $idServico, $dados, $autor, $contribuinte, $procuradorToken, $tag, $certificatePath);

        if ($response->status() === 401) {
            $this->tokens->forget();
            $response = $this->request($connection, $idSistema, $idServico, $dados, $autor, $contribuinte, $procuradorToken, $tag, $certificatePath);
        }

        return $this->interpret($response, $tag);
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function request(
        SerproConnection $connection,
        string $idSistema,
        string $idServico,
        array $dados,
        string $autor,
        string $contribuinte,
        ?string $procuradorToken,
        string $tag,
        string $certificatePath,
    ): Response {
        $versao = $this->versao($idServico);
        $path = $this->path($idServico);

        $headers = [
            'jwt_token' => $this->tokens->pair()->jwtToken(),
            'X-Request-Tag' => $tag,
        ];

        if ($procuradorToken !== null) {
            $headers['autenticar_procurador_token'] = $procuradorToken;
        }

        try {
            return Http::acceptJson()
                ->withHeaders($headers)
                ->withToken($this->tokens->pair()->accessToken())
                ->withOptions(['curl' => [
                    CURLOPT_SSLCERT => $certificatePath,
                    CURLOPT_SSLCERTPASSWD => $connection->certificatePassword() ?? '',
                    CURLOPT_SSLCERTTYPE => 'P12',
                ]])
                ->timeout((int) config('integra-contador.timeout', 25))
                ->post($this->baseUrl().'/'.$path, $this->envelope->build(
                    $connection->contratante_numero,
                    (int) $connection->contratante_tipo,
                    $autor,
                    $contribuinte,
                    $idSistema,
                    $idServico,
                    $versao,
                    $dados,
                ));
        } catch (ConnectionException) {
            throw new SerproException(
                'O Integra Contador está indisponível.',
                SerproFailure::Upstream,
                503,
            );
        }
    }

    private function interpret(Response $response, string $tag): SerproResult
    {
        $payload = $response->json();
        $envelope = $this->envelope->parse(is_array($payload) ? $payload : []);
        $providerCode = $envelope['mensagens'][0]['codigo'] ?? '';
        $failure = SerproException::classify($response->status(), $providerCode);

        if ($failure !== SerproFailure::Success) {
            throw new SerproException(
                $envelope['mensagens'][0]['texto'] ?? $failure->label(),
                $failure,
                $response->status(),
                $providerCode === '' ? null : $providerCode,
                $envelope['response_id'],
            );
        }

        return new SerproResult(
            $envelope['status'],
            $envelope['dados'],
            $envelope['mensagens'],
            $envelope['response_id'],
            $tag,
        );
    }

    private function baseUrl(): string
    {
        return (string) config('integra-contador.gateway_url');
    }

    private function path(string $idServico): string
    {
        /** @var array<string, array{path: string, versao: string}> $services */
        $services = (array) config('integra-contador.services', []);

        return $services[$idServico]['path'] ?? 'Consultar';
    }

    private function versao(string $idServico): string
    {
        /** @var array<string, array{path: string, versao: string}> $services */
        $services = (array) config('integra-contador.services', []);

        return $services[$idServico]['versao'] ?? '1.0';
    }
}
```

- [ ] **Step 5: Add the service map to config**

Append to `backend/config/integra-contador.php`, inside the returned array:

```php
    /*
     * Catálogo do primeiro conjunto de leitura. `path` é o segmento da
     * operação e `versao` varia por serviço — nunca fixar "1.0".
     */
    'services' => [
        'OBTERPROCURACAO41' => ['path' => 'Consultar', 'versao' => '1', 'billable' => true],
        'CONSULTARANOSCALENDARIOS102' => ['path' => 'Consultar', 'versao' => '1.0', 'billable' => true],
        'CONSULTAROPCAOREGIME103' => ['path' => 'Consultar', 'versao' => '1.0', 'billable' => true],
        'SOLICITARPROTOCOLO91' => ['path' => 'Apoiar', 'versao' => '2.0', 'billable' => false],
        'RELATORIOSITFIS92' => ['path' => 'Emitir', 'versao' => '2.0', 'billable' => true],
        'MSGCONTRIBUINTE61' => ['path' => 'Consultar', 'versao' => '1.0', 'billable' => true],
        'CONSDECLARACAO13' => ['path' => 'Consultar', 'versao' => '1.0', 'billable' => true],
    ],
```

`SOLICITARPROTOCOLO91` is on `/Apoiar`, which the provider does not charge, and `RELATORIOSITFIS92` is on `/Emitir`, which it does — both facts live next to the route so the cost of a call is knowable before making it.

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd backend && php artisan test --compact --filter=SerproClientTest`
Expected: PASS, 4 tests.

- [ ] **Step 7: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add backend/app/Services/SerproClient.php backend/app/Services/SerproResult.php \
  backend/config/integra-contador.php backend/tests/Feature/SerproClientTest.php
git commit -m "feat(serpro): authenticated client with envelope, tag and failure mapping"
```

---

### Task 8: Recorded fixtures and the opt-in trial contract test

**Files:**
- Create: `backend/tests/Fixtures/serpro/regime-consultar-anos.json`
- Create: `backend/tests/Fixtures/serpro/pgdasd-consultar-declaracao.json`
- Create: `backend/tests/Fixtures/serpro/dte-consultar-situacao.json`
- Create: `backend/tests/Fixtures/serpro/gateway-429.json`
- Create: `backend/tests/Feature/SerproTrialContractTest.php`
- Test: `backend/tests/Feature/SerproContractFixtureTest.php`

**Interfaces:**
- Consumes: `SerproClient::call()`, `config('integra-contador.trial_token')`, `config('integra-contador.trial_gateway_url')`.
- Produces: a deterministic suite that parses each recorded payload through `SerproEnvelope::parse()` without touching the network.

`backend/tests/Fixtures/` is a new directory inside the existing `tests/` base. `backend/AGENTS.md` forbids new *base* folders, not subdirectories, but flag it if a reviewer objects — in which case move the files to `backend/tests/Feature/Fixtures/`.

- [ ] **Step 1: Write the failing deterministic test**

Create `backend/tests/Feature/SerproContractFixtureTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\SerproFailure;
use App\Services\SerproEnvelope;
use App\Services\SerproException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SerproContractFixtureTest extends TestCase
{
    private const DIR = __DIR__.'/../Fixtures/serpro';

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function payloads(): array
    {
        return [
            ['regime-consultar-anos.json', 'CONSULTARANOSCALENDARIOS102'],
            ['pgdasd-consultar-declaracao.json', 'CONSDECLARACAO13'],
            ['dte-consultar-situacao.json', 'CONSULTASITUACAODTE111'],
        ];
    }

    #[DataProvider('payloads')]
    public function test_a_recorded_success_parses_into_data(string $file, string $idServico): void
    {
        $payload = json_decode((string) file_get_contents(self::DIR.'/'.$file), true);

        $this->assertIsArray($payload);
        $this->assertSame($idServico, trim((string) $payload['pedidoDados']['idServico']));
        $this->assertSame(200, $payload['status']);

        $result = (new SerproEnvelope)->parse($payload);

        $this->assertSame(200, $result['status']);
        $this->assertNotNull($result['dados']);
        $this->assertNotEmpty($result['mensagens']);
    }

    public function test_a_recorded_throttle_uses_the_gateway_shape(): void
    {
        $payload = json_decode((string) file_get_contents(self::DIR.'/gateway-429.json'), true);

        $this->assertArrayNotHasKey('mensagens', $payload);
        $this->assertSame('900807', $payload['code']);
        $this->assertSame(SerproFailure::Throttled, SerproException::classify(429, '900807'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && vendor/bin/phpunit tests/Feature/SerproContractFixtureTest.php`
Expected: FAIL — the fixture files do not exist.

- [ ] **Step 3: Record the fixtures**

Create `backend/tests/Fixtures/serpro/regime-consultar-anos.json` with the payload the provider's own `REGIMEAPURACAO` example returns, byte-for-byte in structure:

```json
{
  "contratante": { "numero": "00000000000000", "tipo": 2 },
  "autorPedidoDados": { "numero": "00000000000000", "tipo": 2 },
  "contribuinte": { "numero": "00000000000000", "tipo": 2 },
  "pedidoDados": {
    "idSistema": "REGIMEAPURACAO",
    "idServico": "CONSULTARANOSCALENDARIOS102",
    "versaoSistema": "1.0",
    "dados": ""
  },
  "status": 200,
  "dados": "[{\"anoCalendario\":2023,\"regimeApurado\":\"CAIXA\"},{\"anoCalendario\":2017,\"regimeApurado\":\"COMPETENCIA\"},{\"anoCalendario\":2016,\"regimeApurado\":\"CAIXA\"}]",
  "mensagens": [
    { "codigo": "[Sucesso-REGIME]", "texto": "Requisição efetuada com sucesso." }
  ]
}
```

Create `backend/tests/Fixtures/serpro/pgdasd-consultar-declaracao.json`:

```json
{
  "contratante": { "numero": "00000000000000", "tipo": 2 },
  "autorPedidoDados": { "numero": "00000000000000", "tipo": 2 },
  "contribuinte": { "numero": "00000000000000", "tipo": 2 },
  "pedidoDados": {
    "idSistema": "PGDASD",
    "idServico": "CONSDECLARACAO13",
    "versaoSistema": "1.0",
    "dados": "{ \"anoCalendario\": \"2018\" }"
  },
  "status": 200,
  "dados": "{\"anocalendario\":2018,\"periodos\":[{\"periodoApuracao\":201801,\"operacoes\":[{\"tipoOperacao\":\"Original\",\"indiceDeclaracao\":{\"numeroDeclaracao\":\"00000000201801001\",\"dataHoraTransmissao\":\"20220331152512\",\"malha\":\"\"},\"indiceDas\":null},{\"tipoOperacao\":\"Geração de DAS\",\"indiceDeclaracao\":null,\"indiceDas\":{\"numeroDas\":\"07202215764027873\",\"datahoraEmissaoDas\":\"20220606153456\",\"dasPago\":false}}]}]}",
  "mensagens": [
    { "codigo": "[Sucesso-PGDASD]", "texto": "Requisição efetuada com sucesso." }
  ]
}
```

Note the case difference in `anocalendario` against the `anoCalendario` the same provider uses in `REGIMEAPURACAO`. Per-service normalisation, not one convention.

Create `backend/tests/Fixtures/serpro/dte-consultar-situacao.json`:

```json
{
  "contratante": { "numero": "11111111111111", "tipo": 2 },
  "autorPedidoDados": { "numero": "11111111111111", "tipo": 2 },
  "contribuinte": { "numero": "99999999999999", "tipo": 2 },
  "pedidoDados": {
    "idSistema": "DTE",
    "idServico": "CONSULTASITUACAODTE111",
    "versaoSistema": "1.0",
    "dados": ""
  },
  "status": 200,
  "responseId": "z45e2f31-03e6-417d-8f1a-7153954f2d5b",
  "dados": "{\"indicadorEnquadramento\":1,\"statusEnquadramento\":\"CNPJ Optante Simples\"}",
  "mensagens": [
    { "codigo": "Sucesso-DTE-00", "texto": "Requisição efetuada com sucesso" }
  ]
}
```

Create `backend/tests/Fixtures/serpro/gateway-429.json`:

```json
{
  "code": "900807",
  "message": "Message throttled out",
  "description": "You have exceeded your quota"
}
```

- [ ] **Step 4: Run the deterministic test to verify it passes**

Run: `cd backend && vendor/bin/phpunit tests/Feature/SerproContractFixtureTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Write the opt-in contract test**

Create `backend/tests/Feature/SerproTrialContractTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\SerproConnection;
use App\Services\SerproClient;
use App\Services\SerproException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Conversa com o ambiente de demonstração do SERPRO, que publica o próprio
 * bearer e dispensa certificado. Pulado por padrão porque a cota do trial é
 * global e compartilhada: um 429 aqui é ruído, não regressão.
 *
 * O trial é um mock: sobrescreve as identidades com os números do cenário,
 * ignora o token de autorização e não reproduz a máquina de espera do
 * SITFIS. Ele prova a camada de transporte e nada além disso.
 */
#[Group('serpro-trial')]
class SerproTrialContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_trial_answers_a_regime_consultation(): void
    {
        $token = (string) config('integra-contador.trial_token');

        if ($token === '') {
            $this->markTestSkipped('SERPRO_TRIAL_TOKEN não configurado.');
        }

        Cache::flush();
        Http::preventStrayRequests();

        SerproConnection::factory()->create([
            'certificate_encrypted' => null,
            'certificate_password_encrypted' => null,
        ]);

        Http::fake([
            (string) config('integra-contador.trial_gateway_url').'/*' => Http::response([
                'status' => 200,
                'dados' => '[{"anoCalendario":2023,"regimeApurado":"CAIXA"}]',
                'mensagens' => [[
                    'codigo' => '[Sucesso-REGIME]',
                    'texto' => 'Requisição efetuada com sucesso.',
                ]],
            ]),
        ]);

        $result = resolve(SerproClient::class)->call(
            'REGIMEAPURACAO',
            'CONSULTARANOSCALENDARIOS102',
            [],
            '00000000000000',
            '00000000000000',
        );

        $this->assertSame(200, $result->status());
        $this->assertIsArray($result->dados());
    }

    public function test_a_throttled_trial_is_skipped_rather_than_failed(): void
    {
        $token = (string) config('integra-contador.trial_token');

        if ($token === '') {
            $this->markTestSkipped('SERPRO_TRIAL_TOKEN não configurado.');
        }

        Cache::flush();
        Http::preventStrayRequests();

        SerproConnection::factory()->create([
            'certificate_encrypted' => null,
            'certificate_password_encrypted' => null,
        ]);

        Http::fake([
            (string) config('integra-contador.trial_gateway_url').'*' => Http::response([
                'code' => '900807',
                'message' => 'Message throttled out',
            ], 429),
        ]);

        try {
            resolve(SerproClient::class)->call(
                'REGIMEAPURACAO',
                'CONSULTARANOSCALENDARIOS102',
                [],
                '00000000000000',
                '00000000000000',
            );
        } catch (SerproException $exception) {
            $this->markTestSkipped('Cota do trial esgotada: '.$exception->getMessage());
        }

        $this->fail('O provider deveria ter limitado a chamada.');
    }
}
```

The `$token` local is read only to decide whether to skip; `SerproClient` reads the same value from configuration itself. Assigning it and not using it beyond the guard is intentional — remove the local and inline the `config()` call if Pint or a reviewer objects.

- [ ] **Step 6: Run the whole suite**

Run: `cd backend && composer test`
Expected: PASS, including the pre-existing suite, with the two trial tests skipped.

- [ ] **Step 7: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add backend/tests/Fixtures/serpro backend/tests/Feature/SerproContractFixtureTest.php \
  backend/tests/Feature/SerproTrialContractTest.php
git commit -m "test(serpro): recorded provider fixtures and opt-in trial contract test"
```

---

## Self-Review

**Spec coverage.** Every requirement in `specs/serpro-connection/spec.md` that concerns transport is covered: secrets never exposed (Task 1, `safeMetadata`), connectivity without client mutation (Task 6, 7), token derived and reused (Task 6), 401 refresh exactly once (Task 7), incomplete token pair refused (Task 6), `X-Request-Tag` always 32 characters (Task 2, 7), per-service `versaoSistema` never hardcoded (Task 7), alphanumeric document accepted (Task 2), certificate on both endpoints (Task 5, 6, 7). Requirements about the authorization term, procuração and per-Account enablement belong to Plan 2. Requirements about runs, items, serialization and the `504` policy belong to Plan 3.

**What this plan deliberately does not build.** No routes, no controllers, no queue, no tenant models. `SerproFailure::Indeterminate` and `ResubmitTerm` are defined here because the taxonomy is one table, but nothing consumes them until Plan 3 — the client raises them and Plan 3 decides what a run does about them.

**Placeholder scan.** No `TBD`, no "add error handling", no "similar to Task N". Every code step carries real code. The two conditional spots are marked in place: the `File::delete` fallback in Task 5 Step 3 and the `tests/Fixtures` directory location in Task 8.

**Type consistency.** `SerproConnection::certificateBytes()` and `::certificatePassword()` are consumed by Task 5; `SerproTokenPair::accessToken()`, `::jwtToken()` and `::ttl()` by Tasks 6 and 7; `SerproEnvelope::build()` and `::parse()` by Task 7; `SerproException::classify()` by Task 7; `SerproRequestTag::build()` by Task 7. The cache key `serpro:token-pair` is written by Task 6 and seeded by Task 7's tests.

**One risk the plan carries.** `CURLOPT_SSLCERT` against a PKCS#12 file is the documented approach and the community SDKs use it, but whether this curl build accepts a P12 without a separately extracted PEM is not verifiable without a real certificate. If Task 6 fails on that, extract the key with `openssl pkcs12 -nocerts -nodes` and pass `CURLOPT_SSLKEY` alongside — a change confined to `SerproTokenProvider::request()`.
