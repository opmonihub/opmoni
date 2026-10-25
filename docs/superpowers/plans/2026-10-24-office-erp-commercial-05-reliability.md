# Recovery, real races and integrated acceptance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prove that the complete office workflow survives context changes, concurrency and deployment boundaries.

**Architecture:** Finish cross-screen error/recovery consistency, run genuine independent-process PostgreSQL races, then validate the integrated system and additive rollback. These gates consume completed feature code; no new business scope is introduced.

**Tech Stack:** Laravel 13 / PHP 8.4 / PostgreSQL 18 / Redis 8; Nuxt 4.6.0 / Vue 3.5.43 / Nuxt UI 4.11.3 / TypeScript / Zod 4.6.5; existing BCMath, PHPUnit and Node test runner.

**Spec:** [design](../../../openspec/changes/add-office-erp-commercial-management/design.md), [five delta specifications](../../../openspec/changes/add-office-erp-commercial-management/specs/), and [master execution plan](2026-10-24-office-erp-commercial-management.md). All original OpenSpec requirements remain in scope.

## Global Constraints

- Preserve the selected worktree, source checkout, existing identities, APP_KEY and private .env files; no merge/push/archive or production rollout.
- "Do not loosen `ResolveAccount` for other business routes"; Super Admin has no implicit tenant membership.
- "Use exact `NUMERIC(20,2)` money columns and canonical two-place decimal strings at API boundaries." No new arithmetic/UI/PDF dependency.
- "All office calls use the normal private Nuxt `/api/v1` proxy, CSRF for writes, Account assertion and no-store responses."
- Apply the Nuxt UI skill and actual official APIs/examples before UI edits; preserve installed Nuxt 4.6.0 / UI 4.11.3 and `.dashboard/app` identity/contrast. Copy is pt-BR; code/artifacts English.
- The [master plan](2026-10-24-office-erp-commercial-management.md) defines the shared shapes, lock order, baseline and validation policy; all of them bind every task below.

## Review Focus

- A committed mutation with a lost response recovers once; another session/actor cannot inspect it (Task 1).
- Account/session/capability changes during a save close editors and discard late success/error across every commercial SFC (Task 1).
- Allowance removal wins versus a late invitation acceptance with zero Company/membership/link effects (Task 2).
- Concurrent credits/receipts and cancel/generation keep nonnegative balances and one recurring identity (Task 2).
- Runtime-specific Origin/dispatch flags, skipped probes and browser failures remain explicit; no partial suite becomes global acceptance (Task 3).

---

## File Structure

Task file lists define the change scope; files shared by sequential tasks must be carried forward, never recreated. Factories live beside their matching existing Erp factories under `backend/database/factories/Erp/Commercial/`. Domain services live in `backend/app/Erp/Commercial/`; persistent Account-owned records in `backend/app/Models/Erp/Commercial/`; OfficeErp HTTP controllers in their named V1 directory; UI composes existing shells. Existing Company finance records and global auth transports are reused only at their specified boundaries. These paths are planned, not evidence of existing implementation.

### Task 1: Complete typed failure envelopes and shared context recovery

**OpenSpec:** 8.3, 10.5 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Modify: `frontend/app/composables/useOfficeErpContext.ts`
- Modify: `frontend/app/composables/useOfficeErpCommand.ts`
- Modify: `frontend/shared/types/officeErpCommercial.ts`
- Modify: `frontend/shared/utils/officeErpCommercial.ts`
- Create: `frontend/tests/officeErpContextRecovery.test.ts`
- Create: `backend/tests/Feature/OfficeErpCommercial/CommercialApiContractsTest.php`

**Interfaces:**
- Consumes: All prior commercial SFCs and operations, existing apiFailure/useAccessApi/sessionGeneration; context capture began in foundation Task 4.
- Produces: Complete coverage of the P1.4 `OfficeErpFailure`, `ContextToken` and `OfficeErpCommandResult<T>` contracts; retain the master invoice-balance shape in `data.current_balances`. useOfficeErpCommand recovers the original operation/payload and blocks duplicate execute until outcome is known; HTTP422/403/404/409 semantics remain distinct.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_cross_context_response_cannot_reopen_editor_or_toast
```

```ts
assert.equal(newOffice.editorOpen.value, false)
assert.equal(staleToastCount, 0)
// test_network_unknown_outcome_uses_lookup_without_second_post
assert.equal(postCount, 1)
assert.equal(lookupCount, 1)
assert.equal(recovered.receipt_id, firstReceiptId)
// test_forbidden_lookup_and_changed_body_replay_are_not_treated_as_retryable_success
```

```php
$changedPayload->assertConflict();
$otherActorLookup->assertForbidden();
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run frontend node --experimental-strip-types --test tests/officeErpContextRecovery.test.ts tests/officeErpPlans.test.ts tests/officeErpContracts.test.ts tests/officeErpBilling.test.ts`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** No per-page parallel transport/store. Audit every irreversible handler uses command identity before effects; each screen consumes the same context token and unknown-capability state. Close/reset protected editors on Account/session/authority invalidation and discard late callbacks. Cover switched-away save, logout/relogin, duplicate submit, 422 field retention, 403 revocation,404 foreign and409 balance/payload conflict in actual SFC harnesses. Never persist raw invitation token/password with operation recovery. A systematic scan must list every command/form caller checked.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run frontend node --experimental-strip-types --test tests/officeErpContextRecovery.test.ts tests/officeErpPlans.test.ts tests/officeErpContracts.test.ts tests/officeErpBilling.test.ts
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/CommercialApiContractsTest.php
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

Run changed-path ESLint after the focused SFC checks. At this plan's feature milestone run frontend typecheck once, serially; no build/type-generation in parallel.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add frontend/app/composables/useOfficeErpContext.ts frontend/app/composables/useOfficeErpCommand.ts frontend/shared/types/officeErpCommercial.ts frontend/shared/utils/officeErpCommercial.ts frontend/tests/officeErpContextRecovery.test.ts backend/tests/Feature/OfficeErpCommercial/CommercialApiContractsTest.php
git commit -m "feat(office-erp): complete typed failure envelopes and shared context recovery"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

### Task 2: Prove database races with independent workers

**OpenSpec:** 11.1 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Create: `backend/tests/Integration/OfficeErpCommercialConcurrencyTest.php`
- Create: `backend/tests/Integration/OfficeErpCommercialConcurrencyWorker.php`
- Create: `backend/tests/Integration/OfficeErpCommercialProcessTestCase.php`

**Interfaces:**
- Consumes: Existing PlatformProcessTestCase signal/barrier/identity pattern and safety assertions; complete services/constraints from all prior plans.
- Produces: OfficeErpCommercialProcessTestCase::worker(string $action,array $payload):Process; waitFor(Process $p,string $signal):void; release(Process $p,string $signal="release"):void. Use worker actions reserve,accept,remove_allowance,issue,adjust,receipt,reverse,cancel,generate and explicit barriers.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_last_slot_and_acceptance_vs_allowance_removal
self::assertSame(1, $occupiedCount);
self::assertSame([$companiesBefore,$membersBefore,$linksBefore], [$companiesAfterRejected,$membersAfterRejected,$linksAfterRejected]);
// test_same_period_workers_identify_one_invoice
self::assertSame($workerA['invoice_id'], $workerB['invoice_id']);
// test_credit_receipt_race_never_goes_negative
self::assertGreaterThanOrEqual(0, OfficeErpMoney::compare($balance['outstanding'],'0.00'));
self::assertNotSame($workerA['database_pid'], $workerB['database_pid']);
self::assertNotSame($workerA['os_pid'], $workerB['os_pid']);
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit -c phpunit.pgsql.xml tests/Integration/OfficeErpCommercialConcurrencyTest.php`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** No sequential race simulation. Cover last licence, owner/CNPJ uniqueness, allowance removal/acceptance, period+number issue, credit/receipt, concurrent receipt allocation/reversal and cancellation/generation. Assert distinct OS and PostgreSQL backend PIDs, lock ownership, durable equal outcomes, no partial losing effects and preserved fixed lock order. Copy safety/barrier conventions into this domain-specific harness without duplicating unrelated platform scenarios. Existing fixtures may clean ONLY verified task-private stores; no manual destructive reset.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run verify
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit -c phpunit.pgsql.xml tests/Integration/OfficeErpCommercialConcurrencyTest.php
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add backend/tests/Integration/OfficeErpCommercialConcurrencyTest.php backend/tests/Integration/OfficeErpCommercialConcurrencyWorker.php backend/tests/Integration/OfficeErpCommercialProcessTestCase.php
git commit -m "feat(office-erp): prove database races with independent workers"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

### Task 3: Run the integrated browser/check matrix and rollback rehearsal

**OpenSpec:** 11.2, 11.3, 11.4, 11.5, 11.6 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Create: `docs/reviews/2026-10-24-office-erp-commercial-management/integrated-acceptance.md`
- Create: `docs/reviews/2026-10-24-office-erp-commercial-management/browser-acceptance.md`
- Create: `docs/reviews/2026-10-24-office-erp-commercial-management/migration-rehearsal.md`
- Modify: `docs/reviews/2026-10-24-office-erp-commercial-management/runtime-handoff.md`

**Interfaces:**
- Consumes: Complete accepted feature branch, verified task runtime, UI reference/component matrix and prior focused/race evidence.
- Produces: Evidence records exact source SHA, runtime identity, commands/exit codes/counts, scenarios, artifacts and explicit unverified boundaries; no production/provider acceptance API is added.

- [ ] **Step 1: Prepare the evidence checklist.** This task verifies completed implementation; it adds no artificial failing test. Record the following assertions against the accepted source SHA.

```text
# Verification-only task: no artificial RED or application rewrite.
# Required assertions in recorded browser/rehearsal evidence:
ordinary_admin_sale -> owner_acceptance -> activation -> first_invoice
100.00 invoice -> 40.00 receipt -> 60.00 outstanding -> settlement -> reversal
delinquency -> manual_hold_preserved -> resale_loss_maintenance
compatible_app_rollback -> identical_company_contract_invoice_receipt_history
```

- [ ] **Step 2: Verify the runtime and preflight the known suite configuration.** Run `scripts/office-erp-commercial-runtime/run verify`, then `scripts/office-erp-commercial-runtime/run backend env FRONTEND_ORIGIN=http://localhost:3000 WHATSAPP_DISPATCH_ENABLED=true FISCAL_ENVIRONMENT=producao vendor/bin/phpunit tests/Feature/WhatsApp/WhatsAppChannelPairingApiTest.php`. Expected: runtime identity passes; all pairing tests pass with Http::preventStrayRequests() and fakes. If either fails, diagnose it before the full suite.

- [ ] **Step 3: Execute browser flows and the migration rehearsal; record results in the listed files.** Before full backend run, inspect the WhatsApp file fakes and run its focused probe if the harness changed; WHATSAPP flag is a test-process-only setting, never a live config edit. Use the whole-worktree layout; host canonical alternative is cd backend && FISCAL_ENVIRONMENT=producao vendor/bin/phpunit with documented host test configuration. Run full suites once at this integrated gate, not after each unchanged tiny task; repeat only when actual changes/failures require it. Host Pint --dirty precedes PHP commits. Apply agent-browser/nuxt-ui for browser acceptance:1366x768,390x844,320px,light/dark,keyboard/focus/axe,expanded/collapsed/mobile sidebar,forms/overlays,server pagination,loading/error/stale/empty,printing. Compare .dashboard/app. Rehearse only additive migrate --force --no-interaction and rollback to a compatible application preserving forward tables, keys and history; never migrate:fresh as rollback. Provider/live fiscal/SERPRO remain distinct and unverified unless actually exercised.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run verify
scripts/office-erp-commercial-runtime/run backend env FRONTEND_ORIGIN=http://localhost:3000 FISCAL_ENVIRONMENT=producao WHATSAPP_DISPATCH_ENABLED=true vendor/bin/phpunit --no-progress
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit -c phpunit.pgsql.xml
scripts/office-erp-commercial-runtime/run frontend pnpm lint
scripts/office-erp-commercial-runtime/run frontend pnpm typecheck
scripts/office-erp-commercial-runtime/run frontend pnpm test
scripts/office-erp-commercial-runtime/run frontend pnpm build
```

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add docs/reviews/2026-10-24-office-erp-commercial-management/integrated-acceptance.md docs/reviews/2026-10-24-office-erp-commercial-management/browser-acceptance.md docs/reviews/2026-10-24-office-erp-commercial-management/migration-rehearsal.md docs/reviews/2026-10-24-office-erp-commercial-management/runtime-handoff.md
git commit -m "docs(office-erp): record integrated acceptance and rollback evidence"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

## Exit Gate

All tasks in this file require their focused behavior checks and task reviews. Consult the master topological order before dispatching a dependent task, especially the recurring task that waits for activation. The global full-suite/browser/migration gates remain separate; this file alone does not authorize release or canonical synchronization.
