# Integra Contador — Conexão e Transporte Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tornar a credencial única da plataforma administrável, verificável e segura, inclusive para clientes com CNPJ alfanumérico, antes de conectá-la a qualquer execução.

**Architecture:** Preservar `SerproClient`/`SerproTokenProvider` e o banco da conexão; pôr extração/validação do documento do certificado em uma unidade própria, expor só metadados por resource e testar a autenticação isoladamente. A suíte usa fixtures locais; o trial continua opt-in e nunca é requisito da CI.

**Tech Stack:** PHP ^8.3, Laravel ^13.17, Sanctum ^4, PHPUnit ^12.5; Nuxt ^4.5, pnpm 12.5.1.

**Spec:** `openspec/changes/complete-serpro-integration/specs/serpro-connection/spec.md`; decisões D1/D9 de `openspec/changes/complete-serpro-integration/design.md`; tarefas 1.2, 1.4–1.5, 2.5, 2.7–2.8, 3.3–3.4.

## Global Constraints

- Uma única credencial **da plataforma**, gravada em `serpro_connections`, nunca por Account; `contratante_numero` é extraído do certificado e precisa coincidir com ele antes de chamar a rede.
- Nunca devolver ou logar segredo, tokens, PFX, senha, caminho ou XML assinado. `Crypt::encryptString` para valores persistidos; `APP_KEY` permanece estável.
- CNPJ alfanumérico de 14 caracteres com dígitos verificadores válidos; CPF mantém sua validação numérica. Não alterar `clients.tax_id`, já `string(14)`.
- Não alterar dependências, nem endpoints fiscais, nem emitir ciência da intimação. Chamadas de teste determinístico não acessam rede.
- Mensagens e nomes de testes em português; identificadores em inglês; seguir `CONTEXT.md` e `backend/AGENTS.md` (o `.ai/rules` não existe).
- Gerar classes Laravel via `php artisan make:* --no-interaction`, testes via `php artisan make:test --phpunit Nome --no-interaction`. PHP: `vendor/bin/pint --dirty --format agent`; frontend: `pnpm lint && pnpm typecheck && pnpm test`.

## Mapa de arquivos e interfaces

| Arquivo | Responsabilidade |
| --- | --- |
| `backend/app/Services/BrazilianTaxId.php`, `backend/app/Models/Client.php`, `frontend/app/utils/taxId.ts`, `frontend/app/components/customers/ClientCreateModal.vue` | Normalização/validação, pesquisa, entrada e formatação alfanumérica; CPF sem regressão. |
| `backend/app/Services/SerproCertificateIdentity.php` | Extrair CNPJ do PFX e verificar `contratante_numero`; nunca registrar o material. |
| `backend/app/Http/Controllers/Admin/SerproConnectionController.php`, `backend/app/Http/Requests/Admin/UpsertSerproConnectionRequest.php`, `backend/app/Http/Resources/SerproConnectionResource.php` | GET de metadados para Membro da Account ou super-admin; PUT somente super-admin; upload PFX/P12 `max:2048`. |
| `backend/app/Services/SerproConnectionManager.php`, `backend/app/Policies/SerproConnectionPolicy.php` | Rotação transacional sem apagar segredo omitido, single-row e invalidar tokens. |
| `backend/app/Http/Controllers/Admin/SerproConnectivityController.php`, `backend/app/Services/SerproConnectivity.php` | POST de autenticação apenas, sem consultar cliente; quatro resultados. |
| `backend/app/Services/SerproEnvelope.php`, `backend/app/Services/SerproClient.php` | Decodificação tolerante aos dois envelopes e erro seguro. |
| `backend/tests/Fixtures/serpro/*.json`, `backend/tests/{Unit,Feature}/*Serpro*Test.php` | Fixtures sanitizadas; testes sem rede, além do trial opt-in existente. |
| `backend/routes/api.php` | GET `/api/serpro/connection` sob `auth:sanctum` com autorização de leitura; PUT conexão e POST connectivity sob `auth:sanctum,super_admin`. |

**Contrato a preservar:** `SerproClient::call(string $idSistema, string $idServico, array $dados, string $autor, string $contribuinte, ?string $procuradorToken = null, int $serviceSequence = 1): SerproResult`. O plano 2 consome `SerproConnection::current()` e os dois endpoints; os planos 3–4 consomem `SerproResult::{status,dados,mensagens,responseId,requestTag}()`.

---

### Task 1: Aceitar CNPJ alfanumérico sem relaxar o dígito verificador

**Files:** Modify `backend/app/Services/BrazilianTaxId.php`, `backend/app/Models/Client.php`, `frontend/app/utils/taxId.ts`, `frontend/app/components/customers/ClientCreateModal.vue`; Test `backend/tests/Unit/BrazilianTaxIdTest.php`, `backend/tests/Feature/Tenancy/ClientCrudTest.php`, create `frontend/tests/taxIdAlphanumeric.test.ts`.

**Interfaces:** Produces `BrazilianTaxId::normalize(string): string` em maiúsculas alfanuméricas e `isValidCnpj(string): bool`; CPF segue `isValidCpf(string): bool`.

- [ ] **Step 1: Teste vermelho.** Acrescentar ao `BrazilianTaxIdTest` um CNPJ calculado no próprio teste para base `12ABC3450001` (A=10, B=11, C=12 no cálculo `ord($char)-48`, módulo 11), e uma variante com o último dígito trocado; afirmar normalização `12.ABC.345/0001-xx → 12ABC3450001xx`, validação válida/inválida e CPF existente.

```php
$base = '12ABC3450001';
$digit = static function (string $number, array $weights): int {
    $sum = 0;
    foreach (str_split($number) as $index => $char) {
        $sum += (ord($char) - 48) * $weights[$index];
    }
    return ($sum % 11) < 2 ? 0 : 11 - ($sum % 11);
};
$first = $digit($base, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
$valid = $base.$first.$digit($base.$first, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
$this->assertTrue((new BrazilianTaxId)->isValidCnpj($valid));
$this->assertFalse((new BrazilianTaxId)->isValidCnpj(substr($valid, 0, -1).(((int) substr($valid, -1) + 1) % 10)));
```

No frontend, teste Node importa `../app/utils/taxId.ts` e exige que `formatTaxId('12ABC345000188') === '12.ABC.345/0001-88'` e CPF numérico existente mantenha a máscara; testar `canLookupCnpj('12ABC345000188') === false` e `canLookupCnpj('27865757000102') === true` em helper puro exportado do mesmo arquivo (lookup público não é fonte para CNPJ alfa).
- [ ] **Step 2: Executar o teste.** `cd backend && php artisan test --compact tests/Unit/BrazilianTaxIdTest.php`; esperado: o válido falha porque `preg_replace('/\D+/')` apaga letras.
- [ ] **Step 3: Implementar.** Usar `strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $value) ?? '')`; `isValidCpf` exige `ctype_digit`, `isValidCnpj` exige `/^[A-Z0-9]{12}[0-9]{2}$/D`, rejeita sequências repetidas, e a soma dos 12/13 caracteres usa `ord($digits[$index]) - 48` (conforme cálculo oficial alfanumérico). Em `Client::scopeSearch` reutilizar `BrazilianTaxId::normalize($term)` em vez de eliminar letras.

```php
public function normalize(string $value): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $value) ?? '');
}
// Na soma de isValidCnpj():
$sum += (ord($digits[$index]) - 48) * $weights[$index];
// Em isValidCpf(), antes do cálculo: if (! ctype_digit($digits)) { return false; }
```
- [ ] **Step 4: Provar entrada e apresentação.** Em `ClientCrudTest`, partir do payload válido já usado no teste e substituir `tax_id` pelo CNPJ calculado: POST aceita o válido e responde 422 para o inválido. Em `ClientCreateModal.vue`, trocar `state.tax_id.replace(/\D/g, '').length === 14` por `canLookupCnpj(state.tax_id)`, permitir letras no input (help “Letras e números; consulta automática apenas para CNPJ numérico”); em `taxId.ts`, formatar os doze primeiros caracteres alfanuméricos e os dois últimos dígitos; CPF numérico não muda. Não alterar `ValidCnpj`, que já delega ao serviço. Executar testes estreitos PHP e `node --test tests/taxIdAlphanumeric.test.ts`.

```ts
export function canLookupCnpj(value: string): boolean {
  return /^\d{14}$/.test(value.replace(/[.\/-]/g, ''))
}
// Em formatTaxId: /([A-Z0-9]{2})([A-Z0-9]{3})([A-Z0-9]{3})([A-Z0-9]{4})(\d{2})/i
```
- [ ] **Step 5: Commit.** `cd frontend && pnpm lint && pnpm typecheck && pnpm test`; `git add backend/app/Services/BrazilianTaxId.php backend/app/Models/Client.php backend/tests frontend/app/utils/taxId.ts frontend/app/components/customers/ClientCreateModal.vue frontend/tests/taxIdAlphanumeric.test.ts && git commit -m "fix(serpro): aceitar CNPJ alfanumérico com verificador"`.

### Task 2: Extrair a identidade do certificado e gerenciar a conexão única

**Files:** Create `backend/app/Services/SerproCertificateIdentity.php`, `backend/app/Services/SerproConnectionManager.php`, `backend/app/Policies/SerproConnectionPolicy.php`, `backend/app/Http/Requests/Admin/UpsertSerproConnectionRequest.php`, `backend/app/Http/Controllers/Admin/SerproConnectionController.php`, `backend/app/Http/Resources/SerproConnectionResource.php`; Modify `backend/app/Models/SerproConnection.php`, `backend/routes/api.php`; Test `backend/tests/Feature/SerproConnectionApiTest.php`.

**Interfaces:** `SerproCertificateIdentity::document(string $bytes, string $password): string` throws `ValidationException`; `SerproConnectionManager::save(string $key, ?string $secret, ?UploadedFile $certificate, ?string $password): SerproConnection`; `SerproConnection::assertIdentity(): void` throws `SerproException` with `DoNotRetry` on mismatch. Produces GET/PUT shapes matching `frontend/app/types/serpro.ts:169-185`.

- [ ] **Step 1: Teste vermelho.** Criar testes com `RefreshDatabase`: `test_apenas_super_admin_salva_conexao_unica`, `test_membro_le_metadados_mas_nao_edita`, `test_segredo_omitido_preserva_valor_cifrado`, `test_documento_divergente_bloqueia_sem_rede`, `test_resposta_nao_contem_segredo_ou_certificado`. Usar um PFX gerado em runtime como em `ClientCertificateTest`/`SerproClientTest`, nunca versionar PFX; `Http::preventStrayRequests()` assegura nenhuma chamada na divergência.

```php
public function test_segredo_omitido_preserva_valor_cifrado(): void
{
    $connection = SerproConnection::factory()->create();
    $before = $connection->consumer_secret_encrypted;
    $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
        ->put('/api/serpro/connection', ['consumer_key' => 'nova-chave'])
        ->assertOk()->assertJsonMissingPath('data.consumer_secret');
    $this->assertSame($before, $connection->fresh()->consumer_secret_encrypted);
}
```
- [ ] **Step 2: Executar.** `cd backend && php artisan test --compact tests/Feature/SerproConnectionApiTest.php`; esperado: 404.
- [ ] **Step 3: Implementar identidade.** `openssl_pkcs12_read($bytes, $parsed, $password)`, `openssl_x509_parse($parsed['cert'])`, extrair `subject.serialNumber` ou CNPJ do CN com separador `:`; rejeitar ausência/múltiplos CNPJs e expiração, verificar `BrazilianTaxId::isValidCnpj`, devolver string normalizada. Não confiar em `contratante_numero` fornecido na request. Não modificar o PFX durante extração.
- [ ] **Step 4: Implementar manager e API.** `DB::transaction` com lock da conexão existente; primeira gravação exige chave/segredo/certificado/senha; update só substitui campos presentes; `Crypt::encryptString($secret)` e `Crypt::encryptString($certificate->get())`; `contratante_numero = identity->document(...)`; em rotação invalidar `SerproTokenProvider::forget()`. Resource deve retornar exatamente `configured`, `consumer_key_hint`, `contracting_document`, `certificate_subject`, `certificate_serial`, `certificate_not_before`, `certificate_not_after`, `updated_at`; nunca serializar o model. GET: `auth:sanctum` + policy autoriza super-admin ou `user->current_account_id` com `accountRole` não nulo; PUT: `auth:sanctum,super_admin`, `multipart` com método PUT; connectivity também super-admin. Validar `certificate => file|extensions:pfx,p12|max:2048` e senha condicional. **`extensions` e não `mimes`**: `mimes` resolve pelo `guessExtension()`, que delega ao host a adivinhação do **conteúdo** do arquivo, e num host cuja libmagic não conhece PKCS#12 a adivinhação dá `bin` e a regra recusa todo e-CNPJ real (medido em `file-5.45`). `extensions` lê `getClientOriginalExtension()` e é determinística. O teste tem de subir um `UploadedFile` **real** sobre arquivo em disco: `UploadedFile::fake()` deriva o MIME do nome e passa em `mimes`, escondendo o defeito. Não usar o middleware tenant no GET global (a conexão não pertence à Account).

```php
// No manager, somente quando o campo foi fornecido:
if ($secret !== null && $secret !== '') {
    $connection->consumer_secret_encrypted = Crypt::encryptString($secret);
}
if ($certificate !== null) {
    $bytes = $certificate->get();
    $connection->contratante_numero = $this->identity->document($bytes, $password ?? '');
    $connection->certificate_encrypted = Crypt::encryptString($bytes);
}
// No resource: nunca usar $this->resource->toArray().
```
- [ ] **Step 5: Aplicar pré-condição antes da rede.** Em `SerproClient::call` e `SerproTokenProvider::authenticate`, chamar `assertIdentity()` antes de materializar e autenticar; o método compara o documento extraído **do PFX cifrado salvo** com `contratante_numero`. Cachear apenas identidade não secreta; 403 do provedor não substitui essa guarda.
- [ ] **Step 6: Reexecutar e commitar.** `cd backend && php artisan test --compact tests/Feature/SerproConnectionApiTest.php tests/Feature/SerproClientTest.php tests/Feature/SerproTokenProviderTest.php`; `git add backend/app backend/routes/api.php backend/tests && git commit -m "feat(serpro): gerenciar credencial única com identidade verificada"`.

### Task 3: Conectividade distingue ausência, credencial e indisponibilidade

**Files:** Create `backend/app/Services/SerproConnectivity.php`, `backend/app/Http/Controllers/Admin/SerproConnectivityController.php`; Modify `backend/app/Services/SerproTokenProvider.php`, `backend/routes/api.php`; Test `backend/tests/Feature/SerproConnectivityTest.php`.

**Interfaces:** `SerproTokenProvider::verify(): void` força autenticação com `forget()` e `pair()`; `SerproConnectivity::check(): array{ok: bool, failed_element: ?string, message: ?string, checked_at: string}`.

- [ ] **Step 1: Teste vermelho.** Em quatro casos com `Http::fake`: sem linha ⇒ `configuracao` sem chamada; PFX ausente/divergente ⇒ `certificado` sem chamada; auth 401 ⇒ `credencial`; `ConnectionException`/503 ⇒ `provedor`; auth 200 com dois tokens ⇒ `ok: true`. Para todos, `assertDatabaseCount('serpro_sync_runs', 0)` somente quando a tabela já existir; antes disso, afirmar que nenhum registro de cliente mudou. Membro de Account sem super-admin recebe 403.

```php
public function test_sem_conexao_retorna_configuracao_sem_chamar_provedor(): void
{
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create(['is_super_admin' => true]), 'sanctum')
        ->postJson('/api/serpro/connectivity')
        ->assertOk()->assertJsonPath('data.failed_element', 'configuracao');
    Http::assertNothingSent();
}
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproConnectivityTest.php`; esperado: 404.
- [ ] **Step 3: Implementar.** `check()` obtém `SerproConnection::current()`, inspeciona campos obrigatórios e validade do PFX, chama `tokens->verify()` (não gateway e não `SerproClient::call`), converte `SerproException` por `failure/status` nas quatro categorias, `checked_at = now()->toISOString()`. No provider, distinguir 4xx de 5xx antes de classificar erro; token incompleto continua `provedor` e jamais segue para o gateway. Controller fino devolve `['data' => $service->check()]`.

```php
public function verify(): void
{
    $this->forget();
    $this->pair(); // valida o par de tokens sem consultar contribuinte
}
// No controller: return response()->json(['data' => $connectivity->check()]);
```
- [ ] **Step 4: Provar e commitar.** Rodar teste específico + `SerproTokenProviderTest`; `git add backend/app backend/routes/api.php backend/tests && git commit -m "feat(serpro): testar autenticação sem tocar nos clientes"`.

### Task 4: Contrato de resposta tolerante e fixtures determinísticas

**Files:** Modify `backend/app/Services/SerproEnvelope.php`, `backend/app/Services/SerproClient.php`, `backend/tests/Feature/SerproContractFixtureTest.php`; Create `backend/tests/Fixtures/serpro/sitfis-relatorio.json`, `backend/tests/Fixtures/serpro/application-error.json`; Test `backend/tests/Unit/SerproEnvelopeTest.php`.

**Interfaces:** Preservar `SerproEnvelope::parse(array): array{status:int,response_id:?string,dados:mixed,mensagens:list<array{codigo:string,texto:string}>}`; `SerproException::{providerCode,responseId}` continua o contrato de erro.

- [ ] **Step 1: Teste vermelho.** Cobrir `dados` como JSON duplamente serializado (`json_encode(json_encode(['tipo'=>'2']))`), `tipo` numérico/textual, resposta de aplicação sem envelope, gateway `{code,message,description}` e `mensagens` vazias. A resposta de erro não pode retornar texto bruto não sanitizado do provedor.

```php
$parsed = (new SerproEnvelope)->parse([
    'status' => 200,
    'dados' => json_encode(json_encode(['tipo' => '2'], JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR),
]);
$this->assertSame(['tipo' => '2'], $parsed['dados']);
$this->assertSame([], (new SerproEnvelope)->parse(['code' => '900807'])['mensagens']);
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Unit/SerproEnvelopeTest.php tests/Feature/SerproContractFixtureTest.php`; esperado: falha no JSON duplo e no erro gateway.
- [ ] **Step 3: Implementar.** Em `parse`, decodificar `dados` enquanto string JSON válida por no máximo **duas** etapas, preservando string comum; ler `status/responseId/mensagens` também do envelope real quando presente, sem assumir tipo de `tipo`. Em `SerproClient::interpret`, usar `payload['code']` como fallback do código; mensagem de `SerproFailure::label()` nos erros de gateway, não incluir payload nem token.

```php
for ($pass = 0; $pass < 2 && is_string($raw); $pass++) {
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        break;
    }
    $raw = $decoded;
}
```
- [ ] **Step 4: Fixture e teste.** Se o trial estiver disponível, registrar a resposta de `SITFIS/RELATORIOSITFIS92` via teste opt-in existente, remover CPF/CNPJ, bearer e dados pessoais antes de salvar; para erro de aplicação, usar envelope publicado na documentação do provedor. A fixture deve conter status, `mensagens`, `responseId` e `dados` string; testar conteúdo e parsing sem rede. **Não afirmar que o trial comprova espera/304/503 do SITFIS.** Se o trial estiver indisponível, registrar somente exemplo documental com provenance no próprio teste e manter captura real como bloqueio explícito da tarefa 3.3, sem fabricar resposta observada.
- [ ] **Step 5: Provar e commitar.** `cd backend && php artisan test --compact tests/Unit/SerproEnvelopeTest.php tests/Feature/SerproContractFixtureTest.php tests/Feature/SerproClientTest.php`; `git add backend/app backend/tests && git commit -m "test(serpro): firmar decodificação e contrato de respostas"`.

### Task 5: Estados compartilhados e verificação de entrega

**Files:** Create `backend/app/Enums/{SerproConnectionState,SerproSyncRunState,SerproSyncItemState,SerproPowerOfAttorneyState,SerproAuthorizationTermState}.php`; Test `backend/tests/Unit/SerproStateTest.php`.

**Interfaces:** Backed enums: `ConnectionState` `not_configured|configured|invalid|unavailable`; `SyncRunState` `queued|running|completed|partial|failed`; `SyncItemState` `sincronizado|ignorado|falhou|indeterminado|nao_processado`; `PowerOfAttorneyState` `pending|established|rejected|expired`; `AuthorizationTermState` `ausente|pendente|validado|autenticado|vencido|recusado`. Os planos seguintes usam esses nomes exatos (prefixo `Serpro` em todos).

- [ ] **Step 1: Teste vermelho.** Em `SerproStateTest`, afirmar `SerproSyncItemState::Indeterminate->value === 'indeterminado'` e as listas exatas de valores dos cinco enums por `array_column(Enum::cases(), 'value')`.

```php
$this->assertSame('indeterminado', SerproSyncItemState::Indeterminate->value);
$this->assertSame(['queued', 'running', 'completed', 'partial', 'failed'],
    array_column(SerproSyncRunState::cases(), 'value'));
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Unit/SerproStateTest.php`; esperado: classe ausente.
- [ ] **Step 3: Implementar.** Enums de string em `backend/app/Enums`, keys TitleCase (`NotConfigured`, `Configured`, `Invalid`, `Unavailable`; `Queued` etc.); usar `->value` nos resources posteriores e casts nos models correspondentes; não duplicar valores em controllers.

```php
namespace App\Enums;
enum SerproSyncItemState: string
{
    case Synchronized = 'sincronizado';
    case Skipped = 'ignorado';
    case Failed = 'falhou';
    case Indeterminate = 'indeterminado';
    case NotProcessed = 'nao_processado';
}
```
- [ ] **Step 4: Checagem e commit.** `cd backend && vendor/bin/pint --dirty --format agent` e `php artisan test --compact`; `git add backend/app/Enums backend/tests && git commit -m "feat(serpro): definir vocabulário de estados do domínio"`.

**Saída verificável:** endpoints reais da conexão/conectividade, negação de segredo, CNPJ alfanumérico válido, respostas decodificadas e estado inerte sem credencial. Conferir `php artisan route:list --path=api/serpro` e `git diff backend/composer.json` vazio. Não acionar trial na suíte padrão.
