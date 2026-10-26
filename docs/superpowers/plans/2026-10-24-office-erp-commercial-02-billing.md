# Exact billing and recurring processing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Provide an independently tested Account billing engine with exact receipts and cause-based access.

**Architecture:** Use Account-owned billing tables and immutable evidence, outside Company finance. Build balances and policy reconciliation before monetary commands; build recurring execution only after the onboarding plan supplies atomic activation.

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

- A January-31 anchor clamps February and returns to March 31; annual February 29 returns on the next leap year (Task 1).
- An empty policy save never silently enables suspension; a manual hold survives settlement (Task 2).
- A credit cannot reduce charge below effective allocations and a canceled period is never regenerated (Tasks 3/5).
- One receipt can span invoices, reverse only once, and retain a private staged proof after a failed transaction (Task 4).
- A job suspended between admission and commit leaves cursor and invoice unchanged and restores prior tenant context (Task 5).

---

## File Structure

Task file lists define the change scope; files shared by sequential tasks must be carried forward, never recreated. Factories live beside their matching existing Erp factories under `backend/database/factories/Erp/Commercial/`. Domain services live in `backend/app/Erp/Commercial/`; persistent Account-owned records in `backend/app/Models/Erp/Commercial/`; OfficeErp HTTP controllers in their named V1 directory; UI composes existing shells. Existing Company finance records and global auth transports are reused only at their specified boundaries. These paths are planned, not evidence of existing implementation.

### Task 1: Add billing storage, balances and original-anchor periods

**OpenSpec:** 5.1, 5.2, 6.1 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Create: `backend/app/Models/Erp/Commercial/OfficeErpInvoice.php`
- Create: `backend/app/Models/Erp/Commercial/OfficeErpInvoiceAdjustment.php`
- Create: `backend/app/Models/Erp/Commercial/OfficeErpInvoiceCounter.php`
- Create: `backend/app/Models/Erp/Commercial/OfficeErpReceipt.php`
- Create: `backend/app/Models/Erp/Commercial/OfficeErpReceiptAllocation.php`
- Create: `backend/app/Models/Erp/Commercial/OfficeErpReceiptReversal.php`
- Create: `backend/app/Models/Erp/Commercial/OfficeErpCommercialSetting.php`
- Create: `backend/database/migrations/2026_10_24_110000_create_office_erp_billing.php`
- Create: `backend/app/Erp/Commercial/OfficeErpCalendar.php`
- Create: `backend/app/Erp/Commercial/OfficeErpBalances.php`
- Modify: `backend/tests/OfficeErpCommercialTestCase.php`
- Create: `backend/tests/Feature/OfficeErpCommercial/BillingSchemaTest.php`
- Create: `backend/tests/Unit/OfficeErpCalendarTest.php`
- Create: `backend/database/factories/Erp/Commercial/OfficeErpInvoiceFactory.php`
- Create: `backend/database/factories/Erp/Commercial/OfficeErpInvoiceAdjustmentFactory.php`
- Create: `backend/database/factories/Erp/Commercial/OfficeErpInvoiceCounterFactory.php`
- Create: `backend/database/factories/Erp/Commercial/OfficeErpReceiptFactory.php`
- Create: `backend/database/factories/Erp/Commercial/OfficeErpReceiptAllocationFactory.php`
- Create: `backend/database/factories/Erp/Commercial/OfficeErpReceiptReversalFactory.php`
- Create: `backend/database/factories/Erp/Commercial/OfficeErpCommercialSettingFactory.php`

**Interfaces:**
- Consumes: Foundation Task 2 Account/composite constraints, OfficeErpMoney and immutable contract revisions.
- Produces: `OfficeErpCalendar::period(string $anchor,int $intervalMonths,int $index,string $timezone,int $dueInDays):array{start:string,end:string,due_on:string}`. Index 0 is the activation period. `OfficeErpBalances::forInvoice(OfficeErpInvoice $invoice):array{original_total:string,adjusted_charge:string,effective_received:string,outstanding:string,settlement_status:string,overdue:bool}` uses the master plan's exact shape. Add fixture `issuedInvoice(OfficeErpExecutionContext $ctx,OfficeErpContract $contract,string $amount="100.00"):OfficeErpInvoice` to the shared test base.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_month_end_and_original_anchor
self::assertSame(['start'=>'2027-02-28','end'=>'2027-03-31','due_on'=>'2027-03-05'], $calendar->period('2027-01-31',1,1,'America/Sao_Paulo',5));
self::assertSame('2028-02-29', $calendar->period('2024-02-29',12,4,'America/Sao_Paulo',0)['start']);
// test_exact_pg_storage_above_js_safe_integer
self::assertSame('9007199254740993.01', $invoice->fresh()->original_total);
// test_number_period_uniqueness_and_foreign_invoice_allocation (separate invalid-write cases)
$this->expectException(QueryException::class);
$invalidInvoiceOrAllocation->save();
// test_two_manual_documents_may_share_a_period_but_recurring_identity_is_unique
self::assertSame(2, $manualInvoicesInSamePeriod->count());
// test_invalid_cycle_or_negative_offset: period(...,2,...) and dueInDays=-1 throw ValidationException
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Unit/OfficeErpCalendarTest.php tests/Feature/OfficeErpCommercial/BillingSchemaTest.php`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** Use NUMERIC(20,2), exact strings and the P1.2 scoped repository/composite relationships. Enforce period uniqueness with a partial unique index for `kind='recurring'` over `(account_id,contract_id,period_start,period_end)`, including canceled rows; do not prohibit separate manual invoices for that period. Reserve display numbers with an Account/year counter such as `ERP-2026-000001`; acquire its lock after contract locks and before invoice locks. Draft/issued/canceled is document_status; settlement and overdue are derived only for issued documents, with no canceled collectible balance. Reversal is an immutable separate row with unique receipt_id. Allocations share Account with invoice/receipt. Settings default manual/zero-grace/America/Sao_Paulo. Validate cycle 1/3/6/12, nonnegative index/offset and IANA timezone; derive every boundary from the original anchor. Freeze time for overdue tests. Run the large-storage assertion only on PostgreSQL; mark that case explicitly skipped in SQLite, where numeric affinity cannot prove it. Add the listed factories without demo seeders.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Unit/OfficeErpCalendarTest.php tests/Feature/OfficeErpCommercial/BillingSchemaTest.php
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit -c phpunit.pgsql.xml tests/Feature/OfficeErpCommercial/BillingSchemaTest.php
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add backend/app/Models/Erp/Commercial/OfficeErpInvoice.php backend/app/Models/Erp/Commercial/OfficeErpInvoiceAdjustment.php backend/app/Models/Erp/Commercial/OfficeErpInvoiceCounter.php backend/app/Models/Erp/Commercial/OfficeErpReceipt.php backend/app/Models/Erp/Commercial/OfficeErpReceiptAllocation.php backend/app/Models/Erp/Commercial/OfficeErpReceiptReversal.php backend/app/Models/Erp/Commercial/OfficeErpCommercialSetting.php backend/database/migrations/2026_10_24_110000_create_office_erp_billing.php backend/app/Erp/Commercial/OfficeErpCalendar.php backend/app/Erp/Commercial/OfficeErpBalances.php backend/tests/OfficeErpCommercialTestCase.php backend/tests/Feature/OfficeErpCommercial/BillingSchemaTest.php backend/tests/Unit/OfficeErpCalendarTest.php backend/database/factories/Erp/Commercial/OfficeErpInvoiceFactory.php backend/database/factories/Erp/Commercial/OfficeErpInvoiceAdjustmentFactory.php backend/database/factories/Erp/Commercial/OfficeErpInvoiceCounterFactory.php backend/database/factories/Erp/Commercial/OfficeErpReceiptFactory.php backend/database/factories/Erp/Commercial/OfficeErpReceiptAllocationFactory.php backend/database/factories/Erp/Commercial/OfficeErpReceiptReversalFactory.php backend/database/factories/Erp/Commercial/OfficeErpCommercialSettingFactory.php
git commit -m "feat(office-erp): add billing storage, balances and original-anchor periods"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

### Task 2: Implement policy admission and licence cause reconciliation

**OpenSpec:** 7.1, 7.2 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Create: `backend/app/Erp/Commercial/OfficeErpDelinquency.php`
- Create: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpSettingsController.php`
- Modify: `backend/app/Enums/Erp/ErpSubscriptionStatus.php`
- Create: `backend/tests/Feature/OfficeErpCommercial/DelinquencyTest.php`
- Modify: `backend/routes/office-erp-commercial.php`

**Interfaces:**
- Consumes: Task 1 authoritative balances/settings, foundation licence allocator/admission, contract snapshots and existing Company security gate.
- Produces: `OfficeErpDelinquency::preview(OfficeErpExecutionContext $ctx,array $settings,int $page=1):array`, `save(OfficeErpExecutionContext $ctx,array $settings,bool $confirmAutomatic,string $key):array`, `reconcile(OfficeErpExecutionContext $ctx,OfficeErpContract $contract,CarbonImmutable $now):string`. Settings input is `{mode:manual|automatic,grace_days:int>=0,billing_timezone:string}`; response uses `{data:settings}`, preview uses `{data:contract_rows,meta:pagination,affected_count:int}`. Routes: `GET/PATCH /erp-commercial/settings`, `POST /erp-commercial/settings/preview`; update body includes `confirm_automatic:boolean`. Extend `ErpSubscriptionStatus` with `Suspended='suspended'`.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_due_day_and_grace_are_inclusive: due_on 2026-10-24, grace 2
self::assertSame('active', $service->reconcile($ctx,$contract,CarbonImmutable::parse('2026-10-26T23:59:59-03:00')));
self::assertSame('past_due', $service->reconcile($ctx,$contract,CarbonImmutable::parse('2026-10-27T00:00:00-03:00')));
// test_settlement_preserves_manual_hold
self::assertSame('suspended', $settledManualHoldStatus);
// test_automatic_policy_requires_explicit_confirmation
$unconfirmedSave->assertUnprocessable()->assertJsonValidationErrors('confirm_automatic');
self::assertSame($beforeSettings, $settings->fresh()->getAttributes());
// test_default_policy_does_not_implicitly_enable_automatic_mode
self::assertSame('manual', $defaultSettings->mode);
self::assertSame(0, $defaultSettings->grace_days);
$unconfirmedSave->assertUnprocessable();
self::assertSame($previousSettings, $settings->fresh()->getAttributes());
// test_no_policy_defaults_to_manual_and_security_suspension_is_not_rewritten
self::assertSame('active', $manualDefaultStatus);
self::assertSame('suspended', $company->fresh()->status);
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/DelinquencyTest.php tests/Feature/ErpCompanyContextTest.php`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** Precedence: canceled agreement ->canceled; unactivated ->pending; manual hold ->suspended; qualifying automatic debt ->past_due; else active. Security suspension stays in its existing boundary. Use local invoice/contract billing timezone and today>due+grace. Partial debt, debit/credit, receipt/reversal, invoice cancellation and policy save all call this same service inside the transaction; never clear manual/cancellation/security causes. Preview is counted/paginated and saving automatic mode requires confirmation. The new enum does not reinterpret unknown historical statuses.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/DelinquencyTest.php tests/Feature/ErpCompanyContextTest.php
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add backend/app/Erp/Commercial/OfficeErpDelinquency.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpSettingsController.php backend/app/Enums/Erp/ErpSubscriptionStatus.php backend/tests/Feature/OfficeErpCommercial/DelinquencyTest.php backend/routes/office-erp-commercial.php
git commit -m "feat(office-erp): implement policy admission and licence cause reconciliation"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

### Task 3: Issue invoices and preserve immutable debit/credit corrections

**OpenSpec:** 5.3, 5.4 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Create: `backend/app/Erp/Commercial/OfficeErpInvoices.php`
- Create: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpInvoiceController.php`
- Create: `backend/app/Http/Requests/OfficeErp/SaveInvoiceRequest.php`
- Create: `backend/app/Http/Requests/OfficeErp/AdjustInvoiceRequest.php`
- Create: `backend/tests/Feature/OfficeErpCommercial/InvoiceTest.php`
- Create: `backend/tests/Feature/OfficeErpCommercial/InvoiceAdjustmentTest.php`
- Modify: `backend/routes/office-erp-commercial.php`

**Interfaces:**
- Consumes: Tasks 1/2 calendar/balances/reconciler; foundation operation journal/admission.
- Produces: `OfficeErpInvoices::draft(OfficeErpExecutionContext $ctx,OfficeErpContract $contract,array $input,string $key):OfficeErpInvoice`, `updateDraft(OfficeErpExecutionContext $ctx,OfficeErpInvoice $invoice,array $input,string $key):OfficeErpInvoice`, `issue(OfficeErpExecutionContext $ctx,OfficeErpInvoice $invoice,string $key):OfficeErpInvoice`, `issuePeriod(OfficeErpExecutionContext $ctx,OfficeErpContract $contract,OfficeErpContractRevision $revision,array $period,string $key):OfficeErpInvoice`, `adjust(OfficeErpExecutionContext $ctx,OfficeErpInvoice $invoice,array $adjustment,string $key):OfficeErpInvoiceAdjustment`, `cancel(OfficeErpExecutionContext $ctx,OfficeErpInvoice $invoice,string $reason,string $key):void`. Manual draft input contains `items:[{description,amount,discount}]`, `period_start`, `period_end`, `due_on`; currency and parties derive from the contract, never client-selected office IDs. Period input uses P2.1 `{start,end,due_on}`.
- Produces routes: `GET/POST /erp-commercial/invoices`, `GET/PATCH /invoices/{id}`, `POST /invoices/{id}/issue`, `/invoices/{id}/adjustments`, `/invoices/{id}/cancel`. Detail includes immutable adjustment history and the shared balance shape. Every mutation uses `Idempotency-Key`.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_credit_settles_partial_receipt_without_changing_original
self::assertSame('100.00', $balance['original_total']);
self::assertSame('40.00', $balance['adjusted_charge']);
self::assertSame('40.00', $balance['effective_received']);
self::assertSame('0.00', $balance['outstanding']);
// test_credit_61_on_100_with_40_received
$excessCredit->assertUnprocessable();
self::assertSame($adjustmentsBefore, $invoice->adjustments()->count());
$excessCredit->assertUnprocessable();
self::assertSame($adjustmentsBefore, $invoice->adjustments()->count());
// test_debit_20_on_settled_100
self::assertSame('20.00', $balances->forInvoice($invoice)['outstanding']);
// test_zero_invoice_has_no_synthetic_receipt
self::assertSame(0, $invoice->receiptAllocations()->count());
// test_changed_operation_payload
$changedPayload->assertConflict();
self::assertSame($firstAdjustmentId, $samePayloadReplayId);
$changedPayload->assertConflict();
self::assertSame($firstAdjustmentId, $replayedAdjustmentId);
// test_opposite_adjustment_is_new_history_and_cancel_requires_reversal
self::assertSame($originalAdjustmentId, $correction->corrects_adjustment_id);
$cancelWithEffectiveReceipt->assertConflict();
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/InvoiceTest.php tests/Feature/OfficeErpCommercial/InvoiceAdjustmentTest.php`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** Expose guarded draft/edit/issue/detail/cancel/adjustment APIs; only effective contracts can be invoiced through standalone commands. Activation sets effective and anchor in its outer transaction before issuePeriod(). Freeze seller/Client/Company/terms/items/currency/dates at issue. Items are description,amount,discount canonical strings; recurring base item uses agreed price and explicit 0.00 discount. Adjustments require direction debit|credit, positive amount, reason and optional opposite-adjustment reference. Lock invoices by ID and enforce adjusted_charge>=effective_received>=0. Reject draft/canceled adjustment and receipt-bearing cancellation; reserve canceled numbers/periods. Reconcile after balance effects; no proration, refunds or negative credit wallet.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/InvoiceTest.php tests/Feature/OfficeErpCommercial/InvoiceAdjustmentTest.php
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add backend/app/Erp/Commercial/OfficeErpInvoices.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpInvoiceController.php backend/app/Http/Requests/OfficeErp/SaveInvoiceRequest.php backend/app/Http/Requests/OfficeErp/AdjustInvoiceRequest.php backend/tests/Feature/OfficeErpCommercial/InvoiceTest.php backend/tests/Feature/OfficeErpCommercial/InvoiceAdjustmentTest.php backend/routes/office-erp-commercial.php
git commit -m "feat(office-erp): issue invoices and preserve immutable debit/credit corrections"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

### Task 4: Record and reverse manual receipts with private proofs

**OpenSpec:** 6.2, 6.3, 6.4 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Create: `backend/app/Erp/Commercial/OfficeErpReceipts.php`
- Create: `backend/app/Erp/Commercial/OfficeErpReceiptProofs.php`
- Create: `backend/app/Models/Erp/Commercial/OfficeErpReceiptProof.php`
- Create: `backend/database/migrations/2026_10_24_120000_create_office_erp_receipt_proofs.php`
- Create: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpReceiptController.php`
- Create: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpReceiptProofController.php`
- Create: `backend/app/Http/Requests/OfficeErp/StoreReceiptRequest.php`
- Create: `backend/app/Jobs/CleanExpiredOfficeErpProofs.php`
- Modify: `backend/routes/business-console.php`
- Create: `backend/tests/Feature/OfficeErpCommercial/ManualReceiptTest.php`
- Create: `backend/tests/Feature/OfficeErpCommercial/ReceiptProofTest.php`
- Create: `backend/database/factories/Erp/Commercial/OfficeErpReceiptProofFactory.php`
- Modify: `backend/routes/office-erp-commercial.php`

**Interfaces:**
- Consumes: Tasks 1-3 balances, immutable receipt/allocation/reversal storage, invoice locks and reconciliation.
- Produces: `OfficeErpReceipts::post(OfficeErpExecutionContext $ctx,array $receipt,string $key):OfficeErpReceipt`, `reverse(OfficeErpExecutionContext $ctx,OfficeErpReceipt $receipt,string $reason,string $key):OfficeErpReceiptReversal`. `OfficeErpReceiptProofs::stage(OfficeErpExecutionContext $ctx,UploadedFile $file):array{id:string,expires_at:string}`, `attach(OfficeErpExecutionContext $ctx,OfficeErpReceipt $receipt,string $proofId):void`, `download(OfficeErpExecutionContext $ctx,string $proofId):StreamedResponse`, `cleanExpired(CarbonImmutable $now):int`. `CleanExpiredOfficeErpProofs::handle(OfficeErpReceiptProofs $proofs):void` deletes only verified unclaimed expired objects.
- Produces routes: `GET/POST /erp-commercial/receipts`, `GET /receipts/{id}`, `POST /receipts/{id}/reverse`, `POST /erp-commercial/receipt-proofs`, `GET /receipt-proofs/{proofId}`. Upload is multipart `file`; its handle enters the ordinary receipt command. Receipt/reversal responses include actual affected invoice balances, never a bank-confirmed state.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_partial_receipt_and_single_reversal
self::assertSame('60.00', $after40['outstanding']);
self::assertSame('100.00', $afterReversal['outstanding']);
self::assertSame($firstReversalId, $replayedReversalId);
// test_allocation_sum_and_locked_balance
$sumMismatch->assertUnprocessable()->assertJsonValidationErrors('allocations');
$staleOverAllocation->assertConflict()->assertJsonStructure(['data'=>['current_balances']]);
// test_private_proof (real-content PDF/PNG/JPEG and size provider)
$validProof->assertCreated();
$oversizeOrDisguisedProof->assertUnprocessable()->assertJsonValidationErrors('file');
$foreignProofDownload->assertNotFound();
// test_failed_receipt_commit_preserves_staged_private_proof
self::assertSame($beforeReceiptCount, $afterReceiptCount);
self::assertNull($proof->fresh()->receipt_id);
Storage::disk('local')->assertExists($proof->path);
self::assertNull($proof->fresh()->receipt_id);
self::assertSame($receiptCountBefore, OfficeErpReceipt::query()->count());
// test_multi_invoice_reversal_restores_each_once
self::assertSame(['60.00', '20.00'], [$firstBalance, $secondBalance]);
// test_foreign_proof_download:404; another actor cannot claim a staged proof
$foreignDownload->assertNotFound();
$otherActorClaim->assertUnprocessable();
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/ManualReceiptTest.php tests/Feature/OfficeErpCommercial/ReceiptProofTest.php`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** Receipt input: amount,received_on,reference?,proof_id?,allocations:[{invoice_id,amount}]; exact sum equals receipt amount. Lock invoices in numeric ID order, then receipt/operation rows. Preserve original receipt and one attributable reversal. Store staged proof once in private local storage; attachment claims only a locked DB handle, avoiding a post-commit file move. Temporary handles bind Account+actor and expire after 24h if unclaimed; failed commit leaves expiring unclaimed data. Committed download requires seller-office receipt authorization; Company invoice consultation grants no seller-wide proof/history access. Clean only verified unclaimed expired task-owned objects, never unrelated private files. Register only this cleanup job on the existing daily schedule with withoutOverlapping(); do not start task or development workers during implementation.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/ManualReceiptTest.php tests/Feature/OfficeErpCommercial/ReceiptProofTest.php
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add backend/app/Erp/Commercial/OfficeErpReceipts.php backend/app/Erp/Commercial/OfficeErpReceiptProofs.php backend/app/Models/Erp/Commercial/OfficeErpReceiptProof.php backend/database/migrations/2026_10_24_120000_create_office_erp_receipt_proofs.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpReceiptController.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpReceiptProofController.php backend/app/Http/Requests/OfficeErp/StoreReceiptRequest.php backend/app/Jobs/CleanExpiredOfficeErpProofs.php backend/routes/business-console.php backend/tests/Feature/OfficeErpCommercial/ManualReceiptTest.php backend/tests/Feature/OfficeErpCommercial/ReceiptProofTest.php backend/database/factories/Erp/Commercial/OfficeErpReceiptProofFactory.php backend/routes/office-erp-commercial.php
git commit -m "feat(office-erp): record and reverse manual receipts with private proofs"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

### Task 5: Generate recurring periods under an explicit system context

**OpenSpec:** 5.5, 5.6 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Create: `backend/app/Erp/Commercial/OfficeErpRecurringBilling.php`
- Create: `backend/app/Jobs/GenerateOfficeErpInvoices.php`
- Create: `backend/app/Console/Commands/GenerateOfficeErpInvoicesCommand.php`
- Modify: `backend/routes/business-console.php`
- Create: `backend/tests/Feature/OfficeErpCommercial/RecurringInvoiceTest.php`

**Interfaces:**
- Consumes: Tasks 1-4 invoice/calendar/reconciliation/journal; onboarding Task 3 effective contract anchors, revision scheduling and cancellation boundaries. This task executes AFTER onboarding Task 3.
- Produces: OfficeErpRecurringBilling::generate(int $contractId,CarbonImmutable $now,int $batchSize=50):array{generated:list<int>,pending:bool}; GenerateOfficeErpInvoices::handle(OfficeErpRecurringBilling $billing):void. Command office-erp:generate-invoices --batch=50, registered with the existing scheduler conventions.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_accepted_unactivated_contract_is_untouched
self::assertNull($accepted->fresh()->service_anchor_on);
self::assertSame(0, $accepted->invoices()->count());
// test_roll_back_cursor_and_restore_context
self::assertSame($beforeCursor, $contract->fresh()->next_period_index);
self::assertSame($beforeTenant, app(\App\Tenant\CurrentTenant::class)->accountId);
// test_right_loss_still_bills_and_suspended_account_does_not
self::assertSame(1, $effectiveWithoutResale->invoices()->count());
self::assertSame(0, $securitySuspendedAccount->invoices()->count());
// test_scheduled_tick_applies_grace_and_period_end_cancellation_without_new_period
self::assertSame('past_due', $overdueSubscriptionAfterTick->status);
self::assertSame($invoiceCountBefore, $overdueContract->invoices()->count());
self::assertSame('canceled', $periodEndContract->fresh()->agreement_status);
self::assertSame('released', $periodEndAllocation->fresh()->status);
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/RecurringInvoiceTest.php tests/Feature/OfficeErpCommercial/DelinquencyTest.php`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** Only effective initialized contracts generate; suspended licences still bill. Under the canonical locks, first call P3.3 `applyDueChanges()` and P2.2 `reconcile()` even when no invoice period is due; a grace expiry and a period-end cancellation must take effect without a receipt or UI action. Stop before generation if cancellation became effective. Recheck active Account/current revision, derive each period from the original anchor, and commit invoice plus cursor together. Canceled recurring periods are never recreated. Bounded catch-up leaves pending=true rather than skipping work or advancing after rollback. Record explicit system contract/job provenance; `CurrentTenant::run` restores context without fabricated membership. Register `office-erp:generate-invoices --batch=50` hourly with chunked contract discovery and `withoutOverlapping`, and schedule P2.4 private-proof cleanup daily. Test concurrent-job safety independently of scheduler overlap prevention. Do not start development workers or scheduler.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/RecurringInvoiceTest.php tests/Feature/OfficeErpCommercial/DelinquencyTest.php
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add backend/app/Erp/Commercial/OfficeErpRecurringBilling.php backend/app/Jobs/GenerateOfficeErpInvoices.php backend/app/Console/Commands/GenerateOfficeErpInvoicesCommand.php backend/routes/business-console.php backend/tests/Feature/OfficeErpCommercial/RecurringInvoiceTest.php
git commit -m "feat(office-erp): generate recurring periods under an explicit system context"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

## Exit Gate

All tasks in this file require their focused behavior checks and task reviews. Consult the master topological order before dispatching a dependent task, especially the recurring task that waits for activation. The global full-suite/browser/migration gates remain separate; this file alone does not authorize release or canonical synchronization.
