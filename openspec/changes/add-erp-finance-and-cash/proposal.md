## Why

Purchases and sales require reliable receivables/payables, payment allocation and cash effects. Legacy financial Account models collide with the office Account name and must be adapted without mixing tenancy.

## What Changes

- Add company financial accounts, payable/receivable records, expenses, revenue and auditable payment allocation/reversal.
- Add cash-register opening, movements, withdrawals/deposits, closing and reconciliation.
- Define reusable transaction participants for purchase/sale posting and reversal without network calls inside database locks.
- Provide protected financial and cashier screens, histories and document outputs present in the core.

## Capabilities

### New Capabilities

- `erp-finance-and-cash`: Financial accounts, receivables/payables, payment allocations, expenses/revenue, bank/cash movements and reconciliation.

### Modified Capabilities

None. Existing capabilities are dependencies, not duplicated deltas.

## Impact

ERP financial migrations/services and operating permission contracts, JSON APIs, Nuxt forms and reports inputs. Reuse global actor IDs; never replace the office Account model or add SaaS billing providers.

**Dependencies:** [add-erp-commercial-access](../add-erp-commercial-access/proposal.md), [add-erp-core-catalogs](../add-erp-core-catalogs/proposal.md)

**Reference baseline:** [opmonihub/sistema at e7b77be](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25); read the [foundation design](../add-erp-company-foundation/design.md) for shared contracts, capability ownership and the source scope map.

Approved scope: one existing Nuxt frontend, one Laravel 13 backend and one PostgreSQL database in the Opmoni repository; a fresh ERP domain with additive migrations; the complete commercial/fiscal core before public release. Specialized CRM, HR, manufacturing, repair, restaurant and e-commerce features, NFS-e issuance, old production data migration and new automated SaaS checkout are excluded. Existing office access, business features, canonical specs and unrelated dirty work remain preserved.

The 2026-10-17 user decisions extend D009 with company-tenanted ERP and explicit owner-authorized accounting delegation; they do not create a platform-role tenant bypass or restore superseded foreign support. D008 office-first login is preserved, with a company-only destination added by the foundation. Earlier conversational alternatives involving separate frontends, Laravel 8 services, databases or SSO are superseded for this suite. No application behavior is delivered by writing these artifacts.

No unresolved product decision prevents preparation of this proposal. Runtime/package/provider feasibility and live acceptance are implementation verification tasks, never assumed completed.
