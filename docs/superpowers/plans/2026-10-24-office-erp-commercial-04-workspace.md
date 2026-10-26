# Billing workspace and Company consultation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete real reporting, invoice/receipt screens and narrow owner consultation.

**Architecture:** Build read projections from the same balances used by commands, then compose audited Nuxt UI screens. Keep Company-owner reads tied to its already-authorized contract and leave seller-wide commercial data behind office guards.

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

- An adjustment posted this month against last month's invoice moves this month's invoiced total without moving issue date (Task 1).
- Reversed or canceled records are excluded consistently from effective totals while history remains visible (Tasks 1/2).
- A long BRL amount displays and submits exact cents without JavaScript Number coercion (Task 2).
- Company owners cannot select another office invoice, seller-wide receipt or private proof (Task 3).
- Enabling automatic suspension requires reviewed consequences and explicit save; keyboard focus returns after cancel/error (Tasks 2/3).

---

## File Structure

Task file lists define the change scope; files shared by sequential tasks must be carried forward, never recreated. Factories live beside their matching existing Erp factories under `backend/database/factories/Erp/Commercial/`. Domain services live in `backend/app/Erp/Commercial/`; persistent Account-owned records in `backend/app/Models/Erp/Commercial/`; OfficeErp HTTP controllers in their named V1 directory; UI composes existing shells. Existing Company finance records and global auth transports are reused only at their specified boundaries. These paths are planned, not evidence of existing implementation.

### Task 1: Expose authoritative lists and real overview metrics

**OpenSpec:** 8.1, 9.2 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpClientController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpSalesPlanController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpContractController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpInvoiceController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpReceiptController.php`
- Create: `backend/app/Erp/Commercial/OfficeErpQueries.php`
- Create: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpSummaryController.php`
- Create: `backend/app/Http/Requests/OfficeErp/ListCommercialRequest.php`
- Create: `frontend/app/pages/gestao-erp/index.vue`
- Modify: `frontend/shared/types/officeErpCommercial.ts`
- Create: `backend/tests/Feature/OfficeErpCommercial/BillingReportTest.php`
- Create: `frontend/tests/officeErpOverview.test.ts`
- Modify: `backend/routes/office-erp-commercial.php`

**Interfaces:**
- Consumes: Billing authoritative balances/adjustments/receipts, contract states and foundation context capabilities.
- Produces: OfficeErpQueries::summary($ctx,array $filters):array; clients/plans/contracts/invoices/receipts($ctx,array $filters):LengthAwarePaginator. GET /erp-commercial/summary and resource lists. Filter names search,status,from_on,to_on,due_from,due_to,page,per_page; resource-specific validators accept only meaningful fields. Lists data+meta.current_page/last_page/per_page/total.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_later_credit_posting_basis: invoice100 last month, credit20 this month
self::assertSame('-20.00', $thisMonth['invoiced_movement']);
self::assertSame('80.00', $current['outstanding']);
// test_receipt_reversal_report_consistency
self::assertSame($invoiceBalance, $summaryBalance);
// test_initial_failure_does_not_render_fake_zero_and_refresh_marks_prior_rows
```

```ts
assert.equal(view.metrics.value, null)
assert.equal(view.stale.value, true)
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/BillingReportTest.php`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** Issued movement uses issue date plus adjustment server-posting dates in office timezone, excludes canceled documents/adjustments. Receipt totals use received_on and effective reversal state. Outstanding/overdue are current balance with explicit due filters, never historical as-of claims. Real server counts/search/pagination for all six lists. Overview uses PageGrid/PageCard reference proportions; unknown/error/stale/empty remain distinct and rights-loss reasons persist.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/BillingReportTest.php
scripts/office-erp-commercial-runtime/run frontend node --experimental-strip-types --test tests/officeErpOverview.test.ts
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

Run changed-path ESLint after the focused SFC checks. At this plan's feature milestone run frontend typecheck once, serially; no build/type-generation in parallel.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpClientController.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpSalesPlanController.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpContractController.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpInvoiceController.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpReceiptController.php backend/app/Erp/Commercial/OfficeErpQueries.php backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpSummaryController.php backend/app/Http/Requests/OfficeErp/ListCommercialRequest.php frontend/app/pages/gestao-erp/index.vue frontend/shared/types/officeErpCommercial.ts backend/tests/Feature/OfficeErpCommercial/BillingReportTest.php frontend/tests/officeErpOverview.test.ts backend/routes/office-erp-commercial.php
git commit -m "feat(office-erp): expose authoritative lists and real overview metrics"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

### Task 2: Compose invoice, receipt and protected print flows

**OpenSpec:** 8.2, 10.1, 10.2 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Modify: `frontend/shared/utils/officeErpCommercial.ts`
- Create: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpInvoicePrintController.php`
- Create: `frontend/app/pages/gestao-erp/faturas/index.vue`
- Create: `frontend/app/pages/gestao-erp/faturas/[id]/index.vue`
- Create: `frontend/app/pages/gestao-erp/faturas/[id]/imprimir.vue`
- Create: `frontend/app/pages/gestao-erp/recebimentos.vue`
- Create: `frontend/app/components/office-erp/OfficeErpAdjustmentForm.vue`
- Create: `frontend/app/components/office-erp/OfficeErpReceiptModal.vue`
- Create: `frontend/app/components/office-erp/OfficeErpReversalModal.vue`
- Create: `frontend/tests/officeErpBilling.test.ts`
- Create: `backend/tests/Feature/OfficeErpCommercial/InvoicePrintTest.php`
- Modify: `backend/routes/office-erp-commercial.php`

- Modify: `frontend/app/pages/gestao-erp.vue`
- Modify: `frontend/app/utils/sidebarNav.ts`

**Interfaces:**
- Consumes: Billing invoice/receipt/proof APIs and Task 1 read projections; useOfficeErpCommand/context and exact string money helper.
- Produces: GET /erp-commercial/invoices/{id}/print returns protected snapshot/balance data with Cache-Control:no-store. Form payloads use strings, adjustment direction+positive amount+reason, receipt allocations and reversal reason. Print route calls window.print() only after authenticated data loads.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_partial_receipt_sfc_payload
```

```ts
assert.deepEqual(body.allocations, [{ invoice_id: 7, amount: '40.00' }])
assert.equal(body.amount, '40.00')
// test_large_money_text_adapter
assert.equal(parseOfficeErpMoney('9.007.199.254.740.993,01'), '9007199254740993.01')
// test_credit_direction_and_error_retention
assert.equal(body.direction, 'credit')
assert.equal(view.amount.value, '60.00')
assert.equal(view.errorVisible.value, true)
// test_modal_cancel_restores_focus
assert.equal(activeElement, originalTrigger)
// test_print_foreign_denial_no_store_no_payment_instrument
```

```php
$response->assertHeader('Cache-Control','no-store');
self::assertStringNotContainsString('Pix', $printedText);
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run frontend node --experimental-strip-types --test tests/officeErpBilling.test.ts`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** Use counted DataTablePanelList and record/detail classes, Modal receipts/reversal and actual SFC submit/error tests. Show frozen original, adjustments, adjusted charge, received and outstanding separately. Reuse foundation Task4 parseOfficeErpMoney(string):string and formatOfficeErpMoney(string):string from shared/utils/officeErpCommercial.ts; accept unambiguous pt-BR comma decimals/grouping, reject ambiguous/excess precision and never Number(). Preserve focus/drafts on errors, use private upload with exact MIME/5MiB messages. Printable HTML is an internal invoice with browser Print/Save as PDF, no extra PDF dependency, QR code, bank line or bank-confirmed wording.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run frontend node --experimental-strip-types --test tests/officeErpBilling.test.ts
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/InvoicePrintTest.php
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

Run changed-path ESLint after the focused SFC checks. At this plan's feature milestone run frontend typecheck once, serially; no build/type-generation in parallel.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add frontend/shared/utils/officeErpCommercial.ts backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpInvoicePrintController.php frontend/app/pages/gestao-erp/faturas/index.vue 'frontend/app/pages/gestao-erp/faturas/[id]/index.vue' 'frontend/app/pages/gestao-erp/faturas/[id]/imprimir.vue' frontend/app/pages/gestao-erp/recebimentos.vue frontend/app/components/office-erp/OfficeErpAdjustmentForm.vue frontend/app/components/office-erp/OfficeErpReceiptModal.vue frontend/app/components/office-erp/OfficeErpReversalModal.vue frontend/tests/officeErpBilling.test.ts backend/tests/Feature/OfficeErpCommercial/InvoicePrintTest.php backend/routes/office-erp-commercial.php frontend/app/pages/gestao-erp.vue frontend/app/utils/sidebarNav.ts
git commit -m "feat(office-erp): compose invoice, receipt and protected print flows"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

### Task 3: Configure policy and expose only a Company owner's contract

**OpenSpec:** 7.4, 10.3, 10.4 (mark each original checkbox only after its complete mapped deliverable is accepted).

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpCompanyInvoiceController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/Erp/ErpSubscriptionController.php`
- Modify: `backend/app/Http/Resources/Erp/ErpSubscriptionResource.php`
- Modify: `backend/routes/erp.php`
- Create: `frontend/app/pages/gestao-erp/configuracoes.vue`
- Modify: `frontend/app/pages/erp/contrato.vue`
- Create: `frontend/tests/officeErpPolicyAndOwner.test.ts`
- Create: `backend/tests/Feature/OfficeErpCommercial/CompanyInvoiceAccessTest.php`

- Modify: `frontend/app/pages/gestao-erp.vue`
- Modify: `frontend/app/utils/sidebarNav.ts`

**Interfaces:**
- Consumes: Billing policy preview/save, onboarding linked retail grants and invoice projections, existing CurrentCompany direct-owner authorization.
- Produces: GET /erp/subscription preserves old response and adds retail_contract only where authorized; GET /erp/commercial-invoices and /{id} resolve via current Company current contract. Owner query methods never accept arbitrary Account context. Settings screen consumes settings/preview APIs with explicit confirm_automatic.

- [ ] **Step 1: Write the named behavior tests before implementation.** Use the existing test base/SFC harness; fixture variables refer to the exact producer interfaces above. Add separate test methods/cases for the assertions below.

```php
// test_owner_invoice_belongs_only_to_current_company
$foreignInvoice->assertNotFound();
$sellerWideReceiptRoute->assertForbidden();
// test_normal_pending_company_page_never_offers_self_activation
```

```ts
assert.equal(view.pendingStatusVisible.value, true)
assert.equal(view.canActivate.value, false)
// test_automatic_policy_toggle_only_changes_draft_until_explicit_save
assert.equal(writes.length, 0)
assert.equal(preview.affected_count, 2)
```

- [ ] **Step 2: Run the focused first command and confirm the intended failure.** Run `scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/CompanyInvoiceAccessTest.php`. Expected: named new behavior fails because its implementation is absent, not because of wrong Origin, stores, missing packages or a broken harness.

- [ ] **Step 3: Implement the declared interfaces in the listed files.** Direct Company owner can read own retail terms/invoice balance while inactive under existing narrow contract read guards; no office membership, seller list, unrelated proof or automatic delegation. Unlinked direct contract presentation remains. Persistent actual pending status must have an actual page SFC test, resolving the earlier crosswalk.csv:16 review minor. Policy preview explains consequences, supports keyboard/error/focus feedback and saves only explicitly; no instant-toggle mutation.

- [ ] **Step 4: Run the covering checks and inspect their output.** Expected: exit 0, no unexpected failures/warnings, actual target behavior and invariant assertions pass; record skips/untested boundaries explicitly.

```bash
scripts/office-erp-commercial-runtime/run backend vendor/bin/phpunit tests/Feature/OfficeErpCommercial/CompanyInvoiceAccessTest.php
scripts/office-erp-commercial-runtime/run frontend node --experimental-strip-types --test tests/officeErpPolicyAndOwner.test.ts
```

After PHP edits, run `(cd backend && vendor/bin/pint --dirty --format agent)` on the host with Git available, before committing.

Run changed-path ESLint after the focused SFC checks. At this plan's feature milestone run frontend typecheck once, serially; no build/type-generation in parallel.

- [ ] **Step 5: Self-review, commit only this task and hand off its report.**

```bash
git add backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpCompanyInvoiceController.php backend/app/Http/Controllers/Api/V1/Erp/ErpSubscriptionController.php backend/app/Http/Resources/Erp/ErpSubscriptionResource.php backend/routes/erp.php frontend/app/pages/gestao-erp/configuracoes.vue frontend/app/pages/erp/contrato.vue frontend/tests/officeErpPolicyAndOwner.test.ts backend/tests/Feature/OfficeErpCommercial/CompanyInvoiceAccessTest.php frontend/app/pages/gestao-erp.vue frontend/app/utils/sidebarNav.ts
git commit -m "feat(office-erp): configure policy and expose only a company owner's contract"
```

One fresh task reviewer checks spec + quality against BASE..HEAD; resolve material findings with the original implementer before the next dispatch.

## Exit Gate

All tasks in this file require their focused behavior checks and task reviews. Consult the master topological order before dispatching a dependent task, especially the recurring task that waits for activation. The global full-suite/browser/migration gates remain separate; this file alone does not authorize release or canonical synchronization.
