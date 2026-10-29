## Context

See `proposal.md` for motivation and the seven delta specs for requirements. Planning and later apply belong to this worktree and branch, not the preserved draft in the main checkout. The standalone preview is interaction evidence; its in-memory operations, invented addresses, hardcoded users, simplified permissions and instantaneous term edits are not application contracts.

Current relevant boundaries:

| Existing source | Reuse or correction |
| --- | --- |
| `OfficeErpCustomerEnrollment::enroll` | Currently creates Client/Company, assigns/activates subscription and invoices atomically. Preserve this compatibility command; extract reusable stages for the new UI. |
| `ErpCompanyProvisioning::createUnclaimedCompany` | Already creates an unclaimed Company, Matriz and one technical pending subscription without a fake owner. Reuse it; do not create another subscription. |
| `OfficeErpQueries` / client and contract controllers | Current client list includes non-ERP portfolio Clients; current contract list is per contract and summary selection uses latest IDs. New Company portfolios need explicit registration links and authoritative current-contract resolution. |
| `OfficeErpContracts` / `OfficeErpAdmission` | Existing calendar, revisions, allocation, activation/invoicing, maintenance and operation recovery remain authoritative. Current module logic rejects shrink-only amendments. |
| `ErpCompanyAdministration` / `ErpCompanyMemberController` | Existing Company-context administration is owner-only and associates existing identities. Keep those guards; share lower-level validation/mutation logic with a separately authorized commercial facade. |
| `OfficeErpOwnerSetup` / invitation continuations | Reuse purpose-separated, hashed tokens, verified continuation and new/existing identity rules. Do not reuse owner setup tokens for member invitations or password recovery. |
| `OfficeErpOperations` | Journals reject secret-bearing payload keys and persist sanitized outcomes. Credentials and email bearer material must stay outside journals. |
| `AccessSession` / `AccessSecurity` | Redis session locks/revocation and absolute deadlines exist; global identity-revision invalidation does not. |
| Laravel password broker / `password_reset_tokens` | Existing provider, hashed-token table, 60-minute expiry and 60-second throttle are reusable; access recovery routes/mail delivery are not implemented. |

The user explicitly chose direct administration by the office without an additional owner approval. This is a new bounded commercial authority, not owner impersonation, accounting delegation or Company operating membership.

## Goals / Non-Goals

**Goals:** implement the reviewed task structure using existing Nuxt UI and services; make data/authority boundaries explicit; preserve issued financial history, one subscription/allocation and global identity; make new command outcomes independently recoverable; give acceptance tests concrete observable targets.

**Non-goals:** a new identity store, generic global-user administration, ownership transfer, public recovery initiation, membership suspension status, fiscal-runtime/provider changes, automatic payments or production-gate changes. No new dependencies are needed. The prototype remains separate and no application implementation is performed during propose.

## Decisions

### 1. Keep the established shell and make Company identity explicit

Use `/gestao-erp/empresas` and `/gestao-erp/empresas/nova`; preserve `/gestao-erp/clientes` and `/gestao-erp/clientes/novo` as compatible redirects with allowlisted selection/search/page intent. Assinaturas remains `/gestao-erp/assinaturas`; Company detail is `/gestao-erp/assinaturas/empresas/[companyId]` so its IDs cannot collide semantically with the retained `/gestao-erp/assinaturas/[contractId]` route. Keep the existing contract list as a secondary `/gestao-erp/assinaturas/contratos` view for legacy contracts, including invitations without a Company. Contract/invoice navigation never relabels a contract ID as a Company ID.

Use Company detail sections Assinatura, Usuários e acessos, Faturas and Histórico, with shareable allowlisted section state. Existing invoice print isolation remains. The management modal exposes real pending/effective terms and available lifecycle actions, including canceling a pending assignment to release its occupied allocation. Terms/module changes use the actual revision/calendar rules; the preview's arbitrary date edits do not redefine a service anchor.

Compose existing `OfficeErpDataTable`, pagination/panels, `UButton`, `UDropdownMenu`, `UModal`, `UForm`/`UFormField`, `UCheckbox`, `USwitch`, `USelect` and documented dashboard patterns. Company/user long editors retain reachable primary/cancel actions and focus restoration. Keep compact headings, neutral commands, semantic states, Public Sans and current themes. Review installed Nuxt UI APIs/examples during apply, rather than port prototype HTML/CSS into production.

Each list owns separate `useOfficeErpListNavigation` state. Backend ordering is whitelisted and deterministic: name or `registered_at` plus Company ID tie-breaker; applied-subscription lists add only documented sorts. Selection uses explicit IDs, at most 100 per operation; select-all is current filtered page only, with indeterminate state. Unknown/failed/stale reads do not become successful empty results.

### 2. Add a durable registration relationship and define editable sources

Add `office_erp_company_registrations` with Account, Client, Company, creating actor, registered timestamp and normal timestamps; unique `(account_id, client_id)` and `(account_id, erp_company_id)`; use existing scoped-record conventions and foreign keys. It is the office portfolio entry, not ownership or delegation. Backfill distinct existing office contract relationships without creating Companies or memberships. Choose the earliest known relationship timestamp for registration date; record the backfill rule rather than invent a new customer creation date.

A Company may have historical registration records from earlier office relationships. Current mutation authority is derived freshly from its currently linked accepted/effective office contract, or from the registration's originating office while still unclaimed and unassigned. Historical rows remain inspectable as commercial history but expose no current member directory and cannot edit another office's managed Company. Backfill conflicts are reported for explicit owner-confirmed association; do not silently adopt unlinked subscriptions or resolve by greatest contract ID.

New registration requires active selected Account/admin and active office subscription, and ordinary Client creation limits when creating a Client. It does not consume licence capacity or require the legacy retail-offer flag. New-company CNPJ lookup/preparation reuses `ClientRegistrationService`; foreign/existing-owned Company collisions return the existing generic association-required conflict. Unassigned registration cannot select an ERP workspace.

Field ownership:

| UI field | Authoritative data |
| --- | --- |
| Legal/trade name, regime, email, phone and address | Selected-office Client fields; Company names project from the same confirmed registration update. |
| Contact person / WhatsApp | Add nullable `contact_person` and `whatsapp` Client fields and the corresponding explicit validation/projection. They are contact data, not login identity. |
| State registration | Existing Company `ie`, changed only through current commercial management. |
| Company display name / legal name | Company `name` / `razao_social`, synchronized with the linked Client's confirmed names. |
| CNPJ, office ownership, global user name/email and credentials | Not editable through company-profile input. |

Compatibility decision confirmed during apply on 2026-10-28: widen `erp_companies.razao_social` from 120 to 255 characters and align the existing Company settings maximum with Client name storage, retaining its minimum of 10. Add a forward migration and regression coverage. Descriptive profile PATCH only synchronizes names explicitly changed; contact-only edits preserve legacy names and fiscal settings, including short pre-existing Client names. New legal-name edits use the retained 10-character minimum and the aligned 255-character maximum.

Do not synchronize this commercial edit into fiscal operating settings, provider snapshots, certificates or establishment identity. Return an opaque profile version derived from the canonical editable Client/Company values and their update metadata, not timestamps alone; compare the submitted version against freshly locked records to detect same-second edits. Require that expected version covering both Client and Company; acquire both records, validate and save atomically. Reject stale edits with 409 and preserve the draft. Generic Client CRUD remains separately authorized and unchanged. Ordinary profile edits retain the office active-subscription write gate.

### 3. Split new UI effects without breaking the compatible combined command

Use three explicit commands:

1. `company.register`: authorized Client selection/new Client preparation, unclaimed Company initialization and registration link. No commercial contract, occupied allocation or invoice. Reusing the provisioner's technical pending subscription is compatible with the UI's “Sem assinatura”; it grants no operation and is excluded from the applied list.
2. `subscription.assign`: on the registered Company, validate current technical plan/modules and exact terms, create an accepted administrative contract with pending licence/subscription state, link the existing subscription and allocate one occupied licence. Store an additive nullable `planned_start_on` on the contract. No invoice is issued here.
3. `contract.activate`: use the assigned plan, `planned_start_on`, interval and due terms to atomically activate and issue the first invoice. Do not reanchor to the response time. Existing legacy pending activation with no planned start retains its existing behavior.

Refactor reusable registration/assignment/activation helpers underneath `customer.enroll`; its existing API/outcome remains the atomic composition with first invoice. Old callers and operation receipts continue working. New APIs return stage-specific outcomes, so UI never infers billing from a registration response.

A bounded bulk registration is one atomic command with a canonical sorted explicit Client ID set. Authorize all inputs before effects, report already registered same-office Clients as unchanged, and roll back all new effects if a forbidden/missing input or Company uniqueness conflict occurs. Lock normalized CNPJs in stable order and enforce DB uniqueness. Provider preparation precedes the transaction with existing bounded failure handling. No new retries of fiscal/provider effects are introduced.

Existing paid/trial records are backfilled as already registered/applied and retain their status, grants, anchors, history, invoices, trial deadlines and allocation. No data migration creates billing effects. Pending assignment cancellation immediately releases its allocation without erasing history; cancellation of already effective service keeps its existing effective-date rules.

### 4. Give every mutation a narrow authority classification

Extend the current capability response and include new capability/relationship inputs in its context identity. The UI uses server capabilities and per-target reasons, not a local `isAdmin` guess.

| Action family | Admission |
| --- | --- |
| Company registration / profile editing | Active Account/admin, active office subscription; creation limits for new Clients. No retail resale requirement; registration occupies no licence. |
| New assignment / added modules | Current provisioning eligibility, active office subscription, capacity and allowed added module keys. |
| Existing lifecycle / module reduction | Accepted current contract maintenance, active Account/admin; preserve existing maintenance when office resale/subscription rights lapse. |
| Company member servicing / credential recovery | Separate current-management commercial capability over an accepted/effective contract, active Account/admin and action-specific target checks; no separate owner approval. |
| Company-context member administration | Existing direct-owner guard; never substitute the office commercial context. |

“Servicing” includes ordinary-member invitation, permission/scope update, removal and access recovery, not a new Company licence. Unclaimed owner setup stays a separate verified operation. Historical-only association, suspended Account, changed `X-Account-Id`, lost role and stale session revoke these capabilities. Resolve nested targets under the Account/Company before reading protected metadata; return 404 for foreign resources and 422 for foreign related selectors. Keep ordinary Company selection and delegation checks untouched.

Protect Owner against commercial role change/removal and prohibit acting office admin/selling-office administrator self-enrollment into the Company through this facade. A Company owner can still use its independently authorized owner flow for an explicit grant to an office identity. This prevents the new commercial route from implicitly granting the office ERP operating authority.

### 5. Keep modules as immutable, scheduled contract revisions

Add `contract.amend.modules.reduce` as bounded maintenance; keep `modules.expand` for any net addition. Classify from current authoritative grants, not the action string submitted by the browser. Validate all keys against `ErpCoreModules::BASIC`; validate added keys against current allowance while retaining unchanged historical grants. Reductions do not require new-sale/resale eligibility. Added-and-removed together is an expansion command and fails atomically if expansion is denied.

The module editor shows current grants, next effective service start and any pending selection. Price/plan/interval/module term changes all use a consistent next-period snapshot. A pending change replacement requires `expected_pending_revision_id`; create a new immutable revision, retain the superseded revision via an additive `superseded_at` marker and move the pending pointer atomically. Never mutate the previous snapshot or merge unrelated concurrent edits silently. Preserve pending price/interval changes when replacing only the module selection and vice versa through a validated full snapshot from the same expected version.

Resolve effective grants using the service calendar at server time, including queued effects, rather than relying on scheduler punctuality. Preserve underlying documents/data when a module is removed; its permission/entitlement check denies new uses after the boundary. Existing Company/role/manual/security/trial/fiscal gates remain independent. Module selection never enables production issuance.

### 6. Reuse member-domain rules behind two separate authorization facades

Add office-commercial member reads/commands scoped to Company, with real server pagination, literal name/email search and active/pending filters. Roles remain Owner, Manager, Operator, Viewer; assignable roles are Manager/Operator/Viewer only. Return the actual permission catalog and establishments; the prototype's ten checks and sample Filial are not an exhaustive catalog or real establishment list.

Extract reusable membership validation/effects so Company-owner and commercial controllers share rules without weakening `assertOwner`. Validate mutation permission restrictions for Viewer, related establishment ownership, explicit selected-establishment nonempty scope, known distinct permission keys and safe identifiers. Use `permissions_mode` with defaults versus explicit selection; an empty explicit custom set is rejected because existing empty arrays mean role defaults. Removing a membership never deletes its User or memberships elsewhere. There is no new suspended-membership status in this change.

Adding a user issues a new Company member invitation rather than writing a password or implicitly granting access based on email. Add a purpose-specific `erp_company_member_invitations` table: Company, issuing Account/registration, intended normalized email/name, role/action/establishment snapshot, token hash, expiry, status, actor and acceptance/revocation timestamps. At most one live invitation per Company/email; index pending uniqueness compatibly with PostgreSQL. Reissue revokes the prior pending invitation and token; operations recover by invitation identity without regenerating a link.

Invitation metadata does not reveal arbitrary global-identity lookup results to the office. Existing identity details are exposed only for already authorized members. Acceptance authenticates the invited existing identity, or lets the intended new recipient establish its own identity. It rechecks issuing Account/current management, invitation, role/grants and establishment scope, then consumes the invitation and creates one membership atomically. A concurrent existing owner association becomes a no-op/appropriate conflict without changing credentials. New acceptance creates no office Account and does not activate, invoice or allocate the subscription. Existing login name/email/password/verification remain unchanged. First-owner setup continues with its separate purpose and contract.

### 7. Keep global credential recovery out of commercial journals

Direct reset takes membership identity, `password` and `password_confirmation`; it derives User and email from the authorized active member. It never accepts an arbitrary target email. Deny direct reset for Super Admin, any Account administrator and the acting user; expose only the safe capability/reason needed to offer email recovery instead, without listing other Accounts or roles. Email recovery is recipient-controlled and remains available for authorized protected targets.

Reuse existing hashing and the configured Laravel password broker/hashed-token repository. Add `users.access_auth_revision` (nonsecret integer, default 0). Under one DB transaction, reauthorize/lock the target, update its salted password hash, increment its auth revision, invalidate its broker recovery token, and write the sanitized command outcome/audit. The raw password is only an ephemeral service argument, never part of the journal payload/canonical hash. Use nonsecret target/revision identity in operation input. Use the expected authentication revision only for a first effect: a completed same-key replay reauthorizes the current actor/relationship/target and returns its original receipt even though that effect advanced the User revision. It never applies a different submitted password; clients recover without resubmitting sensitive input.

Every authenticated request checks the fresh User revision against the session's captured revision. Legacy sessions missing the field are accepted only while the User is still revision 0. A mismatched session becomes unauthenticated and is invalidated. Cache/DB/session-lock failures fail closed. No scan/flush of Redis sessions is needed or allowed.

Password verification, any rehash and capture of an authentication proof `{user_id, access_auth_revision}` occur under the same User lock used by reset. Session issuance uses that captured revision, never rereads a newer revision to “upgrade” stale proof. If reset commits between DB verification and Redis session persistence, the newly persisted old-revision session is already invalid. Initial registration/activation and existing invitation authentication also use an explicit captured revision. Absolute 12-hour/30-minute deadlines and logout revocation remain.

Use one reviewed lock order across recovery, invitation acceptance, Company-owner membership mutations and commercial mutations: acquire normalized-email advisory locks for identity-changing work, then target User rows, then Account/commercial aggregate and Company/contract/allocation, then membership/invitation rows, with same-kind IDs sorted. The current owner-member mutation wrapper locks Company first and must be adjusted at the shared domain boundary without changing its owner authorization; otherwise FK/user locks can invert against reset. Verify the order against existing owner setup/profile-deletion paths before implementing effects and prove it with independent PostgreSQL/Redis processes. Do not introduce global unscoped convenience helpers.

A successful password reset revokes old browser sessions on their next authentication check; it does not promise rollback of business transactions already authorized before reset. The UI explains global credential consequences, clears both password inputs after submission/close and offers safe receipt recovery on unknown outcomes.

### 8. Deliver links through a bounded encrypted outbox

Member invitations expire after seven days; password recovery expires after at most 60 minutes and is globally throttled per normalized identity for 60 seconds. Use shared Redis actor/IP budgets as well: ten sensitive attempts per actor/IP/minute and thirty public resolve/current attempts per IP/minute. Return 429/Retry-After consistently. Same operation replay consumes no second issue/send allowance; an explicit new resend uses a new operation identity and is throttled.

Add `office_erp_access_deliveries`: Account, Company, operation, invitation/recovery authority, recipient User or normalized email, expiry, authentication-revision snapshot, state/attempt timestamps and an encrypted short-lived payload containing bearer material. Journal outcomes store only delivery/invitation IDs and metadata, never encrypted or raw credential material. Queue payloads contain the delivery ID only. The same transaction persists issuance and its outbox; dispatch runs after commit. Persist only a token hash in the invitation/broker store and clear encrypted delivery material on terminal state or expiry. Keep the existing APP_KEY.

The dispatcher claims a delivery once and reauthorizes its live invitation/recovery relationship and target before sending. Transport states distinguish prepared/sending/transport_accepted/failed/unknown. A definite pre-send failure is retryable with the same invitation/token within its expiry; a timeout that may have transmitted becomes unknown and is not automatically resent. A same-key replay/lookup never creates another token or message. An explicit resend replaces the pending invitation/recovery token and creates a new delivery; two concurrent identities are serialized at the User/email boundary. “Transport accepted” is not inbox delivery.

Require a real allowlisted mail transport; reject logging/array transports and failovers to logging for secret-bearing delivery. Tests use controlled fakes without credentials in logs. Do not change the user's mail settings in propose or claim live delivery. URLs derive from configured frontend origin and use fragments, not query-string secrets. Do not use caller Host/Origin as the URL authority.

### 9. Public receivers are purpose-bound access continuations

Use `/erp/convite` for ordinary-member invitations and `/redefinir-senha` for password recovery, retaining `/erp/ativar` for owner setup. They are explicit public routes ahead of anonymous/default-workspace redirects, also when a logged-in account has no available workspace. Pages remove the fragment before external content runs, send the token through a CSRF/origin-protected POST resolve and hold only an allowlisted signed server-side continuation with a maximum 15-minute lifetime capped by token expiry. No raw token, password or bearer URL is serialized into SSR, browser storage, logs, analytics or ordinary read outcomes. Set no-referrer and no-store.

An existing member-invitation identity logs in and returns through the existing bounded authentication continuation pattern; a new invitee chooses/confirm its own password. A different logged-in identity is shown mismatch and a deliberate login/switch choice; do not silently reset or accept for another User. Recovery completion changes credentials and returns a receipt/login action without implicitly authenticating the target. No public endpoint initiates recovery by arbitrary email and public company/office registration gates remain unchanged.

### 10. Additive HTTP contracts

All paths below are relative to `/api/v1`. Commercial reads use `{data}` or `{data,meta}` with current page/per-page/last-page/total; all use no-store and the existing authenticated selected-Account ingress. Mutations require `Idempotency-Key`, current capability and target version where specified.

| Method/path | Contract |
| --- | --- |
| `GET /erp-commercial/companies` | Registered Company rows; bounded search/page/order and applied/unassigned state; no raw User secrets. |
| `POST /erp-commercial/companies` | New Client registration or explicit `client_ids` batch, never both; outcome contains created/unchanged registration/Company IDs. |
| `GET/PATCH /erp-commercial/companies/{company}` | Authorized company/contact profile; PATCH uses expected profile version and explicit allowlist, not CNPJ or login fields. |
| `GET /erp-commercial/subscription-companies` | One Company per applied subscription, including history-only state, server-side search/order/pagination. |
| `GET /erp-commercial/companies/{company}/subscription` | Current/pending terms, trial/allocation, grants, balances and action capabilities; no fabricated current contract. |
| `GET /erp-commercial/companies/{company}/contracts` | Paginated scoped history, retaining original contract IDs. |
| `POST /erp-commercial/companies/{company}/assignments` | Exact plan/price/interval/due/start/timezone; returns pending contract/subscription/allocation, no invoice. |
| Existing contract activate/renew/suspend/resume/cancel/terms routes | Keep shape/IDs; add planned activation start, net-module action classification and expected pending-revision replacement. |
| `GET /erp-commercial/companies/{company}/members` | Active memberships and pending invitations, discriminated row kind/IDs, pagination/search/status. |
| `GET /erp-commercial/companies/{company}/member-options` | Actual assignable roles, action catalog, authorized establishments and protected action reasons. |
| `GET/PATCH/DELETE /erp-commercial/companies/{company}/members/{membership}` | Read/edit ordinary role/permissions/scope or remove Company membership; immutable User identity. |
| `POST /erp-commercial/companies/{company}/member-invitations` | Intended name/email and validated role/grant/scope snapshot; returns pending invitation/delivery IDs, never a bearer link. |
| `POST /erp-commercial/companies/{company}/member-invitations/{invitation}/reissue` / `revoke` | Explicit new issue/revocation, current-target checks and safe receipts. |
| `POST /erp-commercial/companies/{company}/members/{membership}/password-reset` | Ephemeral password/confirmation; returns a sanitized reset receipt and no credential hash. |
| `POST /erp-commercial/companies/{company}/members/{membership}/recovery` | Derive stored recipient; return delivery identity/state only. |
| `GET /erp-commercial/companies/{company}/access-deliveries/{delivery}` | Authorized current delivery state, no raw/encrypted payload. |
| Existing `/erp-commercial/operations/{key}` | Extend action-specific dispatch/recovery; current target is reauthorized, including completed credential commands. |
| `POST /erp-member-invitations/resolve`, `GET /current`, `POST /accept` | Public purpose-bound continuation, existing-identity authentication or recipient new identity, no selected workspace required. |
| `POST /password-recovery/resolve`, `GET /current`, `POST /complete` | Anonymous CSRF/origin protected continuation, matching confirmed password and token/authority/revision recheck. |

Distinct member-versus-invitation row keys prevent invitation IDs from being used as membership IDs. Existing `/erp-commercial/clients`, `/contracts`, combined enrollment, owner setup and `/erp/members` contracts remain compatible; new UI does not treat their envelopes as the new Company API. Unknown query/body keys and foreign related IDs are rejected explicitly.

## Risks / Trade-offs

- **Office can change a global Company user's password** → explicit UI consequence, fresh scoped authority, protected platform/Account-admin targets, no arbitrary-user endpoint and secret-free audit. No separate owner approval is required by the confirmed product decision.
- **Unassigned Companies can be registered without licence capacity** → require active office subscription and normal new-Client limits; capacity/operating gates still apply at assignment and use. Registration creates no invoice or commercial allocation.
- **Historical/foreign links might expose Company users** → registration history alone cannot authorize live member/profile mutations or current user reads; derive current management from the authoritative subscription/contract.
- **Multi-stage UI could duplicate billing** → stage-specific outcomes, one subscription/allocation constraints, activation journal and preserved atomic legacy endpoint.
- **Identity lock-order inversion** → shared domain locking for new and existing owner operations, bounded lock waits and independent-process conflict/race tests.
- **SMTP and database cannot commit together** → encrypted outbox, safe state labels and no automatic resend after an uncertain network send; live inbox delivery remains separately unverified.
- **Module changes affect access later than a switch click** → label current versus next-cycle selection and use server-time entitlement resolution even before scheduler reconciliation.
- **Global session revocation changes rollback compatibility** → deploy revision-aware authentication everywhere before enabling reset routes; never roll back to a version that ignores revisions after resets have been used.
- **Preview appears more complete than backend acceptance** → build actual APIs/components and run real route/session/DB tests; do not count local prototype effects as implementation.

## Migration Plan

1. Reuse this isolated worktree; preserve the source draft/preview and any unrelated files. Load backend/frontend instructions before code and run required focused baseline checks in an isolated runtime during apply, not during planning.
2. Add registration links, nullable Client contact fields, planned contract start, superseded-revision marker, invitation/outbox tables and User authentication revision. Enforce existing foreign-key/scoping conventions and partial uniqueness; add indexes for relationship, invitation and Company-list queries.
3. Backfill existing Company/Client/office contract relationships insert-only with an explicit repeatable command. Record/report ambiguous associations. Verify counts, source IDs, user password hashes, membership/grant snapshots, invoices/allocations/trials and APP_KEY remain unchanged. Do not migrate:fresh or touch development Redis.
4. Deploy revision-aware session authentication to every application instance and worker-compatible services before exposing any reset path. Add capability/route/UI layers and use existing configuration for ingress/origin/mail; no production registration/fiscal/ERP gates are toggled.
5. Run focused route/SFC checks, frontend lint/typecheck/test/build, host-layout regular backend suite, formatting and independent PostgreSQL/Redis races/migration repeatability against `opmoni_test` and Redis 13–15. Complete desktop/mobile/theme/keyboard browser flows. Record real transport acceptance separately from fakes and live inbox delivery separately from transport acceptance.
6. Roll back only to a compatible app version that retains authentication-revision checks and understands the additive schema. Keep registration links, invitations, deliveries, operations/audits, contract revisions and financial history. Never reverse issued invoices or restore old passwords to roll back. If revision-aware compatible recovery is unavailable, disable the affected routes and restore service forward rather than run pre-revision authentication.

## Deferred operational inputs

The real production mail transport/provider and inbox used for delivery acceptance are environment inputs, not product-design blockers. No new credential or configuration value is committed in this change. All product choices required for this proposal are resolved; implementation details such as class/file naming can follow existing conventions without changing these contracts.
