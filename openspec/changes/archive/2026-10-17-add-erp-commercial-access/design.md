## Context

See [proposal.md](proposal.md) for the approved motivation and scope. Reference: [pinned source](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25). The current system has Nuxt presentation, Laravel-owned access/data, PostgreSQL and Redis, and existing dirty implementation work outside this planning change.

**Dependencies:** [add-erp-company-foundation](../2026-10-17-add-erp-company-foundation/proposal.md)

This design is required because it changes multiple layers, tenant/data contracts or external-effect/migration behavior. It is a target design, not a statement of delivered behavior.

## Goals / Non-Goals

**Goals:** Implement company commercial access using the shared foundation contracts; make authorization, persistence, failure outcomes and acceptance traceable across API, UI and tests.

**Non-Goals:** Separate runtimes/databases/identity stores, destructive migration, old production-data import, excluded complements and automatic production release. Preserve unrelated source work and current office behavior.

## Decisions

### Separate commercial ownership
Use `erp_plans`, `erp_subscriptions` and plan module entitlements; at most one current subscription belongs to a company. Office Plan/Subscription and Account access stay unchanged. Company suspension blocks all company operations; missing, pending, past_due or canceled subscription allows authorized contract/setup reads but blocks ordinary operating writes. Active subscription permits only enabled modules and never grants membership. Foundation setup APIs have narrow explicit exceptions; no wildcard subscription bypass.

### Registration and provisioning
After an initialized platform and only when the final release gate permits signup, `POST /api/v1/erp/register` accepts new owner identity/password and company data through existing origin/CSRF controls and a five-per-minute trusted-IP budget. It atomically creates a global user, company, owner membership and pending company subscription; it creates no office Account. An existing normalized email returns a login-required field response without changing credentials or adding membership. Authenticated existing users use `POST /api/v1/erp/companies`. Duplicate company identity returns validation failure and never associates by CNPJ automatically. Session issuance occurs after commit and retains recoverable login behavior if Redis fails.

Use an insert-only basic company plan with the approved core modules and no invented billing prices/quotas; pending subscriptions require explicit Super Admin activation. This is direct contracting/habilitation, not a paid checkout. Platform APIs manage company plans/subscriptions with audited status changes; company owners read their contract but cannot activate it. No free trial or provider charge is implied. Later launch work may enable the registration gate only after acceptance.

### HTTP and presentation
Owner contract read is `GET /api/v1/erp/subscription`; platform APIs are `/api/v1/admin/erp/plans` and `/api/v1/admin/erp/subscriptions`. `/erp/contrato` shows pending/active/blocked status truthfully, with setup progress and no fake successful payment. `/admin/erp/*` uses the existing global role guard; operating-company assertions are not required on those platform endpoints. Registration is `/erp/cadastro`, distinct from first-installation `/onboarding`.

Plan edits validate known core module keys and nonnegative optional quotas; no unapproved quotas or prices are seeded. Repeat initialization preserves customized plans and existing contracts. Subscription writes serialize on the company to prevent competing registration/activation from creating duplicate contracts.

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
