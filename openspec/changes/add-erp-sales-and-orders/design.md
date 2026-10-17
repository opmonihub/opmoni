## Context

See [proposal.md](proposal.md) for the approved motivation and scope. Reference: [pinned source](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25). The current system has Nuxt presentation, Laravel-owned access/data, PostgreSQL and Redis, and existing dirty implementation work outside this planning change.

**Dependencies:** [add-erp-core-catalogs](../add-erp-core-catalogs/proposal.md), [add-erp-finance-and-cash](../add-erp-finance-and-cash/proposal.md), [add-erp-purchasing-and-stock](../add-erp-purchasing-and-stock/proposal.md)

This design is required because it changes multiple layers, tenant/data contracts or external-effect/migration behavior. It is a target design, not a statement of delivered behavior.

## Goals / Non-Goals

**Goals:** Implement sales and commercial orders using the shared foundation contracts; make authorization, persistence, failure outcomes and acceptance traceable across API, UI and tests.

**Non-Goals:** Separate runtimes/databases/identity stores, destructive migration, old production-data import, excluded complements and automatic production release. Preserve unrelated source work and current office behavior.

## Decisions

### Documents and totals
Quotes do not affect balances; confirmed orders preserve requested quantities without pretending an invoice was issued. Orders do not reserve stock in this release: availability is enforced when posting the sale. Sales preserve immutable party, establishment, product/unit, price, discount, tax and payment-condition snapshots. Compute totals server-side with the catalogs decimal policy; reject invalid precision, unauthorized discounts and discrepancies rather than trusting browser totals. Document numbers are unique within company/establishment/document kind and allocated under PostgreSQL locking.

### Atomic posting and returns
Posting locks the sale/order source first, then sorted stock keys, then finance allocations and cash rows in the shared order. Revalidate permissions, commercial entitlements and current stock. Commit the sale, stock consumption and financial/cash effects together. Stable source-operation keys make double submits idempotent; different payloads under one key conflict. A transaction failure leaves the draft and all stock/financial states unchanged.

Returns reference posted sale lines and their consumed allocations and cannot exceed unreturned quantities. Stock and financial compensation is atomic and audited. Posted or fiscally referenced records cannot be deleted or silently edited. Fiscal cancel/correction/return obligations remain explicit issuance actions; commercial reversal alone does not claim SEFAZ cancellation. The fiscal module consumes approved immutable sale snapshots through an internal interface, never a second independent recalculation of changed catalog data.

### Interfaces and user experience
Use `/api/v1/erp/quotes`, `/orders`, `/sales` and `/sale-returns`, with explicit confirm/post/return actions. `/erp/vendas/*` covers quotes, orders, counter sale, completed sale details, payments and returns. Invoice status is distinct from sale status; retry does not pretend transmission succeeded. Forms preserve edits on failure, show server field errors, require confirmation for posting and expose actions only when allowed by the server. No restaurant/table/service-staff or e-commerce basket flows are copied.

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
