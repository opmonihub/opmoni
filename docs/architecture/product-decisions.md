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

#### Commercial maintenance boundary — 2026-10-25 implementation clarification

The Office ERP commercial module treats maintenance of an already accepted office-client contract and its financial history separately from making a new sale. If the office subscription or resale allowance later becomes inactive, an active office with a real admin member may still read and perform the action-classified maintenance operations for existing contracts: lifecycle maintenance, invoice issue/correction, receipt recording/reversal, policy maintenance, reporting, and revoking an existing invitation. This narrow exception preserves servicing and accounting history; it does not restore the right to create/send contracts, issue invitations, adopt a Company subscription, add licences, expand modules, or create/publish offers. Those new-sale actions require current subscription/resale rights and capacity.

This boundary does not alter D009: a Super Admin still needs real Account membership for tenant operations, and a selected office context must match that membership. Office status, admin role, current contract/allocation state and action-specific authorization remain required. No general write bypass is granted for an inactive office subscription. This clarification records implemented authorization behavior and its acceptance evidence; it does not authorize production rollout or change launch gates.

### ERP customer onboarding and office trial scope — integrated implementation, local verification passed

The archived [simplify-office-erp-client-onboarding change](../../openspec/changes/archive/2026-10-28-simplify-office-erp-client-onboarding/proposal.md) records the approved scope for direct customer enrollment from a new company-client registration or an existing office portfolio Client, optional owner access setup, and office-attributed seven-day ERP-only trials. Paid enrollment and trial conversion use explicit office-provided terms; conversion retains the same Company and trial history. Expiry blocks operating writes while retaining authorized reads and data. There is no trial invoice, automatic collection, or automatic paid conversion.

This scope supersedes mandatory published-offer/owner-invitation onboarding and no-free-trial clauses in the affected commercial capabilities. It preserves D009's selected-Account and real-membership boundary, Company ownership and explicit delegation, immutable financial history, and existing invitation/contract recovery. The regular backend suite, scoped PHP formatting, full frontend matrix, private browser flows, and synthetic PostgreSQL dump/restore with compatible-app recovery are recorded in the [acceptance report](../reviews/2026-10-25-erp-client-onboarding/acceptance.md). Strict OpenSpec validation passed. The full PostgreSQL profile passed on exact accepted fixture source `7774e09a5844792faf04998f39ae9bc6926a01e5` (253 tests, 29,033 assertions, exit 0); the acceptance report records exact source provenance and historical fixture diagnostics. That acceptance checkpoint did not include canonical synchronization or archival. Both completed separately on 2026-10-28; see the [archive record](../reviews/2026-10-25-erp-client-onboarding/archive-record.md). This decision does not authorize production rollout or change registration, trial, fiscal, provider, payment, or operating gates.

## Specification reconciliation — 2026-10-13

The user requested a complete system map with delta specs and synchronization of decisions and canonical contracts. [reconcile-system-specs](../../openspec/changes/archive/2026-10-13-reconcile-system-specs/proposal.md), archived on 2026-10-13 with all ten tasks complete and its seventeen capability deltas synchronized, documents the system contracts and preserves the access baseline. The [system reconciliation report](system-spec-reconciliation.md) links every registered application route and frontend page family to implementation evidence and records remaining differences.

This paragraph records the 2026-10-13 reconciliation baseline. The earlier [add-super-admin-platform](../../openspec/changes/archive/2026-10-20-add-super-admin-platform/proposal.md) proposal was not a blanket description of delivered functionality. Its foreign support role/entry/exit is superseded by D009's real-membership-only authorization. At that baseline, the summary API, arbitrary contracting Account selection, stricter catalog editing, and stronger lifecycle/concurrency guarantees were absent or unverified. Those dated observations remain historical evidence and are superseded where the 2026-10-18 implementation update below records delivery; they do not establish today's acceptance status.

At the 2026-10-13 baseline, platform summary was composed from existing list APIs, commercial Account contact data used a separate endpoint, and certificate reuse selected the earliest Account. These observations remain dated and are no longer the current implementation description.

## Platform implementation follow-up — 2026-10-18

The preceding reconciliation records the 2026-10-13 baseline. The active `add-super-admin-platform` implementation now supplies the dedicated summary, bounded Account/contact/subscription projection, insert-only catalog initialization, strict nullable plan updates, explicit contracting-certificate source selection and bounded authentication diagnostic. Existing Account issuance/reissue envelopes and distinct pagination conventions are retained. The dedicated platform contracting vault is separate from the tenant certificate vault; legacy attachments can be preserved without copying or granting membership. Plan update validation allows only `name` and the known nullable nonnegative integer limits; a supplied sparse `limits` object clears omitted known limits. The Admin page exposes plan editing only, although the API retains a separate create endpoint.

D009 remains the authorization rule: Super Admin is a platform role, Account entry requires real membership, and inactive subscriptions deny tenant writes while preserving permitted reads. Foreign support entry/exit, effective support roles and banners remain retired. The reconciled report retains 56 original items as 46 valid items and ten retired items. In the 2026-10-18 evidence snapshot, 46/46 valid tasks are checked; no executable task remains open. The final whole-backend/PostgreSQL gate and frontend checks passed. Final independent source review is Spec PASS / Quality APPROVED with no findings; local browser acceptance and documentation reconciliation passed. Three optional live-provider probes are not live SERPRO acceptance. Canonical synchronization and archive completed on 2026-10-20; production gates remain unchanged. This implementation record does not authorize production rollout. [Runtime initialization and rollback](../../README.md#platform-administration-and-initialization) preserve additive records, encrypted sources, audit history and existing keys.
