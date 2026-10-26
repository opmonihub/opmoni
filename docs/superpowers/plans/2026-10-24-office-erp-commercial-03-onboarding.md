# Owner acceptance and contract lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an office sell to its portfolio while the actual owner accepts and the first invoice activates atomically.

**Architecture:** Reservations precede owner confirmation, and accepted allocation precedes explicit activation. Existing Company adoption is staged without changing direct access; activation consumes the tested billing engine and one current subscription.

**Tech Stack:** Laravel 13 / PHP 8.4 / PostgreSQL 18 / Redis 8; Nuxt 4.6.0 / Vue 3.5.43 / Nuxt UI 4.11.3 / TypeScript / Zod 4.6.5; existing BCMath, PHPUnit and Node test runner.

**Spec:** [design](../../../openspec/changes/archive/2026-10-25-add-office-erp-commercial-management/design.md), [five delta specifications](../../../openspec/changes/archive/2026-10-25-add-office-erp-commercial-management/specs/), and [master execution plan](2026-10-24-office-erp-commercial-management.md). All original OpenSpec requirements remain in scope.

## Global Constraints

- Preserve the selected worktree, source checkout, existing identities, APP_KEY and private .env files; no merge/push/archive or production rollout.
- "Do not loosen `ResolveAccount` for other business routes"; Super Admin has no implicit tenant membership.
- "Use exact `NUMERIC(20,2)` money columns and canonical two-place decimal strings at API boundaries." No new arithmetic/UI/PDF dependency.
- "All office calls use the normal private Nuxt `/api/v1` proxy, CSRF for writes, Account assertion and no-store responses."
- Apply the Nuxt UI skill and actual official APIs/examples before UI edits; preserve installed Nuxt 4.6.0 / UI 4.11.3 and `.dashboard/app` identity/contrast. Copy is pt-BR; code/artifacts English.
- The [master plan](2026-10-24-office-erp-commercial-management.md) defines the shared shapes, lock order, baseline and validation policy; all of them bind every task below.

## Review Focus

- An invitation for an existing email cannot overwrite a password or bypass login (Task 2).
- Losing allowance before consumption leaves no Company, membership, link or occupied allocation (Tasks 1/2).
- An owner logging in without any membership reaches setup without an access redirect loop (Task 2).
- An accepted occupied sale activates after right loss without a second capacity check/allocation (Task 3).
- A period-end cancellation preserves prior debt/data and never clears unrelated Company security (Task 3).

---

## File Structure

Task file lists define the change scope; files shared by sequential tasks must be carried forward, never recreated. Factories live beside their matching existing Erp factories under `backend/database/factories/Erp/Commercial/`. Domain services live in `backend/app/Erp/Commercial/`; persistent Account-owned records in `backend/app/Models/Erp/Commercial/`; OfficeErp HTTP controllers in their named V1 directory; UI composes existing shells. Existing Company finance records and global auth transports are reused only at their specified boundaries. These paths are planned, not evidence of existing implementation.

### Task 1: Reserve capacity and issue reviewed portfolio invitations

**OpenSpec:** 4.1, 9.4 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Create: `backend/app/Models/Erp/Commercial/OfficeErpOwnerInvitation.php`
- Create: `backend/database/migrations/2026_10_24_130000_create_office_erp_owner_invitations.php`
- Create: `backend/app/Erp/Commercial/OfficeErpOwnerInvitations.php`
- Create: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpClientController.php`
- Create: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpInvitationController.php`
- Create: `frontend/app/pages/gestao-erp/clientes/index.vue`
- Create: `frontend/app/pages/gestao-erp/clientes/novo.vue`
- Create: `backend/tests/Feature/OfficeErpCommercial/OwnerInvitationTest.php`
- Create: `frontend/tests/officeErpOnboarding.test.ts`
- Modify: `backend/routes/office-erp-commercial.php`

- Create: `backend/database/factories/Erp/Commercial/OfficeErpOwnerInvitationFactory.php`
- Modify: `frontend/app/pages/gestao-erp.vue`
- Modify: `frontend/app/utils/sidebarNav.ts`

**Interfaces:**
- Consumes: Foundation offers/context/allocator/operations and Client.tax_id with active ClientStatus; existing Client is an office portfolio entity, not ErpContact.
- Produces: OfficeErpOwnerInvitations::preview($ctx,int $clientId,int $revisionId,array $owner):array; issue($ctx,int $clientId,int $revisionId,array $owner,string $key):array{invitation_id:int,expires_at:string,url?:string}; revoke($ctx,int $id,string $reason,string $key):void; reissue($ctx,int $id,string $key):array. GET /erp-commercial/clients, POST /clients/{id}/onboarding-preview and POST /clients/{id}/invitations plus invitation revoke/reissue actions. issue() creates the draft contract/snapshot and reservation atomically, then sets awaiting_owner; no separate contract-create API is required.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_seven_day_secret_reservation
self::assertSame(now()->addDays(7)->toDateTimeString(), $expiresAt);
self::assertNotSame($rawToken, $invitation->token_hash);
self::assertSame(1, $usage['reserved']);
// test_foreign_or_inactive_client
$foreignClient->assertNotFound();
$inactiveClient->assertUnprocessable();
self::assertSame($beforeContracts, $afterRejectedContracts);
// test_expiry_reissue_revocation_release_once
self::assertSame(1, $usageAfterReissue['reserved']);
// test_issue_outcome_and_logs_never_persist_raw_secret
self::assertStringNotContainsString($rawToken, $storedOperationJson);
self::assertStringNotContainsString($rawToken, $capturedLogs);
// test_real_review_sfc_preserves_fields_and_recovers_issued_status
```

```ts
assert.equal(view.ownerEmail.value, 'owner@example.test')
assert.equal(recovered.invitation_id, firstInvitationId)
assert.equal(blindResendCount, 0)
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/OwnerInvitationTest.php`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** Show explicit seller, published revision, intended owner and due terms before issue. Seven-day revocable single-use token, hash only at rest, raw token only in URL fragment of the initial response. Durable outcome contains invitation_id/expiry/status, never raw token/URL. Equal replay/lookup returns that same durable outcome; if the first link response was lost, show issued status and an explicit revoke/reissue action to obtain a new link, without blind resend. Protect recovery authorization and redact secret-bearing request/log data. Draft uses no capacity; invitation reserves once under allowance lock; expiry/revoke/reissue are attributable and release/replace reservation. Server-search/pagination for active own-office Clients. No automatic Company/delegation from a CNPJ match. Add matching invitation factory.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/OwnerInvitationTest.php
scripts/office-erp-commercial-runtime/run frontend node --experimental-strip-types --test tests/officeErpOnboarding.test.ts
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

Run changed-path ESLint after the focused SFC checks. At this plan's feature milestone run frontend typecheck once, serially; no build/type-generation in parallel.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add backend/app/Models/Erp/Commercial/OfficeErpOwnerInvitation.php backend/database/migrations/2026_10_24_130000_create_office_erp_owner_invitations.php backend/app/Erp/Commercial/OfficeErpOwnerInvitations.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpClientController.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpInvitationController.php frontend/app/pages/gestao-erp/clientes/index.vue frontend/app/pages/gestao-erp/clientes/novo.vue backend/tests/Feature/OfficeErpCommercial/OwnerInvitationTest.php frontend/tests/officeErpOnboarding.test.ts backend/routes/office-erp-commercial.php backend/database/factories/Erp/Commercial/OfficeErpOwnerInvitationFactory.php frontend/app/pages/gestao-erp.vue frontend/app/utils/sidebarNav.ts
git commit -m "feat(office-erp): reserve capacity and issue reviewed portfolio invitations"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

### Task 2: Accept through session-bound setup and actual owner provisioning

**OpenSpec:** 4.2, 4.3, 4.4 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Create: `backend/app/Erp/Commercial/OfficeErpInvitationContinuation.php`
- Create: `backend/app/Erp/Commercial/OfficeErpOwnerAcceptance.php`
- Create: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpOwnerSetupController.php`
- Create: `backend/routes/office-erp-invitations.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Support/AccessSession.php`
- Create: `frontend/app/pages/erp/ativar.vue`
- Modify: `frontend/app/middleware/access.global.ts`
- Modify: `frontend/app/composables/useAuth.ts`
- Create: `backend/tests/Feature/OfficeErpCommercial/OwnerAcceptanceTest.php`
- Create: `frontend/tests/officeErpOwnerActivation.test.ts`

**Interfaces:**
- Consumes: Task 1 invitation/reservation; ErpCompanyProvisioning::createCompanyForOwner(User,array):ErpCompany, ErpCompanyInitializer::initialize(), existing global identities and AccessSecurity.
- Produces: OfficeErpInvitationContinuation::exchange(Request $request,string $token):array; current(Request $request):array; clear(Request $request):void. OfficeErpOwnerAcceptance::accept(Request $request,array $input,string $key):OfficeErpContract. POST /erp-invitations/resolve, GET /erp-invitations/current, POST /erp-invitations/accept under private ingress/origin/CSRF/rate guards, outside selected-tenant middleware.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_existing_identity_keeps_password_and_memberships
self::assertSame($oldPasswordHash, $user->fresh()->password);
self::assertSame($oldOfficeMemberships, $user->fresh()->accounts->modelKeys());
// test_late_acceptance_after_right_loss
self::assertSame([$companiesBefore,$membersBefore,$linksBefore,0], [$companiesAfter,$membersAfter,$linksAfter,$occupiedDelta]);
// test_repeated_acceptance_recovers_original_result
self::assertSame($firstCompanyId, $retryCompanyId);
// test_existing_company_adoption_does_not_change_direct_status_plan_before_activation
self::assertSame($originalSubscriptionAttributes, $subscription->fresh()->getAttributes());
// test_no_membership_login_reaches_setup_and_expired_session_is_bounded
```

```ts
assert.equal(destination, '/erp/ativar')
assert.equal(redirectLoopCount, 0)
assert.equal(expiredContinuation.state, 'expired')
assert.equal(rawSecretPersisted, false)
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/OwnerAcceptanceTest.php tests/Feature/ErpCompanyInitializationTest.php tests/Feature/AccessFoundationTest.php`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** 15-minute server continuation (implementation default; original invitation stays seven days), session-bound and allowlisted /erp/ativar login continuation only. Remove fragment immediately after exchange; no raw token in persisted UI state, analytics or referrers. Existing identity must authenticate; new identity is created only from intended owner credentials with no password overwrite. Existing Company requires authenticated direct owner and matching CNPJ; no guess-based association or automatic accounting delegation. Revalidate active seller Account, current allowance/modules and own reservation BEFORE provisioning. Acceptance, owner membership, Company binding and occupied allocation commit atomically; only new Companies receive their normal pending subscription. Distinguish invalid/expired from retryable service failure; no missing-workspace redirect loop. Limit AccessSession change to preserving a verified invitation continuation across login, with regression tests for normal destinations.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/OwnerAcceptanceTest.php tests/Feature/ErpCompanyInitializationTest.php tests/Feature/AccessFoundationTest.php
scripts/office-erp-commercial-runtime/run frontend node --experimental-strip-types --test tests/officeErpOwnerActivation.test.ts
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

Run changed-path ESLint after the focused SFC checks. At this plan's feature milestone run frontend typecheck once, serially; no build/type-generation in parallel.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add backend/app/Erp/Commercial/OfficeErpInvitationContinuation.php backend/app/Erp/Commercial/OfficeErpOwnerAcceptance.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpOwnerSetupController.php backend/routes/office-erp-invitations.php backend/routes/api.php backend/app/Support/AccessSession.php frontend/app/pages/erp/ativar.vue frontend/app/middleware/access.global.ts frontend/app/composables/useAuth.ts backend/tests/Feature/OfficeErpCommercial/OwnerAcceptanceTest.php frontend/tests/officeErpOwnerActivation.test.ts
git commit -m "feat(office-erp): accept through session-bound setup and actual owner provisioning"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

### Task 3: Activate and maintain one current retail contract

**OpenSpec:** 3.5, 3.6, 7.3, 9.5 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Create: `backend/app/Erp/Commercial/OfficeErpContracts.php`
- Create: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpContractController.php`
- Modify: `backend/app/Erp/ErpCommercialGate.php`
- Modify: `backend/app/Http/Controllers/Api/V1/Admin/ErpSubscriptionController.php`
- Create: `frontend/app/pages/gestao-erp/assinaturas/index.vue`
- Create: `frontend/app/pages/gestao-erp/assinaturas/[id].vue`
- Create: `backend/tests/Feature/OfficeErpCommercial/ContractLifecycleTest.php`
- Create: `backend/tests/Feature/OfficeErpCommercial/CompanyAccessTest.php`
- Create: `frontend/tests/officeErpContracts.test.ts`
- Modify: `backend/routes/office-erp-commercial.php`

- Modify: `frontend/app/pages/gestao-erp.vue`
- Modify: `frontend/app/utils/sidebarNav.ts`

**Interfaces:**
- Consumes: Billing Tasks 1-4 calendar, invoices, receipts and cause reconciler; foundation allocator/admission/journal; Tasks 1/2 accepted owner-confirmed contracts.
- Produces: OfficeErpContracts::activate($ctx,OfficeErpContract $contract,string $key):array{contract_id:int,invoice_id:int,subscription_id:int}; amend($ctx,$contract,array $terms,string $key):OfficeErpContractRevision; renew($ctx,$contract,string $key):OfficeErpContractRevision; suspend($ctx,$contract,string $reason,string $key):void; resume($ctx,$contract,string $key):void; cancel($ctx,$contract,string $mode,string $reason,string $key):void. Modes immediate|period_end. `applyDueChanges(OfficeErpExecutionContext $ctx,OfficeErpContract $contract,CarbonImmutable $now):void` applies an effective pending revision or cancellation under the held contract lock; P2.5 invokes it before reconciliation/generation.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_activation_atomically_issues_first_invoice
self::assertSame(1, $contract->invoices()->count());
self::assertSame(1, $company->subscription()->count());
self::assertSame('effective', $contract->fresh()->agreement_status);
// test_invoice_failure_rolls_back_effective_status_anchor_and_link
self::assertSame('accepted', $contract->fresh()->agreement_status);
// test_accepted_occupied_activation_after_resale_loss_succeeds_without_new_slot
$activationAfterRightLoss->assertOk();
self::assertSame($occupiedBefore, $occupiedAfter);
// test_price_only_amendment_is_maintenance_but_module_expansion_is_new_sale
$priceOnlyAmendmentAfterRightLoss->assertOk();
$moduleExpansionAfterRightLoss->assertForbidden();
// test_legacy_direct_subscription_and_grants_keep_existing_behavior
self::assertSame($beforeDirectSubscription, $afterDirectSubscription);
// test_period_end_cancel_keeps_history_and_manual_security_causes
self::assertSame($invoiceIdsBefore, $invoiceIdsAfter);
self::assertSame($securitySuspendedBefore, $securitySuspendedAfter);
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/ContractLifecycleTest.php tests/Feature/OfficeErpCommercial/CompanyAccessTest.php tests/Feature/OfficeErpCommercial/InvoiceTest.php`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** Activation sets effective agreement, local activation anchor, first period invoice and the existing Company subscription in one operation/transaction. Accepted occupied contracts are grandfathered; unaccepted invitations are not. Staged direct adoption links only here. Accepted module snapshots govern linked Company access; current allowance never intersects existing grants. Preserve pending/active legacy behavior for unlinked subscriptions; privileged legacy mutation cannot bypass retail lifecycle. Next-cycle changes and unchanged-term renewal use immutable revisions; grant expansion re-enters new-sale admission. Immediate/end-period cancellation retains debt/data and releases only when effective. applyDueChanges() evaluates the local effective boundary even when no invoice is generated; applying an unchanged pending change twice is harmless. Separate agreement/licence statuses in real list/detail SFCs; recover unknown outcomes with retained fields.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/ContractLifecycleTest.php tests/Feature/OfficeErpCommercial/CompanyAccessTest.php tests/Feature/OfficeErpCommercial/InvoiceTest.php
scripts/office-erp-commercial-runtime/run frontend node --experimental-strip-types --test tests/officeErpContracts.test.ts
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

Run changed-path ESLint after the focused SFC checks. At this plan's feature milestone run frontend typecheck once, serially; no build/type-generation in parallel.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add backend/app/Erp/Commercial/OfficeErpContracts.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpContractController.php backend/app/Erp/ErpCommercialGate.php backend/app/Http/Controllers/Api/V1/Admin/ErpSubscriptionController.php frontend/app/pages/gestao-erp/assinaturas/index.vue 'frontend/app/pages/gestao-erp/assinaturas/[id].vue' backend/tests/Feature/OfficeErpCommercial/ContractLifecycleTest.php backend/tests/Feature/OfficeErpCommercial/CompanyAccessTest.php frontend/tests/officeErpContracts.test.ts backend/routes/office-erp-commercial.php frontend/app/pages/gestao-erp.vue frontend/app/utils/sidebarNav.ts
git commit -m "feat(office-erp): activate and maintain one current retail contract"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

## Exit Gate

All tasks in this file require their focused behavior checks and task reviews. Consult the master topological order before dispatching a dependent task, especially the recurring task that waits for activation. The global full-suite/browser/migration gates remain separate; this file alone does not authorize release or canonical synchronization.
