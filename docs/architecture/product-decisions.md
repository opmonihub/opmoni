# Product decisions

Started on 2026-10-12.

## Review process

Present one part of the product at a time for user review. Record acceptance or requested changes before presenting the next part. A proposal remains pending until the user explicitly accepts it. Agreement on one part does not approve the remaining product design or implementation.

## Confirmed decisions

### D001 — Fiscal capture purpose

Capture NF-e and CT-e XML documents using each customer's A1 certificate, through a microservice integrating NFePHP.

### D002 — Fiscal service location

Place the PHP service in `services/fiscal/`, with the Nuxt interface in `frontend/app/` and its server integration in `frontend/server/`. Composer owns the PHP dependencies. Certificates and XML documents use persistent Docker volumes outside Git.

See [Fiscal service structure](fiscal-service.md) for the recorded directory layout.

### D003 — Incremental product review

Review and accept each part separately, keeping confirmed decisions and pending proposals distinct in this document.

### D004 — First release scope

Accepted by the user on 2026-10-12:

- Access and Account membership to isolate each accounting office's data.
- Clients: registration, client details, and A1 certificate management.
- Fiscal: capture overview, capture status by client, document listing, details, and XML download for NF-e and CT-e.

Monitoring/SERPRO, Work, WhatsApp, and commercial plan/subscription management are deferred.

Status as of 2026-10-13: these deferrals are superseded by D009. The Clients/Fiscal purpose remains applicable; the separate fiscal service under D001/D002 remains an unimplemented extraction target, while the integrated fiscal runtime now lives in Laravel.

### D005 — Application backend (superseded by D006)

Accepted by the user on 2026-10-12: implement access and application data in the Nuxt `frontend/server/` directory. PHP is limited to the separate `services/fiscal/` microservice. Do not introduce a Laravel `backend/` into this project.

### D006 — Separate frontend and Laravel backend

Accepted by the user on 2026-10-12 after D005: move the complete Nuxt application to `frontend/` and initialize a Laravel 13 template in `backend/`, without frontend scaffolding. This supersedes the prohibition on a Laravel backend in D005. The existing Nuxt sample handlers remain in `frontend/server/`; no application API migration or authentication integration is implemented by this initialization. The fiscal service remains separately planned under `services/fiscal/`.

## Reference context

The source review of `/home/obsidian/dev/legacy` identified an accounting-office product organized around Accounts and client portfolios, with Fiscal, Monitoring, Work, Team, Settings, and global administration modules. This is reference context, not approval to reproduce every module. Runtime behavior was not verified during that review.

## Delivery records and pending proposals

### P002 — Access and Accounts foundation

Status: implemented and archived. Nuxt presentation, Laravel application APIs, PostgreSQL persistence, and Laravel-managed Redis sessions follow D006. Archived change: [add-access-accounts-foundation](../../openspec/changes/archive/2026-10-12-add-access-accounts-foundation/proposal.md); canonical contracts live in [openspec/specs](../../openspec/specs/). On 2026-10-12 the user authorized direct implementation using its recorded contracts, overriding the remaining incremental page-review gate. Onboarding is available only with zero users and zero Accounts. Login and selection use the confirmed flow below; implementation and verification evidence remains in the archived tasks.md.

Use the legacy's access, Account membership, roles, provisioning, and page flows as reference, adapting implementation to the Nuxt frontend and Laravel backend (D006) and explicitly documenting proposed corrections. This proposal does not implement the remaining Clients or Fiscal pages. Capture scheduling and manifestation policy require later decisions.

## Subsequent confirmed decisions

### D007 — Access foundation implementation authorization

Accepted by the user on 2026-10-12: apply `add-access-accounts-foundation` directly using the current page and API contracts. Onboarding is first installation only: both users and Accounts must be empty; either existing store closes registration. After credentials succeed, one active member Account enters directly, multiple active member Accounts require office-profile cards, and zero active memberships show unavailable access. Each new multi-Account login requires a choice; session reload preserves it. The template team selector is for subsequent switching only. Suspension during card selection refreshes choices, with unavailable access when none remain. Sessions last at most 12 hours without remember-me. Unknown email/wrong password share the same error; service failure preserves the form/email and allows retry. Automatic password recovery remains deferred to platform-administrator assistance, without requiring a contact channel to complete this change, as subsequently clarified by the user.


### D008 — Automatic default office entry

Accepted by the user on 2026-10-12: supersedes D007's mandatory profile-card selection. Login enters the default active office directly, even with multiple memberships; optional switching happens through AccountMenu. The default is the earliest membership by membership ID among active Accounts. Manual switching remains local to the session and survives reload; a new login returns to the default. Existing sessions with no selection acquire that default on identity refresh without extending their deadline. No active memberships show unavailable access; suspended choices remain blocked.


### D009 — Complete legacy business integration

Accepted by the user on 2026-10-13: copy and activate the legacy business logic across Clients, Fiscal, Monitoring, Work, Team, Settings, and platform administration, while preserving the existing Opmoni route conventions, theme, access screens, session ownership, and account memberships. This supersedes the earlier review-only scope and deferred business page integration. Business APIs use `/api/v1`; the session selects the account and `X-Account-Id` asserts the displayed account. Super Admin remains a platform role and does not bypass tenant membership or grant tenant writes. Legacy support impersonation and persistent user current-account fields are superseded by the canonical access contract.

The local Compose scheduler activates the imported schedules in the fiscal homologation environment. Queue workers use per-job deadlines and restore account context between jobs. External provider calls retain their existing configuration and contract guards; test verification uses provider fakes except for explicit opt-in live probes.

## Specification reconciliation — 2026-10-13

The user requested a complete system map with delta specs and synchronization of decisions and canonical contracts. [reconcile-system-specs](../../openspec/changes/archive/2026-10-13-reconcile-system-specs/proposal.md), archived on 2026-10-13 with all ten tasks complete and its seventeen capability deltas synchronized, documents the system contracts and preserves the access baseline. The [system reconciliation report](system-spec-reconciliation.md) links every registered application route and frontend page family to implementation evidence and records remaining differences.

The earlier [add-super-admin-platform](../../openspec/changes/add-super-admin-platform/proposal.md) proposal is not a blanket description of delivered functionality. Its foreign support role/entry/exit is superseded by D009's real-membership-only authorization. Uniform envelopes, a dedicated summary API, arbitrary contracting Account selection, stricter catalog editing, and stronger lifecycle/concurrency guarantees remain pending targets where implementation is absent or unverified. Original unchecked tasks are preserved; synchronization does not authorize or claim those features.

The current imported commercial behavior blocks tenant writes for inactive/missing subscriptions and enforces client/member creation limits. Current platform summary uses existing list APIs, commercial Account contact data has its own endpoint, and certificate reuse still selects the earliest Account. These behaviors and their limitations are explicitly mapped, without introducing new product approval, implementing the pending extraction, or claiming live-provider acceptance.

## Platform implementation follow-up — 2026-10-18

The preceding reconciliation records the 2026-10-13 baseline. The active `add-super-admin-platform` implementation now supplies the dedicated summary, combined Account/contact projection, insert-only catalog initialization, strict nullable plan limits, explicit contracting-certificate source selection and bounded authentication diagnostic. Existing Account issuance/reissue envelopes and list pagination conventions are retained. The dedicated platform contracting vault is separate from the tenant certificate vault; legacy attachments can be preserved without copying or granting membership.

D009 remains the authorization rule: Super Admin is a platform role, Account entry requires real membership, and inactive subscriptions deny tenant writes while preserving permitted reads. Foreign support entry/exit, effective support roles and banners remain retired. The active change has 46 valid original criteria and ten superseded originals; superseded work must not be counted as implemented. Independent-process evidence, UI acceptance and completion status are recorded in the active change's evidence sections after review. This implementation record neither archives the change nor authorizes production rollout or live-provider acceptance. [Runtime initialization and rollback](../../README.md#platform-administration-and-initialization) preserve additive records, encrypted sources, audit history and existing keys.
