## Why

Office administrators cannot sell or manage ERP licences through the current Super Admin-only catalog and subscription screens. They need an office-owned commercial workspace with their own offers, client activation, recurring invoices and manual receipts, while preserving the existing Company ownership and accounting-delegation boundaries.

## What Changes

- Introduce `/gestao-erp` in the existing office dashboard, with overview, retail plans, portfolio clients, subscriptions, invoices, receipts and delinquency settings. Ordinary office administrators use this workspace; the office used by the Super Admin follows exactly the same membership and commercial rules.
- Let each office create and archive its own ERP retail offers, prices and billing cycles. `/admin/planos` configures explicit office-plan resale eligibility, licence capacity and permitted ERP modules.
- Provide owner-confirmed Company onboarding, contract activation, scheduled changes, renewal, suspension and cancellation without converting the office into the Company's owner or granting accounting access automatically.
- Generate recurring, numbered internal billing invoices with immutable issued values, reasoned debit/credit adjustments, printable documents, full/partial manual receipts, attributable reversals and authoritative outstanding balances.
- Provide office-configurable manual or automatic delinquency suspension after a grace period. Invoice overdue state, licence state and resale eligibility remain distinct.
- Preserve existing contracts, recurring invoices and their maintenance when an office loses resale eligibility; deny new sales and expansions. This includes a narrowly scoped existing-contract maintenance admission rule, rather than a general inactive-subscription bypass.
- Make `.dashboard/app` the mandatory theme/layout reference and retain existing accessibility/contrast improvements. Record API, example and installed-version evidence for every selected component: 31 core components audited before creation, plus two individually validated components for the existing Company contract card and optional receipt evidence upload.
- Replace the old `/admin/erp/*` commercial navigation with the office workspace. Existing privileged APIs remain compatible for unlinked legacy subscriptions; old frontend bookmarks receive guarded office-aware handling without implicit office entry.

### Scope and exclusions

This is a complete office commercial workflow with internal invoicing and manual settlement. It does not implement payment gateways, bank confirmation, Pix, boleto, automatic collection, commissions, external messaging campaigns, tax-document issuance or a separate white-label application. Fiscal/SERPRO release flags and public ERP registration remain unchanged. No existing company subscription is automatically adopted into a seller's portfolio.

The user confirmed independent office pricing, a common `/gestao-erp` entry for all office administrators, manual payment recording, configurable delinquency suspension and continuity of existing contracts after resale-right loss. BRL, calendar-based recurring cycles, next-cycle contract amendments and manual suspension as the initial policy are explicit design defaults for review; no material business decision is left as an implementation guess.

## Capabilities

### New Capabilities

- `office-erp-commercial-management`: Office-owned offers, client onboarding, contracts, licence capacity, permission-aware navigation and the template-based commercial workspace.
- `office-erp-billing`: Recurring invoices, printable billing documents, exact balances, manual receipts/reversals, reporting and configurable delinquency reconciliation.

### Modified Capabilities

- `platform-commercial-management`: Add explicit ERP resale settings to office plans and a bounded existing-contract maintenance exception while preserving existing plan-limit and membership contracts.
- `erp-commercial-access`: Allow eligible office administrators to activate their own accepted contracts, preserve legacy direct subscriptions and resolve Company access from the actual contract/licence state.
- `access-pages`: Admit the office commercial routes and secure Company-owner invitation flow without Super Admin or pre-existing Company membership requirements.

## Impact

- **Frontend:** Office navigation/search, Account-aware guards, typed capabilities, `/gestao-erp/*`, owner activation, `/erp/contrato` and the office-plan editor. Reuse the existing default layout, page/table shells, Nuxt UI 4.11.3 and Zod; no new UI dependency.
- **Backend:** Account-scoped commercial APIs, additive office billing/contract storage, scoped owner invitations, exact-decimal operations, scheduler/jobs, audit history and the Company commercial gate. Seller receivables remain outside the client's Company finance ledger.
- **Compatibility:** Existing `limits.users/clients/monitorings`, raw platform Plan envelopes, direct Company subscriptions, identities, membership, fiscal history and accounting mappings remain intact. The platform-only activation sentence in `erp-commercial-access` is extended for explicit office contracts. D009's membership foundation remains; its general inactive-office write rule gains only the documented contract-maintenance exception.
- **Delivery:** This change requires delta-spec reconciliation, additive migrations, real independent PostgreSQL/Redis concurrency verification, focused frontend/backend checks and desktop/mobile light/dark browser acceptance. The pre-creation specialist audit and component validation are planning evidence, not implementation or browser acceptance.
- **Planning files:** [Design](design.md), [UI reference audit](ui-reference-audit.md), [component matrix](component-matrix.md) and [tasks](tasks.md). The source checkout's unrelated archive/documentation edits are preserved in place; planning occurs in an isolated worktree based on `f8efb50`.
