## Why

The core ERP must support complete quote/order/sale/return flows connected to real stock and finance. A copied sales screen without atomic posting and company permissions would not replace the source workflow.

## What Changes

- Add quotes, orders, sales drafts, completed sales and linked returns with stable operation keys.
- Implement company-scoped pricing/discount/tax snapshots and server-calculated totals.
- Post stock consumption, obligations and cash/payment effects atomically; expose explicit correction and return workflows.
- Provide the commercial/counter-sale Nuxt workflows and hand off approved sale snapshots to fiscal issuance.

## Capabilities

### New Capabilities

- `erp-sales`: Quotes, orders, sale posting, returns, price/tax snapshots and transactional stock/finance integration.

### Modified Capabilities

None. Existing capabilities are dependencies, not duplicated deltas.

## Impact

ERP sales state machine, migrations, validated APIs and Nuxt screens; consumes catalog, stock and finance contracts. Restaurant/POS hospitality features and e-commerce checkout are excluded.

**Dependencies:** [add-erp-core-catalogs](../2026-10-17-add-erp-core-catalogs/proposal.md), [add-erp-finance-and-cash](../2026-10-17-add-erp-finance-and-cash/proposal.md), [add-erp-purchasing-and-stock](../2026-10-17-add-erp-purchasing-and-stock/proposal.md)

**Reference baseline:** [opmonihub/sistema at e7b77be](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25); read the [foundation design](../2026-10-17-add-erp-company-foundation/design.md) for shared contracts, capability ownership and the source scope map.

Approved scope: one existing Nuxt frontend, one Laravel 13 backend and one PostgreSQL database in the Opmoni repository; a fresh ERP domain with additive migrations; the complete commercial/fiscal core before public release. Specialized CRM, HR, manufacturing, repair, restaurant and e-commerce features, NFS-e issuance, old production data migration and new automated SaaS checkout are excluded. Existing office access, business features, canonical specs and unrelated dirty work remain preserved.

The 2026-10-17 user decisions extend D009 with company-tenanted ERP and explicit owner-authorized accounting delegation; they do not create a platform-role tenant bypass or restore superseded foreign support. D008 office-first login is preserved, with a company-only destination added by the foundation. Earlier conversational alternatives involving separate frontends, Laravel 8 services, databases or SSO are superseded for this suite. No application behavior is delivered by writing these artifacts.

No unresolved product decision prevents preparation of this proposal. Runtime/package/provider feasibility and live acceptance are implementation verification tasks, never assumed completed.
