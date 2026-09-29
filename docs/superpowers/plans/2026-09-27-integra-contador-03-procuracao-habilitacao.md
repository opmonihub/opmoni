# Integra Contador — Procuração e Habilitação Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Determinar, sem conceder procurações pelo produto, quais serviços cada cliente PJ permite consultar e habilitar/desabilitar a integração por Account.

**Architecture:** Estender a procuração e-CAC existente com metadados do provedor e uma relação `(account_id, client_id, family)` com código, estado e expiração. Um serviço lê o oracle `PROCURACOES/OBTERPROCURACAO41` e mapeia nomes e-CAC conhecidos para famílias; nome desconhecido fica não autorizado. Outro serviço consulta elegibilidade por família; o flag de Account vive em `settings` sem tocar na credencial compartilhada.

**Tech Stack:** Laravel ^13.17, PHP ^8.3, PHPUnit ^12.5, Nuxt ^4.5 e pnpm 12.5.1; sem dependências novas.

**Spec:** `openspec/changes/complete-serpro-integration/specs/client-fiscal-access/spec.md`, `openspec/changes/complete-serpro-integration/specs/serpro-connection/spec.md` (procuração e habilitação), decisões D4/D8 do `design.md`, tarefas 5.1–5.5. Executar após planos 01 (transporte seguro) e 02 (termo/token).

## Global Constraints

- Procuração e-CAC é o registro feito pelo Membro; autorização de família é **observada** no provedor. Nunca criar outorga no SERPRO nem converter ausência de resposta em `established`.
- `PROCURACOES/OBTERPROCURACAO41` não depende de outra procuração; para as demais famílias, exigir estado `established` + datas válidas + token vigente do termo.
- Código `00146` cobre PGDASD **e** DEFIS, sem duplicar outorga; strings alfanuméricas permanecem texto. PF não é elegível; datas da procuração existente permanecem intactas em recusa.
- Modelos da Account usam `BelongsToAccount`, mas todo job/query sem middleware impõe `account_id` explicitamente; role `admin` para enablement, `admin|operador` para edição da procuração, `user` leitura.
- Mensagens/testes em português; seguir `CONTEXT.md`/`backend/AGENTS.md`; `vendor/bin/pint --dirty --format agent`, `php artisan test --compact`; frontend `pnpm lint && pnpm typecheck && pnpm test`.

## Mapa de arquivos e interfaces

| Arquivo | Responsabilidade |
| --- | --- |
| `backend/database/migrations/*_add_serpro_state_to_client_ecac_powers_of_attorney.php`, `*_create_serpro_client_authorizations_table.php` | Colunas nullable na procuração; unicidade `(account_id,client_id,family)`. |
| `backend/app/Models/ClientEcacPowerOfAttorney.php`, `backend/app/Models/SerproClientAuthorization.php`, factory, `backend/app/Models/Client.php` | Relações e casts do estado/validade. |
| `backend/app/Services/SerproPowerNames.php` | Tabela revisável `nome e-CAC → código de família`; match somente de nomes comprovados, desconhecido ≠ autorizado. |
| `backend/app/Services/SerproPowerOracle.php`, `backend/app/Services/SerproEligibility.php` | Consulta ao provedor e atualização transacional; consulta read-only de elegibilidade por serviço. |
| `backend/app/Http/Requests/Tenant/UpsertClientEcacPowerOfAttorneyRequest.php`, `backend/app/Http/Resources/{ClientEcacPowerOfAttorneyResource,ClientResource}.php` | Escrita e leitura de código/estado não secretos. |
| `backend/app/Services/SerproAccountEnablement.php`, `backend/app/Http/Controllers/Tenant/SerproAccountEnablementController.php`, `backend/routes/api.php` | GET/PUT `/api/serpro/enablement` no grupo `auth:sanctum,tenant`; escrita só `admin`. |
| `frontend/app/composables/useSerpro.ts`, `frontend/app/pages/monitoring/termos.vue` | Botão de habilitação no contexto do Account, visível só a admin; backend continua impondo 403. |

**Interfaces produzidas:** `SerproPowerOracle::refresh(int $accountId, int $clientId): void`; `SerproEligibility::for(int $accountId, int $clientId, string $family): array{eligible:bool,reason:?string,expires_on:?string}`; `SerproAccountEnablement::enabled(int $accountId): bool`; `::set(int $accountId, bool $enabled): void`. Planos 04/05 usam estes métodos sem confiar no scope condicional de `CurrentTenant`.

---

### Task 1: Persistir estado e autorizações por família sem perder procurações existentes

**Files:** Create duas migrations, `backend/app/Models/SerproClientAuthorization.php`, `backend/database/factories/SerproClientAuthorizationFactory.php`; Modify `backend/app/Models/{Client,ClientEcacPowerOfAttorney}.php`, `backend/app/Http/Requests/Tenant/UpsertClientEcacPowerOfAttorneyRequest.php`, `backend/app/Http/Resources/{ClientResource,ClientEcacPowerOfAttorneyResource}.php`; Test `backend/tests/Feature/Tenancy/ClientEcacPowerOfAttorneyTest.php`, `backend/tests/Feature/SerproPowerSchemaTest.php`.

**Interfaces:** `SerproClientAuthorization` fields `account_id,client_id,family,code,state,expires_on,verified_at`; `Client::serproAuthorizations(): HasMany`; `ClientEcacPowerOfAttorney::{serpro_code,integration_state}` nullable; estado usa `SerproPowerOfAttorneyState` do plano 01.

- [ ] **Step 1: Teste vermelho.** GET cliente com procuração retorna `serpro_code` e `integration_state` sem arquivo/caminho/credencial; PUT com código novo mantém `starts_at/expires_at`; PUT sem código preserva antigo; `user` 403; `assertSame('00146', $authorization->code)` para PGDASD/DEFIS; `Schema::hasColumn`/`assertDatabaseHas` confirmam índice único.

```php
$this->actingAs($operator, 'sanctum')->putJson("/api/clients/{$client->id}/ecac-power-of-attorney", [
    'starts_at' => '2026-09-01', 'expires_at' => '2027-09-01', 'serpro_code' => '00146',
])->assertOk()->assertJsonPath('data.ecac_power_of_attorney.serpro_code', '00146');
$this->assertDatabaseHas('client_ecac_powers_of_attorney', ['client_id' => $client->id, 'integration_state' => 'pending']);
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproPowerSchemaTest.php tests/Feature/Tenancy/ClientEcacPowerOfAttorneyTest.php`; esperado: campo/índice ausente.
- [ ] **Step 3: Schema e models.** Migration agrega `serpro_code string(32) nullable`, `integration_state string(24) nullable`; tabela `serpro_client_authorizations` FK account/client, `family string(48)`, `code string(16)`, `state string(24)`, `expires_on date nullable`, `verified_at timestamp nullable`, `unique(account_id,client_id,family)` e índices por Account. Model usa `BelongsToAccount` e enum cast; ao receber novo código manual, marcar `Pending`, nunca `Established` sem consulta ao oracle. Resource expõe só código/estado/datas.

```php
Schema::create('serpro_client_authorizations', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('account_id')->constrained()->cascadeOnDelete();
    $table->foreignId('client_id')->constrained()->cascadeOnDelete();
    $table->string('family', 48);
    $table->string('code', 16);
    $table->string('state', 24);
    $table->date('expires_on')->nullable();
    $table->timestamp('verified_at')->nullable();
    $table->timestamps();
    $table->unique(['account_id', 'client_id', 'family']);
});
```
- [ ] **Step 4: Verificar/commitar.** Testes específicos, migration `down()` reversível por inspeção (não rodar rollback num DB de dados); `git add backend/app backend/database backend/tests && git commit -m "feat(serpro): registrar procuração por família e estado observado"`.

### Task 2: Ler nomes e-CAC do oracle e negar por padrão nomes desconhecidos

**Files:** Create `backend/app/Services/SerproPowerNames.php`, `backend/app/Services/SerproPowerOracle.php`, `backend/tests/Fixtures/serpro/procuracao-familias.json`; Test `backend/tests/Feature/SerproPowerOracleTest.php`, `backend/tests/Unit/SerproPowerNamesTest.php`.

**Interfaces:** `SerproPowerNames::familiesFor(string $systemName): array` retorna código(s) **somente para nomes comprovados**; `SerproPowerOracle::refresh(int,int): void` chama `SerproClient::call('PROCURACOES','OBTERPROCURACAO41', ['outorgante'=>$clientTaxId,'tipoOutorgante'=>'2','outorgado'=>$accountDocument,'tipoOutorgado'=>'2'], $accountDocument, $clientTaxId, $termToken)`. O exemplo publicado contém JSON de entrada malformado; usar o **quadro de campos** como contrato.

- [ ] **Step 1: Teste vermelho.** Fixture sanitizada do serviço inclui `sistemas[]`, data de expiração e nomes textuais; testar nomes de exemplo `Caixa Postal - Mensagens` (`00006`) e `Caixa Postal - Termo de Opção pelo Domicílio Tributário Eletrônico` (`00050`); nome não mapeado devolve `[]`, não ganha permissão. Cobrir expiração e `00146` para PGDASD/DEFIS num mapeamento verificado.

```php
$map = new SerproPowerNames;
$this->assertSame(['00006'], $map->familiesFor('Caixa Postal - Mensagens'));
$this->assertSame(['00050'], $map->familiesFor('Caixa Postal - Termo de Opção pelo Domicílio Tributário Eletrônico'));
$this->assertSame([], $map->familiesFor('Outro sistema não documentado'));
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproPowerOracleTest.php tests/Unit/SerproPowerNamesTest.php`; esperado: classes ausentes.
- [ ] **Step 3: Implementar.** Fixture vem da página [Obter procuração (10/04/2026)](https://apicenter.estaleiro.serpro.gov.br/documentacao/api-integra-contador/pt/solucoes/integra-procuracoes/procuracoes/servicos/obter_procuracao/) (não do trial como prova de autorização real): resposta contém `dados` string de array de `{dtexpiracao:"aaaaMMdd",nrsistemas,sistemas:[nomes]}`; no teste registrar o par nome/código e data da publicação. `SerproPowerNames` mantém array com nomes conhecidos normalizados por `trim`/espaços, sem fuzzy match; preencher novas famílias `00060,00002,00146,00103,00004` **apenas** quando os nomes estiverem comprovados pelo payload/documentação. `refresh` usa `account_id`/`client_id` explícitos, atualiza via `updateOrCreate`, marca como `Expired` ou `Rejected` autorizações ausentes/recusadas sem apagar datas da procuração manual. `-022` não gera retentativa.

```php
private const KNOWN = [
    'Caixa Postal - Mensagens' => ['00006'],
    'Caixa Postal - Termo de Opção pelo Domicílio Tributário Eletrônico' => ['00050'],
];
public function familiesFor(string $systemName): array
{
    return self::KNOWN[preg_replace('/\s+/u', ' ', trim($systemName))] ?? [];
}
// No updateOrCreate, a chave inclui account_id + client_id + family.
$dados = ['outorgante' => $client->tax_id, 'tipoOutorgante' => '2',
    'outorgado' => $certificate->document, 'tipoOutorgado' => '2'];
```
- [ ] **Step 4: Provar/commitar.** `Http::preventStrayRequests()` nos testes, testar outra Account não altera linhas; `git add backend/app/Services backend/tests && git commit -m "feat(serpro): consultar habilitações por família no e-CAC"`.

### Task 3: Calcular elegibilidade com datas, PF e termo sem consulta inesperada

**Files:** Create `backend/app/Services/SerproEligibility.php`; Test `backend/tests/Feature/SerproEligibilityTest.php`.

**Interfaces:** `SerproEligibility::for(int $accountId, int $clientId, string $family): array{eligible:bool,reason:?string,expires_on:?string}`. Códigos exatos: `pessoa_fisica`, `sem_procuracao`, `procuracao_invalida`, `sem_termo`, `null` (válido). Para `PROCURACOES`, basta termo/Account; não exige procuração da família.

- [ ] **Step 1: Teste vermelho.** `Carbon::setTestNow` e factories: PF, PJ sem linha, linha `Pending`/`Rejected`/`Expired`, começa amanhã, expira ontem, expira hoje, vence em 30 dias (`eligible=true` e `expires_on` informado), outra família estabelecida (não empresta permissão), `PGDASD` e `DEFIS` mapeiam ambos para `00146`, sem token ⇒ `sem_termo`; nenhuma chamada HTTP em `for()`.

```php
Carbon::setTestNow('2026-09-27 12:00:00');
$this->assertSame(['eligible' => false, 'reason' => 'sem_procuracao', 'expires_on' => null],
    $eligibility->for($account->id, $client->id, '00002'));
Http::assertNothingSent();
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproEligibilityTest.php`; esperado: classe ausente.
- [ ] **Step 3: Implementar.** `Client::where('account_id',$accountId)->findOrFail($clientId)`; `ClientPersonType::Company` para PJ; para `PROCURACOES` ignorar a relação de famílias; demais serviços consultam `SerproClientAuthorization::where('account_id',$accountId)->where('client_id',$clientId)->where('family',$family)->first()`, estado e intervalo `[starts_at,expires_on]` da procuração e termo via `SerproTermManager::validToken($accountId)`; devolver reason sem escrever no DB.

```php
$client = Client::query()->where('account_id', $accountId)->findOrFail($clientId);
if ($client->person_type !== ClientPersonType::Company) {
    return ['eligible' => false, 'reason' => 'pessoa_fisica', 'expires_on' => null];
}
$authorization = SerproClientAuthorization::query()
    ->where('account_id', $accountId)->where('client_id', $clientId)->where('family', $family)->first();
```
- [ ] **Step 4: Provar/commitar.** `cd backend && php artisan test --compact tests/Feature/SerproEligibilityTest.php`; `git add backend/app/Services/SerproEligibility.php backend/tests && git commit -m "feat(serpro): calcular elegibilidade por família sem cruzar Accounts"`.

### Task 4: Habilitar Account sem tocar no histórico ou no segredo compartilhado

**Files:** Create `backend/app/Services/SerproAccountEnablement.php`, `backend/app/Http/Controllers/Tenant/SerproAccountEnablementController.php`, `backend/app/Http/Requests/Tenant/UpdateSerproEnablementRequest.php`; Modify `backend/routes/api.php`, `backend/app/Models/Account.php`; Test `backend/tests/Feature/SerproAccountEnablementTest.php`.

**Interfaces:** GET `/api/serpro/enablement` ⇒ `{"data":{"enabled":false}}`; PUT body `{ "enabled": true|false }` ⇒ mesmo envelope. `enabled(int): bool` é false para chave ausente; `set(int,bool): void` preserva demais chaves de `Account.settings`.

- [ ] **Step 1: Teste vermelho.** `admin` habilita com conexão utilizável; sem conexão PUT true responde 422 e não grava; `operador`/`user` PUT ⇒ 403; GET de qualquer Membro; PUT false não apaga dados gravados; update de `settings` conserva outras chaves e não afeta outra Account.

```php
$this->actingAs($operator, 'sanctum')->putJson('/api/serpro/enablement', ['enabled' => true])->assertForbidden();
$this->actingAs($admin, 'sanctum')->putJson('/api/serpro/enablement', ['enabled' => false])
    ->assertOk()->assertJsonPath('data.enabled', false);
$this->assertSame('preservado', $account->fresh()->settings['other_key']);
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproAccountEnablementTest.php`; esperado: 404.
- [ ] **Step 3: Implementar.** Controller resolve `CurrentTenant::accountId`, valida `boolean` em Form Request e exige `admin` via `accountRole`/`isSuperAdmin`; service faz lock da linha `Account`, mescla `settings` com `['serpro_enabled'=>$enabled]`, exige `SerproConnection::current()` íntegra antes de `true`; leitura sempre autorizada por tenant. Não alterar `SerproConnection` nem revogar token do termo ao desligar.

```php
public function enabled(int $accountId): bool
{
    return (bool) (Account::query()->findOrFail($accountId)->settings['serpro_enabled'] ?? false);
}
// Dentro da transação de set():
$account = Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
$account->settings = [...($account->settings ?? []), 'serpro_enabled' => $enabled];
$account->save();
```
- [ ] **Step 4: Provar/commitar.** `cd backend && php artisan test --compact tests/Feature/SerproAccountEnablementTest.php`; `git add backend/app backend/routes/api.php backend/tests && git commit -m "feat(serpro): habilitar integração por Account"`.

### Task 5: Expor habilitação ao Admin do Account

**Files:** Modify `frontend/app/composables/useSerpro.ts`, `frontend/app/pages/monitoring/termos.vue`; Create `frontend/app/utils/serproEnablement.ts`, `frontend/tests/monitoringTermGuidance.test.ts`.

**Interfaces:** `useSerpro().enablement(): Promise<{enabled:boolean}>`, `setEnablement(enabled:boolean): Promise<{enabled:boolean}>`; GET/PUT sem `/api` no call site. `enablementNotice(enabled: boolean): string` é função pura testável em Node.

- [ ] **Step 1: Teste vermelho.** `node --test` importa `../app/utils/serproEnablement.ts`: `enablementNotice(false) === 'Integração desabilitada para este Account'`; `enablementNotice(true) === 'Integração habilitada'`. Não importar `.vue` no Node.

```ts
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { enablementNotice } from '../app/utils/serproEnablement.ts'
test('desabilitação nomeia o Account sem apagar histórico', () => {
  assert.equal(enablementNotice(false), 'Integração desabilitada para este Account')
})
```
- [ ] **Step 2: Rodar.** `cd frontend && node --test tests/monitoringTermGuidance.test.ts`; esperado: helper ausente.
- [ ] **Step 3: Implementar.** Adicionar chamadas GET/PUT no composable; em `termos.vue` exibir status e botão de habilitação **só** para `useAuth().canManageMembers` (`admin`), com confirmação para desligar, toast para 403/422 e `reload()` após sucesso. Não colocar controle em `admin/serpro.vue`: este é o painel da plataforma, não do Account.

```ts
export function enablementNotice(enabled: boolean): string {
  return enabled ? 'Integração habilitada' : 'Integração desabilitada para este Account'
}
async function setEnablement(enabled: boolean) {
  const res = await $api<{ data: { enabled: boolean } }>('/serpro/enablement', { method: 'PUT', body: { enabled } })
  return res.data
}
```
- [ ] **Step 4: Verificar/commitar.** `cd frontend && pnpm lint && pnpm typecheck && pnpm test`; `git add frontend/app frontend/tests && git commit -m "feat(serpro): controlar habilitação no Account"`.

**Saída verificável:** Account desligado nunca é considerado elegível para disparo posterior, sem apagar histórico. Rodar `cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact` e frontend completo. Não alegar que a tabela nome→família cobre nomes ainda não observados: mantê-los explicitamente recusados até evidência oficial.
