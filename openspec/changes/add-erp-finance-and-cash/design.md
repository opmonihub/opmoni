## Context

See [proposal.md](proposal.md) for the approved motivation and scope. Reference: [pinned source](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25). The current system has Nuxt presentation, Laravel-owned access/data, PostgreSQL and Redis, and existing dirty implementation work outside this planning change.

**Dependencies:** [add-erp-commercial-access](../add-erp-commercial-access/proposal.md), [add-erp-core-catalogs](../add-erp-core-catalogs/proposal.md)

This design is required because it changes multiple layers, tenant/data contracts or external-effect/migration behavior. It is a target design, not a statement of delivered behavior.

## Goals / Non-Goals

**Goals:** Implement finance and cash operations using the shared foundation contracts; make authorization, persistence, failure outcomes and acceptance traceable across API, UI and tests.

**Non-Goals:** Separate runtimes/databases/identity stores, destructive migration, old production-data import, excluded complements and automatic production release. Preserve unrelated source work and current office behavior.

## Decisions

### Financial records and invariants
Use ERP-namespaced LedgerAccount/FinancialAccount concepts and `erp_*` tables, never the office Account model. Store transaction amount, currency, due date, party and establishment snapshots. Balances derive from posted immutable entries; corrections create explicit reversals with actor/reason. Amounts use fixed-decimal strings and allocations cannot exceed the authorized open amount. Archived party/account references remain on history; no cascade erase of posted records.

### Shared posting interface
Expose internal `postObligation`, `allocatePayment`, `reverseAllocation` and `postCashMovement` operations that participate in the caller's existing PostgreSQL transaction. Each takes explicit Company/actor/source type/source ID and a stable operation key; unique source-operation constraints make duplicate calls return the existing result. Purchase/sale modules own their lifecycle and call these operations while holding their own source lock, then financial records, then cash records in that fixed order. Lower-level participants never acquire source locks or call back into purchase/sale state machines. All writes must commit or roll back together.

Cash sessions belong to one company/establishment/register and responsible operator, with constrained open-session ownership. Closing locks the session, reconciles opening balance plus movements and records declared/count differences without rewriting entries. Repeating a close is idempotent. Concurrency cannot allocate a receivable twice or post into a closed cash session. Register movement and close permissions are separate from financial administration and company ownership.

### Interfaces
API families are `/api/v1/erp/financial-accounts`, `/obligations`, `/payments`, `/cash-registers` and `/cash-sessions`, with explicit allocation/reversal/open/close actions and no destructive deletion of posted history. `/erp/financeiro/*` and `/erp/caixa/*` provide filters, safe totals, forms, receipts and reconciliation states. Boleto/document generation supported by the source is private and reports failure truthfully; external payment registration is an explicit recoverable operation, never an automatic charge during ordinary reads. New SaaS checkout integration is outside this suite.

### Failure boundaries
Before any enabled external financial effect, persist a durable intended operation and idempotency key after local transaction approval. Unknown outcomes require provider lookup/reconciliation rather than a second blind charge or payment record. Provider credentials never enter API resources or browser state.

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
