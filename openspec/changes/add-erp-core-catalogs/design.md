## Context

See [proposal.md](proposal.md) for the approved motivation and scope. Reference: [pinned source](https://github.com/opmonihub/sistema/tree/e7b77beccdad983ddd990c0a045be750fc3f7e25). The current system has Nuxt presentation, Laravel-owned access/data, PostgreSQL and Redis, and existing dirty implementation work outside this planning change.

**Dependencies:** [add-erp-company-foundation](../add-erp-company-foundation/proposal.md)

This design is required because it changes multiple layers, tenant/data contracts or external-effect/migration behavior. It is a target design, not a statement of delivered behavior.

## Goals / Non-Goals

**Goals:** Implement core commercial and fiscal catalogs using the shared foundation contracts; make authorization, persistence, failure outcomes and acceptance traceable across API, UI and tests.

**Non-Goals:** Separate runtimes/databases/identity stores, destructive migration, old production-data import, excluded complements and automatic production release. Preserve unrelated source work and current office behavior.

## Decisions

### Catalog boundaries and references
Keep company commercial parties in `erp_contacts`, with customer/supplier flags; they are not office Clients or ERP tenant Companies. Products may be stock-tracked goods or non-stock services; variants, unit conversions, locations, tax references, selling-price groups and prices belong to the same company. Shared public geography/standard-code reference sets may be global read-only data; company operational records remain company-scoped. Establishment registration, tax regime, operation nature, fiscal classification and numbering-series settings are versioned operating configuration, not authentication data.

Preserve decimal precision for amounts, quantities and conversions, using decimal strings in API contracts and PostgreSQL numeric columns rather than floating-point arithmetic. Snapshots on posted transactions preserve descriptions/prices/taxes even after catalogs change. Referenced records are archived instead of cascade-deleted; active references remain readable on history but cannot be used for new writes when invalid. Company payloads never set the authorized company ID.

### API and UI
CRUD route families under `/api/v1/erp/contacts`, `/products`, `/units`, `/categories`, `/brands`, `/price-groups`, `/tax-settings`, `/carriers`, `/vehicles` and `/drivers` use the foundation envelopes. Related IDs are validated in company context; establishment IDs cannot cross companies. `/erp/cadastros/*` uses existing Nuxt table/search/form patterns, explicit field errors, latest-response handling and separate create/edit permissions. Source import workflows become authenticated preview/confirm operations with validation results, bounded upload limits, duplicate detection and no silent partial success. Never import production dumps or source credentials.

The reference map preserves source fields needed for NF-e/NFC-e/CT-e/MDF-e rather than inventing generic replacements. It covers NaturezaOperacao, TaxRate, PercentualImpostoEstado, IBPT reference usage, transport carriers/vehicles and party addresses. A successful lookup may suggest changes but never overwrites saved catalog data without confirmation. Failure keeps existing form data and offers retry.

### Transaction consumers
Finance owns ledger accounts and bank/cash entities, purchasing owns balances/movements and sales owns commercial document lifecycle. Catalog writes cannot create those effects. This change owns shared decimal/reference serialization; downstream modules reuse it and save immutable transaction snapshots.

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
