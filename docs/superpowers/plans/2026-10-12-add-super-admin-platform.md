# Super Admin Platform Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver all seven Super Admin pages with working platform APIs, commercial metadata, audited support access, and secure Serpro authentication.

**Architecture:** Extend the existing Nuxt-to-Laravel access boundary and additive PostgreSQL schema; retain the foundational provisioning actions and session fencing. Support is an explicit session context reconciled against a persisted lifecycle, never an implicit tenant bypass. Serpro is a separate credential/certificate module with authentication-only provider calls.

**Tech Stack:** Installed PHP 8.4.26, Laravel 13.35.0, PHPUnit 12.5.39, Guzzle 8.2.0, OpenSSL 3.0.22 and cURL; Nuxt 4.6.0, Vue 3.5.43, Nuxt UI 4.11.3, TypeScript, existing node:test runner; PostgreSQL 18 and Redis 8.

**Spec:** Read `openspec/changes/archive/2026-10-20-add-super-admin-platform/proposal.md`, `design.md`, `tasks.md`, and all five `specs/*/spec.md` together. Compatibility baseline: `openspec/specs/{auth,accounts,isolation,access-pages}/spec.md` and `openspec/changes/archive/2026-10-12-add-access-accounts-foundation/`. Presentation inventory: `docs/architecture/super-admin-frontend-map.md` and `/home/obsidian/dev/legacy/frontend/app`.

## Global Constraints

- “All planning files and implementation identifiers are English; UI copy is pt-BR.”
- “Existing dirty work must be preserved.”
- “There is no Inertia runtime.”
- “No payment processing or operational quota enforcement is introduced.”
- “No raw session IDs are stored.”
- “The database and Redis session are separate commit domains: no distributed atomicity is claimed.”
- “Native PHP multipart parsing uses POST transport plus `_method=PUT` for certificate/connection writes; the resolved public API method remains PUT.”
- “Enforce 2 MiB file / 4 MiB total request bounds with 413 for oversized requests.”
- “Provider connect timeout is 3 seconds and total request timeout 8 seconds by default.”
- “Proxy timeout is 15 seconds for `/api/v1/serpro/*` and contracting-certificate writes; unrelated access endpoints keep 10 seconds.”
- “The existing 30-second session lock remains longer than normal provider work; assert ownership before/after the action and deny success if lost.”
- “Endpoint tests use fakes; no paid/service provider calls run during routine verification.”
- “No automatic retries on credential refusal.”
- “Identity SHALL preserve its existing top-level envelope.”
- “UI labels and feedback SHALL be in Brazilian Portuguese and preserve the mapped Nuxt UI presentation.”
- Existing `access.admin-list`, `access.admin-create`, `access.admin-update`, and `access.admin-reissue` route names and issuance envelopes remain compatible. Member roles are `admin|operador|user` in this checkout.
- Follow `backend/AGENTS.md`; use Artisan generators and explicit PHP signatures. Do not change dependencies without authorization. No legacy credential/data migration, manual password assignment, user CRUD/promotion, operational modules, or certificate download/delete routes.

## Review Focus

1. Redis save failure after a committed support transition: unmatched or closed lifecycle references must never grant support; test in Tasks 5 and 6.
2. Suspension or Super Admin revocation between requests, including exit/logout: effective tenant access must stop, restoration must remain safe, and logout must remain revocation-safe; test in Tasks 5, 6, and 14.
3. Supplied empty PKCS#12 password, unusual certificate MIME, and conflicting CNPJ representations: distinguish missing from empty, parse real bytes, and reject ambiguity without disclosure; test in Tasks 8 and 11.
4. Credential/source replacement while connectivity waits: never report an old result as current or leave temporary material; test in Tasks 9 and 10.
5. Old list/identity responses and refresh failure after success: retain the latest successful state, reset tenant data on context changes, and recover an out-of-range page; test in Tasks 12–15.

---

## Baseline and execution rules

This plan was prepared against the current `/home/obsidian/dev/opmoni` checkout, not the older Inertia tenancy worktree. Backend/frontend/source-foundation files are already dirty or untracked. Only this plan is written during planning. Do not restore, clean, stage wholesale, or treat those existing changes as this implementation's changes.

The proposal/design still describe canonical specs as empty; live inspection shows the access foundation already archived and its four canonical specs present. Use those live compatibility sources; do not undo the archive. No scope/contract decision remains open.

The five capabilities share Account resources, identity, ingress, and the same seven-page deliverable. Keep one ordered integration plan, with independently reviewable tasks, rather than parallel plans that would define those interfaces differently. Dependencies: 1 → 2 → 3 → 4; 3 → 5 → 6 → 7; 3 → 8 → 9 → 10 → 11; 1/7/11 → 12 → 13 → 14 → 15 → 16. Do not release incomplete pages or enable support before Task 6 passes.

Before implementation, record `git status --short`, installed versions, routes, and runtime capabilities; read any newly applicable rules. If execution needs isolation, use using-git-worktrees at execution time and explicitly carry the current foundation into that checkout; a worktree at HEAD alone lacks this uncommitted baseline. Never stash it away implicitly. Keep an execution ledger alongside this plan recording task checks, commits, baseline file ownership, and race/browser evidence. Update OpenSpec checkboxes only after the corresponding evidence passes.

All commands below run from the repository root unless prefixed by a container/workdir. Backend commands use `docker compose exec -T backend`; frontend commands use `docker compose exec -T frontend` (container working directory `/app`). Backend feature tests use their existing isolated SQLite/array config. Integration tests use `phpunit.pgsql.xml`, exclusively `opmoni_test` and Redis databases 13/14/15; preserve bootstrap safety assertions before any reset. Do not run integration fixtures in `opmoni` or Redis development databases. Browser/proxy integration uses a test-configured app pair attached to those test resources, never the normal development database.

Commit steps mean review and stage only the named task hunks/new files. Existing untracked baseline files may need a separately identified baseline commit before task commits; never silently bundle their unrelated content. Every command below expects exit 0 and no failing assertions unless the step explicitly expects RED.

## File structure and boundary decisions

| Area | Files and responsibility |
| --- | --- |
| Contracts | `frontend/shared/types/admin.ts`, `support.ts`, `serpro.ts`; additive `access.ts`; pure shared utilities tested with node:test |
| Commercial persistence | `backend/app/Models/Plan.php`, `Subscription.php`, additive Account contacts; `PlanCatalogSeeder`, `EnsureAccountSubscription`, `InitializePlatform` |
| Platform API | Controllers under `backend/app/Http/Controllers/Api/V1/Admin/`, requests under `backend/app/Http/Requests/Admin/`, resources under `backend/app/Http/Resources/Admin/`; maintain existing Account route names |
| Support | `SupportContext`, `SupportAccessLog`; `Actions/SupportAccess`, `Support/SupportAudit`, `SupportWriteDescriptor`; extend existing identity/session/resolver files |
| Certificates | `AccountContractingCertificate`, `SerproConnection`; `Support/Serpro/ValidatedCertificate`, `ValidatePkcs12`, `CertificateDocument`, `CertificateMaterializer`, `ConnectionSnapshot`; lifecycle actions remain separate from HTTP |
| Serpro HTTP | `Actions/CheckSerproConnectivity`, `Support/Serpro/SerproAuthenticator`, `config/serpro.php`; safe Resources under `Http/Resources/Serpro/` |
| UI shell | `pages/admin.vue`, `utils/adminNav.ts`, existing default layout/guard/focus plugin, shared table components from the mapped inventory |
| UI pages | `admin/{index,contas,planos,assinaturas,usuarios,suporte,serpro}.vue`; focused Account/commercial/Serpro form components under existing `app/components/` |
| Tests | Feature tests per API capability; shared PHPUnit access fixture concern; generated certificate fixtures; independent-process integration classes; frontend pure behavior tests and a real-ingress multipart test |

Migration prefixes below are explicit unique planning names, after existing `2026_10_12_*` migrations. Generate with Artisan and rename to the specified basename before committing. Each new model gets its named factory. Do not add demonstration seeders.

## Task 1: Freeze additive contracts and preserve the access baseline

**Files:** Create `frontend/shared/types/{admin,support,serpro}.ts`, `frontend/tests-admin-contracts.test.ts`, `backend/tests/Concerns/InteractsWithAccess.php`. Modify `frontend/shared/types/access.ts`, `frontend/tests-access.test.ts`, `frontend/package.json`, `backend/tests/Feature/AccessFoundationTest.php` only for compatible fixtures/regression assertions.

**Interfaces:** Produce `PageMeta={page:number,per_page:number,total:number,last_page:number}`, `Resource<T>={data:T}`, `Page<T>={data:T[],meta:PageMeta}`, `Plan`, `Subscription`, `PlatformUser`, `SupportLog`, `PlatformSummary`, `SerproConnectionMetadata`, `CertificateMetadata`, `ConnectivityResult`. `Membership.is_member` is literal `true`; `SupportAccount` has `is_member:false,role:'admin'`; `Identity.current_account:Membership|SupportAccount|null` and `support:SupportMetadata|null`. Keep `PlatformAccount` exported from `access.ts` with added contact/subscription fields and existing issuance shape.

- [ ] **Step 1: Add the failing typed fixtures and access regression assertions.**

```ts
// test: additive admin and support shapes preserve issuance and identity envelopes
const meta: PageMeta = { page: 1, per_page: 15, total: 0, last_page: 1 }
assert.deepEqual(Object.keys(meta), ['page', 'per_page', 'total', 'last_page'])
const supported: SupportAccount = { id: 9, name: 'Office', status: 'active', role: 'admin', is_member: false }
assert.equal(supported.is_member, false)
// Include `satisfies` fixtures for all seven endpoint outputs; no secrets in metadata types.
```

In `AccessFoundationTest`, add `test_subscription_preparation_preserves_access_baseline`: run existing login/issue/reissue/activation flow and assert exact stored credential/profile values, seven-day expiry, default selection by membership ID, no membership before acceptance, suspension rejection, and unchanged absolute deadline on switching. `InteractsWithAccess` supplies `loginAccess(User $user): void`, `csrfToken(): string`, and trusted-ingress set-up following the existing test; never print cookies/tokens.

- [ ] **Step 2: Run RED:** `docker compose exec -T frontend pnpm exec nuxt typecheck`; expect missing additive type exports. Run `docker compose exec -T backend php artisan test --compact tests/Feature/AccessFoundationTest.php tests/Feature/TenantFoundationTest.php`; baseline regressions must pass before any schema/context work.
- [ ] **Step 3: Implement the type interfaces.** Plan `limits` is exactly `{users:number|null,clients:number|null,monitorings:number|null}`; subscription status is `active|past_due|canceled`; user `account_links` are real Account ID/name/status/role projections. Connectivity failure element is `null|'configuracao'|'certificado'|'credencial'|'provedor'`. Metadata field names follow the Serpro spec exactly. Update all existing Identity/Membership test fixtures for required additive fields. Expand the frontend test script to `node --experimental-strip-types --test tests-*.test.ts`.
- [ ] **Step 4: Run GREEN:** frontend `pnpm test` and `pnpm typecheck`, plus the two backend suites above; expect all fixtures/regressions passing.
- [ ] **Step 5: Commit task-owned changes:** `git commit -m "test: freeze additive platform contracts and access regressions"`.

## Task 2: Add the catalog and atomic subscription provisioning

**Files:** Create migrations `backend/database/migrations/2026_10_13_000001_add_platform_contacts_to_accounts.php`, `000002_create_plans_table.php`, `000003_create_subscriptions_table.php` (same date prefix); `backend/app/Models/{Plan,Subscription}.php`, `backend/database/factories/{Plan,Subscription}Factory.php`, `backend/database/seeders/PlanCatalogSeeder.php`, `backend/app/Actions/EnsureAccountSubscription.php`, `backend/app/Console/Commands/InitializePlatform.php`, `backend/tests/Feature/PlatformProvisioningTest.php`. Modify `backend/app/Models/Account.php`, `backend/app/Actions/AccessAccounts.php`.

**Interfaces:** Produce `PlanCatalogSeeder::run():void`, `EnsureAccountSubscription::handle(Account $account):Subscription`, `InitializePlatform::handle():int` (`platform:initialize`), `Account::subscription():HasOne`, `Subscription::plan():BelongsTo`, `Subscription::account():BelongsTo`. Consume existing `AccessAccounts::bootstrap(array):array` and `issue(array,?int):array` unchanged externally.

- [ ] **Step 1: Write RED tests:** `test_catalog_is_insert_only`, `test_backfill_is_repeatable`, `test_provisioning_is_atomic_without_manual_seeding`, `test_subscription_constraints`, with these assertions:

```php
$this->artisan('platform:initialize')->assertSuccessful();
$this->assertSame(['users' => 5, 'clients' => 50, 'monitorings' => null], Plan::where('slug', 'basico')->firstOrFail()->limits);
$this->assertSame(['users' => 20, 'clients' => 500, 'monitorings' => null], Plan::where('slug', 'profissional')->firstOrFail()->limits);
$this->assertSame(['users' => null, 'clients' => null, 'monitorings' => null], Plan::where('slug', 'empresarial')->firstOrFail()->limits);
// After editing a default and subscription, initialize again: exact edits retained.
$this->assertSame(1, Subscription::where('account_id', $account->id)->count());
// Inject provisioning failure: Account, membership, activation and subscription counts unchanged.
```

Assert seeded plans do not close registration; existing owner credentials/activation rows survive migration/backfill; duplicate slug/account subscription, invalid status, and restricted plan deletion fail at the DB boundary.

- [ ] **Step 2: Run RED:** `docker compose exec -T backend php artisan test --compact tests/Feature/PlatformProvisioningTest.php`; expect missing command/model/schema.
- [ ] **Step 3: Implement schema, seeder and action.** Contacts are nullable phone/state/city and boolean WhatsApp default false. Plan slug is unique; subscription Account FK unique, plan FK restricted, status DB-check constrained. Insert only absent slugs (`insertOrIgnore`, never overwrite edits). `platform:initialize` walks Accounts with `chunkById(200)` and provisions missing active Básico subscriptions under Account locks. Use the same action within existing bootstrap/issue transactions; initialize missing catalog rows lazily. Lock order: Account before subscription; catalog inserts precede plan-row selection without acquiring Account locks elsewhere in the opposite order.
- [ ] **Step 4: Run GREEN:** new provisioning suite plus AccessFoundationTest and TenantFoundationTest; expect all pass and no orphan records on injected failure.
- [ ] **Step 5: Commit:** `git commit -m "feat: provision platform catalog and account subscriptions atomically"`.

## Task 3: Extend Account APIs without replacing activation behavior

**Files:** Create `backend/app/Http/Controllers/Api/V1/Admin/AccountController.php`, `backend/app/Http/Requests/Admin/{AccountListRequest,StoreAccountRequest,UpdateAccountRequest}.php`, `backend/app/Http/Resources/Admin/AccountResource.php`, `backend/app/Support/PlatformPagination.php`, `backend/tests/Feature/PlatformAccountsTest.php`. Modify `backend/routes/api.php`, `backend/app/Http/Controllers/Api/V1/AccessController.php`, `backend/app/Actions/AccessAccounts.php`, `backend/app/Models/Account.php`; retain `AccessRequest` for first-access reissue.

**Interfaces:** Produce `AccountController::{index(AccountListRequest),store(StoreAccountRequest),show(Request,int $id),update(UpdateAccountRequest,int $id)}:JsonResponse`, `AccountResource::toArray(Request):array`, `PlatformPagination::response(LengthAwarePaginator $page,string $resourceClass):JsonResponse`. Add `Account::pendingOwnerActivation():HasOne` for eager loading. Consume Task 2 provisioning; keep `AccessAccounts::projection(Account):array` as a compatibility adapter to the same resource.

- [ ] **Step 1: Write RED:** `test_extended_list_and_detail_are_safe`, `test_contact_patch_omission_and_null`, `test_contact_validation_is_atomic`, `test_literal_search_and_pagination`, `test_platform_authorization_without_membership`. Tests pin:

```php
$this->getJson('/api/v1/admin/accounts/'.$account->id)->assertOk()
    ->assertJsonPath('data.phone_whatsapp', false)->assertJsonPath('data.subscription.plan.slug', 'basico')
    ->assertJsonMissingPath('data.first_access')->assertJsonMissingPath('data.pending_owner.token_hash');
$this->patchJson('/api/v1/admin/accounts/'.$account->id, ['phone' => null])->assertOk()
    ->assertJsonPath('data.phone', null)->assertJsonPath('data.phone_whatsapp', false);
$this->getJson('/api/v1/admin/accounts?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
```

Use access fixture CSRF before writes. Dataset: phone 21 characters; invalid UF; city 256; flag true without effective phone; name/status/contact combination with one bad field retains every old value. PATCH omissions retain values; 27 official UF codes accepted. Search `%`, `_`, and backslash literally; trim/bound q to 255; 16 fixtures prove per_page 15, descending IDs, last_page 2. Unknown authorized ID 404. For every endpoint: anonymous 401, ordinary admin 403, wrong origin/missing CSRF 403, Super Admin without membership succeeds, response no-store. Existing create/reissue tests still assert their route names and seven-day output.

- [ ] **Step 2: Run RED:** `docker compose exec -T backend php artisan test --compact tests/Feature/PlatformAccountsTest.php`; expect missing GET detail/contact fields.
- [ ] **Step 3: Implement controllers/validation/resource and adapters.** Keep named Account routes while moving list/create/update into the dedicated controller; reissue stays foundational. Eager-load subscription.plan, pending activation and member count; never perform per-row credential projection. Add contact fields to original issuance transaction. Nullable clearing uses explicit presence, and clearing phone forces false. Literal SQL LIKE escaping is explicit. `PlatformPagination` fixes 15 rows and `{page,per_page,total,last_page}`.
- [ ] **Step 4: Run GREEN:** PlatformAccountsTest, PlatformProvisioningTest and AccessFoundationTest. Inspect `docker compose exec -T backend php artisan route:list --path=api/v1`; names/paths remain compatible.
- [ ] **Step 5: Commit:** `git commit -m "feat: extend platform account resources and contact management"`.

## Task 4: Provide edit-only commercial APIs

**Files:** Create `backend/app/Http/Controllers/Api/V1/Admin/{PlanController,SubscriptionController}.php`, `backend/app/Http/Requests/Admin/{UpdatePlanRequest,SubscriptionListRequest,UpdateSubscriptionRequest}.php`, `backend/app/Http/Resources/Admin/{PlanResource,SubscriptionResource}.php`, `backend/tests/Feature/PlatformCommercialTest.php`. Modify `backend/routes/api.php`.

**Interfaces:** Produce `PlanController::index(Request):JsonResponse`, `update(UpdatePlanRequest,int $id):JsonResponse`; `SubscriptionController::index(SubscriptionListRequest):JsonResponse`, `update(UpdateSubscriptionRequest,int $id):JsonResponse`. Consume Task 2 models and Task 3 pagination.

- [ ] **Step 1: Write RED:** `test_plan_limits_replace_including_null`, `test_invalid_plan_payload_preserves_values`, `test_subscription_list_and_atomic_update`, `test_commercial_status_does_not_control_access`.

```php
$this->putJson('/api/v1/admin/plans/'.$plan->id, ['name' => 'Básico', 'limits' => ['users' => 5, 'clients' => null, 'monitorings' => null]])
    ->assertOk()->assertJsonPath('data.limits.clients', null);
$this->putJson('/api/v1/admin/subscriptions/'.$subscription->id, ['plan_id' => 999999, 'status' => 'canceled'])
    ->assertUnprocessable()->assertJsonValidationErrors('plan_id');
$this->assertSame($oldPlanId, $subscription->fresh()->plan_id);
```

Datasets: Plan name 1/256, limits missing key/extra key/negative/fractional, immutable slug submission; all three commercial statuses; account/plan literal search; invalid page/status. Plans ID ascending, subscriptions ID descending/page 15. Canceled/past_due preserve active tenant request and owner activation eligibility. Add full auth/origin/CSRF/no-store matrix.

- [ ] **Step 2: Run RED:** `docker compose exec -T backend php artisan test --compact tests/Feature/PlatformCommercialTest.php`; expect missing routes.
- [ ] **Step 3: Implement list/update contracts.** Validate exactly the three limit keys (required but nullable) and reject unexpected keys. Update complete validated values atomically; prohibit plan CRUD/slug edits. Lock subscription before changing plan/status, validate restricted real plan FK, and leave operational status untouched.
- [ ] **Step 4: Run GREEN:** PlatformCommercialTest, PlatformAccountsTest and AccessFoundationTest.
- [ ] **Step 5: Commit:** `git commit -m "feat: add administrative plan and subscription APIs"`.

## Task 5: Persist explicit support transitions and identity

**Files:** Create migrations `backend/database/migrations/2026_10_13_000004_create_support_contexts_table.php`, `000005_create_support_access_logs_table.php` (same prefix); `backend/app/Models/{SupportContext,SupportAccessLog}.php`, their `backend/database/factories/*Factory.php`; `backend/app/Actions/SupportAccess.php`, `backend/app/Http/Controllers/Api/V1/SupportController.php`, `backend/tests/Feature/SupportTransitionsTest.php`. Modify `backend/routes/api.php`, `backend/app/Http/Controllers/Api/V1/AccessController.php`, `backend/app/Http/Resources/IdentityResource.php`, `backend/app/Support/AccessSession.php`.

**Interfaces:** Produce `SupportAccess::enter(Request $request,int $accountId):void`, `exit(Request $request,string $reason='manual'):void`, `switchMembership(Request $request,int $accountId):void`, `reconcile(Request $request):?SupportContext`. Controller `enter(Request,int $id):JsonResponse`, `exit(Request):JsonResponse`. Session shape is `{context_id:UUID,account_id:int,started_at:ISO,previous_account_id:?int}` under `support_context`; identity follows Task 1. Persist lifecycle before session reference grants authority.

- [ ] **Step 1: Write RED:** `test_foreign_support_is_session_local`, `test_same_target_is_idempotent`, `test_target_change_closes_then_opens`, `test_member_target_switches_normally`, `test_exit_restores_valid_membership_or_null`, `test_invalid_context_fails_closed`, `test_login_logout_and_reconciliation`.

```php
$deadline = $this->app['session']->get('access_deadline');
$this->postJson('/api/v1/support/accounts/'.$foreign->id.'/enter')->assertOk()
    ->assertJsonPath('id', $actor->id)->assertJsonPath('current_account.is_member', false)
    ->assertJsonPath('current_account.role', 'admin')->assertJsonPath('support.account_id', $foreign->id);
$this->assertDatabaseMissing('account_memberships', ['user_id' => $actor->id, 'account_id' => $foreign->id]);
$this->assertSame($deadline, $this->app['session']->get('access_deadline'));
// Second entry: same open context ID and exactly one enter event.
// Exit with no own memberships: current_account=null, support=null, exit count=1.
```

Assert real `accounts` stay unchanged with `is_member=true`, isolated second session has no support, own target creates no enter event, target change enter/exit IDs are coherent, suspended target 403/account_suspended, unknown target 404. Include forged UUID/actor mismatch/closed context, suspended previous membership fallback by default membership ID, audit-insert failure rollback, lost ownership denies success, stale session referring to DB-closed context clears on /me, fresh privilege loss, new login, logout revocation, and unchanged deadline. Check audit actor/target FK deletion restrictions and action check constraints.

- [ ] **Step 2: Run RED:** `docker compose exec -T backend php artisan test --compact tests/Feature/SupportTransitionsTest.php`; expect missing routes/lifecycle.
- [ ] **Step 3: Implement the lifecycle and transitions.** Restricted actor/Account FKs, random non-bearer UUID context correlation, indexed time/ID, action check `enter|exit|create|update|delete`. Acquire affected Account locks in ascending ID order, then memberships, lifecycle rows and audit rows. Re-read actor privilege in the transaction. Commit lifecycle and enter/exit event together; stage session changes only after DB success and assert lock ownership before/after. Reconcile DB-open state/actor/target before returning identity. Invalidations must commit closure/audit before denying tenant access (do not throw inside a transaction that would roll back the closure). Ordinary switching exits support atomically with membership selection. Entry and explicit exit APIs require fresh Super Admin authorization; neither uses ResolveAccount. Before returning 403 for a revoked actor, reconcile and close their now-invalid context; /me and logout can run that internal cleanup without Super Admin authorization. A still-authorized actor can exit without any active membership or target. Logout always revokes the session, even if its audit closure fails; failed closure returns no successful transition and cannot restore access. Authentication explicitly clears support after old-context cleanup. Keep return restoration separate from identity's default selection so valid foreign support is never overwritten.
- [ ] **Step 4: Run GREEN:** SupportTransitionsTest, AccessFoundationTest and TenantFoundationTest; full support authorization/origin/no-store matrix passes, including revoked-actor exit returning 403 after reconciliation and /me/logout clearing support safely.
- [ ] **Step 5: Commit:** `git commit -m "feat: persist audited session-local support transitions"`.

## Task 6: Enforce support tenant isolation and transactional write audit

**Files:** Create `backend/app/Support/{SupportAudit,SupportWriteDescriptor}.php`, `backend/tests/Feature/SupportTenantAuditTest.php`, `backend/tests/Integration/SupportConcurrencyTest.php`, `backend/tests/Fixtures/SupportTenantFixture.php`. Modify `backend/app/Support/CurrentAccount.php`, `backend/app/Http/Middleware/ResolveAccount.php`, `backend/tests/Integration/request-worker.php` only for isolated test handlers/barriers.

**Interfaces:** Produce `CurrentAccount::__construct(Account $account,?AccountMembership $membership,User $actor,string $effectiveRole,bool $isSupport,?SupportContext $supportContext=null)`; existing `assertWrite():void`, `assertAdmin():void`, `cacheKey(string):string` remain. `SupportWriteDescriptor::__construct(string $action,string $resourceType,int|string $resourceId,array $metadata=[])`; `SupportAudit::record(CurrentAccount $context,SupportWriteDescriptor $descriptor,Request $request):void`. Registered tenant route defaults declare action/resource type; handler sets its validated descriptor on request attributes before success.

- [ ] **Step 1: Write RED:** `test_support_role_is_explicit_without_membership`, `test_scope_and_assertion_fail_closed`, `test_support_write_and_audit_commit_together`, `test_unsupported_support_writes_are_denied`.

```php
$this->withHeader('X-Account-Id', (string) $previous->id)->getJson('/api/v1/test/tenant-items')
    ->assertConflict()->assertJsonPath('code', 'account_context_changed');
// Valid target create/update/delete: exactly one corresponding event and target-owned row change.
// Inject SupportAccessLog insertion failure: no row change and no success event.
// GET: unchanged event count. Unregistered write: 403 and unchanged row count.
```

Fixture uses a dedicated test-only scoped table/model and routes, including a handler returning a non-2xx response after attempting a write: resolver rolls it back. Missing context, forged body Account ID, suspended target, privilege revoke, nullable membership dereferences and ordinary `operador|user` role behavior all tested. Never ship fixture endpoints in production.

- [ ] **Step 2: Run RED:** `docker compose exec -T backend php artisan test --compact tests/Feature/SupportTenantAuditTest.php`; expect membership-required denial for valid support.
- [ ] **Step 3: Implement support-aware resolver/audit.** Inventory `membership->` references first. Reconcile invalid context outside the tenant mutation transaction, then revalidate under target Account lock. A valid persisted support context computes admin; no other Super Admin bypass. Retain displayed-ID and body validation/AccountScope. For support writes require registered route and matching descriptor; allow only resource type/ID and configured nonsecret metadata keys. Audit in the same transaction as mutation, before ownership fencing and commit; unsuccessful handlers roll back. GET adds no write event. Remove request context in finally.
- [ ] **Step 4: Add and run process-level tests.** SupportConcurrencyTest methods `test_duplicate_entry`, `test_target_change_races_exit`, `test_suspension_races_tenant_write`, `test_ownership_loss_rolls_back_write`, `test_failed_session_save_cannot_grant_support` use committed fixtures and separate PHP workers/Redis sessions. Barrier checkpoints force actual overlap; one same-target enter, no overlapping open lifecycles acknowledged as valid in one session, closed marker never grants support after simulated Redis save failure, post-suspension write denied, ownership-loss response not 2xx. Run `docker compose exec -T backend vendor/bin/phpunit -c phpunit.pgsql.xml --filter SupportConcurrencyTest` and the feature test; all pass. Sequential feature tests alone cannot close this step.
- [ ] **Step 5: Commit:** `git commit -m "feat: enforce support tenant context and atomic mutation audit"`.

## Task 7: Add global users, audit lists, and real summary

**Files:** Create `backend/app/Http/Controllers/Api/V1/Admin/{UserController,SupportLogController,SummaryController}.php`, `backend/app/Http/Requests/Admin/{UserListRequest,SupportLogListRequest}.php`, `backend/app/Http/Resources/Admin/{UserResource,SupportLogResource}.php`, `backend/tests/Feature/PlatformReadModelsTest.php`. Modify `backend/routes/api.php`, `backend/app/Models/User.php` (real membership relation).

**Interfaces:** Produce `UserController::index(UserListRequest):JsonResponse`, `SupportLogController::index(SupportLogListRequest):JsonResponse`, `SummaryController::__invoke(Request):JsonResponse`. Consume Task 3 pagination and Task 5 safe log model. Summary reuses SupportLogResource.

- [ ] **Step 1: Write RED:** `test_users_list_only_real_links`, `test_audit_filter_and_safe_fields`, `test_summary_counts_and_five_latest_events`.

```php
$this->getJson('/api/v1/admin/users?type=super')->assertOk()->assertJsonPath('data.0.is_super_admin', true)
    ->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.remember_token');
$this->getJson('/api/v1/admin/summary')->assertOk()->assertJsonCount(5, 'data.recent_support_logs')
    ->assertJsonPath('data.totals.accounts', Account::count());
```

Assert all four populated/empty counts, timestamp/ID tie ordering, multi-membership role links, support target absent from links, q=name/email literal behavior, invalid type/account_id/page 422, audit actor name/email/Account name/IP metadata allowlist, 15-row paging. Every read gets 401/403/no-office/no-store tests; no user or audit mutation route exists.

- [ ] **Step 2: Run RED:** `docker compose exec -T backend php artisan test --compact tests/Feature/PlatformReadModelsTest.php`; expect missing routes.
- [ ] **Step 3: Implement allowlisted eager-loaded read models.** New paginated lists order ID descending except audit timestamp then ID descending. Four independent summary counts are a read-time snapshot, without a consistency claim or large transaction. Restrict audit fields to specification; do not serialize models/raw metadata blindly.
- [ ] **Step 4: Run GREEN:** PlatformReadModelsTest and SupportTransitionsTest.
- [ ] **Step 5: Commit:** `git commit -m "feat: expose safe platform summary users and support audit"`.

## Task 8: Validate real certificates and implement the private Account vault

**Files:** Modify `backend/bootstrap/app.php` for certificate-password normalization exceptions. Create migration `backend/database/migrations/2026_10_13_000006_create_account_contracting_certificates_table.php`; `backend/app/Models/AccountContractingCertificate.php`, its factory; `backend/app/Support/Serpro/{ValidatedCertificate,ValidatePkcs12,CertificateDocument}.php`; `backend/app/Actions/ReplaceAccountCertificate.php`, `backend/app/Http/Controllers/Api/V1/Admin/ContractingCertificateController.php`, `backend/app/Http/Requests/Admin/ReplaceCertificateRequest.php`, `backend/app/Http/Resources/Serpro/CertificateResource.php`; `backend/tests/Support/CertificateFixtures.php`, `backend/tests/Feature/ContractingCertificateTest.php`. Modify `backend/routes/api.php`.

**Interfaces:** Produce `ValidatePkcs12::handle(UploadedFile $file,string $password):ValidatedCertificate`; immutable validated object carries internal bytes/password and safe `document,subject,serial,notBefore,notAfter`, never JSON serialization. `CertificateDocument::extract(array $x509):string` normalizes one supported CNPJ. `ReplaceAccountCertificate::handle(Request $request,int $accountId,ValidatedCertificate $certificate):AccountContractingCertificate`. Metadata GET/PUT controller signatures `show(Request,int):JsonResponse`, `update(ReplaceCertificateRequest,int):JsonResponse`.

- [ ] **Step 1: Write RED:** `test_real_pkcs12_with_empty_password`, `test_invalid_upload_preserves_vault`, `test_safe_encrypted_account_vault`. Generate temporary real key/cert/container with OpenSSL in CertificateFixtures, clean in teardown; use native UploadedFile, not image/fake MIME helpers.

```php
$file = CertificateFixtures::uploadedPkcs12(password: '', cnpj: '12345678000195');
$validated = app(ValidatePkcs12::class)->handle($file, '');
$this->assertSame('12345678000195', $validated->document);
// Missing password field: 422. Supplied empty password: accepted for passwordless file.
// Raw DB container/password != plaintext; model/resource JSON contains neither.
```

Dataset: `.pfx/.p12` with MIME octet-stream; wrong extension/password, malformed bytes, >2 MiB, mismatched private key, expired/future validity, conflicting identity, supported subject/SAN forms and alphanumeric document characters. Implementer checks supported ICP-Brasil encodings against primary documentation before finalizing parser tests; no digits-only extraction heuristic. Unsupported OpenSSL cipher fails with sanitized certificate field error rather than shelling secrets into CLI output. Non-first active Account works; suspended source cannot replace; unauthorized calls denied; no read/download/delete material endpoint.

- [ ] **Step 2: Run RED:** `docker compose exec -T backend php artisan test --compact tests/Feature/ContractingCertificateTest.php`; expect missing validator/vault.
- [ ] **Step 3: Implement parse/validation and encrypted vault.** File bound 2,097,152 bytes and actual PKCS#12 parsing/key match/current validity/unambiguous document; derive document, reject writable document input. Preserve raw supplied certificate password before generic trimming/null conversion (including whitespace and empty string), while enforcing field presence. Base64 container inside encrypted text cast, encrypted password, hidden attributes, unique restricted Account FK, revision starts at 1 and increments. Lock Account then vault. Whitelist safe metadata; active checks under lock. No unvalidated legacy decryption fallback.
- [ ] **Step 4: Run GREEN:** ContractingCertificateTest and PlatformAccountsTest; no real certificate/credentials left in tracked files or logs.
- [ ] **Step 5: Commit:** `git commit -m "feat: validate and encrypt contracting account certificates"`.

## Task 9: Implement atomic singleton connection and rotation

**Files:** Create migration `backend/database/migrations/2026_10_13_000007_create_serpro_connections_table.php`; `backend/app/Models/SerproConnection.php`, its factory; `backend/app/Actions/SaveSerproConnection.php`; `backend/app/Support/Serpro/ConnectionSnapshot.php`; `backend/app/Http/Controllers/Api/V1/SerproController.php`, `backend/app/Http/Requests/Serpro/SaveConnectionRequest.php`, `backend/app/Http/Resources/Serpro/ConnectionResource.php`; `backend/tests/Feature/SerproConnectionTest.php`, `backend/tests/Integration/SerproRotationTest.php`. Modify `backend/routes/api.php`, `backend/app/Actions/ReplaceAccountCertificate.php`.

**Interfaces:** Produce `SaveSerproConnection::handle(Request $request,array $data,?ValidatedCertificate $replacement):SerproConnection`; `ConnectionSnapshot::capture():?self`, `assertCurrent():void`. Snapshot holds encrypted-material-derived internal values, connection revision and optional source revision/Account ID; no serialization. SerproController `show(Request):JsonResponse`, `update(SaveConnectionRequest):JsonResponse`. Consume validator/vault from Task 8.

- [ ] **Step 1: Write RED:** `test_unconfigured_is_successful_metadata`, `test_initial_connection_requires_complete_source`, `test_partial_rotation_and_source_switch`, `test_attached_vault_rotation_is_atomic`.

```php
$this->getJson('/api/v1/serpro/connection')->assertOk()->assertJsonPath('data.configured', false)
    ->assertJsonPath('data.consumer_key_hint', null);
// Secret-only replacement preserves exact key/container and increments revision once.
// Blank/omitted replacement key/secret preserves values; bad source leaves all old values.
// Account source stores FK, no copied container; vault replacement updates connection metadata/revision.
```

Assert singleton DB check ID=1, source consistency/FK restrictions, ciphertext/hidden values, same safe metadata fields whether source upload/account, suspended/missing/invalid source 422, empty vs missing password, first creation and every validation failure atomic. No complete key/secret/private-key/container/password response or log. Authorization/no-store matrix.

- [ ] **Step 2: Run RED:** `docker compose exec -T backend php artisan test --compact tests/Feature/SerproConnectionTest.php`; expect missing singleton/routes.
- [ ] **Step 3: Implement storage/rotation.** Acquire shared `serpro-connection` PostgreSQL transaction advisory lock for connection creation/rotation and attached vault replacement, then Account locks ascending, vault row, connection row. This is the same order for all writers. Upload source persists encrypted bytes/password in connection; Account source clears upload material and references vault. Partial updates merge only meaningful replacement fields, preserving unspecified source. Revision/metadata updated in one transaction. Snapshot captures connection/source coherently under the same lock and releases before network; `assertCurrent` checks both revisions and source eligibility, otherwise 409 `connection_changed`. Do not cache tokens.
- [ ] **Step 4: Run GREEN including races:** `docker compose exec -T backend php artisan test --compact tests/Feature/SerproConnectionTest.php`; `docker compose exec -T backend vendor/bin/phpunit -c phpunit.pgsql.xml --filter SerproRotationTest`. Separate workers force competing first creations, source switch versus vault replacement, and two secret rotations. Assert one singleton, coherent source/metadata, monotonic revisions, no deadlock or mixed snapshot. Extend existing integration worker only as needed; never emit secrets.
- [ ] **Step 5: Commit:** `git commit -m "feat: store and rotate the encrypted Serpro platform connection"`.

## Task 10: Authenticate with Serpro and fence stale connectivity results

**Files:** Create `backend/config/serpro.php`, `backend/app/Support/Serpro/{CertificateMaterializer,SerproAuthenticator}.php`, `backend/app/Actions/CheckSerproConnectivity.php`, `backend/tests/Feature/SerproConnectivityTest.php`. Modify `backend/app/Http/Controllers/Api/V1/SerproController.php`, `backend/routes/api.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/.env.example`, `backend/tests/Integration/SerproRotationTest.php`.

**Interfaces:** Produce `CertificateMaterializer::withCertificate(ValidatedCertificate $certificate,Closure $operation):mixed` (operation receives private string path); `SerproAuthenticator::authenticate(ConnectionSnapshot $snapshot,string $certificatePath):array{ok:bool,failed_element:?string,message:string,checked_at:string}`; `CheckSerproConnectivity::handle(Request $request):array` same safe shape; controller `connectivity(Request):JsonResponse`. Consume Task 9 snapshot. Tokens never leave authenticator.

- [ ] **Step 1: Write RED:** `test_authentication_request_and_safe_success`, `test_named_local_and_provider_failures`, `test_materialization_always_cleans_up`, `test_connectivity_rate_budgets`, `test_rotation_invalidates_inflight_result`.

```php
Http::fake([$url => Http::response(['access_token' => 'test-access', 'jwt_token' => 'test-jwt'])]);
$this->postJson('/api/v1/serpro/connectivity')->assertOk()->assertJsonPath('data.ok', true)
    ->assertJsonPath('data.failed_element', null)->assertJsonMissingPath('data.access_token')
    ->assertJsonMissingPath('data.jwt_token');
// Assert form grant_type=client_credentials, Basic credentials, Role-Type TERCEIROS.
// Seventh actor or trusted-IP call: 429 with Retry-After; forged browser IP cannot reset budget.
```

Dataset: no config → configuracao/no HTTP; invalid/expired/suspended source → certificado/no HTTP; provider 401/403 → credencial; 429/5xx/timeout/redirect/malformed or single-token response → provedor. Success/failure/decrypt/parse/callback exceptions leave zero temporary files; private directory 0700/file 0600. Log spy never sees test key/secret/token/password/PEM. Missing privilege 403. Force concurrent rotation during fake provider wait and assert 409/code connection_changed, not old success; ownership loss returns failure even if provider accepted.

- [ ] **Step 2: Run RED:** `docker compose exec -T backend php artisan test --compact tests/Feature/SerproConnectivityTest.php`; expect missing connectivity route/action.
- [ ] **Step 3: Implement safe provider adapter/configuration.** Follow the [official Serpro authentication guide](https://apicenter.estaleiro.serpro.gov.br/documentacao/api-integra-contador/pt/quick_start/) verified on 2026-10-12: server-controlled `https://autenticacao.sapi.serpro.gov.br/authenticate`, form POST, Basic authentication, TERCEIROS header, mutual TLS, both nonempty tokens. Installed Guzzle 8.2 has `cert_type`; use cURL handler with `cert_type='P12'` and `cert=[privatePath,password]`, verify=true, redirects=false, connect_timeout=3, timeout=8. Unit tests check underlying options as HTTP fakes do not prove TLS. Configure `SERPRO_AUTH_URL`, `SERPRO_CONNECT_TIMEOUT=3`, `SERPRO_TIMEOUT=8`, `SERPRO_TEMP_DIRECTORY` privately; reject HTTP/user-supplied URLs. No retries/body logging/token cache/service calls. Assert session ownership before and after HTTP and recheck snapshot revision before verdict. Named safe messages are pt-BR. Enforce two independent six/min Redis budgets: actor and trusted ingress IP.
- [ ] **Step 4: Run GREEN:** SerproConnectivityTest and SerproRotationTest. A deterministic local mutual-TLS test server with generated test CA verifies actual client-certificate options and peer verification; no real provider request. Reject configurations whose provider budget cannot fit proxy/lock budgets. Record runtime capability/output with no secret values.
- [ ] **Step 5: Commit:** `git commit -m "feat: verify Serpro authentication with safe connectivity verdicts"`.

## Task 11: Preserve multipart bytes and ingress budgets

**Files:** Modify `frontend/app/composables/useAccessApi.ts`, `frontend/server/api/v1/[...path].ts`, `backend/docker/php.ini`, `backend/bootstrap/app.php` (only upload validation/error handling), `frontend/package.json`. Create `frontend/shared/utils/adminTransport.ts`, `frontend/tests-admin-transport.test.ts`, `frontend/tests-integration/admin-multipart.test.ts`, `backend/tests/Feature/PlatformUploadIngressTest.php`.

**Interfaces:** Extend API method union to `GET|POST|PUT|PATCH`; body `Record<string,unknown>|FormData`. Explicitly allow transport `_method` for these upload requests without persisting it; reject all other unknown fields. Produce `multipartMethod(body:FormData,method:'PUT'):FormData` and `adminProxyTimeout(path:string,method:string):number` (15000 selected, 10000 otherwise). On multipart PUT send POST with `_method=PUT`, never set Content-Type manually; backend public route still PUT.

- [ ] **Step 1: Write RED tests:**

```ts
test('selected credential routes keep bounded timeout and method override', () => {
  assert.equal(adminProxyTimeout('/api/v1/serpro/connectivity', 'POST'), 15000)
  assert.equal(adminProxyTimeout('/api/v1/admin/accounts/2/contracting-certificate', 'POST'), 15000)
  assert.equal(adminProxyTimeout('/api/v1/login', 'POST'), 10000)
  assert.equal(multipartMethod(new FormData(), 'PUT').get('_method'), 'PUT')
})
```

Real ingress integration submits generated PKCS#12 bytes using browser-compatible FormData to isolated Nuxt/Laravel pair. Assert SHA-256 equality at test fixture boundary, metadata save, correct CSRF/origin/cookie, stripped forged x-opmoni/x-forwarded headers, retained trusted ingress IP, `_method` accepted only on expected transport. Content-Length >4 MiB and chunked streaming beyond bound yield 413; >2 MiB file field fails safely; Unicode multipart fields and empty/whitespace password survive. Invalid origin/missing CSRF never reaches mutation.

- [ ] **Step 2: Run RED:** `docker compose exec -T frontend pnpm test`; backend PlatformUploadIngressTest; expect missing PUT/FormData/timeout handling.
- [ ] **Step 3: Implement transport and bounds.** Raw binary bounded forwarding; do not JSON/stringify/parse-and-reserialize multipart. Strip untrusted ingress before forwarding, keep no-store and backend error envelopes. Enforce 4,194,304-byte total at Nuxt even without Content-Length; PHP upload/post ceilings align and errors remain safe. Preserve empty supplied passwords through Laravel normalization on these routes. Only Serpro and certificate writes get 15s, unrelated access stays 10s. Add explicit `test:ingress` script for integration file; fixture server targets only test resources and refuses development configuration.
- [ ] **Step 4: Run GREEN:** frontend test/typecheck; backend PlatformUploadIngressTest and certificate suites; `pnpm test:ingress` against isolated app pair. Verify real byte equality and timeout failures; ordinary pure unit tests cannot satisfy this gate.
- [ ] **Step 5: Commit:** `git commit -m "feat: forward bounded certificate multipart uploads through trusted ingress"`.

## Task 12: Build the shared Admin shell and reliable load behavior

**Files:** Create `frontend/app/pages/admin.vue`, `frontend/app/utils/adminNav.ts`, `frontend/shared/utils/{adminNavigation,adminList}.ts`, `frontend/app/composables/{useAdminList,useRetryableLoad,useClientMediaQuery}.ts`; mapped `frontend/app/components/data-table/{PanelList,ColumnMenu,RowActionsMenu,PanelTableEmpty}.vue`, `panel.ts`, `frontend/app/components/{MetricCard,ErrorRetryAlert,AdminAccessDenied}.vue`, `frontend/tests-admin-navigation.test.ts`, `frontend/tests-admin-list.test.ts`. Modify `frontend/app/layouts/default.vue`, `frontend/app/middleware/access.global.ts`, `frontend/app/plugins/access-focus.client.ts`, `frontend/shared/utils/access.ts`.

**Interfaces:** `AdminPage={label:string,icon:string,to:string}`, `adminPages:readonly AdminPage[]`, `isAdminRoute(path:string):boolean`, `adminPageActive(path:string,page:AdminPage):boolean`, `adminNavbarTitle(path:string):string`; app-only `adminSidebarChildren(path:string,onSelect?:()=>void):NavigationMenuItem[]`, `adminTabs(path:string):NavigationMenuItem[][]`. `useAdminList<T>(path:string,filters:Ref<Record<string,string|undefined>>)` returns `data,meta,pending,error,hasLoaded,page,refresh`. `useRetryableLoad<T>(load:()=>Promise<T>)` retains prior success. Pure `createLatestRequestGate()` exposes `begin():number,isCurrent(version:number):boolean`; `recoverPage(page:number,lastPage:number):number`.

- [ ] **Step 1: Write RED:**

```ts
test('admin routes match on a segment boundary with exact summary', () => {
  assert.equal(isAdminRoute('/admin/usuarios'), true)
  assert.equal(isAdminRoute('/administrator'), false)
  assert.equal(adminPageActive('/admin/contas', adminPages[0]!), false)
  assert.equal(adminNavbarTitle('/admin/planos'), 'Planos')
  assert.deepEqual(adminPages.map(p => p.label), ['Resumo', 'Contas', 'Planos', 'Assinaturas', 'Usuários', 'Suporte', 'Serpro'])
})
test('out-of-range page recovers and older responses cannot win', () => {
  assert.equal(recoverPage(4, 2), 2)
  const gate = createLatestRequestGate(); const old = gate.begin(); const latest = gate.begin()
  assert.equal(gate.isCurrent(old), false); assert.equal(gate.isCurrent(latest), true)
})
```

Add destination fixtures: no-office Super Admin → `/admin`; active support → `/`; ordinary suspended selection retains current unavailable behavior. Deferred promises test old success after new success, old error after new success, failure after success preserving data, filters reset page, out-of-range refetch once, initial error distinct from loaded empty.

- [ ] **Step 2: Run RED:** frontend `pnpm test`; expect missing pure helpers.
- [ ] **Step 3: Implement shared presentation and route/focus behavior.** Port only mapped compatible components using current Nuxt UI; no full legacy shell/auth. One UDashboardPanel/navbar/tabs/NuxtPage in admin parent; remove current Account-specific layout wrapper. Sidebar trigger children, collapsed popover, search items only Super Admin, mobile callback closes menu. Parent renders access-denied with return action for ordinary users and no child API load. Generalize route/focus guards with segment boundary. Lists debounce q by 300ms, reset on filters, serialize only valid filters/page, retain previous success and discard stale results/errors. SSR media query must hydrate safely. Do not present zero summary while initial fetch fails.
- [ ] **Step 4: Run GREEN:** frontend test/lint/typecheck. Once page tasks exist, browser gate verifies all seven titles, exactly one panel/navbar, keyboard/expanded/collapsed/mobile navigation, no-office access and ordinary-user denial; record those results in Task 16.
- [ ] **Step 5: Commit:** `git commit -m "feat: add the shared Super Admin shell and reliable list loading"`.

## Task 13: Connect summary, Accounts, Plans, Subscriptions, and Users

**Files:** Create `frontend/app/pages/admin/{index,planos,assinaturas,usuarios}.vue`, `frontend/app/components/admin/{AccountForm,PlanEditor,SubscriptionEditor}.vue`, `frontend/app/utils/brazilUfs.ts`, `frontend/shared/utils/adminForms.ts`, `frontend/tests-admin-forms.test.ts`. Modify `frontend/app/pages/admin/contas.vue`.

**Interfaces:** Consume Task 1 resources, Tasks 3/4/7 APIs and Task 12 loaders/table shell. Produce `planUpdate(name:string,limits:{users:string,clients:string,monitorings:string}):{name:string,limits:Plan['limits']}`; `contactUpdate(values:{phone:string,state:string,city:string,phone_whatsapp:boolean}):Pick<PlatformAccount,'phone'|'state'|'city'|'phone_whatsapp'>`. Form components emit `saved` with typed Resource data; AccountForm retains issuance only in its open form state.

- [ ] **Step 1: Write RED:**

```ts
test('cleared limits and phone serialize explicit null', () => {
  assert.deepEqual(planUpdate('Básico', { users: '5', clients: '', monitorings: '' }), { name: 'Básico', limits: { users: 5, clients: null, monitorings: null } })
  assert.deepEqual(contactUpdate({ phone: '', state: '', city: '', phone_whatsapp: true }), { phone: null, state: null, city: null, phone_whatsapp: false })
})
```

Pin negative/fractional input rejection, preserve finite zero, no truthiness-based null conversion. Browser checks: summary failure has retry/no false zeros; linked real counts/five events; Account create/edit/reissue/status confirmation/temporary link clear and clipboard failure feedback; table plan/member count/contact; Plan local search and finite→null reload; Subscription all three labels, failed input retention and missing catalog disables save/retry; multi-Account user badges/read-only. Every table has column visibility, counts, correct empty/loading/error/paging behavior.

- [ ] **Step 2: Run RED:** frontend `pnpm test`; expect missing form utilities.
- [ ] **Step 3: Implement five pages and focused forms.** Keep seven-day owner activation fields `owner_name/login_email`, never password assignment. All mutation loading/field errors use existing apiFailure semantics, no duplicate submit. Plan list is local filtered catalog, no create/delete. Subscription statuses show pt-BR and do not suspend Account. Clear issuance/secret state on cancel/unmount, not just saved close. Account actions offer support entry only when active; Task 14 connects it.
- [ ] **Step 4: Run GREEN:** frontend test/lint/typecheck/build; isolated browser forms against real APIs. Re-run PlatformAccountsTest/PlatformCommercialTest only if contract mismatches require backend edits.
- [ ] **Step 5: Commit:** `git commit -m "feat: connect platform summary account and commercial administration pages"`.

## Task 14: Connect support search, audit, banner and context resets

**Files:** Create `frontend/app/pages/admin/suporte.vue`, `frontend/app/components/SupportBanner.vue`, `frontend/app/composables/useSupportMode.ts`, `frontend/shared/utils/support.ts`, `frontend/tests-support.test.ts`. Modify `frontend/app/composables/useAuth.ts`, `frontend/app/components/AccountMenu.vue`, `frontend/app/layouts/default.vue`, `frontend/app/plugins/access-focus.client.ts`, `frontend/app/pages/admin/contas.vue`.

**Interfaces:** Add `useAuth().enterSupport(accountId:number):Promise<void>`, `exitSupport():Promise<void>`; `applyIdentity(identity:Identity):void` centralizes tenant state invalidation. Produce `identityContextKey(identity:Identity|null):string`, `useSupportMode()` exposing `active,accountName,exit`. Key includes actor, current ID/status/role, membership/support marker and support started_at so same Account with changed mode/privilege still resets.

- [ ] **Step 1: Write RED:**

```ts
test('support ending in the same Account still changes client context', () => {
  assert.notEqual(identityContextKey(supportIdentity), identityContextKey(memberIdentity))
  assert.equal(accessDestination(noOfficeSuperAdmin), '/admin')
})
```

Provide explicit Identity fixtures for these names. Test stale /me cannot overwrite a newer transition, privilege/status/support changes with same ID reset state, ordinary missing context denied. Browser: server search/name/ID detail envelope, suspended target disabled, Entrar/Auditoria positive Account filter, foreign entry/banner/exit/no-office restoration, real membership switch exit event, own-target no support, invalidation and second tab focus. Clear previous Account Nuxt data/state before navigation; no full-platform download/search scan.

- [ ] **Step 2: Run RED:** frontend `pnpm test`; expect missing context-key/support helpers.
- [ ] **Step 3: Implement support UI/auth transitions.** Use existing API/CSRF generation handling, central identity application and full tenant reset for enter/exit/switch/invalidation/focus. Banner names Account, explains audited access and keeps exit available without active target. AccountMenu lists only real memberships while separately displaying support current projection. Preserve Admin route on focus for authorized no-office users. Logout resets support/client state and honors backend revocation. No fake membership options.
- [ ] **Step 4: Run GREEN:** frontend test/lint/typecheck and isolated browser support checks; backend SupportTransitionsTest/SupportTenantAuditTest remain green after any contract adjustment.
- [ ] **Step 5: Commit:** `git commit -m "feat: expose support entry audit and persistent context controls"`.

## Task 15: Connect the secret-safe Serpro page

**Files:** Create `frontend/app/pages/admin/serpro.vue`, `frontend/app/components/admin/{SerproCredentialForm,ContractingCertificateUpload}.vue`, `frontend/app/composables/useSerproConnection.ts`, `frontend/shared/utils/serpro.ts`, `frontend/tests-serpro.test.ts`.

**Interfaces:** `useSerproConnection()` exposes `metadata,pending,error,refresh,save(FormData):Promise<SerproConnectionMetadata>,check():Promise<ConnectivityResult>` through useAccessApi. Produce `credentialPayload(values:{consumer_key:string,consumer_secret:string,certificate_source:'upload'|'account',contracting_account_id:number|null,certificate:File|null,password:string,passwordSupplied:boolean}):FormData`; `connectivityPresentation(result:ConnectivityResult):{label:string,color:string,icon:string}`.

- [ ] **Step 1: Write RED:**

```ts
test('blank replacements are omitted while a supplied empty password survives', () => {
  const data = credentialPayload({ consumer_key: '', consumer_secret: '', certificate_source: 'upload', contracting_account_id: null, certificate: generatedFile, password: '', passwordSupplied: true })
  assert.equal(data.has('consumer_secret'), false)
  assert.equal(data.has('consumer_key'), false)
  assert.equal(data.has('certificate_password'), true)
  assert.equal(data.get('certificate_password'), '')
})
```

Use actual File fixture; pin account-source ID serialization and no manual document, four named failure labels, safe connection_changed retry feedback. Browser checks distinguish actual configured=false from GET 404/503; safe metadata only, source selector/server Account search/inline vault upload, first configuration and partial rotation, bad file field errors, expired/suspended source, save clears old connectivity, inputs clear on success/cancel/navigation, duplicate saving/testing blocked, named failure/result timing.

- [ ] **Step 2: Run RED:** frontend `pnpm test`; expect missing payload/presentation helpers.
- [ ] **Step 3: Implement source-aware page/forms.** Never hydrate key/secret/password with saved values; show only hint/derived document/subject/serial/validity. Request metadata for explicitly selected Account and require valid configured vault before source save. Multipart password key is `certificate_password` consistently in frontend/backend requests; source/account ID names follow spec. UI testing uses fake provider in isolated backend; no operational Serpro calls. Preserve errors/last success with retry; mutual busy guard for save/check/source upload and clear obsolete verdict on successful save.
- [ ] **Step 4: Run GREEN:** frontend test/lint/typecheck/build, ingress test and browser real-file workflows for both sources. Confirm client/network results contain no saved credentials/provider tokens.
- [ ] **Step 5: Commit:** `git commit -m "feat: add Serpro credential rotation and connectivity administration"`.

## Task 16: Complete provisioning races, whole-change verification and handoff

**Files:** Create `backend/tests/Integration/PlatformProvisioningConcurrencyTest.php`. Modify the execution ledger, `backend/README.md`, `frontend/README.md`, `docs/architecture/product-decisions.md`; update `openspec/changes/archive/2026-10-20-add-super-admin-platform/tasks.md` only for verified items. Keep this plan synchronized if a justified contract change was needed.

**Interfaces:** Consume all preceding deliverables. Produce reproducible validation evidence, operational initialization/rollback instructions and completed OpenSpec task mapping, without claiming an actual live Serpro account has been tested.

- [ ] **Step 1: Write process-level tests:** `test_concurrent_initialization_preserves_edits`, `test_backfill_races_account_provisioning`, `test_catalog_initialization_does_not_close_registration`. Workers overlap using barriers and committed fixtures; assert unique default slugs, one subscription per Account, edited values untouched, no orphan activation/Account/subscription, exact identity preservation, and absence of demo users. First reproduce the naive duplicate path in a temporary failing test double, then verify the real locking/constraints pass; do not regress working code just to manufacture RED.
- [ ] **Step 2: Run race gate:** `docker compose exec -T backend vendor/bin/phpunit -c phpunit.pgsql.xml`; all existing/new integration classes pass with real PostgreSQL/Redis and no data outside test resources altered. Log process/checkpoint coverage separately from feature suite counts.
- [ ] **Step 3: Run complete checks:**

```bash
docker compose exec -T backend vendor/bin/pint --dirty --format agent
docker compose exec -T backend php artisan test --compact
docker compose exec -T frontend pnpm lint
docker compose exec -T frontend pnpm typecheck
docker compose exec -T frontend pnpm test
docker compose exec -T frontend pnpm build
```

Pint may include pre-existing dirty PHP files: inspect its diff and preserve unrelated baseline formatting instead of committing wholesale. Run `pnpm test:ingress` against isolated app pair. All commands exit 0; no unrun/skipped required suite may be called complete.

- [ ] **Step 4: Run the browser acceptance matrix with agent-browser:** 1366×639 and 390×844; every direct route, expanded/collapsed/mobile sidebar, tabs/search, keyboard focus, ordinary user denied/no data, no-office Super Admin/focus, initial failure/retry/empty/stale success, out-of-range recovery, all forms, support reset/two tabs, both real multipart sources. Capture nonsecret screenshots and expected/observed results in the ledger. Compare mapped legacy presentation while preserving foundational activation. Do not use live credentials in screenshots.
- [ ] **Step 5: Request a whole-change independent review:** use requesting-code-review with the actual baseline/task commit range, all five specs and execution evidence. Focus on session/DB failure domains, auth/CSRF/ingress, literal search, safe serialization, lock order, secret cleanup, multipart and all state resets. Resolve actionable findings; rerun affected checks only when fixes justify it. Independent-process failure or missing browser evidence keeps the task incomplete.
- [ ] **Step 6: Document initialization and rollback:** publish `php artisan platform:initialize --no-interaction`, insert-only/backfill behavior, API/runtime env/prerequisites and bounded budgets. Schema deploy precedes API/UI; do not migrate:fresh development/production. Roll back UI/routes first, close support contexts before restoring membership-only resolver, retain encrypted/audit tables, never rotate APP_KEY/drop records as rollback. State no quota/billing/operational modules and distinguish deterministic provider testing from live-account verification. Update OpenSpec only from evidence.
- [ ] **Step 7: Commit task-owned verification/docs:** `git commit -m "test: verify platform concurrency and document administration rollout"`.

## Coverage and self-review

| OpenSpec sections | Owning tasks |
| --- | --- |
| 1 baseline/contracts/security | 1, each endpoint's auth matrix, 16 |
| 2 catalog/provisioning | 2, 16 |
| 3 Account compatibility/contacts | 3, 13 |
| 4 commercial APIs/semantics | 4, 13 |
| 5 support lifecycle/identity | 5, 14 |
| 6 tenant authorization/audit/races | 6, 7, 14 |
| 7 global users/summary | 7, 13 |
| 8 certificates/connection/rotation | 8, 9, 10 |
| 9 auth/connectivity/ingress/budgets | 10, 11, 15 |
| 10 shared UI/navigation/loading | 12 |
| 11 seven pages | 13, 14, 15 |
| 12 integrated acceptance/docs/review | 16 |

Self-review: all five specs and OpenSpec sections have owners; signatures and identity/resource envelopes agree; each Review Focus condition has an explicit negative or race test; no task adds omitted operational modules. Test snippets specify exact critical values while implementation bodies remain for the implementer. No feature, test result, migration, or live-provider success is claimed by this planning document.
