> Reconciled 2026-10-17 against D009 and the accepted 2026-10-13 delivery record. This proposal describes retained completion targets, not a claim that they are implemented. Canonical specs remain unchanged until reviewed delivery and explicit synchronization.

## Why

The current Nuxt/Laravel application already delivers platform administration and the integrated business modules. Its original Super Admin proposal still mixes obsolete foreign support elevation with stronger Account, catalog, summary, certificate-source and concurrency acceptance targets; complete the surviving targets while preserving the delivered contracts.

## What Changes

- Finish the shared Admin shell, typed contracts, safe list recovery and all seven original pages. Preserve the existing nine-entry navigation, including Planos ERP and Assinaturas ERP in their current positions.
- Add a generic safe Account detail and dedicated summary API; extend root Account contact projection and mutations through the existing settings-backed commercial contact action. Preserve `/commercial`, issuance, real identity and existing response envelopes.
- Retain insert-only catalog initialization and transactional missing-subscription provisioning already reviewed. Complete safe database status constraints, factories and strict Plan limit validation with backward-compatible empty/sparse replacement input.
- Preserve D009: real memberships alone authorize office entry; Super Admin grants no tenant role or writes. Inactive/missing subscriptions block tenant writes, and client/member creation limits continue. Retire only ten original incompatible tasks, preserving all 56 original acceptance descriptions in `tasks.md`.
- Harden stored audit reads with safe scalar metadata and minimal actor/Account projections; preserve existing rows and canonical list ordering. Ordinary switching does not create a foreign support lifecycle.
- Add the separately owned encrypted platform contracting-certificate vault and arbitrary active Account source selection; preserve existing tenant certificate ownership, term issuance and legacy connection reuse. Encrypt historical consumer keys through a preserving migration.
- Add coherent credential snapshots, deterministic process-safe rotation, fresh diagnostic-only authentication, independent actor/IP throttles and real multipart ingress verification. Keep operational token caching and long-running fiscal/monitoring route budgets.
- Complete isolated PostgreSQL/Redis races, browser acceptance, final suites and independent reviews before marking the retained tasks complete.

## Capabilities

### New Capabilities

None. All five capability paths already exist canonically; additive requirements below extend them without duplicating existing requirements.

### Modified Capabilities

- `platform-administration`: shared workspace, compatible envelopes, dedicated summary and reliable screen states.
- `platform-account-management`: generic detail, root contact editing and combined resource projection while retaining separate commercial/access actions.
- `platform-commercial-management`: preserving initialization, strict normalized Plan edits and existing D009 subscription gates/creation limits.
- `platform-support-access`: real-membership search/detail entry and stronger secrecy of historical audit browsing.
- `platform-serpro-connection`: separate platform vault, source compatibility, encrypted key migration, rotation/snapshot concurrency and isolated authentication diagnostics.

## Impact

- Frontend: existing Nuxt 4/Vue 3/Nuxt UI 4 Admin pages, shared types, table/list utilities, membership guards and private `/api/v1` proxy.
- Laravel: additive Admin reads/contact validation, safe resources, Plan/status constraints, encrypted platform certificate/source storage, lifecycle coordination and diagnostic auth. PostgreSQL and Redis remain the persistence/session authorities.
- Data: settings-backed `platform_billing_contact` remains authoritative; no duplicate contact columns, secret import, reset or destructive certificate migration. Preserve existing subscription Account cascade and restricted Plan FK behavior.
- Compatibility: raw Plan resources, Account `meta.page`, resource `meta.current_page`, top-level Identity, create `{data,first_access}` and reissue `{first_access}` are binding. The selected-active-member GET `/serpro/connection` exception remains; new platform endpoints are Super Admin-only.
- Non-goals: foreign support elevation/context/banner, user CRUD/promotion, payment collection, new business integrations or fiscal-service extraction. Existing Clients/Fiscal/Monitoring/Work/Team/Settings/WhatsApp behavior and unrelated ERP pages remain operational and outside this slice.
- Authority: D009 and [accepted reconciliation](../../../docs/architecture/product-decisions.md) are preserved, not superseded. No unresolved product decisions remain. [System reconciliation](../../../docs/architecture/system-spec-reconciliation.md) describes delivery; `design.md` defines completion contracts, and `tasks.md` preserves original traceability and evidence limits.
