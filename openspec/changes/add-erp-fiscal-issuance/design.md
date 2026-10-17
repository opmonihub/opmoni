## Context

See [proposal.md](proposal.md) for the approved motivation and scope. Reference: [pinned source](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25). The current system has Nuxt presentation, Laravel-owned access/data, PostgreSQL and Redis, and existing dirty implementation work outside this planning change.

**Dependencies:** [add-erp-core-catalogs](../add-erp-core-catalogs/proposal.md), [add-erp-purchasing-and-stock](../add-erp-purchasing-and-stock/proposal.md), [add-erp-sales-and-orders](../add-erp-sales-and-orders/proposal.md)

This design is required because it changes multiple layers, tenant/data contracts or external-effect/migration behavior. It is a target design, not a statement of delivered behavior.

## Goals / Non-Goals

**Goals:** Implement fiscal issuance and document lifecycle using the shared foundation contracts; make authorization, persistence, failure outcomes and acceptance traceable across API, UI and tests.

**Non-Goals:** Separate runtimes/databases/identity stores, destructive migration, old production-data import, excluded complements and automatic production release. Preserve unrelated source work and current office behavior.

## Decisions

### Reuse and compatibility
Map source NFeService, NFCeService, CTeService, MDFeService, DevolucaoService and Inutil controllers into namespaced Laravel 13 services. Review every transitive fiscal/rendering package against the installed PHP/Laravel runtime; pin compatible versions, replace framework adapters instead of installing the old application lockfile, and prove behavior with generated certificates/XML fixtures. Public `xml_*` source paths become company/establishment private storage. Do not copy source .env, database dumps, public artifacts or certificate material.

### Certificate and document ownership
Encrypted company/establishment certificates and passwords are owner-authorized configuration with bounded upload validation and safe metadata. Do not reuse an arbitrary first office certificate or the platform SERPRO singleton. Source snapshots bind company, establishment, model, environment, series, number, operation and applicable commercial references. Allocate numbers transactionally; rejected or indeterminate attempts do not silently free numbers for a different document. Model-specific required fields and fiscal calculations follow the pinned source contract and current official schemas/providers, recorded per model during implementation. NFS-e and unrelated source expansion remain out of scope.

Each attempt binds one coherent certificate/password/configuration revision internally. Rotation validates and replaces the complete configuration atomically; an in-flight attempt never combines an old certificate with a new password, issuer or numbering configuration. The revision participates in the effective operation identity, but secret bytes never enter the canonical browser request or audit metadata. Permission, company status and permitted provider scope are checked again before dispatch.

### External operation state machine
Persist draft, queued, submitting, authorized, rejected, indeterminate, canceled and other model-specific event outcomes, with an append-only attempt/event history. An idempotency key binds the canonical request. Commit intent before dispatch; network calls execute outside open business transactions and do not retry unknown transmission blindly. On timeout/crash after possible acceptance, consult by the same key/receipt before retrying. Persist verified authorized XML, protocol, environment, byte length and SHA-256 atomically with publication state; storage failure remains non-ready. Duplicate provider responses and callbacks are idempotent. Failed events never rewrite accepted document history.

### Supported actions and transport
API families `/api/v1/erp/fiscal/documents`, `/fiscal/certificates`, `/fiscal/events` and `/fiscal/invalidations` expose model-filtered creation/read, explicit transmit/consult/cancel/correct/close actions where applicable, and protected XML/PDF downloads. `/erp/fiscal/*` provides setup, drafts, pending/uncertain attention, authorized documents, events and auxiliary printing. Real multipart/binary bytes retain ingress, CSRF and company assertions. Cancellation, correction, inutilization, MDF-e closure and source-supported CT-e complement/substitution remain separate model-valid actions with required justification and permission; none is fabricated by generic CRUD.

Source-supported NFC-e contingency is explicit, records pending reconciliation and requires later confirmation; offline/local generation never claims provider authorization. Automatic recipient science/conclusive manifestation is not part of self-issued ERP document processing. Production transmission is disabled by default; a later explicit model/environment/company canary is separate from local verification and final public launch.

Pre-release live verification uses a dedicated non-public operator canary harness, not a public preview endpoint. It requires separately authorized company/actor/model/environment/key scope, normal company permissions and explicit provider enablement; its outcome, numbers and artifacts are recorded by the same fiscal state machine. It cannot enable public ERP mode or act as a tenant bypass. Without that approval or valid scope no live effect is attempted. Change 9 owns this narrow verification channel and the final production release decision.

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
