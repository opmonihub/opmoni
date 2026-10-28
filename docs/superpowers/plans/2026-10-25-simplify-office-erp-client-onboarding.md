# Office ERP Customer Enrollment and Seven-Day Trial Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an office register or select an ERP customer, assign and administer its subscription, and share its own seven-day ERP trial link without making retail offers or commercial invitations prerequisites.

**Architecture:** Extend the existing office commercial boundary, Client/Company provisioning and Company subscription instead of creating another billing or authentication system. Reuse frozen contract terms, bounded allocations, operation replay and invoice lifecycle; add narrowly scoped owner setup, office trial links and durable server-clock trial grants. Paid administration, customer identity and optional delegated operating access remain separate.

**Tech Stack:** Laravel 13 / PHP 8.4, Nuxt 4 / Vue / TypeScript, existing Nuxt UI v4, PostgreSQL and Redis; no new runtime dependencies.

**Spec:** [Design](../../../openspec/changes/archive/2026-10-28-simplify-office-erp-client-onboarding/design.md), [proposal](../../../openspec/changes/archive/2026-10-28-simplify-office-erp-client-onboarding/proposal.md), [capability deltas](../../../openspec/changes/archive/2026-10-28-simplify-office-erp-client-onboarding/specs/) and [tracked tasks](../../../openspec/changes/archive/2026-10-28-simplify-office-erp-client-onboarding/tasks.md).

## Global Constraints

- Trial time starts at committed successful registration, lasts 168 hours, and expires inclusively at its end; expired trials retain read access and data.
- One trial is available per normalized Company CNPJ globally; changing offices or recreating a link cannot restart it.
- No trial invoice, automatic collection or automatic paid conversion occurs.
- Offices manage subscriptions but gain no Company operating access from the commercial relationship alone.
- Trial and paid enrollment retain centrally configured licence and module ceilings; zero capacity requires platform configuration rather than an automatic quota increase.
- Preserve existing identity/passwords, Company ownership, explicit delegation, selected-Account assertions, session deadlines, immutable money strings and legacy contract/invitation recovery.
- A public link resolves its Account server-side; public signup grants no Account membership or general portfolio CRUD.
- `ERP_OFFICE_TRIAL_ENABLED` defaults to false; do not modify deployed `.env`, APP_KEY, registration/operating/fiscal/provider gates, development stores or private proofs.
- Write product copy in Brazilian Portuguese and code/artifacts in English; reuse existing shells/components and consult Nuxt UI skills/API examples before UI edits.
- All implementation and tests run inside the authorized `.worktrees/` checkout; use only isolated test PostgreSQL/Redis for concurrency. Do not install dependencies or start shared services as a planning side effect.

## Review Focus

1. A registration committed but session storage/refresh failed: Task 6 and Task 9 must recover without another password POST, duplicate identity or restarted seven-day clock.
2. Public registration is open while its link rotates or its office loses capacity: Task 5/6/11 must deny before commit and expose no foreign Client data.
3. A trial subscription says `trialing` but lacks a grant, has a manual hold, or has accepted/non-effective contract modules: Task 7 must deny invalid grants, preserve holds and permit valid trial modules.
4. An owner claims an already provisioned Company or a foreign owner reuses its continuation: Task 4 must bind only the intended person without another activation, allocation or invoice.
5. Expiry and paid conversion race at the exact deadline: Task 7/11 must leave one truthful subscription/allocation and at most one first paid invoice, preserving all trial/operating data.

---

## File and responsibility map

| Responsibility | Existing seams | New focused units |
|---|---|---|
| Domain representation | `backend/app/Enums/Erp/ErpSubscriptionStatus.php`, `Models/Erp/{ErpCompany,ErpSubscription}.php`, `Models/Erp/Commercial/{OfficeErpContract,OfficeErpOwnerInvitation}.php` | Two additive migrations; `ErpSubscriptionTrial`, `OfficeErpTrialLink` models |
| Office admission | `backend/app/Erp/Commercial/OfficeErpAdmission.php`, `OfficeErpOperationController.php` | New action classifications/capabilities in the existing boundary |
| Client/Company provisioning | `backend/app/Services/ClientManager.php`, `backend/app/Erp/ErpCompanyProvisioning.php` | `OfficeErpCustomerEnrollment`, `OfficeErpEnrollmentController` |
| Optional responsible-person access | Existing owner invitation/acceptance/continuation services and `/erp/ativar` | `OfficeErpOwnerSetup`, purpose dispatch in existing controllers |
| Office trial links | Existing office routes and operation journal | `OfficeErpTrialLinks`, `OfficeErpTrialLinkController` |
| Public trial registration | `ErpCommercialRegistration`, registration controller patterns, `backend/routes/erp.php` | `OfficeErpTrialRegistration`, `ErpTrialController` |
| Trial entitlement/expiry | `ErpCommercialGate`, `ResolveCompany`, fiscal queued authorization, recurring billing | `ErpTrialLifecycle`, bounded expiry command |
| Customer UI/recovery | Existing `gestao-erp/clientes`, subscription/settings pages and command composables | `useErpTrialRegistration`, `/erp/testar/[code]` and focused trial/customer tests |
| Navigation | `gestao-erp.vue`, `sidebarNav.ts` and existing shell tests | No replacement shell or new design system |

New names and signatures below are planning contracts; they are not present yet. Preserve existing callers when extracting methods. Do not modify generated `backend/AGENTS.md` or `backend/CLAUDE.md`.


## Checkout, sequencing and verification contract

This plan lives in `/home/obsidian/dev/opmoni/.worktrees/erp-client-onboarding` on `codex/erp-client-onboarding`. Its planning commit `1e86053` has the accepted local `main` commit `babea5d` as its immediate parent. Before implementation check `git status --short` and `git merge-base --is-ancestor refs/heads/main HEAD`; resolve a newer accepted main intentionally instead of rebuilding from an old worker branch. This document is the only file changed by this plan refinement.

| Task | Must consume completed tasks | Independently reviewable deliverable |
|---|---|---|
| 1 | None | Additive schema, domain values and truthful legacy compatibility |
| 2 | 1 | Core office eligibility and typed capabilities |
| 3 | 1, 2 | Direct paid enrollment from new/existing Client |
| 4 | 1, 2, 3 | Optional owner setup without financial side effects |
| 5 | 1, 2 | Office-link configuration and safe public availability |
| 6 | 1, 2, 3, 5 | Atomic office-attributed trial signup and recovery |
| 7 | 1, 2, 3, 6 | Time-based entitlement, expiry and explicit conversion |
| 8 | 2, 3, 4 | Office customer enrollment screens |
| 9 | 4, 5, 6, 7 | Public signup, trial state and conversion screens |
| 10 | 2, 5, 8, 9 | Coherent navigation/settings/feedback |
| 11 | 3, 4, 5, 6, 7 | Independent-process race evidence |
| 12 | 1–11 | Integrated acceptance, rollout record and scenario evidence |

The customer-admin and trial slices share identity, licence and subscription transactions, so keep one specification and this dependency-ordered plan; do not create independent implementations of their shared services. Commit after each accepted task using only that task's named files; preserve prior files in the same branch rather than rewriting task history.

For each named PHP suite below, use `cd backend && FISCAL_ENVIRONMENT=producao vendor/bin/phpunit --filter 'ClassA|ClassB'`; PASS means exit 0 with no failures/errors and explicitly justified skips. Run PHP formatter as `vendor/bin/pint --dirty --format agent` from `backend/`. For changed Nuxt/TS files, use `cd frontend && node node_modules/eslint/bin/eslint.js <exact changed paths>` and the task's actual rendered tests; PASS means exit 0, not a process launch. In Task 11 run `cd backend && vendor/bin/phpunit -c phpunit.pgsql.xml --filter OfficeErpCommercialConcurrencyTest` through the verified isolated-runtime wrapper with all existing isolation markers. Do not run tests in the development DB or make a broad runtime reset. Missing dependencies are a setup prerequisite, not permission to auto-install or link another checkout's generated configuration.

## Cross-task payload and state contracts

`OfficeErpEnrollmentInput` is a discriminated source: either `client_id: number` or `new_client` (validated existing company-client fields), never both. Both forms include top-level `erp_plan_id`, canonical string `price`, literal `currency: 'BRL'`, integer `interval_months`, integer `due_in_days`, ISO `start_on` and IANA `billing_timezone`. Use the existing accepted cycle/day ranges from `OfficeErpContracts::terms()` rather than inventing another validator. `OfficeErpEnrollmentOutcome` contains numeric `client_id`, `company_id`, `subscription_id`, `contract_id`, `invoice_id` and `owner_setup_status: 'unclaimed'|'claimed'`. New enrollment returns HTTP 201 `{data: outcome}`; existing office operation lookup returns the same `data` object. Changed payload with the same key returns 409, never another invoice.

`TrialRegistrationOutcome` contains numeric `client_id`, `company_id`, `subscription_id`, `contract_id`, `trial_id`, literal `status: 'trialing'` and UTC ISO `started_at`/`ends_at`. Successful POST `/api/v1/erp/trials/{code}/register` returns HTTP 201 `{identity: Identity, data: TrialRegistrationOutcome}` after normal identity/session issuance; the new endpoint's `identity` is the unchanged existing identity resource shape. Authenticated GET `/api/v1/erp/trials/current` accepts the original `Idempotency-Key` header and returns HTTP 200 `{data: TrialRegistrationOutcome}` only for that owner/operation. It does not automatically select another Company or expose the most recent unrelated registration. Register/current use the same shared key grammar and 16–128 length as the current owner-acceptance protocol. Define/export these response types in `frontend/shared/types/officeErpCommercial.ts`; Task 9 must consume Task 6's exact shape.

Definitive 409 `login_required`, 422 validation, 403 eligibility and 410 invalid continuation remain editable/login/denied states as appropriate. Network timeout, 5xx or a failed identity refresh after committed success moves signup to read-only recovery; no password POST is repeated. Password exists only in the local active submission, and nonsecret recovery metadata has a 15-minute client retention cap. All response status/error examples below are planned assertions, not observed test results.

### Task 1: Add compatible schema and trial representations

**Files:**
- Create: `backend/database/migrations/2026_10_25_090000_add_erp_customer_enrollment.php`, `backend/database/migrations/2026_10_25_091000_create_erp_office_trials.php`.
- Create: `backend/app/Models/Erp/ErpSubscriptionTrial.php`, `backend/app/Models/Erp/Commercial/OfficeErpTrialLink.php`.
- Modify: `backend/app/Models/Erp/ErpCompany.php`, `backend/app/Models/Erp/ErpSubscription.php`, `backend/app/Models/Erp/Commercial/OfficeErpContract.php`, `backend/app/Models/Erp/Commercial/OfficeErpOwnerInvitation.php`, `backend/app/Enums/Erp/ErpSubscriptionStatus.php`.
- Modify: `backend/app/Http/Resources/Erp/ErpSubscriptionResource.php`, `backend/app/Http/Controllers/Api/V1/Admin/ErpSubscriptionController.php`.
- Test: `backend/tests/Feature/OfficeErpCommercial/TrialSchemaTest.php`, `backend/tests/Feature/ErpCommercialAccessTest.php`.

**Interfaces:** Produces `trialing`/`trial_expired`, `owner_setup_status`, contract `provisioning_source`/`erp_plan_id`/administrative attribution, invitation `purpose`, and the relationships `ErpSubscription::trial()` / `ErpCompany::trial()`. The grant timestamps and original normalized CNPJ are immutable except explicit converted/expired markers. The link's public UUID is not an authentication token.

- [ ] **Step 1:** Add `test_legacy_records_keep_owner_and_financial_snapshots`, `test_trial_cnpj_is_unique_across_offices`, `test_trial_read_status_is_not_generic_admin_write_permission`. Assert the existing default provenance is `legacy_invitation`, existing Companies are `claimed`, and trials cannot be fabricated through platform updates.

```php
$this->assertSame('claimed', $existingCompany->fresh()->owner_setup_status);
$this->assertSame('legacy_invitation', $existingContract->fresh()->provisioning_source);
$this->assertDatabaseCount('erp_subscription_trials', 1); // after conflicting second grant
```

- [ ] **Step 2:** Run `cd backend && vendor/bin/phpunit --filter TrialSchemaTest`; require the new-field/uniqueness assertions to fail before production edits.
- [ ] **Step 3:** Add the design fields/tables, restrictive trial provenance FKs, safe model relations/casts and valid ERP subscription statuses. Retain one subscription per Company. Add validity checks to `erp_subscriptions`, never the office `subscriptions` constraint. Read filters may expose trials; generic status mutations cannot create/reset them.
- [ ] **Step 4:** Run `cd backend && vendor/bin/phpunit --filter 'TrialSchemaTest|ErpCommercialAccessTest'` and Pint on changed PHP. Repeat migration/uniqueness checks on the guarded PostgreSQL profile; require no rewrites of old invoices, cursors or membership.
- [ ] **Step 5:** Commit only these schema/models/status/resource/tests as `feat(erp): add customer enrollment and trial provenance`. Covers OpenSpec 1.1–1.2.

### Task 2: Align core admission and policy permissions

**Files:** Modify `backend/app/Erp/Commercial/OfficeErpAdmission.php`, `backend/app/Erp/Commercial/OfficeErpExecutionContext.php`, `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpContextController.php`, `backend/tests/Feature/OfficeErpCommercial/CommercialAdmissionTest.php`, `frontend/shared/types/officeErpCommercial.ts`, `frontend/tests/contracts/officeErpCommercial.ts`.

**Interfaces:** Preserve existing capabilities and add booleans `can_provision`, `can_start_trial`, `can_manage_trial_link`. Existing `can_configure` describes real-admin policy maintenance independently of retail enablement; new paid/trial provisioning requires active office subscription and explicit capacity/modules. Produce action admission for `customer.enroll`, `owner.setup.issue`, `trial.link.configure`, `trial.link.rotate`, `trial.register`, `trial.convert`, and system-only trial expiry; preserve existing classifications. Add `OfficeErpExecutionContext::trialRegistration(Account $account, User $actor, string $verifiedRegistrationIdentity): self`, keeping `actorKind='owner'`, fixed purpose `trial.register`, and stable verified trial-owner/registration identity. This factory consumes server-verified link/version and established owner identity, never caller tenant headers; only trial registration is allowed from that purpose.

- [ ] **Step 1:** Add tests for retail disabled with positive capacity, inactive office subscription maintenance, zero/null capacity, capacity downgrade, operator and Super Admin without membership, and stale Account assertion. Pin the core case:

```php
$response->assertOk()->assertJsonPath('data.can_configure', true)
    ->assertJsonPath('data.can_provision', true)
    ->assertJsonPath('data.can_sell_new', false);
```

- [ ] **Step 2:** Run `cd backend && vendor/bin/phpunit --filter CommercialAdmissionTest`; observe the resale-coupling regression fail.
- [ ] **Step 3:** Extend the existing admission methods; do not make public trial registration an office-admin impersonation. Policy reads/maintenance retain D009 after office-subscription loss; new public provisioning cannot use that exception. Leave platform quota edits platform-owned.
- [ ] **Step 4:** Re-run focused PHP tests/Pint and `cd frontend && node node_modules/typescript/bin/tsc --project tsconfig.contracts.json --noEmit`; require old and new capability shapes to type-check.
- [ ] **Step 5:** Commit `fix(erp): separate customer administration from retail resale`. Covers 2.1.

### Task 3: Implement direct customer/subscription enrollment

**Files:**
- Modify: `backend/app/Services/ClientManager.php`, `backend/app/Erp/ErpCompanyProvisioning.php`, `backend/app/Erp/Commercial/OfficeErpContracts.php`, `backend/app/Erp/Commercial/OfficeErpLicenceAllocator.php`, `backend/routes/office-erp-commercial.php`.
- Create: `backend/app/Erp/Commercial/OfficeErpCustomerEnrollment.php`, `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpEnrollmentController.php`, `backend/tests/Feature/OfficeErpCommercial/CustomerEnrollmentTest.php`.
- Modify: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpOperationController.php` for authorized lookup.

**Interfaces:** `ClientManager::prepareCompanyRegistration(array $data): array`, `createPreparedCompany(Account $account, array $prepared): Client`; `ErpCompanyProvisioning::createUnclaimedCompany(array $companyData): ErpCompany`, retaining the old owner wrapper. `OfficeErpCustomerEnrollment::plans(OfficeErpExecutionContext $ctx): array` and `enroll(OfficeErpExecutionContext $ctx, array $input, string $key): array` return the design's typed IDs and owner-setup state. Exactly one of `client_id` or `new_client` is required; paid `start_on` is at or before current office-local date. Produce `OfficeErpLicenceAllocator::allocateProvisioned(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, ErpCompany $company): OfficeErpLicenceAllocation` for verified administrative/trial provisioning; existing `occupy()` requires a legacy reservation and must not be called as if a direct assignment had an invitation. Produce `OfficeErpContracts::activateProvisioned(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, CarbonImmutable $start, string $invoiceOperationKey): array` for an already locked administrative/trial contract within the caller's transaction; it writes paid anchors/cursor and returns the original IDs plus first invoice. Legacy `activate()` preserves owner acceptance and delegates only shared internals, without a second outer operation journal.

- [ ] **Step 1:** Add route tests `test_existing_client_enrolls_without_offer_or_owner_acceptance`, `test_new_client_lookup_failure_leaves_no_partial_records`, `test_direct_enrollment_replay_has_one_invoice`, `test_foreign_or_inactive_client_is_not_enrolled`, `test_invalid_source_plan_terms_and_future_start_have_no_effect`, `test_owned_company_requires_explicit_current_owner_association`. Pin the result without assigning the office user Company access:

```php
$response->assertCreated()->assertJsonPath('data.owner_setup_status', 'unclaimed');
$this->assertDatabaseCount('erp_subscriptions', $beforeSubscriptions + 1);
$this->assertDatabaseCount('office_erp_invoices', $beforeInvoices + 1);
$this->assertDatabaseMissing('erp_company_memberships', ['erp_company_id' => $companyId, 'user_id' => $officeAdmin->id]);
```

- [ ] **Step 2:** Run `cd backend && vendor/bin/phpunit --filter CustomerEnrollmentTest`; expect missing route/behavior failure.
- [ ] **Step 3:** Add GET `/enrollment-plans` and POST `/enrollments` under existing office middleware. Prepare CNPJ provider data before locks; persist within the aggregate with plan/client/module/licence rechecks. Reuse null offer references, frozen exact-money terms, period calendar and first-invoice producer. Existing-owned CNPJ returns `company_association_required`; no automatic adoption. Journals contain no credentials. Use `allocateProvisioned()` to create one occupied allocation without a fake invitation reservation and `activateProvisioned()` once inside the outer enrollment operation transaction; Task 7 reuses both for conversion. The Step 1 test set also covers new-client CPF denial, malformed decimal/cycle/plan/foreign module, future `start_on` rejection, explicit owned-Company association and no fabricated provider data.
- [ ] **Step 4:** Run `CustomerEnrollmentTest`, `ContractLifecycleTest`, `InvoiceTest`, `ClientCrudTest`, `ErpCommercialAccessTest` and Pint; require rollback, replay and unchanged legacy owner signup.
- [ ] **Step 5:** Commit `feat(erp): enroll customers directly from the office portfolio`. Covers 2.2–2.3.

### Task 4: Separate optional owner access setup

**Files:**
- Create: `backend/app/Erp/Commercial/OfficeErpOwnerSetup.php`, `backend/tests/Feature/OfficeErpCommercial/OwnerSetupTest.php`.
- Modify: `backend/app/Erp/Commercial/OfficeErpOwnerInvitations.php`, `backend/app/Erp/Commercial/OfficeErpOwnerAcceptance.php`, `backend/app/Erp/Commercial/OfficeErpInvitationContinuation.php`.
- Modify: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpInvitationController.php`, `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpOwnerSetupController.php`, `backend/routes/office-erp-commercial.php`, `backend/routes/office-erp-invitations.php`.


**Interfaces:** `OfficeErpOwnerSetup::issue(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, array $owner, string $key): array`; `accept(Request $request, array $input, string $key): OfficeErpContract` consumes only a verified `access_setup` continuation. Current commercial accept behavior remains purpose-dispatched and compatible. Return nonsecret IDs; raw access fragment remains one-time, never durable outcome metadata.

- [ ] **Step 1:** Add intended owner claim/replay, existing email login, foreign consumed continuation, already claimed Company and unchanged invoice/allocation tests. Pin no second financial effect:

```php
$this->assertSame($subscriptionId, $company->fresh()->subscription->id);
$this->assertSame($invoiceCount, OfficeErpInvoice::count());
$this->assertSame($allocationCount, OfficeErpLicenceAllocation::count());
```

- [ ] **Step 2:** Run `cd backend && vendor/bin/phpunit --filter OwnerSetupTest`; require failure before edits.
- [ ] **Step 3:** Reuse verified intended-person continuations and row locks. Claim only an explicitly unclaimed Company; preserve password, member uniqueness and current owner. Add POST `/contracts/{contract}/owner-setup` with maintenance admission, without issuing licences or automatic delegation.
- [ ] **Step 4:** Run `OwnerSetupTest`, `OwnerAcceptanceTest`, `OwnerInvitationTest`, `CompanyAccessTest` and Pint. Include replay after resale loss/effective contract, foreign actor denial and anonymous setup transport.
- [ ] **Step 5:** Commit `feat(erp): decouple customer access setup from subscriptions`. Covers 2.4.

### Task 5: Configure and resolve office trial links

**Files:** Create `backend/app/Erp/Commercial/OfficeErpTrialLinks.php`, `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpTrialLinkController.php`, `backend/app/Http/Controllers/Api/V1/Erp/ErpTrialController.php`, `backend/tests/Feature/OfficeErpCommercial/TrialLinkTest.php`, `backend/routes/office-erp-trials.php`. Modify `backend/config/erp.php`, `backend/.env.example`, `backend/routes/api.php`, `backend/routes/office-erp-commercial.php`, `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpOperationController.php`.

**Interfaces:** `OfficeErpTrialLinks::read(OfficeErpExecutionContext $ctx): array`, `configure(OfficeErpExecutionContext $ctx, array $input, string $key): array`, `rotate(OfficeErpExecutionContext $ctx, string $key): array`, `resolvePublic(string $code): array`. Public resolve exposes only sponsor display, selected plan/modules, duration and safe availability. Produce `ErpTrialController::show(Request $request, string $code, OfficeErpTrialLinks $links): JsonResponse` for public availability in this task; registration/current are added in Task 6, not dangling routes. Add `ERP_OFFICE_TRIAL_ENABLED=false`; no deployed environment write.

- [ ] **Step 1:** Add real selected-office authorization, public safe-fields, release-off, wrong-near-prefix, rotated-code and independent quota-zero tests:

```php
$this->getJson('/api/v1/erp/trials/'.$oldCode)->assertNotFound();
$response->assertJsonPath('data.duration_days', 7)->assertJsonMissingPath('data.clients');
```

- [ ] **Step 2:** Run `cd backend && vendor/bin/phpunit --filter TrialLinkTest`; observe missing capability failure.
- [ ] **Step 3:** Add exact design routes, selected technical-plan configuration, public UUID/revision rotation and current-origin link construction. Include `office-erp-trials.php` in `backend/routes/api.php` beside the existing public invitation include, inside TrustedIngress/cookie/serialized-session/AccessSecurity but before the `auth` group. `backend/routes/erp.php` is included inside `auth` and is not the public trial registration mount. Mount only public GET `/erp/trials/{code}` as `erp.trials.show` in this task and constrain `{code}` to a UUID. Task 6 adds `erp.trials.register` and authenticated `erp.trials.current`; place the static current route before parameter routes. Do not install an unusable controller/route pair between tasks. Resolve Account only from its row; link knowledge grants no identity/membership. Reauthorize lookup with current action/resource.
- [ ] **Step 4:** Re-run `TrialLinkTest`, `CommercialOperationsTest`, `CommercialApiContractsTest`, Pint. Confirm `.env` and other release flags are unchanged.
- [ ] **Step 5:** Commit `feat(erp): add office-specific ERP trial links`. Covers 3.1.

### Task 6: Register a trial atomically through its office link

**Files:** Create `backend/app/Erp/Commercial/OfficeErpTrialRegistration.php`, `backend/tests/Feature/OfficeErpCommercial/TrialRegistrationTest.php`. Modify `backend/app/Http/Controllers/Api/V1/Erp/ErpTrialController.php`, `backend/routes/office-erp-trials.php`, `backend/app/Http/Middleware/AccessSecurity.php`, `backend/app/Actions/ErpCommercialRegistration.php`, `backend/app/Erp/ErpCompanyProvisioning.php`. Existing identity/provisioning methods remain compatible.

**Interfaces:** `OfficeErpTrialRegistration::register(Request $request, string $code, array $input, string $key): array`; `current(Request $request, string $key): array` resolves only that authenticated owner's committed operation. Registration/current return the exact Cross-task payload and state contracts above; passwords never enter durable operation payloads. Consume Task 5 public controller/file and add `ErpTrialController::register(Request $request, string $code, OfficeErpTrialRegistration $registration): JsonResponse` and `current(Request $request, OfficeErpTrialRegistration $registration): JsonResponse`; the latter requires `auth` only and no selected Company. Use existing server-only tenant/execution contexts without fabricating a CurrentAccount admin.

- [ ] **Step 1:** Add actual-route tests for new identity, authenticated existing owner, existing email without login, same-office active Client reuse, foreign/supplied Client ID, archived Client, provider failure, exhausted portfolio/licence, and no office membership. Freeze UTC time and assert:

```php
$this->assertSame(168 * 3600, (int) $trial->started_at->diffInSeconds($trial->ends_at));
$this->assertDatabaseCount('office_erp_invoices', $beforeInvoices);
$this->assertDatabaseMissing('account_memberships', ['account_id' => $sponsorId, 'user_id' => $ownerId]);
```

- [ ] **Step 2:** Run `cd backend && vendor/bin/phpunit --filter TrialRegistrationTest`; establish missing behavior RED.
- [ ] **Step 3:** Extend existing `AccessSecurity` route-name budgets: trial registration 5/minute per trusted ingress IP and authenticated actor when present, availability/current 30/minute; preserve the existing Origin and CSRF checks and return 429 with `Retry-After`. Rate-budget keys include the route and identity/IP dimension, never the submitted password. Add the design transaction. Recheck link revision, office and plan limits after provider preparation; use durable CNPJ uniqueness/advisory locks, not email-only trial eligibility. A post-commit session error returns recoverable outcome/login without another password submission. `current` requires ownership and operation-key scope.
- [ ] **Step 4:** Re-run `TrialRegistrationTest`, `ErpCommercialAccessTest`, `ClientCrudTest` and Pint; assert exactly one Client/Company/subscription/grant and no side effects after pre-commit failure. Test post-commit Redis/session failure separately with no grant reset. Test `test_anonymous_trial_entry_does_not_require_office_membership`, `test_public_current_is_not_parsed_as_link_code`, `test_trial_registration_requires_exact_origin_and_csrf` and `test_trial_throttle_has_retry_after`. Tests use distinct trusted fixture IPs, without disabling limiters. Assert successful response shape `identity.id` plus `data.trial_id`, no top-level private portfolio data, and foreign-owner current returns 404.
- [ ] **Step 5:** Commit `feat(erp): register office-attributed seven-day trials`. Covers 3.2.

### Task 7: Enforce expiry and convert explicitly to paid

**Files:** Create `backend/app/Erp/Commercial/ErpTrialLifecycle.php`, `backend/app/Console/Commands/ExpireErpTrialsCommand.php`, `backend/tests/Feature/OfficeErpCommercial/TrialLifecycleTest.php`. Modify `backend/app/Erp/ErpCommercialGate.php`, `backend/app/Http/Middleware/ResolveCompany.php`, `backend/app/Erp/Fiscal/ErpFiscalAttemptAuthorization.php`, `backend/app/Erp/Commercial/OfficeErpCustomerEnrollment.php`, `backend/app/Erp/Commercial/OfficeErpRecurringBilling.php`, `backend/routes/business-console.php`, `backend/routes/office-erp-commercial.php`, `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpEnrollmentController.php`, `backend/app/Http/Resources/Erp/ErpSubscriptionResource.php`. Read existing `backend/app/Jobs/ProcessErpFiscalAttempt.php` and `backend/app/Jobs/DeliverErpAccountingDocument.php` to trace actual effect gates before adding a new job-local bypass. Modify `backend/app/Erp/Accounting/ErpAccountingHandoffService.php` at its locked revalidation fence only if needed to stop new trial-origin queued effects after expiry; keep valid previously delivered history intact.

**Interfaces:** Retain `ErpCommercialGate::allowsOperatingWrites(ErpCompany $company): bool` and `moduleEnabled(ErpCompany $company, string $moduleKey): bool`; both must recognize real unexpired trial grants and frozen accepted trial-contract modules. Add `ErpCommercialGate::operatingWriteDenialCode(ErpCompany $company): ?string` as the shared reason source (`erp_trial_expired`, existing `erp_subscription_inactive`, or null) and retain boolean API callers. `ErpTrialLifecycle::expire(ErpSubscriptionTrial $trial, CarbonImmutable $now): void`; `OfficeErpCustomerEnrollment::convertTrial(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, array $terms, string $key): array` returns original IDs plus first paid invoice. Hourly `office-erp:expire-trials --batch=50` validates batch range 1–100.

- [ ] **Step 1:** Add `test_exact_trial_end_blocks_write_before_scheduler`, `test_accepted_trial_contract_modules_are_usable`, `test_missing_grant_and_manual_hold_deny`, `test_trial_does_not_generate_invoice`, and paid conversion/replay tests. Assert:

```php
$this->travelTo($trial->ends_at);
$write->assertForbidden()->assertJsonPath('code', 'erp_trial_expired');
$read->assertOk();
$this->assertSame($originalTrialStart, $trial->fresh()->started_at->toISOString());
$this->assertSame($originalSubscriptionId, $converted['subscription_id']);
```

- [ ] **Step 2:** Run `cd backend && vendor/bin/phpunit --filter TrialLifecycleTest`; verify exact-boundary/module gating failures first.
- [ ] **Step 3:** Use server-time entitlement before HTTP/queued writes, including genuine external-effect fences; preserve operating/fiscal release flags and holds. Expiry and conversion share aggregate lock ordering; expire/release only still-trial records. Paid conversion uses explicit terms/start date and exact first-invoice producer, no retroactive charge or fake payment.
- [ ] **Step 4:** Run `TrialLifecycleTest`, `RecurringInvoiceTest`, `CompanyAccessTest`, `ContractLifecycleTest`, `ErpCommercialAccessTest` and Pint. Add `test_queued_trial_effect_is_denied_after_expiry_without_provider_call` with spies at the real fiscal/accounting effect fence, retaining all separate dispatch gates. Freeze clocks for one instant before, exact end, after end and timezone boundary; require no reliance on scheduler timing.
- [ ] **Step 5:** Commit `feat(erp): enforce trial expiry and explicit paid conversion`. Covers 3.3–3.4.

### Task 8: Build customer-first office enrollment UI

**Files:** Modify `frontend/app/pages/gestao-erp/clientes/index.vue`, `frontend/app/pages/gestao-erp/clientes/novo.vue`, `frontend/app/pages/gestao-erp/assinaturas/index.vue`, `frontend/app/pages/gestao-erp/assinaturas/[id].vue`, `frontend/shared/types/officeErpCommercial.ts`, `frontend/tests/officeErpOnboarding.test.ts`, `frontend/tests/officeErpContracts.test.ts`, `frontend/tests/contracts/officeErpCommercial.ts`. Reuse `frontend/app/components/customers/ClientCreateModal.vue` and its form utilities where compatible; do not silently bypass CNPJ lookup.

**Interfaces:** Consume Tasks 2–4 capability/plan/enrollment/owner-setup APIs with real `useOfficeErpCommand`. The enrollment form discriminates new client versus portfolio client, supplies exact paid terms and never collects a customer password. List summaries include selected plan, owner-setup state, trial state/end or next billing. Expose user action labels from the design.

- [ ] **Step 1:** Add rendered-SFC tests `registers_without_published_offer`, `selects_portfolio_client_without_duplicate`, `recovers_enrollment_without_second_invoice`, `preserves_inputs_on_lookup_failure`, `clears_protected_customer_after_authority_change`. Assert payload lacks offer revision/password and uses canonical strings:

```ts
assert.equal(body.price, '9007199254740993.01')
assert.equal('revision_id' in body, false)
assert.equal('password' in body, false)
assert.equal(executeCalls.length, 1) // after uncertain-outcome recovery
```

- [ ] **Step 2:** Run `cd frontend && node --experimental-strip-types --test tests/officeErpOnboarding.test.ts tests/officeErpContracts.test.ts`; observe intended behavior fail.
- [ ] **Step 3:** Read Nuxt UI/Impeccable guidance and installed component examples, then compose the two source modes, technical plan selection, explicit terms and post-success owner setup. Preserve search/page state, field errors, fresh-state recovery, exact money and stale-context invalidation; disable only genuinely in-flight actions.
- [ ] **Step 4:** Re-run the focused rendered suite, changed-path ESLint and contract tsc; test actual handlers rather than compilation only. Ensure existing legacy unknown-operation recovery remains compatible.
- [ ] **Step 5:** Commit `feat(erp): center office enrollment on customers and subscriptions`. Covers 4.1.

### Task 9: Build public trial signup, owner status and conversion UI

**Files:** Create `frontend/app/pages/erp/testar/[code].vue`, `frontend/app/composables/useErpTrialRegistration.ts`, `frontend/tests/officeErpTrialRegistration.test.ts`. Modify `frontend/tests/useAccessApiAdmissionQueue.test.ts`, `frontend/tests/officeErpOwnerActivation.test.ts`, `frontend/tests/officeErpPolicyAndOwner.test.ts`, `frontend/app/pages/gestao-erp/configuracoes.vue`, `frontend/app/pages/gestao-erp/assinaturas/[id].vue`, `frontend/app/pages/erp/contrato.vue`, `frontend/shared/types/officeErpCommercial.ts`. Read `frontend/app/composables/useAccessApi.ts` and `frontend/shared/utils/business.ts`; modify them only if the real public-route transport regression requires it, never by broadly opening `/erp-commercial` paths.

**Interfaces:** Add/export `TrialRegistrationInput` in `frontend/shared/types/officeErpCommercial.ts` with `name: string`, `email: string`, `password?: string` (new identities only), `company_name: string`, `cnpj: string`, `tax_regime: string` validated against the existing allowed company regimes, and `confirm_existing_association?: boolean`; no Account/Client ID is accepted. `useErpTrialRegistration()` exposes refs `phase` (`availability|form|submitting|confirmed|recovery`), `failure`, `outcome`; methods `load(code: string): Promise<void>`, `submit(input: TrialRegistrationInput): Promise<void>`, `recover(): Promise<void>`. Reuse `useAccessApi` CSRF/identity transport and no automatic resend; discard password from local state after first ambiguous/confirmed submission and persist only nonsecret recovery key/intended email/time. Task 5 supplies office link controls; Task 7 supplies conversion/status endpoints.

- [ ] **Step 1:** Render the real signup/owner/subscription SFCs and invoke handlers for anonymous no-office signup, existing-email login, definitive 422 correction, rotated link, post-commit identity-refresh failure and logout/session replacement. Pin:

```ts
assert.equal(passwordPosts.length, 1)
assert.equal(recoveryGets.length, 1)
assert.match(renderedText, /Teste de 7 dias/)
assert.equal(publicRequestHeaders.has('X-Account-Id'), false)
```

- [ ] **Step 2:** Run `cd frontend && node --experimental-strip-types --test tests/officeErpTrialRegistration.test.ts tests/officeErpOwnerActivation.test.ts tests/officeErpPolicyAndOwner.test.ts`; require the new flows to fail before UI edits.
- [ ] **Step 3:** Build the public page without office auth middleware/shell. Show sponsor/plan, explicit existing-association confirmation, real end dates, "Em teste"/"Teste encerrado", copy/rotate controls and office-only explicit paid conversion. Existing `/erp/cadastro` stays pending and independent. Add exact-path transport exceptions only if real helper tests prove necessary; `/erp/*` is already outside tenant membership admission.
- [ ] **Step 4:** Run rendered/transport/owner tests and scoped ESLint/contracts typecheck. Verify normal setup anchors and owner activation flows remain intact; no raw owner tokens/passwords survive reload or enter durable test outcomes. Task 6's signup payload uses `response.identity` to refresh auth state and `response.data` for registered IDs; recovery consumes only `response.data` plus the existing identity refresh, never an invented `.user` wrapper.
- [ ] **Step 5:** Commit `feat(erp): expose office-linked signup and trial status`. Covers 4.2.

### Task 10: Simplify navigation, header and settings feedback

**Files:** Modify `frontend/app/pages/gestao-erp.vue`, `frontend/app/utils/sidebarNav.ts`, `frontend/app/pages/gestao-erp/configuracoes.vue`, `frontend/tests/sidebarNav.test.ts`, `frontend/tests/officeErpOverview.test.ts`, `frontend/tests/officeErpPolicyAndOwner.test.ts`, `frontend/tests/officeErpOnboarding.test.ts`. Keep offer page APIs and already issued invitation routes.

**Interfaces:** Consume truthful `can_configure`/`can_manage_trial_link` and safe structured reason codes from Task 2/5. Primary destinations are overview, ERP customers, subscriptions, invoices, receipts, settings; Account selector is unchanged. Error presentation uses existing subtle error/retry components only for transient failures.

- [ ] **Step 1:** Add tests for no primary Offers entry, retained legacy offer route, no duplicated Account name, all six primary destinations, actual settings access when resale is disabled, and no retry for a definitive role/status denial. Keep ordinary admin eligibility and Super Admin-without-membership rejection.

```ts
assert.equal(primaryLinks.some(item => item.to === '/gestao-erp/planos'), false)
assert.match(renderedText, /Configurações/)
assert.equal(retryForBusinessRestriction, undefined)
```

- [ ] **Step 2:** Run `cd frontend && node --experimental-strip-types --test tests/sidebarNav.test.ts tests/officeErpOverview.test.ts tests/officeErpPolicyAndOwner.test.ts tests/officeErpOnboarding.test.ts`; require the intended navigation/permission assertions to fail before product changes.
- [ ] **Step 3:** Update tabs/sidebar/search once, remove only the redundant header span and render actionable eligibility panels. Retain print bypass, exact-root active state, actual Account selection, existing financial maintenance and transient retry feedback.
- [ ] **Step 4:** Re-run focused navigation/settings/onboarding suites and scoped ESLint. Browser-check keyboard/focus and horizontal table actions at 320px/390px/1366px, light/dark; report inherited accessibility findings separately.
- [ ] **Step 5:** Commit `fix(erp): simplify customer navigation and policy feedback`. Covers 4.3.

### Task 11: Prove race safety with independent PostgreSQL/Redis workers

**Files:** Modify `backend/tests/Integration/OfficeErpCommercialConcurrencyTest.php`, `backend/tests/Integration/OfficeErpCommercialConcurrencyWorker.php`, `backend/tests/Integration/OfficeErpCommercialProcessTestCase.php`; add isolated trial fixtures inside that harness rather than another shared runtime.

**Interfaces:** Consume actual service/routes from Tasks 3–7. Each worker uses the verified checkout, isolated DB `opmoni_test`, Redis 13/14/15 and existing required isolation markers. Preserve previously accepted race scenarios and restore tenant/clock context in finally blocks. Synchronize on real locks/transaction events, not blind sleeps.

- [ ] **Step 1:** Add tests `test_trial_last_licence_has_one_winner`, `test_same_cnpj_across_offices_has_one_trial`, `test_rotation_wins_before_registration_commit`, `test_expiry_and_paid_conversion_serialize`, `test_duplicate_conversion_has_one_first_invoice`. Assert exact domain outcomes:

```php
$this->assertSame([201, 409], $sortedRegistrationStatuses);
$this->assertSame(1, $trialCountForOriginalCnpj);
$this->assertSame(1, $firstPaidInvoiceCount);
```

- [ ] **Step 2:** Run `cd backend && vendor/bin/phpunit -c phpunit.pgsql.xml --filter 'OfficeErpCommercialConcurrencyTest::test_(trial_last_licence|same_cnpj_across_offices|rotation_wins|expiry_and_paid|duplicate_conversion)'` in the verified isolated fixture before adjusting production behavior; capture a genuine failure if a race defect exists. Never weaken locks or guards to make a fixture pass.
- [ ] **Step 3:** Implement deterministic worker fixtures/barriers and scoped domain fixes only when proven. Verify current subscription/Company links before inserting dependent rows; ensure unique valid fixture CNPJs across retained runs and snapshot persisted fields, not virtual outcome attributes.
- [ ] **Step 4:** Run `vendor/bin/phpunit -c phpunit.pgsql.xml --filter OfficeErpCommercialConcurrencyTest` from `backend/` twice in separate processes with exact-source fingerprints and no overlapping heavy frontend check. Require no errors, no partial loser effects and consistent retained-store repetition. Do not infer success from worker launch or static lint.
- [ ] **Step 5:** Commit `test(erp): prove office trial and conversion contention`. Covers 5.1.

### Task 12: Integrated acceptance, rollout documentation and scenario traceability

**Files:** Modify `README.md`, `docs/architecture/product-decisions.md`, `docs/architecture/system-spec-reconciliation.md`; create `docs/reviews/2026-10-25-erp-client-onboarding/acceptance.md` and `docs/reviews/2026-10-25-erp-client-onboarding/scenario-crosswalk.csv`. Modify `openspec/changes/archive/2026-10-28-simplify-office-erp-client-onboarding/tasks.md` checkboxes only with accepted evidence. Keep main canonical specs unsynchronized until the intended sync/archive workflow.

**Interfaces:** Consume all capability scenarios and task evidence. The crosswalk maps each scenario to a real test/browser assertion, source commit, observed result and honest remaining boundary. New design provenance supersedes mandatory offer/invitation/no-trial clauses while preserving D009, financial history and old invitation compatibility.

- [ ] **Step 1:** Run narrow suites, then the documented regular backend profile from full repository layout (`cd backend && FISCAL_ENVIRONMENT=producao vendor/bin/phpunit`) and Pint. Use the standard PHP runtime if host certificate fixtures differ; retain six known optional/precision skips explicitly instead of claiming universal live provider coverage.
- [ ] **Step 2:** Serially run the guarded full PostgreSQL profile and frontend `pnpm lint`, `pnpm typecheck`, `pnpm test`, `pnpm build` against the accepted source. Use the checkout's valid dependencies; no silent auto-install/prepare into another worktree. Record exit status, counts, source and limits; full gates cannot be replaced by scoped totals.
- [ ] **Step 3:** On a private guarded runtime, prove office new-client and portfolio enrollment without an offer, optional owner access, office-link self-registration, own-office attribution, exact-end writes denied/reads retained, explicit paid conversion with same data and one invoice, header/settings/mobile behavior and valid legacy invitations/printing. Do not send production email, provider or payment effects.
- [ ] **Step 4:** Rehearse additive migration and a compatible application rollback using a private restored copy. Assert unchanged prior invoice snapshots, passwords, owner/delegation links, APP_KEY and release flags. Application rollback must understand trial deadline/status; no destructive schema rollback or data reset.
- [ ] **Step 5:** Update the evidence/decision docs, run `openspec validate simplify-office-erp-client-onboarding --strict` and `git diff --check`, then commit `docs(erp): record customer onboarding and trial acceptance`. Leave any unverified scenario pending; no production rollout, spec archive or global acceptance is implied by this plan. Covers 5.2–5.3.

## Specification coverage matrix

Each row below covers its complete requirement block, including its retained legacy scenarios. Keep the 54 scenario identities in the final evidence crosswalk; an aggregate suite count is not a substitute for a scenario assertion.

| Capability / requirement | Owning plan tasks | Mandatory evidence surface |
|---|---|---|
| office-erp-commercial-management / Commercial administration uses the selected office | 2, 10 | Actual context/settings routes, roles and wrong-office denial |
| office-erp-commercial-management / Licence allocation is bounded and race safe | 3, 6, 7, 11 | Direct/trial quota and independent last-slot/conversion workers |
| office-erp-commercial-management / Owner onboarding preserves identities and Company ownership | 3, 4, 8 | New/portfolio source, provider rollback and retained legacy acceptance |
| office-erp-commercial-management / Contracts preserve agreed terms throughout their lifecycle | 3, 7, 8 | Frozen revisions, cancellation/receipts and first-invoice replay |
| office-erp-commercial-management / Resale-right loss preserves existing business | 2, 4, 7 | Existing business maintenance versus denied new provisioning |
| office-erp-commercial-management / The commercial workspace preserves the dashboard reference | 8, 9, 10, 12 | Actual handlers, six destinations, legacy routes and mobile/print checks |
| erp-commercial-access / ERP registration preserves platform initialization and global identities | 4, 6, 9 | Existing-email login, bootstrap failure and atomic no-partial signup |
| erp-commercial-access / Company entitlements are independent of office contracts | 1, 7 | Paid/pending/held/trial status, modules and exact-end HTTP/queued gates |
| erp-commercial-access / Commercial provisioning is repeatable and truthful | 3, 6, 7, 12 | Catalog initializer unchanged and no fake payment or duplicate records |
| erp-company-foundation / Administrative provisioning separates subscription from owner access | 3, 4 | Commercial admin denied Company operation; verified responsible person only |
| client-portfolio / Tenant client CRUD and validation | 3, 6 | Normal tenant CRUD regressions and narrow public sponsorship exception |
| erp-office-trials / Share links identify the sponsoring office without granting membership | 5, 6, 9 | Safe public response, rotation, no tenant headers/membership |
| erp-office-trials / Trial registration is atomic and attributable | 6, 9, 11 | Correct office association, capacity/provider failure and secret-free recovery |
| erp-office-trials / Trial eligibility is seven days and cannot be restarted | 1, 6, 7, 11 | Frozen 168-hour clock, normalized CNPJ across offices and no repeat grant |
| erp-office-trials / Expiry blocks writes and preserves data without billing | 7, 9, 11 | Exact cutoff before scheduler, reads kept and no trial invoice |
| erp-office-trials / Paid conversion is explicit and preserves the Company | 7, 9, 11 | Same IDs/data, hold preservation and exactly one paid invoice |

## Plan self-review and execution handoff

Coverage: Task 1 -> representation/compatibility; Task 2 -> office eligibility; Task 3 -> portfolio/new customer assignment; Task 4 -> owner access isolation; Task 5 -> link attribution; Task 6 -> atomic registration/secret boundaries; Task 7 -> exact expiry/modules/conversion; Tasks 8–10 -> actual Nuxt callers/settings/navigation; Task 11 -> races; Task 12 -> integrated/migration/browser evidence. All five Review Focus failures have explicit owning tests.

This document is a plan, not an implementation result. Its test snippets describe required assertions, not executed evidence. Implementation status remains unchecked in `tasks.md`. No product code, live runtime, dependencies or canonical specs are changed by creating these planning files. Review the design and plan before choosing native execution or per-task delegated implementation; neither starts during the propose workflow.
