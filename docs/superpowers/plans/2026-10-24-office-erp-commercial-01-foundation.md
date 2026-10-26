# Office catalog and admission Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a usable Account-owned offer catalog without requiring a selected Company.

**Architecture:** Keep the existing office dashboard and platform Plan APIs. Add a bounded office commercial context and Account-owned domain records; the first visible delivery is the resale editor and own-office offers.

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

- Clearing a finite capacity sends null and leaves the three legacy limits unchanged (Task 1).
- Amounts above the JavaScript safe-integer range retain every digit and reject excess precision (Task 2).
- Changed-payload operation reuse or another actor lookup never discloses an old outcome (Task 2).
- A real operator, a foreign resource and a Super Admin without membership remain denied; Account context restores after exceptions (Task 3).
- A rejected refresh or an Account/session change cannot show fabricated zeros, stale success or another office's editor (Task 4).

---

## File Structure

Task file lists define the change scope; files shared by sequential tasks must be carried forward, never recreated. Factories live beside their matching existing Erp factories under `backend/database/factories/Erp/Commercial/`. Domain services live in `backend/app/Erp/Commercial/`; persistent Account-owned records in `backend/app/Models/Erp/Commercial/`; OfficeErp HTTP controllers in their named V1 directory; UI composes existing shells. Existing Company finance records and global auth transports are reused only at their specified boundaries. These paths are planned, not evidence of existing implementation.

### Task 1: Finish the existing platform Plan editor

**OpenSpec:** 2.2 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Modify: `frontend/app/pages/admin/planos.vue`
- Modify: `frontend/shared/types/admin.ts`
- Create: `frontend/shared/types/officeErpCommercial.ts`
- Create: `frontend/shared/utils/officeErpCommercial.ts`
- Modify: `frontend/tests/adminPageStates.test.ts`

**Interfaces:**
- Consumes: Completed backend d52bbbd: raw Plan.erp_commercial; Plan::DEFAULT_ERP_COMMERCIAL; UpdatePlanRequest::erpCommercialRules().
- Produces: OfficeErpAllowance {enabled:boolean;licence_limit:number|null;module_keys:string[]}; Plan.erp_commercial?:OfficeErpAllowance|null; defaultOfficeErpAllowance():OfficeErpAllowance. One complete nested erp_commercial object in each editor PUT.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```ts
// test_resale_editor_sends_explicit_allowance_and_legacy_limits
assert.deepEqual(writes[0].erp_commercial, { enabled: true, licence_limit: null, module_keys: ['purchases'] })
assert.deepEqual(writes[0].limits, { users: null, clients: 0, monitorings: null })
// test_resale_editor_preserves_values_and_errors_on_422
assert.equal(view.state.editOpen.value, true)
assert.equal(view.state.editState.erp_commercial.licence_limit, null)
assert.deepEqual(view.state.fieldErrors.value['erp_commercial.module_keys'], ['Invalid module'])
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run frontend node --experimental-strip-types --test tests/adminPageStates.test.ts`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** Extend the existing named Slideover form; use the audited Switch, InputNumber and SelectMenu. Keep local search, columns and edit-only behavior. Capacities use integer controls; empty modules are valid. Define the eight module options once in officeErpCommercial.ts, matching ErpCoreModules::BASIC exactly: purchases, add_sale, pos_sale, stock_transfers, stock_adjustment, expenses, cte, mdfe. Use Portuguese labels. Do not add another catalog endpoint or mutate backend validation.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run frontend node --experimental-strip-types --test tests/adminPageStates.test.ts
scripts/office-erp-commercial-runtime/run frontend pnpm exec eslint app/pages/admin/planos.vue shared/types/admin.ts shared/types/officeErpCommercial.ts shared/utils/officeErpCommercial.ts tests/adminPageStates.test.ts
```

Run changed-path ESLint after the focused SFC checks. At this plan's feature milestone run frontend typecheck once, serially; no build/type-generation in parallel.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add frontend/app/pages/admin/planos.vue frontend/shared/types/admin.ts frontend/shared/types/officeErpCommercial.ts frontend/shared/utils/officeErpCommercial.ts frontend/tests/adminPageStates.test.ts
git commit -m "feat(office-erp): finish the existing platform plan editor"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

### Task 2: Create scoped records, exact money and the operation journal

**OpenSpec:** 3.1, 3.3 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Create: `backend/app/Erp/Commercial/OfficeErpExecutionContext.php`
- Create: `backend/app/Erp/Commercial/OfficeErpMoney.php`
- Create: `backend/app/Erp/Commercial/OfficeErpOperations.php`
- Create: `backend/app/Erp/Commercial/OfficeErpRecords.php`
- Create: `backend/app/Models/Erp/Commercial/OfficeErpSalesPlan.php`
- Create: `backend/app/Models/Erp/Commercial/OfficeErpSalesPlanRevision.php`
- Create: `backend/app/Models/Erp/Commercial/OfficeErpContract.php`
- Create: `backend/app/Models/Erp/Commercial/OfficeErpContractRevision.php`
- Create: `backend/app/Models/Erp/Commercial/OfficeErpLicenceAllocation.php`
- Create: `backend/app/Models/Erp/Commercial/OfficeErpCommercialOperation.php`
- Create: `backend/app/Models/Erp/Commercial/OfficeErpAuditEvent.php`
- Create: `backend/database/migrations/2026_10_24_100000_create_office_erp_commercial_core.php`
- Modify: `backend/app/Models/Erp/ErpSubscription.php`
- Create: `backend/tests/OfficeErpCommercialTestCase.php`
- Create: `backend/tests/Feature/OfficeErpCommercial/CommercialRecordsTest.php`
- Create: `backend/tests/Feature/OfficeErpCommercial/CommercialOperationsTest.php`
- Create: `backend/tests/Unit/OfficeErpMoneyTest.php`
- Create: `backend/database/factories/Erp/Commercial/OfficeErpSalesPlanFactory.php`
- Create: `backend/database/factories/Erp/Commercial/OfficeErpSalesPlanRevisionFactory.php`
- Create: `backend/database/factories/Erp/Commercial/OfficeErpContractFactory.php`
- Create: `backend/database/factories/Erp/Commercial/OfficeErpContractRevisionFactory.php`
- Create: `backend/database/factories/Erp/Commercial/OfficeErpLicenceAllocationFactory.php`
- Create: `backend/database/factories/Erp/Commercial/OfficeErpCommercialOperationFactory.php`
- Create: `backend/database/factories/Erp/Commercial/OfficeErpAuditEventFactory.php`

**Interfaces:**
- Consumes: Account, Client.tax_id/ClientStatus, CurrentAccount, CurrentTenant::run(), the existing ErpCompany and unique-company ErpSubscription.
- Produces: `OfficeErpExecutionContext::__construct(Account $account,?User $actor,string $actorKind,string $purpose)` creates a trusted internal value, never request-selected authority; its factories verify the declared actor identity. Static `OfficeErpMoney::canonical(mixed $value,string $field="amount"):string`, `add(string $left,string $right):string`, `subtract(string $left,string $right):string`, `compare(string $left,string $right):int`. Canonical input is a nonnegative two-place string with at most 18 whole digits; reject floats, scientific notation, overflow and excess precision. Arithmetic can return signed strings for report movements; monetary commands validate their nonnegative/positive bounds separately.
- Produces: `OfficeErpRecords::query(OfficeErpExecutionContext $ctx,string $modelClass):Builder` and `persist(OfficeErpExecutionContext $ctx,Model $record):Model` for new Account-owned models only; these assert the verified context, filter/set `account_id` and reject mismatches without relying on request-only `BelongsToAccount` hooks.
- Produces: `OfficeErpOperations::execute(OfficeErpExecutionContext $ctx,string $key,string $action,array $payload,Closure $authorize,Closure $effect):array`; `lookup(OfficeErpExecutionContext $ctx,string $key,Closure $authorize):array`. `$authorize(string $action,array $payload):void` verifies current authority and takes required business locks before outcome access/effects; `$effect():array` returns the sanitized durable outcome. Services supply command authorization; the journal tests use explicit callbacks and do not depend on the not-yet-created admission service.
- Produces: `OfficeErpCommercialTestCase extends BusinessTestCase` using isolated `RefreshDatabase` fixtures and helpers `officeContext(array $allowance=[]):OfficeErpExecutionContext`, `acceptedContract(OfficeErpExecutionContext $ctx,array $terms=[]):OfficeErpContract`. Test contexts are fixtures, never HTTP admission shortcuts. Owner contexts identify their verified invitation and optional User; system contexts identify their verified contract/job.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_exact_money_and_large_values
self::assertSame('60.00', OfficeErpMoney::subtract('100.00', '40.00'));
self::assertSame('9007199254740993.01', OfficeErpMoney::canonical('9007199254740993.01'));
// test_rejects_excess_precision (separate test)
$this->expectException(ValidationException::class);
OfficeErpMoney::canonical('1.001');
// test_equal_repeat_has_one_effect_and_changed_payload_conflicts
self::assertSame($first, $operations->execute($ctx, $key, 'plan.create', $payload, $authorize, $effect));
self::assertSame(1, $effectCount);
// test_foreign_lookup_and_cross_account_relationship_are_denied
self::assertFalse($foreignActorCanLookup);
self::assertSame(0, $crossAccountRelationshipCount);
// test_effect_exception_rolls_back_operation_and_domain_change
self::assertSame(0, $committedOperationsAfterException);
self::assertSame($beforeDomainCount, $afterDomainCount);
// test_lookup_rechecks_authority_even_for_a_committed_operation
$this->expectException(AuthorizationException::class);
$operations->lookup($revokedCtx, $key, $authorize);
// test_owner_and_system_records_restore_tenant_context_after_exception
self::assertSame($previousTenantId, app(\App\Tenant\CurrentTenant::class)->accountId);
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/CommercialRecordsTest.php tests/Feature/OfficeErpCommercial/CommercialOperationsTest.php tests/Unit/OfficeErpMoneyTest.php`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** Use Account-owned tables from design decision 4; create immutable revisions before adding their circular current-revision references. Add restricted/composite references and nullable subscription `office_erp_contract_id` with Company-match constraint. One allocation per contract and one reserved/occupied current seller allocation per Company; released/canceled history must not prevent a later authorized new sale. Preserve unlinked subscriptions. Journal uniqueness includes Account, actor identity and key; payload/action mismatch is 409. Acquire business locks in the authorization callback before inserting/locking operation rows; uniqueness races roll back the losing effect and reauthorize recovery. Every effective command appends an attributable event. Keep token/password fields out of journal payloads/outcomes/audits. Use only the new scoped repository for owner/system persistence within `CurrentTenant::run`; never edit shared tenant traits or fabricate request memberships. Use existing BCMath; `ErpDecimal::parse()` accepts floats/four decimals and does not fit this boundary. No demo seeder.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/CommercialRecordsTest.php tests/Feature/OfficeErpCommercial/CommercialOperationsTest.php tests/Unit/OfficeErpMoneyTest.php
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add backend/app/Erp/Commercial/OfficeErpExecutionContext.php backend/app/Erp/Commercial/OfficeErpMoney.php backend/app/Erp/Commercial/OfficeErpOperations.php backend/app/Erp/Commercial/OfficeErpRecords.php backend/app/Models/Erp/Commercial/OfficeErpSalesPlan.php backend/app/Models/Erp/Commercial/OfficeErpSalesPlanRevision.php backend/app/Models/Erp/Commercial/OfficeErpContract.php backend/app/Models/Erp/Commercial/OfficeErpContractRevision.php backend/app/Models/Erp/Commercial/OfficeErpLicenceAllocation.php backend/app/Models/Erp/Commercial/OfficeErpCommercialOperation.php backend/app/Models/Erp/Commercial/OfficeErpAuditEvent.php backend/database/migrations/2026_10_24_100000_create_office_erp_commercial_core.php backend/app/Models/Erp/ErpSubscription.php backend/tests/OfficeErpCommercialTestCase.php backend/tests/Feature/OfficeErpCommercial/CommercialRecordsTest.php backend/tests/Feature/OfficeErpCommercial/CommercialOperationsTest.php backend/tests/Unit/OfficeErpMoneyTest.php backend/database/factories/Erp/Commercial/OfficeErpSalesPlanFactory.php backend/database/factories/Erp/Commercial/OfficeErpSalesPlanRevisionFactory.php backend/database/factories/Erp/Commercial/OfficeErpContractFactory.php backend/database/factories/Erp/Commercial/OfficeErpContractRevisionFactory.php backend/database/factories/Erp/Commercial/OfficeErpLicenceAllocationFactory.php backend/database/factories/Erp/Commercial/OfficeErpCommercialOperationFactory.php backend/database/factories/Erp/Commercial/OfficeErpAuditEventFactory.php
git commit -m "feat(office-erp): create scoped records, exact money and the operation journal"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

### Task 3: Admit office administrators and allocate licences safely

**OpenSpec:** 2.3, 2.4, 3.4 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Create: `backend/app/Http/Middleware/ResolveOfficeErpCommercial.php`
- Create: `backend/app/Erp/Commercial/OfficeErpAdmission.php`
- Create: `backend/app/Erp/Commercial/OfficeErpLicenceAllocator.php`
- Create: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpContextController.php`
- Create: `backend/routes/office-erp-commercial.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/bootstrap/app.php`
- Create: `backend/tests/Feature/OfficeErpCommercial/CommercialAdmissionTest.php`
- Create: `backend/tests/Feature/OfficeErpCommercial/LicenceAllocationTest.php`

**Interfaces:**
- Consumes: Task 2 records/context/journal, Plan.erp_commercial and its row lock, AccessSession::assertOwnership(), session current_account_id and X-Account-Id.
- Produces: `OfficeErpAdmission::context(Request $request):OfficeErpExecutionContext`, `capabilities(OfficeErpExecutionContext $ctx):array`, `assertCommand(OfficeErpExecutionContext $ctx,string $action,?OfficeErpContract $contract=null,array $modules=[],?OfficeErpLicenceAllocation $reservation=null):void`. `OfficeErpLicenceAllocator::reserve(OfficeErpExecutionContext $ctx,OfficeErpContract $contract,CarbonImmutable $expiresAt):OfficeErpLicenceAllocation`, `occupy(OfficeErpExecutionContext $ctx,OfficeErpContract $contract,ErpCompany $company):OfficeErpLicenceAllocation`, `release(OfficeErpExecutionContext $ctx,OfficeErpContract $contract,string $reason):void`. `GET /erp-commercial/context` returns capabilities plus allowance and reserved/occupied usage; `capability_key` changes when effective authority changes.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_real_admin_and_context_assertion
$adminResponse->assertOk()->assertJsonPath('data.can_read', true);
$operatorResponse->assertForbidden();
$superAdminWithoutMembership->assertForbidden();
$staleHeaderResponse->assertConflict();
// test_right_loss_preserves_only_accepted_maintenance
self::assertTrue($capabilitiesAfterLoss['can_maintain']);
self::assertFalse($capabilitiesAfterLoss['can_sell_new']);
$admission->assertCommand($ctx, 'contract.renew', $acceptedContract);
self::assertSame($before, $acceptedContract->fresh()->getAttributes());
$ordinaryInactiveClientWrite->assertForbidden()->assertJsonPath('code', 'subscription_inactive');
// test_own_reservation_does_not_require_a_second_free_slot
self::assertSame(1, $occupiedAfterAcceptance);
// test_context_restoration_after_admission_exception
self::assertSame($beforeTenant, app(CurrentTenant::class)->accountId);
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/CommercialAdmissionTest.php tests/Feature/OfficeErpCommercial/LicenceAllocationTest.php tests/Feature/TenantFoundationTest.php`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** Do not weaken ResolveAccount. Check real admin membership, active Account, session ownership and header assertions before/after effects; restore tenant context in finally and order middleware before bindings. Maintenance actions resolve their actual own accepted/effective target; owner/system actors are allowed only their dedicated actions. An own unexpired reservation counts once during acceptance; drafts count zero, suspended/effective/accepted allocations remain occupied until effective cancellation. New sale and grant expansion lock Account, current subscription and Plan before Company/contract/allocation; allowance changes already lock Plan without taking Account later. Expiry/revocation releases reservations; capacity downgrade preserves occupied usage. Expose separate can_read/can_maintain/can_configure/can_sell_new and reason codes.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/CommercialAdmissionTest.php tests/Feature/OfficeErpCommercial/LicenceAllocationTest.php tests/Feature/TenantFoundationTest.php
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add backend/app/Http/Middleware/ResolveOfficeErpCommercial.php backend/app/Erp/Commercial/OfficeErpAdmission.php backend/app/Erp/Commercial/OfficeErpLicenceAllocator.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpContextController.php backend/routes/office-erp-commercial.php backend/routes/api.php backend/bootstrap/app.php backend/tests/Feature/OfficeErpCommercial/CommercialAdmissionTest.php backend/tests/Feature/OfficeErpCommercial/LicenceAllocationTest.php
git commit -m "feat(office-erp): admit office administrators and allocate licences safely"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

### Task 4: Deliver own-office offers and the commercial shell

**OpenSpec:** 3.2, 9.1, 9.3, 9.6 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Create: `backend/app/Erp/Commercial/OfficeErpSalesPlans.php`
- Create: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpSalesPlanController.php`
- Create: `backend/app/Http/Requests/OfficeErp/SaveSalesPlanRequest.php`
- Create: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpOperationController.php`
- Create: `frontend/app/pages/gestao-erp.vue`
- Create: `frontend/app/pages/gestao-erp/planos.vue`
- Create: `frontend/app/components/office-erp/OfficeErpPlanEditor.vue`
- Create: `frontend/app/composables/useOfficeErpContext.ts`
- Create: `frontend/app/composables/useOfficeErpCommand.ts`
- Modify: `frontend/app/layouts/default.vue`
- Modify: `frontend/app/utils/sidebarNav.ts`
- Modify: `frontend/app/middleware/access.global.ts`
- Modify: `frontend/app/pages/admin/erp/index.vue`
- Modify: `frontend/app/pages/admin/erp/planos.vue`
- Modify: `frontend/app/pages/admin/erp/assinaturas.vue`
- Modify: `frontend/shared/types/officeErpCommercial.ts`
- Modify: `frontend/shared/utils/officeErpCommercial.ts`
- Modify: `backend/routes/office-erp-commercial.php`
- Create: `frontend/tests/officeErpMoney.test.ts`
- Create: `backend/tests/Feature/OfficeErpCommercial/RetailOfferTest.php`
- Create: `frontend/tests/officeErpPlans.test.ts`

**Interfaces:**
- Consumes: Tasks 1-3 typed allowance/context/journal and money; existing DataTablePanelList, pageShell, useRetryableLoad and createLatestRequestRunner.
- Produces: `OfficeErpSalesPlans::create(OfficeErpExecutionContext $ctx,array $terms,string $key):OfficeErpSalesPlan`, `revise(OfficeErpExecutionContext $ctx,OfficeErpSalesPlan $plan,array $terms,string $key):OfficeErpSalesPlanRevision`, `archive(OfficeErpExecutionContext $ctx,OfficeErpSalesPlan $plan,string $key):void`. `GET/POST /erp-commercial/plans`; `GET /plans/{id}`; `POST /plans/{id}/revisions`, `/plans/{id}/archive`; `GET /erp-commercial/operations/{key}` uses the journal's action-specific authorization callback, not a generic read bypass.
- Produces in `frontend/shared/types/officeErpCommercial.ts`: `ContextToken {key:string;generation:number}`, `OfficeErpCapabilities {can_read:boolean;can_maintain:boolean;can_configure:boolean;can_sell_new:boolean;capability_key:string;reasons:Record<string,string[]>;allowance:OfficeErpAllowance;usage:{reserved:number;occupied:number}}` and the master plan's `OfficeErpFailure` / invoice balance shapes. `OfficeErpCommandResult<T>` is `{status:'confirmed';data:T}|{status:'unknown';key:string}|{status:'failed';failure:OfficeErpFailure}|{status:'stale'}`.
- Produces: `useOfficeErpContext()` returns `capabilities:Readonly<Ref<OfficeErpCapabilities|null>>`, `capture():ContextToken`, `isCurrent(token:ContextToken):boolean`, `invalidate():void`, `refresh():Promise<void>`. `useOfficeErpCommand()` returns `pending:Readonly<Ref<boolean>>`, `execute<T>(action:string,path:string,body:Record<string,unknown>,method:'POST'|'PATCH'='POST'):Promise<OfficeErpCommandResult<T>>`, `recover<T>(key:string):Promise<OfficeErpCommandResult<T>>`. Use existing `Idempotency-Key` transport, one frozen identity/payload, and context checks around every callback. P5.1 broadens coverage; it does not introduce these contracts late.
- Produces: `parseOfficeErpMoney(input:string):string`, `formatOfficeErpMoney(value:string):string` in `frontend/shared/utils/officeErpCommercial.ts`. Accept ungrouped pt-BR `100` / `100,00`, valid pt-BR groups with a comma fraction, or a canonical dot-decimal API value; reject ambiguous `1.001`, malformed grouping, negatives and overflow. The formatter may reuse the existing string-only `formatErpMoney` adapter, retaining exactly two cents.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_revision_keeps_sold_terms: old contract price remains 100.00 after new offer price 120.00
self::assertSame('100.00', $acceptedRevision->fresh()->price);
self::assertSame('120.00', $newOfferRevision->price);
// test_foreign_office_plan
$foreignDetail->assertNotFound();
$foreignArchive->assertNotFound();
```

```ts
// test_money_parser_precedes_the_offer_editor
assert.equal(parseOfficeErpMoney('9.007.199.254.740.993,01'), '9007199254740993.01')
assert.throws(() => parseOfficeErpMoney('1.001'))
assert.throws(() => parseOfficeErpMoney('1000000000000000000,00'))
// test_actual_sfc_failure_and_context_invalidation
assert.equal(view.confirmedEmpty.value, false)
assert.equal(view.errorVisible.value, true)
assert.equal(view.editorOpen.value, false) // after Account switch
assert.equal(staleSuccessToastCount, 0)
// test_exact_pt_br_money_adapter
assert.equal(parseOfficeErpMoney('9.007.199.254.740.993,01'), '9007199254740993.01')
assert.throws(() => parseOfficeErpMoney('1,001'))
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/RetailOfferTest.php tests/Feature/OfficeErpCommercial/CommercialAdmissionTest.php`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** Money form state stays a canonical string; parse unambiguous pt-BR input with the shared string adapter, reject excess precision, never Number(). Offer terms: name, description, price BRL, interval_months in 1/3/6/12, due_in_days>=0, module_keys and publication. Revisions never rewrite sold terms; archive remains maintenance. Build the money parser and command/context contracts before connecting the editor. Register office operation lookup with current action/resource authorization; known committed outcomes must not bypass revoked membership. Parent has one DashboardPanel/Navbar/Toolbar; no Company guard. Until P4.1 supplies the overview, `/gestao-erp` redirects to its usable plans list; do not link unfinished children or render placeholder metrics. Add each child to local/sidebar/search navigation as its delivering task lands. Legacy bookmarks retain Super Admin admission and redirect only with an authorized selected office; otherwise explain missing context. Keep old backend privileged APIs for unlinked legacy administration. Persist only nonsecret pending operation data; invalidate reads/editors on Account/session/capability change. Test real SFCs and route guards, not source-text-only markup checks.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/RetailOfferTest.php tests/Feature/OfficeErpCommercial/CommercialAdmissionTest.php
scripts/office-erp-commercial-runtime/run frontend node --experimental-strip-types --test tests/officeErpPlans.test.ts tests/officeErpMoney.test.ts tests/sidebarNav.test.ts tests/adminNav.test.ts
scripts/office-erp-commercial-runtime/run frontend pnpm exec eslint app/pages/gestao-erp.vue app/pages/gestao-erp/planos.vue app/components/office-erp/OfficeErpPlanEditor.vue app/composables/useOfficeErpContext.ts app/composables/useOfficeErpCommand.ts app/layouts/default.vue app/utils/sidebarNav.ts app/middleware/access.global.ts app/pages/admin/erp/index.vue app/pages/admin/erp/planos.vue app/pages/admin/erp/assinaturas.vue shared/types/officeErpCommercial.ts shared/utils/officeErpCommercial.ts tests/officeErpPlans.test.ts tests/officeErpMoney.test.ts
scripts/office-erp-commercial-runtime/run frontend pnpm typecheck
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

Run changed-path ESLint after the focused SFC checks. At this plan's feature milestone run frontend typecheck once, serially; no build/type-generation in parallel.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add backend/app/Erp/Commercial/OfficeErpSalesPlans.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpSalesPlanController.php backend/app/Http/Requests/OfficeErp/SaveSalesPlanRequest.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpOperationController.php frontend/app/pages/gestao-erp.vue frontend/app/pages/gestao-erp/planos.vue frontend/app/components/office-erp/OfficeErpPlanEditor.vue frontend/app/composables/useOfficeErpContext.ts frontend/app/composables/useOfficeErpCommand.ts frontend/app/layouts/default.vue frontend/app/utils/sidebarNav.ts frontend/app/middleware/access.global.ts frontend/app/pages/admin/erp/index.vue frontend/app/pages/admin/erp/planos.vue frontend/app/pages/admin/erp/assinaturas.vue frontend/shared/types/officeErpCommercial.ts frontend/shared/utils/officeErpCommercial.ts backend/routes/office-erp-commercial.php frontend/tests/officeErpMoney.test.ts backend/tests/Feature/OfficeErpCommercial/RetailOfferTest.php frontend/tests/officeErpPlans.test.ts
git commit -m "feat(office-erp): deliver own-office offers and the commercial shell"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

## Exit Gate

All tasks in this file require their focused behavior checks and task reviews. Consult the master topological order before dispatching a dependent task, especially the recurring task that waits for activation. The global full-suite/browser/migration gates remain separate; this file alone does not authorize release or canonical synchronization.
