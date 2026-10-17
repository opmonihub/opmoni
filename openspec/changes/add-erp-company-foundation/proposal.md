## Why

The approved ERP must serve companies directly and accounting offices through explicit delegation inside the existing application. Reusing the legacy business/user tables as office Accounts would break identity preservation and isolation.

## What Changes

- Introduce a Company tenant independent of Account and office Client, with establishments and memberships referencing existing global users.
- Add explicit, revocable delegation to one active office at a time; ordinary office membership and platform status alone grant no ERP access.
- Reuse one Nuxt application, Laravel 13 application, PostgreSQL database and current session authentication; add company selection and the /erp workspace.
- Freeze shared API/context/persistence contracts, source-to-destination scope and dependency ownership for all nine changes.
- **BREAKING**: Additive company identity fields allow users without an office to enter ERP; preserve all existing office response fields and office-first login behavior.

## Capabilities

### New Capabilities

- `erp-company-foundation`: Company tenants, establishments, global-user memberships, scoped permissions, office delegation, company context, onboarding primitives and the unified ERP shell.

### Modified Capabilities

- `auth`: Add company-safe identity and default-company selection while preserving the office login and bootstrap contracts.
- `access-pages`: Add the ERP workspace and company-only login destination without removing office navigation.
- `isolation`: Explicitly distinguish office scope from company scope and define company membership/delegation checks.
- `application-runtime`: Extend the private transport to company assertions and explicit company background context.

## Impact

Additive company migrations, Company context/policies, safe identity projections, private API ingress and Nuxt layouts/types. Reference source is Laravel 8/MySQL and is adapted, never copied over the access foundation.

**Dependencies:** None.

**Reference baseline:** [opmonihub/sistema at e7b77be](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25); read the [foundation design](../add-erp-company-foundation/design.md) for shared contracts, capability ownership and the source scope map.

Approved scope: one existing Nuxt frontend, one Laravel 13 backend and one PostgreSQL database in the Opmoni repository; a fresh ERP domain with additive migrations; the complete commercial/fiscal core before public release. Specialized CRM, HR, manufacturing, repair, restaurant and e-commerce features, NFS-e issuance, old production data migration and new automated SaaS checkout are excluded. Existing office access, business features, canonical specs and unrelated dirty work remain preserved.

The 2026-10-17 user decisions extend D009 with company-tenanted ERP and explicit owner-authorized accounting delegation; they do not create a platform-role tenant bypass or restore superseded foreign support. D008 office-first login is preserved, with a company-only destination added by the foundation. Earlier conversational alternatives involving separate frontends, Laravel 8 services, databases or SSO are superseded for this suite. No application behavior is delivered by writing these artifacts.

No unresolved product decision prevents preparation of this proposal. Runtime/package/provider feasibility and live acceptance are implementation verification tasks, never assumed completed.
