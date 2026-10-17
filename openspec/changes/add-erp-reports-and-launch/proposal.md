## Why

The user requires the entire agreed commercial/fiscal core before release. A set of partially implemented screens or passing unit tests cannot establish replacement parity or production readiness.

## What Changes

- Add real company-scoped core reports and exports with consistent filters, decimals and reconciliation.
- Complete the pinned-source route/feature/API/page/test matrix and resolve every included-core gap.
- Verify migration preservation, whole-application regressions, PostgreSQL/Redis races, SSR and desktop/mobile core workflows.
- Keep ERP public registration/operating routes disabled until explicit whole-core release approval; no partial production replacement.

## Capabilities

### New Capabilities

- `erp-reports`: Permission-scoped company reports, reconciled commercial/stock/financial/cash/fiscal totals and protected exports.

### Modified Capabilities

- `application-runtime`: Add the explicit ERP preparation/preview/production release gate and whole-core acceptance requirements.

## Impact

Reporting queries/APIs/screens and explicit release/runtime configuration, protected exports, acceptance records and operational docs. Source complements, NFS-e issuance and automated SaaS checkout remain excluded.

**Dependencies:** [add-erp-company-foundation](../add-erp-company-foundation/proposal.md), [add-erp-commercial-access](../add-erp-commercial-access/proposal.md), [add-erp-core-catalogs](../add-erp-core-catalogs/proposal.md), [add-erp-finance-and-cash](../add-erp-finance-and-cash/proposal.md), [add-erp-purchasing-and-stock](../add-erp-purchasing-and-stock/proposal.md), [add-erp-sales-and-orders](../add-erp-sales-and-orders/proposal.md), [add-erp-fiscal-issuance](../add-erp-fiscal-issuance/proposal.md), [add-erp-accounting-documents](../add-erp-accounting-documents/proposal.md)

**Reference baseline:** [opmonihub/sistema at e7b77be](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25); read the [foundation design](../add-erp-company-foundation/design.md) for shared contracts, capability ownership and the source scope map.

Approved scope: one existing Nuxt frontend, one Laravel 13 backend and one PostgreSQL database in the Opmoni repository; a fresh ERP domain with additive migrations; the complete commercial/fiscal core before public release. Specialized CRM, HR, manufacturing, repair, restaurant and e-commerce features, NFS-e issuance, old production data migration and new automated SaaS checkout are excluded. Existing office access, business features, canonical specs and unrelated dirty work remain preserved.

The 2026-10-17 user decisions extend D009 with company-tenanted ERP and explicit owner-authorized accounting delegation; they do not create a platform-role tenant bypass or restore superseded foreign support. D008 office-first login is preserved, with a company-only destination added by the foundation. Earlier conversational alternatives involving separate frontends, Laravel 8 services, databases or SSO are superseded for this suite. No application behavior is delivered by writing these artifacts.

No unresolved product decision prevents preparation of this proposal. Runtime/package/provider feasibility and live acceptance are implementation verification tasks, never assumed completed.
