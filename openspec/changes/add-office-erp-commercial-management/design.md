## Context

See [proposal.md](proposal.md) for motivation and the five [delta specifications](specs/) for behavior. The source baseline is `f8efb50`. Current office plans expose three strict limits; ERP plans are a separate global technical catalog, and ERP subscriptions contain only Company, technical plan and status. Their current admin pages provide plan listing and pending-contract activation, with no seller tenancy or recurring billing.

The existing `ResolveAccount` binds session + `X-Account-Id` and denies inactive-subscription mutations; `ResolveCompany` binds Company membership/delegation + `X-Company-Id`. `ErpCommercialGate` resolves Company operating eligibility separately from office membership. `BelongsToAccount` mutation hooks require a request `CurrentAccount`, so background billing cannot simply call those hooks with only a tenant ID. Existing ERP commercial audit rows require `super_admin_user_id` and are unsuitable for representing ordinary seller administrators as platform actors.

The required visual reference is `/home/obsidian/dev/opmoni/.dashboard/app`. The independent specialist analysis completed before creation and is recorded in [ui-reference-audit.md](ui-reference-audit.md); exact selected component APIs and version differences are recorded in [component-matrix.md](component-matrix.md). Both template and installed frontend resolve Nuxt UI 4.11.3.

## Goals / Non-Goals

**Goals:**

- Provide one office commercial boundary for direct sales through the platform's own office and resale through other offices, with identical membership/capacity rules.
- Keep retail pricing, customer invoices and seller receipts Account-scoped while retaining Company ownership, its one current ERP subscription and explicit accounting delegation.
- Preserve accepted business after selling rights are removed, with durable and attributable monetary effects.
- Make invoices and manual receipts independent of a future payment-provider adapter.

**Non-Goals:**

- No new company finance ledger, dummy Company for the seller, payment gateway, bank transaction, Pix, boleto, automatic refund, tax issuance or messaging-provider rollout.
- No automatic adoption of legacy direct subscriptions, global permission grant, hard-coded office ID 1 or general inactive-office write bypass.
- No redesign of the shared dashboard, copied demo handlers, new visual framework or new PDF dependency.

## Decisions

### 1. Place commercial management in the Account context

Use `frontend/app/pages/gestao-erp.vue` under the existing `default` layout, with one DashboardPanel/Navbar/Toolbar and child NuxtPage. `/erp/*` remains the Company workspace. Office API requests use `/api/v1/erp-commercial/*` and Account assertions, never Company selection. Owner setup endpoints use a separately guarded `/api/v1/erp-invitations/*` boundary.

An alternative was to invoice through a seller-owned Company ERP and reuse its operational receivables. That would require every office to create and operate a Company before selling, mix vendor debt with Company operations, and couple resale to a different contract. A separate Account billing domain is simpler and reusable without weakening either tenancy boundary. Reuse pure exact-money helpers and UI patterns, not Company-owned finance rows.

### 2. Separate capability to sell from capability to maintain

Add an independently validated `Plan.erp_commercial` object: `enabled` boolean, `licence_limit` nonnegative integer or explicit unlimited null, and a distinct list of known `module_keys`. Missing legacy configuration means disabled, not unlimited. Existing `limits.users/clients/monitorings` semantics and response envelopes stay unchanged. `/admin/planos` edits this object alongside its existing limits.

Expose a typed context response containing `can_read`, `can_maintain`, `can_configure`, `can_sell_new`, reason codes, allocated/reserved counts and the current allowance. Read/maintenance requires a real admin membership in an active Account; new sales additionally require an active office subscription, enabled resale, available capacity and allowed resources. Operators/viewers receive no commercial administration by default. Super Admin is irrelevant to tenant admission.

Loss of resale eligibility or an inactive office subscription preserves accepted contracts and their maintenance, as confirmed by the user. Implement a dedicated commercial admission policy that reuses Account/session/membership checks but explicitly permits only maintenance routes for those contracts. Do not loosen `ResolveAccount` for other business routes or trust a route name/client boolean without checking its target contract and requested change. Account suspension still blocks office actions and new job effects.

Maintenance includes existing-contract reads, unchanged-grant renewal, price-only next-cycle amendment, invoice issuance/correction, receipt/reversal, suspension/resumption, cancellation and policy configuration. New contracts, ownership invitations, adoption of unlinked subscriptions, extra licences and module/capacity expansion are new sales. Creating/publishing new offers requires `can_sell_new`; an offer can always be archived by an authorized maintainer. Expired/unaccepted invitations are not grandfathered sales.

### 3. Keep retail offers separate from the legacy technical catalog

Store each office's retail plan and immutable revisions separately from existing `erp_plans`. A retail revision records name/description, BRL price, interval months in `{1,3,6,12}`, due-date offset, module grants and publication state. Offices freely choose their commercial prices and labels; published grants are bounded by the platform allowance at admission.

An alternative was to add seller fields and prices directly to the global technical catalog. That would change global slug/catalog assumptions and make an office's price edit affect Company access plumbing. Separate retail records preserve legacy behavior and make contract snapshots explicit. A linked Company subscription obtains its agreed grants from the accepted retail-contract revision; an unlinked subscription continues using its existing technical plan. No second technical catalog screen is introduced for ordinary users.

### 4. Use additive Account-owned storage and one current Company licence

| Record | Responsibility and constraints |
|---|---|
| `office_erp_sales_plans` and revisions | Account-owned offer, archive state and immutable published commercial versions |
| `office_erp_contracts` and revisions | Seller Account, portfolio Client, Company after acceptance, agreement lifecycle, immutable effective terms and one scheduled amendment |
| `office_erp_owner_invitations` | Intended email/owner, contract, token hash, seven-day expiry, consumption/revocation and associated reservation |
| `office_erp_licence_allocations` | Reserved/occupied/released capacity; one current seller allocation per Company and one allocation per contract |
| `office_erp_invoices` | Number, service period, document state, exact totals, party/item snapshots and operation identity |
| `office_erp_invoice_adjustments` | Immutable debit/credit direction, positive exact magnitude, original invoice, actor, reason, posting time and unique operation identity |
| `office_erp_receipts` and allocations | Manual receipt facts and invoice applications; reversal records preserve originals |
| `office_erp_commercial_settings` | Current office billing timezone and manual/automatic delinquency mode/grace |
| `office_erp_commercial_operations` and audit events | Account/actor/operation/payload identity, recovery outcome and append-only attributable changes |

Invoice items can be an immutable validated JSON snapshot on the invoice; a second mutable line ledger is unnecessary. Use exact `NUMERIC(20,2)` money columns and canonical two-place decimal strings at API boundaries. Do not reuse float conversions from a component or silently round excess precision. No new arithmetic package is needed.

Add nullable `office_erp_contract_id` to the existing Company subscription. Keep its unique Company identity. Enforce that linked contract Company equals subscription Company and that invoice/receipt/allocation related records share the same Account using scoped queries and composite database constraints. Referenced plans, contracts and Clients cannot be hard-deleted through this feature; use archive/cancel/reversal. Do not migrate existing subscriptions into an office automatically.

Agreement states are `draft`, `awaiting_owner`, `accepted`, `effective`, `canceled`. Licence states remain on the Company subscription, extended with `suspended` for a manual hold; `past_due` means an effective automatic delinquency hold, not merely a late invoice. Persist manual and automatic hold causes separately. Invoice document state and settlement/overdue state are independent, as specified. Avoid mirrored mutable active flags on every record.

### 5. Onboard the actual owner before provisioning access

The office selects an active portfolio Client, a published retail revision, owner identity details and explicit start/due terms. It reviews the proposed contract before issuing an invitation. Issuance reserves capacity under the same admission lock as activation. Drafts reserve nothing; expiry/revocation releases the reservation.

Use the existing activation security conventions (cryptographic token, hash at rest, seven-day expiry, rate/origin/CSRF protection), but a dedicated Company-owner invitation record. Do not call `AccessAccounts::issue` to create an Account or use the office creator as Company owner. Resolve an existing global email through authenticated login; only the intended new owner establishes new credentials.

The acceptance page is `/erp/ativar`, a setup page independent of selected Account/Company. Keep the raw token in a URL fragment only long enough to exchange it for a short-lived, session-bound server continuation; redact token-bearing request data and use no-referrer/no third-party analytics. Only the allowlisted invitation continuation survives login, not an arbitrary return URL. Revalidate intended identity, expiry, new-sale entitlement, capacity and CNPJ ownership at consumption.

Acceptance atomically binds/provisions the Company, owner membership and accepted contract; its reservation becomes an occupied allocation. A new Company receives its single pending subscription through the current Company initializer. For an existing direct subscription, acceptance stages the owner-confirmed adoption without changing its current status, plan or access; linking occurs only at explicit activation. An existing Company requires its authenticated direct owner; any conflicting current seller contract returns a conflict rather than silently transferring it. Record commercial acceptance separately from any later accounting delegation. Reissued/expired/consumed links remain attributable; retry after a lost response recovers the original accepted outcome.

### 6. Version contract terms and compute access from actual causes

Activation of an accepted contract commits the effective agreement, first recurring invoice and Company subscription together. The first period starts on the activation date in the office billing timezone; no backdated or future activation is implicit. Later cycles are generated by the billing scheduler. An accepted, already allocated sale can complete activation through maintenance after resale eligibility is removed; an unaccepted invitation cannot. Contract revisions keep price, cycle, due offset, grants and service-period snapshots; editing an offer has no retrospective effect.

Plan/price changes take effect at the next cycle by default. An immediate commercial correction is a separately reasoned invoice adjustment; there is no implicit retrospective proration. Cancellation can be immediate or scheduled at period end, stops periods starting from its effective date and releases capacity only when effective. Suspended licences retain allocation and recurring billing until the contract is canceled. Recreating a canceled contract is a new sale, while resuming an existing effective contract is maintenance.

For linked subscriptions, reconcile precedence as: canceled agreement -> canceled; awaiting owner/activation -> pending; manual hold -> suspended; qualifying automatic debt hold -> past_due; otherwise -> active. Company/Account security suspension stays in its existing authorization boundary and is never rewritten by a receipt. Clearing automatic debt never clears a manual hold. Unlinked legacy subscriptions retain their current activation/status behavior.

Loss of resale rights does not intersect away already accepted module grants or re-check current capacity to revoke an allocation. Use the contract's agreed snapshot for its continuing Company rights. All additions to grants/capacity re-enter new-sale admission; price-only amendments and ordinary renewal retain existing rights.

### 7. Generate exact, idempotent recurring invoices

Use BRL, advance invoicing and original-anchor calendar periods, represented as inclusive start/exclusive end dates. A date at the end of a short month is clamped only for that occurrence; derive every subsequent boundary from the original anchor, not from the clamped result. Due date is period start plus the contract's explicit nonnegative `due_in_days`; the form shows this value before confirmation. Office billing timezone defaults to `America/Sao_Paulo` and is stored with the contract schedule.

The recurring invoice identity is `(account_id, contract_id, period_start, period_end, recurring)`. Keep a database uniqueness constraint for recurring rows, including canceled invoices. Adjustments have their own operation identity and never impersonate another recurring period. Use a transactional per-office/year counter for display numbers such as `ERP-2026-000001`; do not derive the next number from an unlocked maximum.

Invoice items are fixed described BRL amounts with explicit initial discounts; the original total cannot be negative. Issue snapshots once, including seller, Client, Company, terms, dates and items. Do not rewrite these after a plan edit or add another invoice when an operator merely retries issuance. A zero-total invoice is settled by its zero balance and generates no synthetic receipt.

Corrections to an issued invoice are immutable debit or credit adjustments with a positive exact BRL magnitude, direction, original invoice, reason, actor, server posting time and operation key. Define `adjusted_charge = original_total + debits - credits` and `outstanding = adjusted_charge - effective_receipt_allocations`. Under the invoice lock, require `adjusted_charge >= effective_receipt_allocations >= 0`; reject excess credit without mutation and require a valid receipt reversal first if necessary. No adjustment creates a negative balance, unallocated credit or bank refund. Corrections to an adjustment use a new opposite-direction record referencing it and satisfying the same invariant, never editing the original. Draft/canceled invoices cannot receive adjustments; canceling an issued invoice excludes its original charge and adjustments from collectible totals while preserving all records.

Adjustments inherit the invoice's currency, service period and due date; they neither create another recurring period nor move the schedule. A debit can reopen a settled invoice; a credit can settle it without a synthetic receipt. Both update authoritative balance/reporting and use the same delinquency reconciliation as receipts, preserving unrelated holds. The printable invoice shows its frozen original amount, adjustment history, adjusted charge, effective receipts and current balance. Invoiced-amount period totals include original issuance on its issue date and adjustments on their server posting date in the office timezone, excluding canceled invoices. Receipt period totals retain the received-date basis; outstanding/overdue figures show current balances with explicit due-date filters, not a claimed historical as-of reconstruction.

The scheduler processes only `effective` contracts with an initialized service anchor through an explicit system commercial context, including suspended licences whose agreements remain effective, even if new-sale eligibility is gone. It never invoices or initializes/advances the schedule of a draft, awaiting-owner or merely accepted contract; explicit activation creates the first invoice and anchor atomically. It acquires an Account/contract lock, rechecks Account security and cancellation/amendment boundaries, creates each due period once and advances its cursor in the same transaction. Catch-up is bounded by batch size, exposes pending work/failures and retries the same period after rollback. System admission does not invent a human membership or use `super_admin_user_id` for ordinary actors.

### 8. Record receipts and immutable reversals

One manual receipt can allocate to one or multiple issued invoices in the same Account. Require a positive exact amount, received date, allocations whose sum equals the amount and optional external reference. Do not introduce an unallocated-credit wallet. Lock invoice balances in identifier order; reject stale over-allocation with a conflict and return current balances. A receipt and all its allocations commit together.

Use a distinct immutable reversal referring to the original receipt, with actor/date/reason and a uniqueness constraint preventing a second effective reversal. Restore its allocations once, recalculate affected invoice balances and enqueue/reconcile licence debt state transactionally. This records a correction; it makes no bank refund claim. A cancellation of an invoice with receipts first requires explicit reversal, preserving both histories.

Optional proof upload uses UFileUpload and a private temporary-upload handle bound to Account/actor. Accept PDF, PNG or JPEG up to 5 MiB, validate actual MIME/size server-side and attach only a verified private object during receipt commit. Do not use a public URL or the client Company's storage context. Failed commits leave an expiring private temporary object, not an apparently attached missing proof or duplicate receipt.

Document state is draft/issued/canceled. Settlement is computed from effective allocations as unpaid/partial/settled; overdue is a separate due-date/positive-balance condition. Dashboard totals query this same effective state. Money APIs and Zod/input state use canonical strings; the display adapter accepts unambiguous Brazilian decimal input without JavaScript-number conversion.

### 9. Reconcile configurable delinquency without erasing other holds

The initial office policy is manual, with zero grace unless explicitly configured. Automatic mode evaluates an issued positive balance only when local today is later than `due_on + grace_days`; due day and grace end remain usable through their end. A current office policy change takes effect on subsequent reconciliations and does not edit invoice snapshots. Saving automatic mode previews affected contracts and requires explicit confirmation before evaluation.

Store and evaluate manual and delinquency hold causes independently. Effective access precedence is defined in decision 6. Payment, reversal, invoice adjustment/cancellation and policy changes schedule the same idempotent reconciliation. A fully settled balance clears only delinquency; a reversed receipt can restore it. No receipt reopens a canceled contract or overrides manual, Company or Account security restrictions. Suspension denies ordinary Company operating writes, while authorized contract/data reads retain the established inactive-contract behavior.

### 10. Use explicit operations, scoped APIs and stable recovery

| Boundary | Planned API surface |
|---|---|
| Office commercial context | `GET /api/v1/erp-commercial/context`, `summary`, `settings`; explicit settings update |
| Offers and clients | `plans` list/create/detail/revision/archive; `clients` server-side search and onboarding preview |
| Contracts | `contracts` draft/detail/list; explicit invitation, activate, amendment, suspend/resume and cancel actions |
| Billing | `invoices` list/draft/detail/issue/cancel/adjustment; `receipts` list/create/detail/reverse; private proof and printable-document reads |
| Recovery | `operations/{key}` outcome lookup bound to Account, actor, operation and canonical payload |
| Owner setup | Bounded invitation resolve/accept endpoints independent of tenant selection |
| Company consultation | Extend guarded `/api/v1/erp/subscription` and Company invoice detail/list reads to the direct owner of the linked contract |

All office calls use the normal private Nuxt `/api/v1` proxy, CSRF for writes, Account assertion and no-store responses. JSON resources use `data`; lists include authoritative `meta.current_page/last_page/per_page/total`. Validation is 422, foreign resources 404, stale context and monetary/licence conflicts 409, missing permission 403. Related payload IDs never select tenant context. Company-owner invoice reads select through the already authorized Company's current contract, not through arbitrary office identifiers.

Persist an operation key and canonical payload before effective activation, invoice issuance/adjustment, receipt, reversal, cancellation and other irreversible commands. Equal repeats return the original outcome; unequal repeats conflict. An outcome lookup is authorized as that operation, not admitted as a generic safe GET. Read and mutation handlers revalidate session ownership and authorization before effects; stale frontend responses are discarded after any Account/session/capability change.

Use a consistent lock order: Account, current office subscription/allowance, Company, contract/allocation, invoices by ID, then receipt/adjustment/operation rows. Platform allowance changes must serialize with admission using the plan-row/version lock; they must not acquire an Account lock after holding another transaction's conflicting plan lock. Prove allowance-revocation, last-slot, owner acceptance, cancellation/generation, adjustment/receipt and receipt/reversal races using independent PostgreSQL processes. Database uniqueness remains the final guard.

For background writes, use an explicit verified commercial execution context/repository within `CurrentTenant::run`, restoring context in finally. Do not broadly disable Account scopes, fabricate a request membership or extend every existing tenant trait as a side effect of billing. Actor type `system` and the originating contract/job are recorded separately from human actions.

### 11. Compose every page from the audited dashboard patterns

| Route | Composition |
|---|---|
| `/gestao-erp` | Real summary metrics using the template HomeStats PageGrid/PageCard proportions and existing page-scroll classes |
| `/gestao-erp/planos` | DataTablePanelList, counted server pagination and existing plan-edit Slideover pattern |
| `/gestao-erp/clientes` and `/clientes/novo` below that prefix | Portfolio search/list and a page-form onboarding review; no new wizard component |
| `/gestao-erp/assinaturas` and `/assinaturas/[id]` | Contract list and existing record/detail classes, explicit terms/actions/history |
| `/gestao-erp/faturas` and `/faturas/[id]` | Issued values, balances, receipt details and short receipt/reversal Modal |
| `/gestao-erp/recebimentos` | Office receipts/reversal history with server filters and links to originating invoices |
| `/gestao-erp/configuracoes` | Existing settings-form pattern with explicit policy save and consequence preview |
| `/gestao-erp/faturas/[id]/imprimir` | Protected print-friendly document with same-origin styles and browser Print / Save as PDF |
| `/erp/ativar`, `/erp/contrato` | Guarded owner setup and Company-only commercial consultation, preserving existing context distinctions |

Add the commercial group to office navigation, search and `sidebarNav` ownership resolution, and admit the new office prefix in `access.global.ts`. Use backend capabilities for every action; do not put `can_sell_new` as a blanket parent guard. Close editors and invalidate requests on context changes. Reuse `useRetryableLoad`, `createLatestRequestRunner`, exact display helpers, ErrorRetryAlert and the existing table/empty/action wrappers.

Use only APIs listed in the component matrix. In particular, money uses UInput text/inputmode=decimal and integer days use UInputNumber; current online FileUpload extras are not assumed to exist in 4.11.3. Keep loading/failure/empty states separate. Overlay forms have labeled fields, real server errors, reachable footer controls and focus return. Routes with shareable destinations use NavigationMenu links, not local tabs with hidden routing state.

Keep old backend `/admin/erp/*` APIs privileged for unlinked legacy administration, with no ordinary-office access. Remove old commercial menu links. Old frontend Super Admin bookmarks redirect only with a currently authorized office; otherwise render an office-context explanation without entering another tenant. All new commercial work, including the platform's own selling office, uses `/gestao-erp`.

Print via an authorized HTML document and standard browser printing/saving as PDF, avoiding a new PDF engine. The document is an internal commercial invoice, not a fiscal artifact. Do not add payment QR codes, bank lines, provider success badges or claims of bank confirmation.

## Risks / Trade-offs

- **Resale removal could block customers or history** -> Separate new-sale admission from maintenance and preserve accepted snapshots/recurring generation, including the narrowly scoped inactive-office maintenance exception.
- **Receipt could affect another office or the client's finance** -> Account-owned storage, composite related constraints, exact operation admission and no use of Company finance rows.
- **Existing contract and licence states could diverge** -> One Company subscription and an explicit cause-based reconciliation path; independent invoice/settlement states.
- **Concurrent issuance/receipts could duplicate money or capacity** -> Durable operation identities, locked balances, period/number/allocation uniqueness and independent-process concurrency tests.
- **Invitation could grant ownership through an email/CNPJ guess** -> Intended identity authentication, current owner confirmation, atomic acceptance and no automatic delegation.
- **Template demos could appear functional** -> Reuse visual composition only; forbid copied random metrics, placeholder waits and fabricated success/zero states.
- **Online docs can drift beyond installed UI** -> Pin evidence to installed declarations and revalidate every newly selected component before use.
- **Large scope can conceal incomplete delivery** -> Ordered capability slices, a full requirement/scenario crosswalk and final integrated financial/ownership/browser acceptance; no task completion from mocks alone.

## Migration Plan

1. Reinspect branch/runtime state and the current OpenSpec baseline before implementation, preserving the main checkout's existing archive/documentation edits. Reconcile this change with any newly integrated canonical specs rather than overwriting them.
2. Add office-plan resale configuration with disabled defaults, Account-owned commercial tables, restricted references, unique identities and the nullable Company-contract link. Preserve all existing records, APP_KEY and environment files. Extend ERP subscription status validation only where required for manual suspension; do not silently normalize unknown historical states.
3. Ship backend admission, operations, onboarding and billing with their tests; ordinary office plans remain ineligible until explicitly configured through `/admin/planos`. No seed creates a demo office, price, Company, invoice or licence, and no special ID receives eligibility.
4. Add the audited UI and guarded legacy bookmarks. Keep public ERP registration, operating, fiscal-production and SERPRO gates as configured. A commercial licence does not override those global rollout gates.
5. Run the isolated PostgreSQL/Redis suites and frontend/backend checks in the project layout, then compare actual pages to `.dashboard/app` in desktop/mobile and both themes. Validate owner acceptance, invoices, partial receipt/reversal, policy changes, right removal and tenant switching end to end.
6. Apply additive schema changes with `php artisan migrate --force --no-interaction`; restart task-scoped workers only after the matching code/schema is present. Do not use migrate:fresh, rotate keys or delete development records. Roll back by deploying a compatible application version; preserve new tables/history and keep new-sale admission disabled until the matching commercial code is restored.
7. On actual acceptance, reconcile the five canonical capabilities and document the bounded D009 maintenance exception. Archiving planning artifacts does not establish production fiscal, provider or delivery acceptance.

## Verification Strategy

Tests must cover the spec scenarios and actual SFCs, with source/reference comparison plus desktop `1366x768`, mobile `390x844`, `320px` reflow and light/dark/keyboard/axe checks. Exact money cases include zero, cents, Brazilian input, rejected excess precision and values above JavaScript's safe integer range. Freeze time for recurrence/grace tests, including month-end, leap-year and timezone boundaries.

Run focused backend feature tests and Pint after PHP changes; focused frontend tests, lint and typecheck after UI changes; then the integrated checks documented in README. Run the full regular PHPUnit suite from the host repository layout with `FISCAL_ENVIRONMENT=producao`; run PostgreSQL concurrency against isolated `opmoni_test` and Redis 13-15 through a verified task runtime. Do not run generic Compose commands against the development stack or treat missing live-provider probes as acceptance.

Planning-time evidence is limited to code/reference inspection and official/local component compatibility. No application changes, financial mutations, provider calls, browser acceptance, SFC checks or application suites were performed while creating this change.
