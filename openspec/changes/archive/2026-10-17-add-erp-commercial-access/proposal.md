## Why

Companies must be able to contract the ERP without an accounting-office Account. Office subscription status and limits cannot be reused as company entitlements.

## What Changes

- Provide separate company registration after platform initialization, with preservation of existing global identities.
- Add company-specific plans, subscriptions and enabled-core-module entitlements, independent of office commercial records.
- Provide owner-visible contract status and platform management inside the same Nuxt application.
- Keep commercial activation separate from payment-provider integration and from final public release approval.

## Capabilities

### New Capabilities

- `erp-commercial-access`: Direct company registration, company plans/subscriptions, module entitlements and platform administration.

### Modified Capabilities

None. Existing capabilities are dependencies, not duplicated deltas.

## Impact

Company commercial migrations/actions and ERP owner/platform forms; Company guard integration and transactional registration. No second identity store or automated SaaS payment gateway.

**Dependencies:** [add-erp-company-foundation](../2026-10-17-add-erp-company-foundation/proposal.md)

**Reference baseline:** [opmonihub/sistema at e7b77be](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25); read the [foundation design](../2026-10-17-add-erp-company-foundation/design.md) for shared contracts, capability ownership and the source scope map.

Approved scope: one existing Nuxt frontend, one Laravel 13 backend and one PostgreSQL database in the Opmoni repository; a fresh ERP domain with additive migrations; the complete commercial/fiscal core before public release. Specialized CRM, HR, manufacturing, repair, restaurant and e-commerce features, NFS-e issuance, old production data migration and new automated SaaS checkout are excluded. Existing office access, business features, canonical specs and unrelated dirty work remain preserved.

The 2026-10-17 user decisions extend D009 with company-tenanted ERP and explicit owner-authorized accounting delegation; they do not create a platform-role tenant bypass or restore superseded foreign support. D008 office-first login is preserved, with a company-only destination added by the foundation. Earlier conversational alternatives involving separate frontends, Laravel 8 services, databases or SSO are superseded for this suite. No application behavior is delivered by writing these artifacts.

No unresolved product decision prevents preparation of this proposal. Runtime/package/provider feasibility and live acceptance are implementation verification tasks, never assumed completed.
