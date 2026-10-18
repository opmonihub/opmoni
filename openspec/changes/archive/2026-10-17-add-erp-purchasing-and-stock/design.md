## Context

See [proposal.md](proposal.md) for the approved motivation and scope. Reference: [pinned source](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25). The current system has Nuxt presentation, Laravel-owned access/data, PostgreSQL and Redis, and existing dirty implementation work outside this planning change.

**Dependencies:** [add-erp-core-catalogs](../2026-10-17-add-erp-core-catalogs/proposal.md), [add-erp-finance-and-cash](../2026-10-17-add-erp-finance-and-cash/proposal.md)

This design is required because it changes multiple layers, tenant/data contracts or external-effect/migration behavior. It is a target design, not a statement of delivered behavior.

## Goals / Non-Goals

**Goals:** Implement purchasing and inventory using the shared foundation contracts; make authorization, persistence, failure outcomes and acceptance traceable across API, UI and tests.

**Non-Goals:** Separate runtimes/databases/identity stores, destructive migration, old production-data import, excluded complements and automatic production release. Preserve unrelated source work and current office behavior.

## Decisions

### Purchase and stock ownership
Purchasing owns supplier documents, purchase lines, draft/post/return status and receipt links. Inventory owns movement ledgers, source-operation uniqueness, balances and costing by company/establishment/product variant. Use `erp_*` tables and numeric quantities/costs. Source units and variant IDs resolve within the selected company; transaction snapshots retain original conversion factors and valuations.

Posted receipts and returns are immutable; correction is a linked compensating movement. Preserve the source FIFO/LIFO company accounting-method setting, defaulting to FIFO for a new company; valuation follows the actual consumed receipt allocations. Changes apply to future allocations and never recost posted history. Preserve source-supported lot/expiry and overselling settings, defaulting overselling to disabled; a documented explicitly enabled overselling policy is required before permitting negative stock. Every outgoing transaction stores receipt/lot allocations and the effective accounting policy for traceable reversal.

### Posting contract and lock order
Purchase posting locks the purchase first, stock keys in sorted establishment/variant/lot order, then finance obligations and cash rows if required. Validate the complete document before changing anything; post purchase, stock and financial effects on the same connection/transaction. Internal `receiveStock`, `issueStock`, `transferStock` and `reverseStock` join a caller transaction and never acquire purchase/sale source locks. A source key repeated with the same canonical payload returns the original result; a changed payload returns 409.

Transfers post balanced outgoing/incoming movements atomically, reject foreign establishments and never transfer into the caller's chosen company through a payload field. Adjustments require their own permission, actor and reason. Supplier returns reference actual posted purchase quantities and cannot exceed remaining returnable stock. Stale/concurrent inventory changes cause a conflict/insufficient-stock result without half-posted finances.

### Interfaces
Routes are `/api/v1/erp/purchases`, `/purchase-returns`, `/stock/balances`, `/stock/movements`, `/stock/adjustments` and `/stock/transfers`; posting and return actions are explicit. `/erp/compras/*` and `/erp/estoque/*` cover supplier entries, source-supported purchase XML preview, posting confirmation, movement histories, costing and safe reversals. XML preview validates file structure, company/party identity and duplicate keys before explicit user confirmation; it cannot overwrite issued fiscal state or bypass the accounting XML readiness contract.

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
