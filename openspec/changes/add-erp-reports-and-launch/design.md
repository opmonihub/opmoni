## Context

See [proposal.md](proposal.md) for the approved motivation and scope. Reference: [pinned source](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25). The current system has Nuxt presentation, Laravel-owned access/data, PostgreSQL and Redis, and existing dirty implementation work outside this planning change.

**Dependencies:** [add-erp-company-foundation](../add-erp-company-foundation/proposal.md), [add-erp-commercial-access](../add-erp-commercial-access/proposal.md), [add-erp-core-catalogs](../add-erp-core-catalogs/proposal.md), [add-erp-finance-and-cash](../add-erp-finance-and-cash/proposal.md), [add-erp-purchasing-and-stock](../add-erp-purchasing-and-stock/proposal.md), [add-erp-sales-and-orders](../add-erp-sales-and-orders/proposal.md), [add-erp-fiscal-issuance](../add-erp-fiscal-issuance/proposal.md), [add-erp-accounting-documents](../add-erp-accounting-documents/proposal.md)

This design is required because it changes multiple layers, tenant/data contracts or external-effect/migration behavior. It is a target design, not a statement of delivered behavior.

## Goals / Non-Goals

**Goals:** Implement core reports and complete erp launch using the shared foundation contracts; make authorization, persistence, failure outcomes and acceptance traceable across API, UI and tests.

**Non-Goals:** Separate runtimes/databases/identity stores, destructive migration, old production-data import, excluded complements and automatic production release. Preserve unrelated source work and current office behavior.

## Decisions

### Report ownership
Build company/establishment-scoped sales, purchases, stock/cost/movement, receivable/payable/aging, payments/cash, fiscal status and integrated dashboard reports from the posted ledgers and immutable snapshots. Drafts and failed/indeterminate fiscal attempts are separately labeled and do not inflate authorized/posted totals. Finance owns accounting amounts, inventory owns quantity/cost movements, sales/purchases own commercial status and issuance owns provider authorization; report definitions reuse those owners rather than independent recalculation.

APIs under `/api/v1/erp/reports/*` use validated period, establishment and status filters, deterministic pagination and safe decimal strings. Protected exports are authorized snapshots with company/actor/filter binding, private artifact storage, bounded work and expiry; regeneration never exposes a different company's data. `/erp/relatorios/*` shows loading, genuine empty results, errors and retry separately. Failed/missing queries cannot display invented zero metrics. Aggregate and detail reports reconcile using shared filter/currency/date definitions.

### Feature and release gate
Use explicit disabled, preview and production ERP release modes. Missing/unknown mode is disabled. Preview requires a bounded non-empty allowlist of real company/actor IDs in an isolated verification environment; it does not grant membership or enable production fiscal effects. Middleware enforces mode for ERP routes, background work and public signup, not only menu visibility. Provider/model fiscal gates remain independent and default disabled. Core schemas may be deployed additively while ERP remains disabled and office operations continue normally.

A dedicated non-public operator canary harness can verify a separately authorized fiscal company/actor/model/environment/key while public ERP remains disabled. This channel requires current company/action permissions, explicit provider gates, a non-empty exact scope and attributable durable fiscal execution; it cannot infer scope, bypass tenant authorization or change public release mode. Ordinary preview APIs and background work cannot use that exception. The operator harness is the only pre-release production-provider verification path; missing approval/configuration leaves live checks unverified. This avoids requiring public release in order to collect the canary evidence that release itself requires.

Production activation is permitted only after every included item in the foundation inventory and all dependent changes' acceptance criteria is complete. No staged public module rollout substitutes for that decision. Record whole-app verification, restored backups/recovery practice, migrations, required persistent private storage and model-scoped provider canary evidence. Missing live prerequisites block the affected production capability and therefore complete release; they do not justify claiming an implemented module is absent or enabling an unverified provider. SaaS payment checkout and specialized complements are excluded by explicit product scope, not hidden as unfinished core.

### Deployment and recovery
Use the existing repository/runtime layout with one Nuxt build, Laravel application and PostgreSQL database. Add only compatible core dependencies/runtime extensions found necessary by the earlier changes; keep per-service Compose configuration explicit. Do not import the old app, MySQL, Blade serving or a second authentication runtime. Restart queue/scheduler for changed code. Rollback disables ERP routes/work/signup first, stops/drains ERP-specific work and retains committed company documents and ledgers; no destructive down migration is used as operational rollback. Existing office access/capture remains protected by its unchanged gates.

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
