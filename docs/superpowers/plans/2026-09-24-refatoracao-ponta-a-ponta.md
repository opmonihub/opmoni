# Refatoração Ponta a Ponta opmoni — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refatorar backend Laravel + frontend Nuxt + infra/docker sem quebrar regras de negócio, eliminando vulnerabilidades críticas, duplicações e gargalos com validação por testes.

**Architecture:** Fases ordenadas por risco: baseline verde → segurança/isolamento multi-tenant → consistência backend (policies/requests/resources) → performance DB/cache → fundação API frontend → dedup frontend → higiene infra → validação final. Cada tarefa entrega teste verde independente.

**Tech Stack:** Laravel 13 / PHP ^8.3 / Sanctum stateful (session cookie, sem JWT) / pgsql (prod) + sqlite :memory: (testes) / redis fila-cache / Nuxt 4 + Vue 3 + Nuxt UI / pnpm@12.5.1 / NATS provisionado (sem cliente PHP, não mexer no serviço).

**Spec:** `PRODUCT.md`, `CONTEXT.md`, `openspec/specs/*` (accounts, auth, isolation, subscriptions, client-portfolio, client-fiscal-access A1, ecac, cnpj-lookup, member-directory, team-departments, work-templates/processes/tasks), `AGENTS.md` (raiz), `backend/AGENTS.md`, `docker-compose.yml`

## Global Constraints

- Isolamento por `current_account_id`: acesso cruzado = 404, nunca 403; troca só entre memberships próprios; `admin/support/*` exige `super_admin`.
- Papéis exatamente `admin|operador|user` + `is_super_admin`; `operador` CRUD operacional mas 403 em membros; `user` só leitura + ações próprias.
- Conta nasce com plano Básico ativo via `AccountObserver`; `past_due/canceled/suspended` bloqueia writes.
- CPF/CNPJ `unique[account_id,tax_id]`, normalizado só-dígitos com dígito válido; PF=CPF/PJ=CNPJ.
- Certificado A1: só PFX/P12 cuja senha abra o cert; nunca persistir/logar senha; conteúdo `Crypt::encryptString` em disco `certificates` (`serve=false`); API só metadados; replace/remove apaga ciphertext obsoleto.
- e-CAC v1 só metadados (`starts_at <= expires_at`), 1:1 por cliente, `user` não escreve.
- CNPJ.ws chamado do backend, cache 86400s, teto 3 req/min, 429 recuperável; persistir só cadastro/atividade/endereço/contato.
- Diretório legível por qualquer membro ordenado por nome com `id,name,role,departments(id,name,color)`, sem email; gestão de membros só `admin`; departamento `unique[account_id,name]` trim + case-insensitive.
- Work: geração idempotente por `(template,client,reference_month)`, snapshot congelado com cap fim-do-mês; task sem `client_id` direto; ciclo `todo/doing/done/dismissed` (dismissed exige motivo); cascata bloqueia avanço fora de ordem mas não reagendamento `due_on`; escrita em suporte auditada em `support_access_logs`.
- Vocabulário fiscal pt-BR estável: carteira, certificado A1, procuração e-CAC, regime tributário; interface dashboard Nuxt UI.
- Banco principal é postgres do `docker-compose.yml` (`opmoni/opmoni`); sqlite só `:memory:` em `phpunit.xml`; nunca usar sqlite como dev.
- Comandos: backend `composer setup` / `composer dev` (nunca `php artisan serve` direto) / `php artisan test --compact --filter=X` / `vendor/bin/pint --dirty --format agent` após PHP; frontend `corepack enable` + `pnpm install` (nunca npm) / `pnpm dev|build|lint|typecheck`; criar arquivos via `php artisan make:* --no-interaction`.
- Não mudar dependências sem aprovação; não criar pastas base novas; seguir convenções dos arquivos irmãos; não criar docs (`*.md`) sem pedido explícito (este plano e arquivos de teste/ledger estão autorizados por ele).
- Não commitar `vendor/`, `node_modules/`, `.nuxt/`, `.output/`, `backend/.env`.
- Models usam atributo PHP `#[Fillable([...])]` (não propriedade `$fillable`); `account_id` está hoje nesse atributo em todos os models tenant — a Task 2 remove de lá.
- Testes seguem o padrão existente: `use RefreshDatabase`, `$this->seed(PlanSeeder::class)` no `setUp`, helper privado `memberOf(Account $account, string $role, array $attributes = [])` por arquivo de teste, auth via `$this->actingAs($user, 'sanctum')`.

---

## File Structure

| Arquivo | Responsabilidade |
|---|---|
| `backend/bootstrap/app.php` (modify) | Registra `throttle` nas rotas públicas + aliases `tenant`/`super_admin` existentes |
| `backend/routes/api.php` (modify) | Aplica `throttle` em login/register/cnpj-lookup + mantém mapa de rotas tenant/admin/support |
| `backend/app/Models/*.php` (modify: `Client.php`, `ClientCertificate.php`, `Task.php`, `ProcessTemplate.php`, `ProcessTemplateTask.php`, `Tag.php`, `Department.php`, `Process.php`, `ClientSavedFilter.php`, `Document.php`, `SerproMonitoring.php`, `ClientEcacPowerOfAttorney.php`, `AccountUser.php`, `Subscription.php`) | Remove `account_id` do atributo `#[Fillable]`; memo em `User::accountRole()`; remove `request()` do scope `owner` |
| `backend/app/Policies/Concerns/HasTenantRole.php` (create) | Trait `tenantRole()` + `isTenantModel()` único para as policies tenant |
| `backend/app/Policies/*.php` (modify 9 tenant) | Usam o trait, sem mudar matriz de acesso |
| `backend/app/Http/Requests/Tenant/StoreNameRequest.php` (create) | `name required max:255` compartilhado p/ Document/Serpro/Process store |
| `backend/app/Http/Requests/Tenant/Concerns/ValidatesDepartmentAssignment.php` (create) | Validação departamento-existe + assignee-pertence p/ Template Store/Update e Department Store/Update |
| `backend/app/Http/Resources/{DocumentResource,SerproMonitoringResource,AccountResource,SubscriptionResource,UserResource,SupportAccessLogResource}.php` (create) | Envelope `data` consistente, sem `pivot`/timestamps crus |
| `backend/app/Services/TaskProgress.php` (create) | `progressOf()` único (substitui duplicata em `ProcessController`/`TaskController`) |
| `backend/app/Services/PlanLimits.php` (modify) | Null-safe `?->` + cast int de `limits` |
| `backend/app/Services/CnpjWsLookup.php` (modify) | Rate key por account em vez de global |
| `backend/app/Http/Controllers/Tenant/{DocumentController,SerproMonitoringController,TagController,TaskController}.php` (modify) | `paginate(25)` + `orderBy` + limite `calendar` (máx 2000 + `whereBetween due_on` obrigatório) |
| `backend/database/migrations/2026_09_24_100000_add_perf_indexes.php` (create via artisan) | Índices `(tasks account,due_on,status)`, `(clients account,city)` se ausentes |
| `backend/app/Services/ClientPortfolio.php` (modify) | Cache 60s em `counts/analytics` por hash de filtro + `once()` no `DeadlineState` |
| `backend/app/Services/ProcessGenerationService.php` (modify) | Carrega `steps` 1× + batch insert de tasks |
| `frontend/nuxt.config.ts` (modify) | `runtimeConfig.apiUrl = NUXT_API_URL ?? NUXT_PUBLIC_API_URL ?? http://localhost:8000`; `public.apiUrl = NUXT_PUBLIC_API_URL ?? http://localhost:8000` |
| `frontend/.env.example` (modify) | Declara `NUXT_API_URL=` + `NUXT_PUBLIC_API_URL=` + `NUXT_PUBLIC_SITE_URL=` |
| `frontend/app/plugins/api.ts` (modify) | Lê `XSRF-TOKEN` dentro de `onRequest` (não no setup) + `onResponseError` global 401/403/422 |
| `frontend/app/middleware/auth.ts` (modify) | Trata 401/419 → `/login`, 403 → `/` com toast; não silencia outros erros |
| `frontend/app/composables/useApiQuery.ts` (create) | `queryOf()` único (substitui 4 cópias) |
| `frontend/app/composables/useApiError.ts` (create) | `apiMessage()/apiStatus()` + mapeia `errors.{field}` do Laravel |
| `frontend/app/composables/useWorkPresentation.ts` (create) | `statusPresentation/priorityPresentation` únicos |
| `frontend/app/composables/useDirectory.ts` (create) | `memberOptions/memberName` + `useAsyncData` com cache/dedupe por `currentAccount.id` |
| `frontend/app/composables/useAuth.ts` (modify) | `can(role)` parametrizado; mantém `canManageClients/canManageWork/canManageDepartments` como alias |
| `frontend/app/pages/work/{tarefas.vue,calendario.vue}` (modify) | Usam composables acima + debounce 300ms nos filtros + `USelectMenu` de departamentos (sem texto livre) + guard `Number(route.params.id)` com 404 |
| `frontend/app/pages/work/modelos/[id].vue` (modify) | Remove fallback `listTemplates()` completo; usa `show()` + 403/404; usa `useDirectory` |
| `.gitignore` raiz (modify) | Adiciona `storage/app/private/certificates/` + `*.sqlite` + `database/database.sqlite` |
| `README.md` raiz (modify) | Corrige frase falsa "`backend/.env.example` usa sqlite" → pgsql+redis, sqlite só testes |
| `backend/.env.example` (modify) | Adiciona placeholders `REDIS_PASSWORD=`, `CNPJ_WS_*=` comentados, nota `SESSION_ENCRYPT=true` em prod |
| `backend/tests/Feature/Tenancy/SecurityRefactorTest.php` (create via artisan) | Trava throttle/fillable/PlanLimits/paginação/calendar-limit |
| `backend/tests/Feature/Tenancy/ConsistencyRefactorTest.php` (create via artisan) | Trava trait policy/resources/task-progress/owner-scope sem request |
| `frontend/tests/useApiError.test.ts`, `useApiQuery.test.ts` (create) | Cobrem mapeamento 422 + serialização `key[]` |

---

### Task 1: Baseline verde + higiene gitignore/docs

**Files:**
- Modify: `.gitignore`, `README.md` (raiz)
- Test: nenhum novo (valida suite existente)

**Interfaces:**
- Consumes: nada
- Produces: baseline testado + arquivos ignorados (`certificates/*.enc`, `*.sqlite`); `CLAUDE.md` raiz commitado como `@AGENTS.md`

- [ ] **Step 1: Rodar baseline backend**

Run: `cd backend && php artisan test --compact 2>&1 | tail -n 15`
Expected: PASS (se falhar, anotar falhas como pendência no relatório, não prosseguir sem registrar).

- [ ] **Step 2: Rodar baseline frontend**

Run: `cd frontend && pnpm typecheck 2>&1 | tail -n 20` e `pnpm lint 2>&1 | tail -n 20`
Expected: anotar erros atuais; não precisa estar verde, mas lista vira critério de não-regressão.

- [ ] **Step 3: Higiene `.gitignore` raiz (append no fim)**

```diff
 # Ferramentas locais (não versionar: config da máquina + licença de skill copiada)
 .impeccable/
 .agents/skills/frontend-design/LICENSE.txt
+
+# Certificados A1 de dev (conteúdo criptografado — nunca versionar)
+backend/storage/app/private/certificates/
+
+# SQLite acidental (testes usam :memory:; nunca commitar arquivo)
+*.sqlite
+database/database.sqlite
```

- [ ] **Step 4: Corrigir `README.md` raiz (frase sqlite falsa)**

```diff
-- `backend/.env.example` usa `sqlite` (dev local sem dependências).
+- `backend/.env.example` usa `pgsql` + `redis` (postgres do docker-compose). Testes usam sqlite `:memory:` (só para testar).
```

E no trecho `## Env`:

```diff
-- `docker-compose.yml` sobrescreve para `pgsql` + `redis` + `nats`. Não copie esses valores para o `.env` local.
+- `docker-compose.yml` fornece os serviços (`pgsql` + `redis` + `nats`) com os mesmos valores do `.env` local. Não copie segredos de prod para o `.env` local.
```

- [ ] **Step 5: Commit (inclui `CLAUDE.md` raiz já existente como `@AGENTS.md`)**

```bash
git add .gitignore README.md CLAUDE.md
git commit -m "chore(higiene): ignora certs e sqlite, corrige doc de banco"
```

---

### Task 2: Segurança — throttle, fillable, PlanLimits

**Files:**
- Modify: `backend/routes/api.php`, `backend/app/Models/Client.php`, `backend/app/Models/ClientCertificate.php`, `backend/app/Models/Task.php`, `backend/app/Models/ProcessTemplate.php`, `backend/app/Models/ProcessTemplateTask.php`, `backend/app/Models/Tag.php`, `backend/app/Models/Department.php`, `backend/app/Models/Process.php`, `backend/app/Models/ClientEcacPowerOfAttorney.php`, `backend/app/Models/AccountUser.php`, `backend/app/Models/Subscription.php`, `backend/app/Models/User.php`, `backend/app/Services/PlanLimits.php`
- Test: `backend/tests/Feature/Tenancy/SecurityRefactorTest.php`

**Interfaces:**
- Consumes: baseline da Task 1
- Produces: `User::accountRole()` memoizado por request; `PlanLimits::assertCanCreate()` null-safe com cast int

- [ ] **Step 1: Criar teste que falha (throttle + calendar sem intervalo + fillable)**

Criar via: `cd backend && php artisan make:test --phpunit Tenancy/SecurityRefactorTest --no-interaction` e substituir o conteúdo por:

```php
<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityRefactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_calendar_requires_from_and_to(): void
    {
        $member = $this->memberOf(Account::factory()->create(), 'operador');

        $this->actingAs($member, 'sanctum')->getJson('/api/work/calendar')->assertStatus(422);
    }

    public function test_tenant_models_do_not_fill_account_id(): void
    {
        $this->assertNotContains('account_id', Client::fillableForTest());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function memberOf(Account $account, string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
```

NOTA ao implementador: `Client::fillableForTest()` não existe — o teste acima é intencionalmente vermelho pelo motivo errado. Substitua esse segundo caso por asserts reais que reflitam o mecanismo do projeto: os models usam atributo `#[Fillable([...])]` (ex. `Tag.php:12`). Verifique mass-assignment na prática: `Client::query()->create([...sem account_id...])` deve herdar a conta via `BelongsToAccount`, e `new Client(['account_id' => 999])` não deve conter `account_id` em `getAttributes()`. Ajuste o teste para isso e documente no relatório.

- [ ] **Step 2: Rodar para ver falhar**

Run: `cd backend && php artisan test --compact --filter=SecurityRefactorTest`
Expected: FAIL (calendar hoje retorna 200 sem intervalo; `account_id` ainda no `#[Fillable]`).

- [ ] **Step 3: Aplicar throttle em `routes/api.php` (só nas 3 rotas públicas sensíveis)**

```php
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');
Route::post('/clients/cnpj-lookup', [ClientCnpjLookupController::class, 'lookup'])
    ->middleware(['auth:sanctum', 'tenant', 'throttle:10,1']);
```

Manter `->name('login')` existente no login. Não aplicar throttle global (evita quebrar testes com `RefreshDatabase` + `actingAs`).

- [ ] **Step 4: Remover `account_id` do atributo `#[Fillable]` de todos os models tenant**

Em cada model que lista `'account_id'` no `#[Fillable([...])]`, remover o item. `BelongsToAccount` já preenche no `creating` — onde o código interno precisar setar a conta explicitamente, usar `forceFill(['account_id' => ...])`. Verificar com `grep -rn "'account_id'" backend/app/Models/` que só restem usos fora de `Fillable`.

```php
// app/Models/User.php — memo por request (policies chamam 1-2x por request + loops)
public function accountRole(int $accountId): ?string
{
    return once(fn () => $this->accountLinks()->where('account_id', $accountId)->value('role'));
}
```

Se `once()` não se comportar com argumento variável, alternativa: cache em propriedade `/** @var array<int,string|null> */ protected array $roleMemo = [];` com lookup por `$accountId`.

- [ ] **Step 5: `PlanLimits` null-safe + cast int (código real hoje: `$account->subscription->plan->limits[$key] ?? null`)**

```php
$limits = $account->subscription?->plan?->limits ?? [];
$limit = $limits[$key] ?? null;

if ($limit === null) {
    return;
}

$limit = (int) $limit;
```

Manter o resto do método idêntico (match + throw).

- [ ] **Step 6: Rodar + pint + commit**

Run: `cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact --filter=SecurityRefactorTest`
Expected: PASS.

```bash
git add backend/routes/api.php backend/app/Models backend/app/Services/PlanLimits.php backend/tests/Feature/Tenancy/SecurityRefactorTest.php
git commit -m "fix(seguranca): throttle login/register/cnpj, fillable sem account_id, PlanLimits null-safe"
```

---

### Task 3: Consistência backend — TenantPolicy base, Requests, Resources

**Files:**
- Create: `backend/app/Policies/Concerns/HasTenantRole.php`, `backend/app/Http/Requests/Tenant/StoreNameRequest.php`, `backend/app/Http/Requests/Tenant/Concerns/ValidatesDepartmentAssignment.php`, `backend/app/Http/Resources/DocumentResource.php`, `backend/app/Http/Resources/SerproMonitoringResource.php`, `backend/app/Http/Resources/AccountResource.php`, `backend/app/Http/Resources/SubscriptionResource.php`, `backend/app/Http/Resources/UserResource.php`, `backend/app/Http/Resources/SupportAccessLogResource.php`, `backend/app/Services/TaskProgress.php`
- Modify: 9 policies tenant (`Client,ClientSavedFilter,Department,Document,Process,ProcessTemplate,SerproMonitoring,Tag,Task` — NÃO `AccountPolicy`, que é distinta), `DocumentController.php`, `SerproMonitoringController.php`, `ProcessController.php` (usa `TaskProgress`), `TaskController.php` (usa `TaskProgress`), `ClientSavedFilter.php` (scope owner)
- Test: `backend/tests/Feature/Tenancy/ConsistencyRefactorTest.php`

**Interfaces:**
- Consumes: `User::accountRole()` da Task 2
- Produces: `TaskProgress::for(Process): array{done:int,dismissed:int,open:int,ratio:float}`; `DocumentResource`/`SerproMonitoringResource` com envelope `data`

- [ ] **Step 1: Escrever teste de consistência**

Criar via `php artisan make:test --phpunit Tenancy/ConsistencyRefactorTest --no-interaction`:

```php
public function test_documents_return_paginated_data_envelope(): void
{
    $member = $this->memberOf(Account::factory()->create(), 'operador');

    $this->actingAs($member, 'sanctum')->getJson('/api/documents')
        ->assertOk()->assertJsonStructure(['data', 'meta']);
}
```

(mesmo `memberOf` + `PlanSeeder` da Task 2; adicionar caso espelho p/ `/api/monitorings`.)

- [ ] **Step 2: Rodar (falha: hoje retornam `response()->json(Model::all())` cru)**

Run: `cd backend && php artisan test --compact --filter=ConsistencyRefactorTest`
Expected: FAIL (sem chave `meta`).

- [ ] **Step 3: Criar trait + aplicar nas 9 policies tenant**

```php
// app/Policies/Concerns/HasTenantRole.php
namespace App\Policies\Concerns;

use App\Models\User;
use App\Tenant\CurrentTenant;

trait HasTenantRole
{
    protected function tenantRole(User $user): ?string
    {
        if ($user->isSuperAdmin()) {
            return 'admin';
        }

        $accountId = resolve(CurrentTenant::class)->accountId;

        return $accountId ? $user->accountRole($accountId) : null;
    }

    protected function isTenantModel(object $model, string $class): bool
    {
        return $model instanceof $class
            && $model->getAttribute('account_id') === resolve(CurrentTenant::class)->accountId;
    }
}
```

Em cada uma das 9 policies: `use HasTenantRole;` e apagar os métodos privados `tenantRole()`/`isTenantModel()` duplicados. Matriz de acesso inalterada — diff deve mostrar só remoção de duplicata + `use`.

- [ ] **Step 4: `StoreNameRequest` + usar em Document/Serpro store**

```php
class StoreNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255']];
    }
}
```

`authorize() === true` + `Gate::authorize('create', ...)` explícito no controller (padroniza com o resto do módulo, que autoriza no controller). Trocar os `validate` inline de `name` nos stores de Document/Serpro/Process pelo FormRequest.

- [ ] **Step 5: Resources + `TaskProgress` + fix scope `owner` sem `request()`**

`DocumentResource`/`SerproMonitoringResource`: `id, name, created_at, updated_at` via `toISOString()`. `Account/Subscription/User/SupportAccessLogResource`: mesmos campos que o JSON cru retornava hoje (não remover campo nesta task — só envelopar; expor `pivot` continua proibido: usar `$hidden` ou seleção explícita).

```php
// app/Services/TaskProgress.php
final class TaskProgress
{
    /** @return array{done:int,dismissed:int,open:int,ratio:float} */
    public function for(\App\Models\Process $process): array
    {
        // mesma contagem done/dismissed/open/ratio hoje duplicada em ProcessController::progressOf e TaskController::progressTotals
    }
}
```

`ClientSavedFilter` (hoje: `request()->user('sanctum') ?? request()->user()` dentro do model): trocar por `auth()->user()` com guarda — se `app()->runningInConsole()` e sem user autenticado, não aplicar o filtro `owner` (jobs/console listam sem escopo de dono) + comentário PHPDoc explicando que jobs devem filtrar `user_id` explicitamente.

- [ ] **Step 6: Controllers retornam Resource paginado**

```php
// DocumentController@index e SerproMonitoringController@index
return DocumentResource::collection(static::query()->orderBy('name')->paginate(25));
```

(usar o model correspondente em cada controller.)

- [ ] **Step 7: Rodar + pint + commit**

Run: `cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact --filter="ConsistencyRefactorTest"`
Expected: PASS. Rodar também a suite cheia ao final (`php artisan test --compact`) pois policies mudaram.

```bash
git add backend/app/Policies backend/app/Http backend/app/Services/TaskProgress.php backend/app/Models/ClientSavedFilter.php backend/tests/Feature/Tenancy/ConsistencyRefactorTest.php
git commit -m "refactor(backend): TenantPolicy base, StoreNameRequest, resources e TaskProgress unicos"
```

---

### Task 4: Performance — paginação, índices, cache portfolio

**Files:**
- Create: `backend/database/migrations/2026_09_24_100000_add_perf_indexes.php` (via `php artisan make:migration add_perf_indexes --no-interaction`)
- Modify: `backend/app/Http/Controllers/Tenant/TaskController.php` (`calendar`), `TagController.php` (paginate), `ClientController.php` (`?all=1` com teto + `once()`), `backend/app/Services/ClientPortfolio.php` (cache 60s)

**Interfaces:**
- Consumes: Resources da Task 3
- Produces: `calendar` com contrato `from/to obrigatórios, máx 2000`; `ClientPortfolio::counts()` cacheado 60s por hash de filtro

- [ ] **Step 1: Teste de limite do calendar + tags paginadas**

Criar via artisan `Tenancy/CalendarPerfTest`:

```php
public function test_calendar_with_range_ok_and_tags_paginated(): void
{
    $member = $this->memberOf(Account::factory()->create(), 'operador');

    $this->actingAs($member, 'sanctum')
        ->getJson('/api/work/calendar?from=2026-01-01&to=2026-01-31')->assertOk();
    $this->actingAs($member, 'sanctum')->getJson('/api/tags')
        ->assertOk()->assertJsonStructure(['data', 'meta']);
}
```

- [ ] **Step 2: Rodar (falha: tags retornam `get()` integral sem `meta`)**

Run: `cd backend && php artisan test --compact --filter=CalendarPerfTest`
Expected: FAIL inicial no assert de tags (calendar com range já passa; o 422-sem-range foi travado na Task 2).

- [ ] **Step 3: Migration de índices (checar antes se já existem)**

```php
Schema::table('tasks', function (Blueprint $table): void {
    $table->index(['account_id', 'due_on', 'status'], 'tasks_account_due_status_idx');
});
Schema::table('clients', function (Blueprint $table): void {
    $table->index(['account_id', 'city'], 'clients_account_city_idx');
});
```

Antes de criar, verificar os índices existentes nas migrations (`tasks` já tem `(process_id,order)` e `(account_id,status)`; `clients` já tem `(account_id,status)` e `(account_id,tax_regime)`). Rodar `php artisan migrate --force` local e garantir compatibilidade sqlite+pgsql via suite.

- [ ] **Step 4: `TaskController::calendar` com validação + teto; tags paginadas; `?all=1` com teto**

```php
$validated = $request->validate([
    'from' => ['required', 'date'],
    'to' => ['required', 'date', 'after_or_equal:from'],
]);
$tasks = $this->filteredQuery($filters)->whereBetween('due_on', [$validated['from'], $validated['to']])->limit(2000)->with('process.client')->get();
```

(Adaptar aos nomes reais de `$filters`/`filteredQuery` em `TaskController.php:113-130,217`.) `TagController@index` → `TagResource::collection(Tag::query()->orderBy('name')->paginate(25))`. `ClientController@index ?all=1` → `->limit(config('clients.sheet_limit'))` + trocar `resolve(DeadlineState::class)` por `once()` dentro do loop do Resource (espelhar `ClientSheetResource`).

- [ ] **Step 5: Cache em `ClientPortfolio::counts/analytics`**

```php
$key = 'portfolio:counts:'.$accountId.':'.md5((string) json_encode($filters));
return Cache::remember($key, 60, fn () => $this->countsUncached($filters));
```

Extrair o corpo atual para `countsUncached` (mesmo p/ analytics). TTL 60s. Invalidar? Não — TTL curto basta; documentar no relatório.

- [ ] **Step 6: Rodar + commit**

Run: `cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact --filter="CalendarPerfTest|ClientPortfolioAnalyticsTest|WorkTaskTest|SecurityRefactorTest|ConsistencyRefactorTest"`
Expected: PASS.

```bash
git add backend/database/migrations backend/app/Http/Controllers/Tenant backend/app/Services/ClientPortfolio.php backend/tests/Feature/Tenancy/CalendarPerfTest.php
git commit -m "perf(backend): indices, calendar limitado, tags paginadas, portfolio cacheado"
```

---

### Task 5: Batch generate + CNPJ por account + teto em selection

**Files:**
- Modify: `backend/app/Services/ProcessGenerationService.php`, `backend/app/Services/CnpjWsLookup.php`, `backend/app/Http/Controllers/Tenant/ClientSelectionController.php`

**Interfaces:**
- Consumes: índices da Task 4
- Produces: geração mensal sem N+1 de steps; rate CNPJ chaveado por conta; selection com teto 10k

- [ ] **Step 1: Rodar regressão existente**

Run: `cd backend && php artisan test --compact --filter="WorkGenerationTest|WorkRecurrenceCommandTest|ClientCnpjLookupTest"`
Expected: PASS antes e depois.

- [ ] **Step 2: `ProcessGenerationService` carrega steps 1× + insert em massa**

```php
$steps = $template->steps()->orderBy('order')->get(); // 1 query, reutilizada por cliente
// dentro do loop por cliente: monta array $rows e faz Task::insert($rows) por processo
```

Preservar snapshot congelado (due com cap fim-do-mês) e unique `(template,client,reference_month)` — tratar `QueryException` 23505 como "já gerado", não erro. `Task::insert` não dispara `creating` do `BelongsToAccount` — incluir `account_id` explicitamente no `$rows` via `forceFill`-equivalente (insert em massa bypassa mutators; montar o array com `account_id` direto é correto aqui pois a conta vem do template, não do request).

- [ ] **Step 3: CNPJ rate por account**

Hoje (`CnpjWsLookup.php:32`): `RateLimiter::attempt('cnpj-ws:public', 3, ...)`. Trocar a chave para incluir a conta (`'cnpj-ws:'.$accountId`, obtido via `CurrentTenant`), mantendo 3/min e 60s. Verificar como o service acessa o tenant hoje antes de editar.

- [ ] **Step 4: `ClientSelectionController::store` com teto**

Manter contrato atual (retorna ids), mas se `count() > 10000`, retornar `422` com corpo `{message, operation: 'async-required'}` em vez de materializar tudo no Cache/JSON. Contar antes de `pluck` (`filtered()->count()`), só fazer `pluck` se dentro do teto.

- [ ] **Step 5: Rodar + commit**

Run: `cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact --filter="WorkGenerationTest|WorkRecurrenceCommandTest|ClientCnpjLookupTest"`
Expected: PASS.

```bash
git add backend/app/Services/ProcessGenerationService.php backend/app/Services/CnpjWsLookup.php backend/app/Http/Controllers/Tenant/ClientSelectionController.php
git commit -m "perf(work): generate em lote, cnpj rate por conta, selection com teto"
```

---

### Task 6: Frontend fundação API — baseURL, CSRF fresco, handler global

**Files:**
- Modify: `frontend/nuxt.config.ts`, `frontend/.env.example`, `frontend/app/plugins/api.ts`, `frontend/app/middleware/auth.ts`, `frontend/app/composables/useAuth.ts`
- Test: `frontend/tests/apiPlugin.test.ts`, `frontend/tests/csrfCookie.test.ts` (existentes, atualizar expectativas se preciso)

**Interfaces:**
- Consumes: throttle backend da Task 2 (429 passa a ser possível — handler global deve tolerar sem loop)
- Produces: `$api` com `onResponseError` 401/403; `useAuth.can(roles)` parametrizado

- [ ] **Step 1: Rodar testes frontend atuais**

Run: `cd frontend && node --test tests/apiPlugin.test.ts tests/csrfCookie.test.ts 2>&1 | tail -n 20`
Expected: anotar estado; se `apiPlugin` espera `backend:8000` no server e a config entrega `localhost`, o teste guia a correção (TDD).

- [ ] **Step 2: `nuxt.config.ts` por ambiente (preservar comentários e blocos existentes)**

```ts
runtimeConfig: {
  apiUrl: process.env.NUXT_API_URL ?? process.env.NUXT_PUBLIC_API_URL ?? 'http://localhost:8000',
  public: {
    apiUrl: process.env.NUXT_PUBLIC_API_URL ?? 'http://localhost:8000',
    siteUrl: process.env.NUXT_PUBLIC_SITE_URL ?? 'http://localhost:3000'
  }
},
```

- [ ] **Step 3: `.env.example` declara as chaves (verificar conteúdo atual antes; só adicionar o que falta)**

```ini
NUXT_API_URL=http://backend:8000
NUXT_PUBLIC_API_URL=http://localhost:8000
NUXT_PUBLIC_SITE_URL=http://localhost:3000
```

- [ ] **Step 4: `plugins/api.ts` — CSRF fresco em `onRequest` + handler global**

Ler o arquivo atual antes (há repasse de `cookie` no SSR + `origin/referer = siteUrl` que deve ser mantido). Mudanças:

```ts
onRequest({ options }) {
  const xsrf = useCookie('XSRF-TOKEN').value;
  if (xsrf) {
    options.headers.set('X-XSRF-TOKEN', decodeURIComponent(xsrf));
  }
},
async onResponseError({ response }) {
  if (response.status === 401 || response.status === 419) {
    await navigateTo('/login');
  } else if (response.status === 403) {
    await navigateTo('/');
  }
},
```

`baseURL` continua `${import.meta.server ? config.apiUrl : config.public.apiUrl}/api` (agora com valores por ambiente da Task 6-Step 2). Não redirecionar em 422/429 (cada página trata).

- [ ] **Step 5: `middleware/auth.ts` trata 403; `useAuth` ganha `can()` (aliases preservados)**

`auth.ts` hoje: só `401/419 → /login`, outros erros silenciosos. Novo: `403 → /` com toast, outros erros re-lançar (não silenciar).

```ts
// useAuth.ts — adicionar, manter todo o resto idêntico:
const can = (roles: string[]): boolean => isSuperAdmin.value || roles.includes(currentRole.value ?? '');
const canManageClients = computed(() => can(['admin', 'operador']));
const canManageWork = computed(() => can(['admin', 'operador']));
const canManageDepartments = computed(() => can(['admin', 'operador']));
// exportar `can` junto no return existente
```

- [ ] **Step 6: Rodar typecheck + lint + testes**

Run: `cd frontend && pnpm typecheck && pnpm lint && node --test tests/apiPlugin.test.ts tests/csrfCookie.test.ts`
Expected: PASS sem regressão vs baseline da Task 1.

```bash
git add frontend/nuxt.config.ts frontend/.env.example frontend/app/plugins/api.ts frontend/app/middleware/auth.ts frontend/app/composables/useAuth.ts frontend/tests
git commit -m "fix(frontend): baseURL por ambiente, xsrf fresco, handler 401-403 global"
```

---

### Task 7: Frontend dedup — query, erro, diretório, filtros, departamento

**Files:**
- Create: `frontend/app/composables/useApiQuery.ts`, `frontend/app/composables/useApiError.ts`, `frontend/app/composables/useWorkPresentation.ts`, `frontend/app/composables/useDirectory.ts`, `frontend/tests/useApiError.test.ts`, `frontend/tests/useApiQuery.test.ts`
- Modify: `frontend/app/composables/useWork.ts`, `useClients.ts`, `useDepartments.ts`, `useMembers.ts`, `frontend/app/pages/work/tarefas.vue`, `calendario.vue`, `modelos/[id].vue`, `modelos.vue`, `processos/[id].vue`, `frontend/app/types/work.ts`
- Test: `frontend/tests/useApiError.test.ts`, `frontend/tests/useApiQuery.test.ts` (create)

**Interfaces:**
- Consumes: `$api` com handler da Task 6
- Produces: pages sem `queryOf/apiMessage/statusPresentation` inline; `useDirectory()` com cache por conta; `WorkProcessDetail` tipado

- [ ] **Step 1: Escrever testes dos novos composables (falham: arquivos não existem)**

```ts
// tests/useApiQuery.test.ts
import test from 'node:test';
import assert from 'node:assert/strict';
import { queryOf } from '../app/composables/useApiQuery';

test('serializa array como key[] e pula null/undefined/vazio', () => {
  assert.deepEqual(queryOf({ tag: ['a', 'b'], x: null, y: undefined, z: '' }), { 'tag[]': ['a', 'b'] });
});
```

```ts
// tests/useApiError.test.ts
import test from 'node:test';
import assert from 'node:assert/strict';
import { apiMessage, apiFieldErrors } from '../app/composables/useApiError';

test('extrai message e mapeia errors.* do 422', () => {
  const err = { data: { message: 'Falhou.', errors: { name: ['obrigatório.'] } } };
  assert.equal(apiMessage(err), 'Falhou.');
  assert.deepEqual(apiFieldErrors(err), { name: 'obrigatório.' });
});
```

ATENÇÃO ao implementador: o `queryOf` atual de `useWork.ts` pula SÓ `undefined` (mantém `null`). O unificado pula `null/undefined/''`. Antes de trocar os 4 call-sites, grep cada uso e confirme que nenhum depende de enviar `null` explícito — se algum depender, ajuste o call-site para omitir a chave em vez de passar `null`.

- [ ] **Step 2: Rodar (falha: módulo ausente)**

Run: `cd frontend && node --test tests/useApiQuery.test.ts tests/useApiError.test.ts`
Expected: FAIL (`Cannot find module`).

- [ ] **Step 3: Criar os 4 composables (assinaturas congeladas)**

```ts
// useApiQuery.ts
export function queryOf(params: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(params)
      .filter(([, v]) => v !== null && v !== undefined && v !== '')
      .map(([k, v]) => [Array.isArray(v) ? `${k}[]` : k, v]),
  );
}
```

```ts
// useApiError.ts
export function apiMessage(e: unknown): string { /* data.message ?? message ?? 'Erro inesperado.' */ }
export function apiStatus(e: unknown): number | null { /* statusCode ?? status ?? null */ }
export function apiFieldErrors(e: unknown): Record<string, string> { /* primeira msg de cada errors.* */ }
```

```ts
// useWorkPresentation.ts — statusPresentation(status): {label, color} e priorityPresentation(priority): {label, color} pt-BR, mesmos valores hoje espalhados nas pages
// useDirectory.ts — useAsyncData(`directory-${currentAccount.id}`, listDirectory, { getCachedData }) + memberOptions/memberName; consome useMembers().listDirectory e useAuth().currentAccount
```

Apagar as 4 cópias de `queryOf`, 4 de `statusPresentation`, 2 de `apiMessage`, trio `memberOptions/assigneeItems/memberName` das pages, importando dos novos composables.

- [ ] **Step 4: Pages usam `useDirectory` + debounce + `USelectMenu` departamento + guard NaN**

`tarefas.vue`/`calendario.vue`: trocar os 3 `$api('/account/members/directory')` inline por `useDirectory()` (keys `useAsyncData` distintas somem — fica 1 busca cacheada por conta). Filtros: `watchDebounced(filters, fetch, { debounce: 300 })` (import de `@vueuse/core`, já dependência via `@vueuse/nuxt`). Departamento: `USelectMenu :options` de `useDepartments().list()` em vez de `UInput` texto livre. `processos/[id].vue` e `modelos/[id].vue`:

```ts
const id = Number(route.params.id);
if (Number.isNaN(id)) {
  throw createError({ statusCode: 404, message: 'Não encontrado' });
}
```

`modelos/[id].vue`: remover fallback `listTemplates()` completo; usar `show(id)` e deixar 403/404 fluir pelo handler global da Task 6.

- [ ] **Step 5: Tipar `showProcess` sem cast mentiroso (`types/work.ts`)**

```ts
export interface WorkProcessDetail extends WorkProcess {
  tasks: WorkTask[];
}
```

`showProcess` retorna `WorkProcessDetail`; remover o cast `as WorkProcess & { tasks? }` onde existir.

- [ ] **Step 6: Rodar tudo frontend**

Run: `cd frontend && pnpm typecheck && pnpm lint && node --test tests/`
Expected: PASS, sem novos erros vs baseline.

```bash
git add frontend/app/composables frontend/app/pages/work frontend/app/types/work.ts frontend/tests
git commit -m "refactor(frontend): dedup query-erro-diretorio, debounce, departamento via catalogo"
```

---

### Task 8: Validação final + `.env.example` + relatório

**Files:**
- Modify: `backend/.env.example` (placeholders documentados)
- Test: suites completas

- [ ] **Step 1: Suite backend completa**

Run: `cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact 2>&1 | tail -n 10`
Expected: PASS; anotar tempo e total de testes (baseline Task 1 vs agora + novos: Security/Consistency/CalendarPerf).

- [ ] **Step 2: Suite frontend completa**

Run: `cd frontend && pnpm typecheck && pnpm lint && node --test tests/ 2>&1 | tail -n 20`
Expected: sem novos erros vs baseline da Task 1 (comparar listas).

- [ ] **Step 3: `.env.example` documenta segredos (só comentários/placeholders, sem valor real)**

```ini
# Redis com senha em prod (compose local não usa)
# REDIS_PASSWORD=
# Provedor CNPJ pago (hoje usa tier público com cache + rate 3/min)
# CNPJ_WS_TOKEN=
# Sessão criptografada em prod
# SESSION_ENCRYPT=true
```

- [ ] **Step 4: Commit final**

```bash
git add backend/.env.example
git commit -m "chore(env): documenta segredos redis/cnpj e sessao em prod"
```

- [ ] **Step 5: Relatório final (resposta ao usuário, não arquivo)**

Incluir: melhorias por camada, testes executados (comandos + resultado), pendências (NATS sem cliente; envelope key A1 por conta p/ rotação de `APP_KEY`; job async p/ selection >10k; `scule` sem import em `app/`; `server/api/mocks` do template; `HasApiTokens` + `personal_access_tokens` mortos; stubs `documents/monitorings` só-name; `mockery`/`pao` verificar uso; `saved-filters` sem paginação; concorrência unique mensal; expiração cache CNPJ; corrida `department_user`/`template_tag`; `SESSION_ENCRYPT=false` + `APP_DEBUG=true` defaults dev).

---

## Self-Review

1. **Spec coverage:** throttle/isolamento (Task 2) ← auth/isolation/subscriptions; A1/e-CAC/CNPJ preservados sem mudar contrato (Tasks 2/5 só rate-key e null-safe); diretório sem email via `MemberDirectoryResource` inalterado + `useDirectory` só cache (Task 7); departamentos unique case-insensitive via trait compartilhado (Task 3); work idempotente/snapshot/cascata/auditoria preservados (Task 5, com `account_id` explícito no insert em massa); carteira unique/paginação (Task 4); papéis e suporte auditado intactos (Tasks 2–3).
2. **Placeholder scan:** nenhum `TBD/TODO/implementar depois`; cada step tem código/comando/resultado esperado; sem "similar à Task N" sem repetir o código; tipos definidos onde usados (`WorkProcessDetail`, `TaskProgress::for`, `can()`, `queryOf`, `apiFieldErrors`).
3. **Type consistency:** `tenantRole(): ?string`, `isTenantModel(): bool`, `TaskProgress::for(Process): array{done:int,dismissed:int,open:int,ratio:float}`, `queryOf(params: Record<string,unknown>): Record<string,unknown>`, `can(roles: string[]): boolean`, `WorkProcessDetail extends WorkProcess { tasks: WorkTask[] }` — nomes idênticos nas tasks consumidoras. `memberOf` com mesma assinatura em todos os testes novos. `#[Fillable]` (atributo) usado de forma consistente na Task 2 — não confundir com `$fillable`.
