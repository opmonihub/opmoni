# Office ERP Commercial Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete `add-office-erp-commercial-management`: office-owned offers, owner-confirmed ERP sales, recurring internal invoices, manual receipts and bounded maintenance after resale-right loss.

**Architecture:** Add Account-owned commercial records and services beside the existing Company ERP domain. Keep one Company subscription, immutable accepted terms and exact billing evidence; use a dedicated office admission boundary without weakening ordinary tenant middleware. Deliver five subsystem plans in the dependency order below, then reconcile actual acceptance evidence.

**Tech Stack:** Laravel 13 / PHP 8.4 / PostgreSQL 18 / Redis 8; Nuxt 4.6.0 / Vue 3.5.43 / Nuxt UI 4.11.3 / TypeScript / Zod 4.6.5 / pnpm 12.11.2; existing BCMath, PHPUnit and Node test runner. No added dependencies.

**Spec:** [proposal](../../../openspec/changes/archive/2026-10-25-add-office-erp-commercial-management/proposal.md), [design](../../../openspec/changes/archive/2026-10-25-add-office-erp-commercial-management/design.md), [five deltas](../../../openspec/changes/archive/2026-10-25-add-office-erp-commercial-management/specs/), [55-item checklist](../../../openspec/changes/archive/2026-10-25-add-office-erp-commercial-management/tasks.md), [69-scenario crosswalk](../../reviews/2026-10-24-office-erp-commercial-management/scenario-crosswalk.csv), [component matrix](../../../openspec/changes/archive/2026-10-25-add-office-erp-commercial-management/component-matrix.md).

## Global Constraints

- Reuse `/home/obsidian/dev/opmoni/.worktrees/office-erp-commercial-design`, branch `codex/office-erp-commercial-design`; preserve existing dirty files, `GLOSSARY.md`, `.env`, `APP_KEY`, identities and data. This planning pass changes only the six plan documents.
- "No automatic adoption of legacy direct subscriptions, global permission grant, hard-coded office ID 1 or general inactive-office write bypass."
- "Do not loosen `ResolveAccount` for other business routes". Account status, real admin membership, selected-office assertion and current session ownership remain mandatory. Super Admin grants no tenant access.
- "Use exact `NUMERIC(20,2)` money columns and canonical two-place decimal strings at API boundaries." "No new arithmetic package is needed." Never store seller receivables in Company finance.
- "All office calls use the normal private Nuxt `/api/v1` proxy, CSRF for writes, Account assertion and no-store responses." Frontend `$api` paths omit `/api/v1`.
- "Agreement states are `draft`, `awaiting_owner`, `accepted`, `effective`, `canceled`." Retain one current Company subscription and accepted grant snapshots after resale/subscription loss.
- "The first period starts on the activation date in the office billing timezone". Use BRL, original-anchor intervals of 1/3/6/12 calendar months, inclusive start/exclusive end, `due_on = period_start + due_in_days`, default timezone `America/Sao_Paulo`; no implicit backdating, proration or pre-activation generation.
- "The initial office policy is manual, with zero grace unless explicitly configured." Debt settlement clears only an automatic hold.
- Owner invitations expire after seven days; token hash at rest, transient fragment exchange, explicit owner authentication, no automatic accounting delegation. Private receipt proofs accept actual PDF/PNG/JPEG up to 5 MiB.
- Preserve `.dashboard/app`, Public Sans, semantic green/zinc and existing contrast fixes. Apply the Nuxt UI skill and official APIs/examples before UI edits. Money uses text inputs, not numeric coercion; copy is pt-BR and artifacts/code are English.
- "Use a consistent lock order: Account, current office subscription/allowance, Company, contract/allocation, invoices by ID, then receipt/adjustment/operation rows." Plan updates never acquire Account after Plan; lock multiple Companies/contracts by ID within their level.
- No gateway, Pix, boleto, bank confirmation, refund, fiscal issuance, archive, merge, push or production rollout. Live `ERP_REGISTRATION_ENABLED=false`, fiscal and SERPRO gates stay configured.

## Review Focus

- Values above the JavaScript safe-integer range, excess precision and storage overflow: P1.2/P1.4/P2.1/P4.2 test exact strings, `1.001` rejection and real PostgreSQL storage.
- Intended owners with no memberships, wrong identities or expired continuations: P3.2 tests access continuation, unchanged credentials, no redirects to unavailable workspaces and no secret retention.
- Allowance removal or last-slot contention during acceptance: P1.3/P3.2/P5.2 prove no partial Company/membership/link/occupied allocation and preserve already accepted sales.
- A lost monetary response or credit/receipt/reversal race: P2.3/P2.4/P5.1/P5.2 prove one effect, current authorized recovery, nonnegative balances and preserved unrelated holds.
- No new user action after grace expiry, plus Account/session changes while work is pending: P2.5 tests scheduled reconciliation without a new invoice; P1.4/P5.1 discard stale callbacks and P2.5 restores tenant context after failure.

---

## Scope and verified checkpoint

The changes directory contains the pending `add-office-erp-commercial-management` and the already checked-off `align-erp-template-and-product-setup`. This plan implements the commercial change only. Preserve the main checkout's separate archive/spec edits; do not replay the completed template change.

The application implementation checkpoint is `d52bbbd1a281b60af25b633b7fcb1e41091a1ef4`; the planning commit follows it. The commercial checklist has **55 items: 5 checked, 50 pending**. The existing execution ledger records subagent-driven execution; retain that handoff for a later implementation request. No implementer or reviewer is dispatched while writing this plan.

| Completed original item | Verified repository evidence |
|---|---|
| 1.1 Baseline preservation | `14f9f22`; `docs/reviews/2026-10-24-office-erp-commercial-management/baseline-preservation.md` |
| 1.2 Isolated runtime | `88856d6`, listener correction `44217f9`; `scripts/office-erp-commercial-runtime/run` |
| 1.3 Requirement/scenario map | `a535f3c`; 26 requirements / 69 scenarios in the five deltas and CSV |
| 1.4 UI reference audit | `0108a0c`; six reference fingerprints / 33 selected components |
| 2.1 Backend office-plan allowance | `d52bbbd`; `Plan::DEFAULT_ERP_COMMERCIAL`, `UpdatePlanRequest::erpCommercialRules()`, `PlanErpCommercialTest.php` |

Allowance defaults already implemented are `{enabled:false, licence_limit:0, module_keys:[]}`; existing raw Plan envelopes and the three legacy limits remain intact. Do not reimplement them.

Recorded prior validation, not rerun in this planning pass: task 2.1 focused SQLite and PostgreSQL checks each passed 67 tests / 368 assertions. The prior complete regular run recorded 3,502 tests / 32,675 assertions, **23 WhatsApp route failures and 3 skipped**. Its focused mocked WhatsApp probe passed 26 / 142 with a process-only dispatch flag. These records do not establish a globally green branch; final acceptance remains pending.

## Architecture and file responsibilities

| Existing boundary | Reuse / planned integration |
|---|---|
| `backend/app/Http/Middleware/ResolveAccount.php`, `Support/CurrentAccount.php`, `Tenant/CurrentTenant.php` | Preserve ordinary write guards; new commercial middleware binds the same Account/session assertions. `BelongsToAccount` currently requires request membership and cannot be blindly applied to owner/system writes. |
| `backend/app/Erp/ErpCompanyProvisioning.php`, `ErpCompanyInitializer.php` | Provision the actual owner's Company and native defaults inside acceptance; preserve direct subscription until explicit adoption activation. |
| `backend/app/Erp/ErpCommercialGate.php`, `Models/Erp/ErpSubscription.php` | Keep unique Company identity; add nullable retail contract link and accepted-grant resolution for linked subscriptions only. |
| `backend/app/Erp/Commercial/`, `backend/app/Models/Erp/Commercial/` | Planned services and Account-owned records; exact-money, operation/audit, offer, contract, billing and recovery boundaries. No Company finance reuse. |
| `backend/app/Http/Controllers/Api/V1/OfficeErp/`, `backend/routes/office-erp-commercial.php` | Office HTTP adapters and explicit guarded actions. Separate `office-erp-invitations.php` handles owner setup without selected tenancy. |
| `frontend/app/layouts/default.vue`, `utils/pageShell.ts`, `utils/sidebarNav.ts` | Existing office shell, scroll/detail/table patterns, sidebar and search ownership; add `/gestao-erp` once. |
| `frontend/app/components/office-erp/`, `composables/useOfficeErpContext.ts`, `useOfficeErpCommand.ts` | Planned reusable forms, authority fencing and operation recovery. Reuse `useAccessApi`, `useRetryableLoad`, `createLatestRequestRunner` and `shared/utils/erpOperationRecovery.ts` where their contracts fit. |

The accepted UI audit records installed versions, component declarations and official Table, SelectMenu and FileUpload examples. Recheck selected component declarations and official APIs before implementation; newer online examples do not expand the audited component subset.

## Delivery order

| Stage | Exact task order | Independently verifiable result |
|---|---|---|
| 1 | P1.1 → P1.2 → P1.3 → P1.4 | Resale editor and Account-owned offer catalog work without a selected Company. |
| 2 | P2.1 → P2.2 → P2.3 → P2.4 | Exact billing, immutable adjustments, manual receipts/reversals and access causes pass domain/API tests. |
| 3 | P3.1 → P3.2 → P3.3 → P2.5 | Reviewed owner onboarding and atomic activation work; scheduler then generates later periods and reconciles due holds/cancellation. |
| 4 | P4.1 → P4.2 → P4.3 | Real reporting, billing screens, protected print and narrow Company-owner consultation work. |
| 5 | P5.1 → P5.2 → P5.3 | Recovery, independent PostgreSQL races, browser acceptance, full checks and compatible rollback are evidenced. |
| 6 | Final.1 → Final.2 | Whole-branch review and truthful scenario/spec/documentation reconciliation. |

There are **18 feature/verification tasks across five subsystem plans, plus two final acceptance tasks**. Shared routes, types and generated Nuxt metadata are edited sequentially. P1.4 produces money parsers and recovery before its first offer mutation; P3.1 creates the contract snapshot atomically with invitation issue; P3.3 consumes P2.3 invoices; P2.5 waits for P3.3 lifecycle services. Reconciliation precedes monetary commands and runs on scheduled ticks even without new periods.

- **P1 — [Office catalog and admission](2026-10-24-office-erp-commercial-01-foundation.md)**: 4 tasks.
- **P2 — [Exact billing and recurring processing](2026-10-24-office-erp-commercial-02-billing.md)**: 5 tasks; its final task runs after P3.
- **P3 — [Owner acceptance and contract lifecycle](2026-10-24-office-erp-commercial-03-onboarding.md)**: 3 tasks.
- **P4 — [Billing workspace and Company consultation](2026-10-24-office-erp-commercial-04-workspace.md)**: 3 tasks.
- **P5 — [Recovery, races and integrated acceptance](2026-10-24-office-erp-commercial-05-reliability.md)**: 3 tasks.

## Shared contracts

- `OfficeErpExecutionContext` carries verified Account, optional actor User, actor kind `office_admin|owner|system`, trusted actor identity and bounded purpose. Human office context uses `CurrentAccount`; owner setup verifies its invitation independently; system work verifies contract plus active Account inside `App\Tenant\CurrentTenant::run(int $accountId, callable $callback):mixed`. Restore context on all exits; no fabricated membership or global scope disable.
- New detail/mutation resources use `{data:T}`; lists use `{data:T[],meta:{current_page,last_page,per_page,total}}`. Existing platform Plan resources remain raw. Use `Idempotency-Key` as the existing mutation transport does; tenant and operation keys never choose the Account from request body.
- HTTP errors retain `{code:string,message:string,errors?:Record<string,string[]>}`: 422 validation, 403 authority, 404 foreign/missing resource, 409 stale context/balance/capacity/payload conflict. Recoverable conflicts may add `data.current_balances` using the exact balance shape below. No foreign metadata in errors.
- `OfficeErpContract`: `account_id`, `client_id`, nullable `erp_company_id`, `agreement_status`, `current_revision_id`, nullable `pending_revision_id`, nullable `service_anchor_on`, `billing_timezone`, nullable `next_period_index`, nullable `cancel_effective_on`, `manual_hold`. Immutable revisions retain `price`, `currency=BRL`, `interval_months`, `due_in_days`, `module_keys`; effective licence status stays on the Company subscription.
- `OfficeErpInvoice`: `account_id`, `contract_id`, `contract_revision_id`, `kind=manual|recurring`, `document_status=draft|issued|canceled`, nullable `number`/`issued_at`, period/due dates, currency, party/item snapshots, `original_total`. Balance shape: `{original_total,adjusted_charge,effective_received,outstanding,settlement_status:unpaid|partial|settled,overdue:boolean}`; all amounts are strings.
- P1.2 defines `OfficeErpOperations::execute($ctx,$key,$action,$payload,$authorize,$effect):array` and `lookup($ctx,$key,$authorize):array`. `execute` and `lookup` open their transaction before invoking `$authorize`; that callback rechecks current authority and locks the actual business target before replay/effect; operation identity is acquired after business locks. Canonical JSON sorts object keys, preserves semantic list order and hashes normalized exact values. Equal replay returns original sanitized outcome; changed action/payload conflicts. No raw invitation token/password in payload, hash input copy, outcome or audit.
- P1.4 defines `ContextToken={key:string,generation:number}`, `OfficeErpCapabilities`, `OfficeErpFailure` and typed command outcomes before any UI consumes them. Context identity includes session generation, Account id/status/role and server capability identity; a selected Company is not required. Persist only nonsecret pending command data under the matching Account/actor/session generation and clear it on authority changes.
- P1.4 defines `parseOfficeErpMoney(input:string):string` for editor text and `formatOfficeErpMoney(value:string):string` for canonical amounts. Both retain digits without `Number`, `parseFloat`, `.number`, floating point formatting or numeric Zod coercion. P4 reuses them.

## Execution and validation policy

1. This is a planning deliverable. Before implementation, review this plan and preserve the recorded subagent-driven method unless the user chooses another. Read the orchestrator skill before any delegation. Use task-scoped implementers/review and one final branch review; fixes return to the original implementer. No concurrent writers in the same worktree.
2. Start at **P1 Task 1 / original 2.2**. Carry forward the completed five original IDs and prior `.superpowers/sdd/tasks/` reports; create no replacement baseline and redispatch none of those items. Initialize a distinct SDD ledger for each linked plan with its producer interfaces and carried completion evidence; the old 55-item brief order is superseded by the master delivery order.
3. Every feature task uses named RED → implementation → GREEN steps, focused tests, matching Pint or changed-file ESLint, explicit-path commit and review. Verification/documentation tasks record results without manufacturing a failing test.
4. Before suites, run `scripts/office-erp-commercial-runtime/run verify`. The runner checks mounts/network/listener, private PostgreSQL `opmoni_test` and Redis 13–15, then repeats guards for `backend`/`frontend` commands. Host frontend is on 3346; backend mounts the whole worktree. Read `runtime-handoff.md` if unavailable; never fall back to generic development Compose commands.
5. Ordinary tests use Origin `http://localhost:3000`, unlike the live task listener; use process-only `FRONTEND_ORIGIN=http://localhost:3000 FISCAL_ENVIRONMENT=producao` where required. Only the mocked WhatsApp route preflight/full regular test process uses `WHATSAPP_DISPATCH_ENABLED=true`; never edit live gates. PostgreSQL profile forces its own isolated settings.
6. Run `(cd backend && vendor/bin/pint --dirty --format agent)` before PHP commits where Git is usable. For UI tasks run the explicit changed-path ESLint commands in each plan; run frontend typecheck serially at subsystem exits. Run full backend/PostgreSQL/frontend suites and build at P5.3, repeating earlier only for a new failure or boundary change.
7. Keep development DB/Redis 0–2 intact. Existing checked-in fixture setup/cleanup may run only after private-store assertions. Manual destructive commands, reset-based rollback and development worker restarts remain prohibited. Apply additive migrations with `php artisan migrate --force --no-interaction`; rollback uses a compatible app version and retains schema/history.
8. A task covers every scenario attached to its original IDs, not just its example assertions. Preserve scenario identities in the CSV; some older planned test filenames differ from these plans. During execution replace those planned references with the actual accepted method/browser artifacts, never with invented success. Keep an original ID unchecked until its complete mapped scope passes.

## Original55-item crosswalk

| Original ID | Execution owner | Current state |
|---|---|---|
|1.1|Completed baseline 14f9f22|Completed|
|1.2|Completed runtime 88856d6/44217f9|Completed|
|1.3|Completed crosswalk a535f3c|Completed|
|1.4|Completed UI reference 0108a0c|Completed|
|2.1|Completed backend allowance d52bbbd|Completed|
|2.2|P1.1|Pending|
|2.3|P1.3|Pending|
|2.4|P1.3|Pending|
|3.1|P1.2|Pending|
|3.2|P1.4|Pending|
|3.3|P1.2|Pending|
|3.4|P1.3|Pending|
|3.5|P3.3|Pending|
|3.6|P3.3|Pending|
|4.1|P3.1|Pending|
|4.2|P3.2|Pending|
|4.3|P3.2|Pending|
|4.4|P3.2|Pending|
|5.1|P2.1|Pending|
|5.2|P2.1|Pending|
|5.3|P2.3|Pending|
|5.4|P2.3|Pending|
|5.5|P2.5|Pending|
|5.6|P2.5|Pending|
|6.1|P2.1|Pending|
|6.2|P2.4|Pending|
|6.3|P2.4|Pending|
|6.4|P2.4|Pending|
|7.1|P2.2|Pending|
|7.2|P2.2|Pending|
|7.3|P3.3|Pending|
|7.4|P4.3|Pending|
|8.1|P4.1|Pending|
|8.2|P4.2|Pending|
|8.3|P5.1|Pending|
|9.1|P1.4|Pending|
|9.2|P4.1|Pending|
|9.3|P1.4|Pending|
|9.4|P3.1|Pending|
|9.5|P3.3|Pending|
|9.6|P1.4|Pending|
|10.1|P4.2|Pending|
|10.2|P4.2|Pending|
|10.3|P4.3|Pending|
|10.4|P4.3|Pending|
|10.5|P5.1|Pending|
|11.1|P5.2|Pending|
|11.2|P5.3|Pending|
|11.3|P5.3|Pending|
|11.4|P5.3|Pending|
|11.5|P5.3|Pending|
|11.6|P5.3|Pending|
|12.1|Final.2|Pending|
|12.2|Final.1|Pending|
|12.3|Final.2|Pending|

## Final acceptance units

### Task 1: Review the complete commercial branch (Final.1)

**OpenSpec:** 12.2.

**Files:** Create `docs/reviews/2026-10-24-office-erp-commercial-management/final-review.md`; any corrective code stays in its owning task's files with a meaningful regression test.

**Interfaces:** Consumes accepted P1–P5 commits and evidence from feature base `f8efb50` through final HEAD; produces a finding ledger and independent tenancy/money/owner/concurrency/UI verdicts.

- [ ] **Step 1: Assemble the review package.** Include complete diff, five delta specs, focused/full results and known baseline limits; refer to planned pending-Company SFC coverage from P4.3, rather than assuming it is already tested.
- [ ] **Step 2: Obtain independent review.** Follow the orchestrator and requesting-code-review skills. Require concrete file/line findings on isolation, exact balances, ownership, transaction/lock behavior and installed UI compatibility.
- [ ] **Step 3: Resolve material findings.** Return them to the original implementer; each fix receives its covering RED/GREEN cycle and focused re-review. No repeated global suite without a justified changed boundary.
- [ ] **Step 4: Record the verdict.** Original 12.2 is complete only after material findings are resolved; preserve any unverified provider/production behavior explicitly.
- [ ] **Step 5: Commit the review record.** Run `git add docs/reviews/2026-10-24-office-erp-commercial-management/final-review.md` then `git commit -m "docs(office-erp): record final commercial review"`. Corrective commits stage their explicit owned paths separately.

### Task 2: Reconcile actual evidence and canonical contracts (Final.2)

**OpenSpec:** 12.1, 12.3.

**Files:** Modify `docs/reviews/2026-10-24-office-erp-commercial-management/scenario-crosswalk.csv`, `docs/architecture/product-decisions.md`, `docs/architecture/system-spec-reconciliation.md`, `README.md`, and existing canonical `access-pages`, `erp-commercial-access`, `platform-commercial-management` specs; create canonical `office-erp-commercial-management/spec.md` and `office-erp-billing/spec.md` only after accepted implementation. The apply workflow owns actual checklist updates.

**Interfaces:** Consumes accepted routes, test/browser evidence and final review; produces a 69-scenario actual evidence map, bounded D009 maintenance exception and validated canonical specs.

- [ ] **Step 1: Verify all 69 scenario identities.** Each links to actual method/artifact, result, commit and runtime identity. Planned test names are not test evidence; incomplete scenarios stay pending.
- [ ] **Step 2: Reinspect current canonical differences.** Preserve newer main-checkout contracts, including the Office Settings requirement recorded in baseline; never copy stale worktree specs wholesale over current contracts.
- [ ] **Step 3: Reconcile documentation/specs.** Apply domain-modeling for decisions and openspec-sync-specs for the five delta capabilities. Describe only delivered routes and the bounded inactive-subscription maintenance exception.
- [ ] **Step 4: Validate reconciled documents.** Run `openspec validate add-office-erp-commercial-management --strict --no-interactive`, `openspec validate --all --strict --no-interactive` and `git diff --check`. Expected: exit 0; every original item marked complete has current supporting evidence.
- [ ] **Step 5: Commit explicit documentation/spec paths.** Use `git commit -m "docs(office-erp): reconcile commercial acceptance and contracts"`; do not stage unrelated changes. Archive, merge, push and production rollout remain separate user-requested workflows.

## Plan self-review and handoff

Check the original 55-item denominator and all 26 requirements / 69 scenarios, task dependency order, producer/consumer types, and a named owning test for each Review Focus. Existing OpenSpec/crosswalk files are read-only during this planning pass. Confirm all `Modify` paths exist and every planned `Create` path has an owner; later modifications refer to that producer explicitly.

Planning validation covers document consistency, links, task/scenario coverage and strict OpenSpec validation. Application suites, browser acceptance, provider calls, migrations and implementation are **not performed by this planning pass**. The next implementation task is P1.1, after the user's plan review; the existing subagent-driven handoff remains recorded without starting execution.

Self-review completed on 2026-10-24: all six documents have valid local links/headers/checkbox steps, every planned file has an owner/producer, all 55 original items map once, and all 26 requirements / 69 source scenario identities remain mapped. Strict validation of the commercial change passed. No application suite was rerun for these documentation changes.
