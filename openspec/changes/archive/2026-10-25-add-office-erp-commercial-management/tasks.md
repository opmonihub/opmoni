## 1. Baseline and acceptance map

- [x] 1.1 Reinspect the selected worktree, current canonical specs and dirty main-checkout archive work before applying; verify a recorded baseline and no unrelated overwrite.
- [x] 1.2 Prepare the isolated application/test runtime and confirm worktree mounts, PostgreSQL `opmoni_test` and Redis 13-15; verify runtime labels and connections before executing any suite.
- [x] 1.3 Create a requirement/scenario-to-task/test crosswalk for all five deltas; verify every scenario has a planned automated or browser acceptance reference.
- [x] 1.4 Recheck `.dashboard/app` fingerprints, installed versions and the component matrix before UI edits; verify any changed baseline/API is reconciled rather than silently assumed.

## 2. Office plan rights and commercial admission

- [x] 2.1 Add disabled-by-default office-plan ERP resale settings with strict validation and backward-compatible Plan resources; verify legacy limit replacement and invalid allowance tests.
- [x] 2.2 Extend the `/admin/planos` editor with resale enablement, capacity and allowed modules using the audited form controls; verify actual SFC submit/error tests and frontend typecheck.
- [x] 2.3 Implement server commercial context/capabilities and real-office-admin admission; verify Office 1/other-office parity, operator denial, foreign resources and Super Admin-without-membership denial.
- [x] 2.4 Implement the bounded existing-contract maintenance boundary independently of new-sale admission; verify rights/subscription loss preserves maintenance while unrelated inactive tenant writes and new sales remain denied.

## 3. Retail offers, contract storage and operations

- [x] 3.1 Add Account-owned offer/revision, contract/revision and licence-allocation storage with restricted/composite relationships; verify schema constraints, preserved legacy subscriptions and tenant isolation tests.
- [x] 3.2 Implement own-office plan list/detail/create/revision/archive APIs; verify exact BRL input, invalid modules, independent office pricing and sold-plan immutability tests.
- [x] 3.3 Add durable commercial operation identities and actor-aware audit/recovery; verify equal repeats, unequal payload conflicts and forbidden operation lookup tests.
- [x] 3.4 Implement allocation/reservation/release and usage projections; verify draft/reserved/accepted/active/suspended/canceled counting and reduced-capacity behavior.
- [x] 3.5 Implement accepted-contract activation and one-current-Company linking; verify atomic first invoice/licence activation, no duplicate subscriptions and grandfathered accepted activation after right loss.
- [x] 3.6 Implement next-cycle amendments, unchanged-term renewals, manual holds and immediate/period-end cancellation; verify term snapshots, no implicit proration and preserved invoice/Company history.

## 4. Owner onboarding and access continuation

- [x] 4.1 Implement portfolio onboarding preview and single-use seven-day owner invitations with reserved capacity; verify foreign/inactive Client rejection, expiry/reissue/revocation and secret-redaction tests.
- [x] 4.2 Implement session-bound invitation resolve/login continuation and new/existing-identity acceptance; verify no password overwrite, no open redirect, no selected-tenant requirement and rejection after resale-right loss without Company/membership/link changes or occupied allocation.
- [x] 4.3 Integrate owner-confirmed new-Company provisioning and explicit existing-Company adoption; verify rollback, CNPJ ownership, unchanged pre-activation direct access and no automatic accounting delegation.
- [x] 4.4 Add the audited `/erp/ativar` setup page and bounded access-middleware continuation; verify first access, existing-user login, invalid link versus service failure and no redirect loops.

## 5. Invoice storage and recurring schedule

- [x] 5.1 Add Account invoice/snapshot/immutable-adjustment, numbering and period uniqueness storage with exact decimal validation; verify cross-office constraints, zero/large values and rejected excess precision tests.
- [x] 5.2 Implement anchored calendar periods and due dates for 1/3/6/12-month cycles; verify frozen-clock short-month, leap-year, timezone and original-anchor tests.
- [x] 5.3 Implement invoice draft/issue/detail/list and immutable issued snapshots; verify plan edits do not change issued evidence and zero totals create no synthetic receipt.
- [x] 5.4 Implement immutable reasoned debit/credit adjustments and cancellation; verify exact adjusted balances, partially received credit limits, settled-invoice debit, equal/changed retries, receipt-bearing cancellation denial and no regeneration of canceled recurring periods.
- [x] 5.5 Implement bounded recurring/catch-up jobs for effective contracts only under an explicit system commercial context; verify accepted/unactivated contracts receive no invoice, cursor or licence mutation, plus rollback cursor recovery, tenant-context restoration, continued billing after resale loss and Account suspension denial.
- [x] 5.6 Implement effective cancellation/amendment boundaries in recurring generation; verify no future canceled periods and one revision per applicable period.

## 6. Manual receipts, allocations and reversal

- [x] 6.1 Add manual receipt/allocation/reversal storage and immutable actor/reason history; verify exact amounts, required allocation sums and restricted invoice relationships.
- [x] 6.2 Implement full/partial manual receipt posting and balance queries; verify `100.00` minus `40.00` produces exactly `60.00`, over-allocation rejection and operation recovery.
- [x] 6.3 Implement one-time receipt reversal and dependent balance restoration; verify repeated reversal, multi-invoice allocation and no bank-refund claim.
- [x] 6.4 Implement optional private receipt proof upload/download and temporary cleanup; verify MIME/5-MiB limits, owner/Account authorization, failed commit and no public evidence URL.

## 7. Delinquency and Company commercial access

- [x] 7.1 Add office manual/automatic delinquency policy, grace settings and affected-contract preview; verify explicit-save validation and default-manual behavior.
- [x] 7.2 Implement cause-based licence reconciliation and the manual suspended status; verify due/grace boundaries, partial debt, debit/credit, settlement/reversal and preservation of manual/security/cancellation holds.
- [x] 7.3 Integrate accepted retail grants with the existing Company commercial gate; verify unlinked direct subscriptions keep legacy behavior and office-right loss does not revoke accepted grants.
- [x] 7.4 Add owner-scoped contract/invoice consultation through Company context; verify owners see only their Company's terms/invoices and no seller-wide data or office membership is granted.

## 8. Read models and printable documents

- [x] 8.1 Implement context, summary, client, contract, invoice and receipt server filters/pagination; verify authoritative adjusted totals, issue/posting/received date criteria, current-balance due filters and consistent canceled/reversed reporting.
- [x] 8.2 Implement protected print-friendly invoice output and browser Print / Save as PDF; verify snapshots, no-store headers, foreign denial and no fiscal/Pix/boleto indicators.
- [x] 8.3 Complete explicit API failure/conflict/recovery envelopes and typed frontend contracts; verify 422/403/404/409 and exact money-string payload tests.

## 9. Office commercial shell and sales screens

- [x] 9.1 Add the `/gestao-erp` parent under the current office layout and update sidebar/search/group resolution/access guards; verify ordinary admin navigation, no Company requirement and no Super Admin bypass.
- [x] 9.2 Build real overview metrics and persistent rights-loss/unknown-capability states from server capabilities; verify initial error, stale refresh and no fabricated zero/success SFC tests.
- [x] 9.3 Build own-plan list and Slideover create/edit/archive actions using existing table/form wrappers; verify reference comparison, field preservation and decimal-string submits.
- [x] 9.4 Build portfolio client list and page-form onboarding review/invitation workflow; verify server search, correct Client-versus-ERP-customer labels and recovery of issued invitations.
- [x] 9.5 Build subscription list/detail and explicit lifecycle/amendment actions; verify separate agreement/licence states and maintenance behavior after right loss.
- [x] 9.6 Remove old ERP commercial navigation and implement guarded legacy bookmarks; verify ordinary users remain denied on privileged URLs and no unauthorized office is selected.

## 10. Billing and policy screens

- [x] 10.1 Build invoice list/detail/adjustment/print flows with server filters and current balances; verify actual SFC state tests, debit/credit direction and exact string payloads, counted pagination, original/adjusted values and immutable history.
- [x] 10.2 Build manual receipt and reasoned reversal Modal flows plus receipt history; verify partial/full values, exact payloads, proof validation and no bank-confirmed wording.
- [x] 10.3 Build policy settings and automatic-suspension consequence preview; verify keyboard/form feedback and explicit confirmation without instant toggle side effects.
- [x] 10.4 Extend the Company contract view for its own retail terms and invoices; verify direct-owner boundaries and existing unlinked contract presentation.
- [x] 10.5 Apply Account/session/capability invalidation across all commercial reads/forms; verify out-of-order responses, switching during save, duplicate submission and unknown-outcome recovery.

## 11. Integration, concurrency and visual acceptance

- [x] 11.1 Run independent PostgreSQL/Redis processes for last-slot allocation, owner acceptance/CNPJ uniqueness, allowance removal versus invitation acceptance, invoice period/number issuance, adjustment/receipt, receipt allocation/reversal and cancellation/generation races; verify rejected late acceptance has no Company/membership/link effects and all other recorded invariants with no sequential simulation substituted.
- [x] 11.2 Run focused backend suites and `vendor/bin/pint --dirty --format agent`, then the full host-layout regular PHPUnit suite with `FISCAL_ENVIRONMENT=producao` and the isolated PostgreSQL suite; record failures/skips and verify existing workflows remain covered.
- [x] 11.3 Run focused frontend tests plus `pnpm --dir frontend lint`, `typecheck`, `test` and `build` in the verified runtime; verify real SFC compilation and no unvalidated direct component usage.
- [x] 11.4 Run browser/axe desktop `1366x768`, mobile `390x844` and `320px` reflow in light/dark against `.dashboard/app`; verify navigation, tables, keyboard, focus, labels, overlays, print and loading/error/empty states with recorded evidence.
- [x] 11.5 Exercise the integrated ordinary-office-admin sale -> owner acceptance -> activation -> recurring invoice -> partial/full receipt -> reversal -> delinquency -> rights-loss maintenance flow; verify no fake payment/provider/fiscal success and no cross-Account data.
- [x] 11.6 Rehearse additive migration and compatible-version rollback with preserved history in the isolated environment; verify no development-data reset, key rotation or production gate changes.

## 12. Delivery records and review

- [x] 12.1 Update the scenario crosswalk, architecture decisions/reconciliation and README with actual delivered routes and the bounded D009 maintenance exception; verify each completion claim links to current evidence.
- [x] 12.2 Obtain focused independent review of tenancy, money, onboarding, concurrency and template/component compatibility; verify findings are resolved before marking implementation accepted.
- [x] 12.3 Reconcile the five delta capabilities with their current canonical contracts after acceptance and run strict OpenSpec validation; keep provider/production gates distinct and verify no archive is claimed to enable them.
