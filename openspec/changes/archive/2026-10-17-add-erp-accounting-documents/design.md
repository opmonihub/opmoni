## Context

See [proposal.md](proposal.md) for the approved motivation and scope. Reference: [pinned source](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25). The current system has Nuxt presentation, Laravel-owned access/data, PostgreSQL and Redis, and existing dirty implementation work outside this planning change.

**Dependencies:** [add-erp-company-foundation](../2026-10-17-add-erp-company-foundation/proposal.md), [add-erp-fiscal-issuance](../2026-10-17-add-erp-fiscal-issuance/proposal.md); complete-xml-accounting-workflow is an additional implementation prerequisite.

This design is required because it changes multiple layers, tenant/data contracts or external-effect/migration behavior. It is a target design, not a statement of delivered behavior.

## Goals / Non-Goals

**Goals:** Implement accounting delivery of issued documents using the shared foundation contracts; make authorization, persistence, failure outcomes and acceptance traceable across API, UI and tests.

**Non-Goals:** Separate runtimes/databases/identity stores, destructive migration, old production-data import, excluded complements and automatic production release. Preserve unrelated source work and current office behavior.

## Decisions

### Prerequisite and ownership
Implementation depends on both the company foundation/fiscal issuance and `complete-xml-accounting-workflow`. The latter owns complete-only ready lists, storage-integrity attention, incoming science/recovery and their rollout gates. This change adds distinct requirements for ERP source origin and delivery; it must not replace those existing MODIFIED blocks. `add-super-admin-platform` is not a delegation mechanism: its superseded foreign-support elevation is never restored.

### Explicit mapping and revocation
A company owner authorizes an office and an explicit existing office Client mapping approved by a real office administrator. The normalized company/establishment CNPJ must match the mapped Client; matching a CNPJ alone never creates a grant or selects another Account. Map establishments independently where their identifiers differ. Only one office delegation is active for a company. Delivery rechecks company status, active delegation, office status and Client mapping before accessing or publishing bytes. Revocation prevents pending/new delivery and further ERP access by the former office, but does not delete valid records previously delivered under authorization.

### Durable projection and deduplication
Create a durable ERP document-version/delegation delivery intent after authorized artifact commit, before worker dispatch. The worker enters Company context for source validation and Account context for destination publication with strict restoration, locks company/delegation before the destination logical document and revalidates the grant under that lock. One projection represents the existing canonical Account/Client/source/document-key identity. A captured copy and issued copy of the same logical note converge into that row with multiple origin references, not duplicate accounting totals. Conflicting keys, model/environment, hashes or protocol evidence enter attention and never silently overwrite an accepted artifact. Correlated events append idempotently to its timeline.

Accept only complete authorized XML with verifiable model/key/issuer/environment/protocol and matching byte length/SHA-256. ERP issuance provenance remains explicitly `erp-issued`; it must not be labeled recipient-authenticated distribution or authorize automatic recipient science. No summaries, drafts, standalone events or indeterminate attempts become ready XMLs. Storage outages are errors, not zero documents; durable retry resumes without duplicate publication or reissuing the fiscal note.

### Interfaces
Company owners/delegated permitted readers use `/api/v1/erp/accounting-deliveries` and explicit bounded retry requests, with `/erp/contabilidade` for status and reasons. Existing office Fiscal APIs preserve their envelopes and Account/Client authorization; any additive origin metadata is safe and permission-scoped. No public artifact URLs or caller-chosen destination IDs bypass mapping. Administrative retries still use real grants and cannot operate as foreign support.

### Alternatives and compatibility

The selected design keeps domain ownership inside the destination application so one authenticated transaction can enforce company permissions and consistent local effects. Separate legacy Laravel/MySQL services were rejected by the user's unification decision; blindly copying source schema/controllers would collide with global identities and Account semantics. A frontend-only port was rejected because authorization and real provider/transaction outcomes must be server-enforced. Shared protocols and prerequisite deltas are read from the foundation design; this change does not restate a different version of them.

## Risks / Trade-offs

- Legacy MySQL SQL/framework/package behavior may not translate directly → prove PostgreSQL query/constraint behavior and installed-runtime compatibility before enabling affected operations; do not install the source lockfile over Opmoni.
- Cached permissions, delegated access and worker reuse can outlive authorization → revalidate at the transaction/effect boundary and restore explicit domain contexts on every exit.
- Multiple module owners can duplicate effects or drift wire contracts → use the foundation ownership registry, caller-owned transactions, stable source-operation keys and producer/consumer fixtures.
- Local provider fakes and static source inventory cannot prove deployed or live-provider acceptance → report those evidence classes separately and preserve explicit rollout gates.

## Migration Plan

1. Read prerequisite proposals/specs/designs from disk, canonical capabilities and the existing dirty baseline; expand the assigned source actions into verification rows without changing agreed scope.
2. Add only this domain's additive PostgreSQL migrations/API/UI/worker behavior behind disabled ERP release mode; preserve the existing office rows, runtime configuration and gates.
3. Verify focused fixtures, dependent-module contracts and required independent PostgreSQL/Redis races using isolated resources; run current frontend lint/typecheck/test/build and relevant backend checks.
4. Rehearse schema/data preservation and disable-first recovery. Public release remains owned by change 9 after full-core acceptance, not by completion of this change.

Rollback disables this domain's ERP entrypoints/background effects and retains committed ledgers, provider evidence and private artifacts. Do not run destructive table drops or reset office/Company identity data as an operational rollback. No unresolved product question changes this design; implementation compatibility discoveries must be surfaced before altering the agreed contract.
