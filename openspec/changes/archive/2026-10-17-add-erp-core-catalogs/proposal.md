## Why

The ERP needs company-owned commercial contacts and catalogs that are distinct from the accounting office client portfolio. Legacy product and fiscal configuration must be usable by the new JSON APIs and Nuxt forms.

## What Changes

- Add company-scoped customers/suppliers, product/service catalogs, variants, units, categories, brands and selling prices.
- Add establishment-level fiscal setup and the carrier/vehicle/driver/reference data required by the supported transport documents.
- Expose validated JSON APIs and corresponding Nuxt management screens with search, pagination, import feedback and safe archival.
- Keep office Clients and company commercial Contacts independent; do not automatically associate matching tax identifiers.

## Capabilities

### New Capabilities

- `erp-core-catalogs`: Company contacts, products/services, variants, units, prices, fiscal/logistics configuration and reference validation.

### Modified Capabilities

None. Existing capabilities are dependencies, not duplicated deltas.

## Impact

ERP catalog tables/models/policies, typed API resources and Nuxt forms. Source table/field mappings and SQL are translated for PostgreSQL; operational dependencies consume these IDs.

**Dependencies:** [add-erp-company-foundation](../2026-10-17-add-erp-company-foundation/proposal.md)

**Reference baseline:** [opmonihub/sistema at e7b77be](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25); read the [foundation design](../2026-10-17-add-erp-company-foundation/design.md) for shared contracts, capability ownership and the source scope map.

Approved scope: one existing Nuxt frontend, one Laravel 13 backend and one PostgreSQL database in the Opmoni repository; a fresh ERP domain with additive migrations; the complete commercial/fiscal core before public release. Specialized CRM, HR, manufacturing, repair, restaurant and e-commerce features, NFS-e issuance, old production data migration and new automated SaaS checkout are excluded. Existing office access, business features, canonical specs and unrelated dirty work remain preserved.

The 2026-10-17 user decisions extend D009 with company-tenanted ERP and explicit owner-authorized accounting delegation; they do not create a platform-role tenant bypass or restore superseded foreign support. D008 office-first login is preserved, with a company-only destination added by the foundation. Earlier conversational alternatives involving separate frontends, Laravel 8 services, databases or SSO are superseded for this suite. No application behavior is delivered by writing these artifacts.

No unresolved product decision prevents preparation of this proposal. Runtime/package/provider feasibility and live acceptance are implementation verification tasks, never assumed completed.
