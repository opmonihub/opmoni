## Why

An authorized accounting office must receive complete ERP-issued documents without downloading the same notes again or acquiring unrestricted company access. Delivery must preserve the complete-XML integrity and provenance contract of the existing accounting workflow.

## What Changes

- Deliver verified complete authorized ERP XMLs and correlated events to the company-authorized office Client through a durable audited handoff.
- Validate explicit Company/Account/Client/establishment mapping; never associate or expose data solely because tax identifiers match.
- Reuse complete-document integrity/readiness, immutable artifacts and source-aware idempotency with revocation-safe delivery.
- Expose handoff status/retry/reconciliation and office views without auto-manifesting self-issued documents.

## Capabilities

### New Capabilities

- `erp-accounting-documents`: Revocable company-to-office delivery, explicit Client mapping, durable publication/reconciliation and history.

### Modified Capabilities

- `fiscal-documents`: Add ERP-issued-origin publication rules without changing capture readiness or automatic recipient-event eligibility.

## Impact

ERP handoff/outbox and Account projection migrations/services, authorized office Fiscal reads and company delivery pages. Requires complete-xml-accounting-workflow implementation contracts before apply; no edits to its current planning artifacts.

**Dependencies:** [add-erp-company-foundation](../2026-10-17-add-erp-company-foundation/proposal.md), [add-erp-fiscal-issuance](../2026-10-17-add-erp-fiscal-issuance/proposal.md); [complete-xml-accounting-workflow](../2026-10-17-complete-xml-accounting-workflow/proposal.md).

**Reference baseline:** [opmonihub/sistema at e7b77be](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25); read the [foundation design](../2026-10-17-add-erp-company-foundation/design.md) for shared contracts, capability ownership and the source scope map.

Approved scope: one existing Nuxt frontend, one Laravel 13 backend and one PostgreSQL database in the Opmoni repository; a fresh ERP domain with additive migrations; the complete commercial/fiscal core before public release. Specialized CRM, HR, manufacturing, repair, restaurant and e-commerce features, NFS-e issuance, old production data migration and new automated SaaS checkout are excluded. Existing office access, business features, canonical specs and unrelated dirty work remain preserved.

The 2026-10-17 user decisions extend D009 with company-tenanted ERP and explicit owner-authorized accounting delegation; they do not create a platform-role tenant bypass or restore superseded foreign support. D008 office-first login is preserved, with a company-only destination added by the foundation. Earlier conversational alternatives involving separate frontends, Laravel 8 services, databases or SSO are superseded for this suite. No application behavior is delivered by writing these artifacts.

No unresolved product decision prevents preparation of this proposal. Runtime/package/provider feasibility and live acceptance are implementation verification tasks, never assumed completed.
