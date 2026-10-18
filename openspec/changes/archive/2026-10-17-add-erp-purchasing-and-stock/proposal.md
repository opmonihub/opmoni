## Why

The ERP must turn purchases, returns and adjustments into consistent company/establishment stock and financial records. Source MySQL inventory allocation and costing cannot be copied without PostgreSQL transaction verification.

## What Changes

- Add purchase drafts, posted entries and supplier returns with immutable item/cost snapshots.
- Add establishment stock balances and a durable ledger for receipts, allocations, adjustments, transfers and reversals.
- Integrate posted purchases with finance in one database transaction and provide internal stock participants for sales.
- Provide complete purchasing, warehouse and stock-history Nuxt screens.

## Capabilities

### New Capabilities

- `erp-purchasing`: Purchase draft/post/return lifecycle with supplier, fiscal-reference and finance integration.
- `erp-inventory`: Company/establishment stock ledger, costing, adjustments, transfers and concurrency-safe movement participants.

### Modified Capabilities

None. Existing capabilities are dependencies, not duplicated deltas.

## Impact

ERP purchase/stock migrations, locking/transaction services, APIs and screens. Dependency contracts are catalog decimal/reference types and finance participants; no standalone database or source-table replacement.

**Dependencies:** [add-erp-core-catalogs](../2026-10-17-add-erp-core-catalogs/proposal.md), [add-erp-finance-and-cash](../2026-10-17-add-erp-finance-and-cash/proposal.md)

**Reference baseline:** [opmonihub/sistema at e7b77be](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25); read the [foundation design](../2026-10-17-add-erp-company-foundation/design.md) for shared contracts, capability ownership and the source scope map.

Approved scope: one existing Nuxt frontend, one Laravel 13 backend and one PostgreSQL database in the Opmoni repository; a fresh ERP domain with additive migrations; the complete commercial/fiscal core before public release. Specialized CRM, HR, manufacturing, repair, restaurant and e-commerce features, NFS-e issuance, old production data migration and new automated SaaS checkout are excluded. Existing office access, business features, canonical specs and unrelated dirty work remain preserved.

The 2026-10-17 user decisions extend D009 with company-tenanted ERP and explicit owner-authorized accounting delegation; they do not create a platform-role tenant bypass or restore superseded foreign support. D008 office-first login is preserved, with a company-only destination added by the foundation. Earlier conversational alternatives involving separate frontends, Laravel 8 services, databases or SSO are superseded for this suite. No application behavior is delivered by writing these artifacts.

No unresolved product decision prevents preparation of this proposal. Runtime/package/provider feasibility and live acceptance are implementation verification tasks, never assumed completed.
