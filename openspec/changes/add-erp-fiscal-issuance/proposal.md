## Why

The approved ERP requires real issuance for NF-e, NFC-e, CT-e and MDF-e, not only the existing accounting capture. Legacy fiscal services need current application boundaries, private artifacts and durable handling of uncertain external outcomes.

## What Changes

- Adapt supported NF-e/NFC-e/CT-e/MDF-e generation, signature, transmission, consultation, cancellation/correction and applicable model-specific events.
- Support source core returns, number invalidation and validated contingency workflows without exposing certificates/XML publicly.
- Persist intended external effects, source snapshots, request identity, provider acceptance/rejection and uncertain outcomes before reporting status.
- Provide all issuance/document/event Nuxt screens and a verified authorized-artifact contract for accounting delivery.

## Capabilities

### New Capabilities

- `erp-fiscal-issuance`: Company-owned fiscal configuration, credentials, issuance, consultation, events, contingency and private XML/auxiliary documents.

### Modified Capabilities

None. Existing capabilities are dependencies, not duplicated deltas.

## Impact

NFePHP dependency compatibility review, ERP fiscal migrations, private storage, queued provider operations, JSON/multipart/binary APIs and Nuxt screens. Existing incoming capture/manifestation gates remain independent; no NFS-e issuance.

**Dependencies:** [add-erp-core-catalogs](../add-erp-core-catalogs/proposal.md), [add-erp-purchasing-and-stock](../add-erp-purchasing-and-stock/proposal.md), [add-erp-sales-and-orders](../add-erp-sales-and-orders/proposal.md)

**Reference baseline:** [opmonihub/sistema at e7b77be](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25); read the [foundation design](../add-erp-company-foundation/design.md) for shared contracts, capability ownership and the source scope map.

Approved scope: one existing Nuxt frontend, one Laravel 13 backend and one PostgreSQL database in the Opmoni repository; a fresh ERP domain with additive migrations; the complete commercial/fiscal core before public release. Specialized CRM, HR, manufacturing, repair, restaurant and e-commerce features, NFS-e issuance, old production data migration and new automated SaaS checkout are excluded. Existing office access, business features, canonical specs and unrelated dirty work remain preserved.

The 2026-10-17 user decisions extend D009 with company-tenanted ERP and explicit owner-authorized accounting delegation; they do not create a platform-role tenant bypass or restore superseded foreign support. D008 office-first login is preserved, with a company-only destination added by the foundation. Earlier conversational alternatives involving separate frontends, Laravel 8 services, databases or SSO are superseded for this suite. No application behavior is delivered by writing these artifacts.

No unresolved product decision prevents preparation of this proposal. Runtime/package/provider feasibility and live acceptance are implementation verification tasks, never assumed completed.
