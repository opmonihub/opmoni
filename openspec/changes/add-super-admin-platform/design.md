> Reconciliation status (2026-10-13): this artifact records the earlier platform target, not the delivered system baseline. See [system reconciliation](../../../docs/architecture/system-spec-reconciliation.md) and `reconcile-system-specs`. D009 supersedes foreign support entry/elevation. Other stronger API, catalog, certificate-source, and concurrency guarantees remain pending where absent or unverified. Reconcile this artifact against the canonical specs before implementation, sync, or archive; do not mark its unchecked tasks complete from the import or documentation sync.

## Context

See `proposal.md` for motivation and `docs/architecture/super-admin-frontend-map.md` for source evidence. The current application is Nuxt 4/Vue 3/Nuxt UI 4 frontend plus Laravel 13/PHP 8.4 application APIs, PostgreSQL persistence, and Redis-backed sessions. There is no Inertia runtime. The completed access foundation supplies trusted ingress, CSRF/origin checks, session serialization and ownership assertions, owner activation, and a request-local Account context with fail-closed model scoping.

Only `/admin/contas` and Account list/create/PATCH/reissue APIs exist. Platform guards and focus refresh currently special-case that exact page. Current Account resources have no contact/plan data; identity only resolves current Accounts through real memberships. The legacy stores Account selection on User and has support/detail-envelope inconsistencies; these implementation details must not be copied.

The access foundation has been archived, and `openspec/specs/` contains the canonical auth, accounts, isolation, and access-pages contracts. The five platform specs explicitly layer over that baseline. All planning files and implementation identifiers are English; UI copy is pt-BR. Existing dirty work must be preserved. The previous commercial/Serpro deferral is superseded for this proposed Admin change, not for the operational modules.

## Goals / Non-Goals

**Goals:** Deliver the mapped Admin presentation with real, typed server contracts; preserve existing identities, session expiry, and activation; provide coherent additive schema/provisioning; make support a separately authorized session context; implement only the external authentication functionality required by Serpro administration.

**Non-Goals:** Legacy data migration, manual password assignment, user CRUD/promotion, payment processing, plan quota enforcement, Monitoring/Fiscal/Work/WhatsApp operations, or a new general Account-settings module. The contracting-certificate vault and inline Serpro source controls are included only because the mapped Serpro form depends on them.

## Decisions

### 1. Shared Admin shell and one typed API boundary

Create `frontend/app/pages/admin.vue` with a single `UDashboardPanel`, route-derived navbar, tabs, and `NuxtPage`. Its seven children render content without another panel. Remove the current `/admin/contas` wrapper in `layouts/default.vue`. `utils/adminNav.ts` supplies routes, sidebar children, search items, titles, and active matching. The sidebar parent uses `type: 'trigger'`, `children`, and collapsed tooltip/popover support. Reuse the mapped table tokens/components rather than copy the full legacy application shell.

All APIs go through `useAccessApi` and the existing `/api/v1/[...path]` proxy. Add `shared/types/admin.ts` and narrowly scoped support/Serpro contracts. Existing identity and issuance envelopes remain intact; existing Account pagination gains `last_page`, retaining `page`. New lists deliberately use this same pagination shape rather than propagate two legacy pagination formats.

Alternative: independent panels per page or importing legacy `$api`/auth. Rejected because those duplicate navigation/authorization and bypass the current ingress/session semantics.

### 2. Dedicated platform controllers with compatibility adapters

Use `backend/app/Http/Controllers/Api/V1/Admin/` controllers, adjacent Form Requests/Resources, `app/Actions/` services, and existing `app/Support/` conventions. These directories remain inside the established API V1 tree. Keep foundational Account actions as the authority for issue/reissue and extend them with contact/subscription work, not a second provisioning path. Admin Account listing/detail/update can move to a dedicated controller, but route names/paths and issuance outputs must remain backward compatible.

The route matrix below fixes cross-layer interfaces. Every route uses the access ingress/session/CSRF stack plus `auth` and `RequireSuperAdmin`; it does not require tenant selection. Support operational requests additionally use the Account resolver.

| Method | Path under `/api/v1` | Contract |
| --- | --- | --- |
| GET | `/admin/summary` | `{data:{totals:{accounts,plans,subscriptions,users},recent_support_logs}}` |
| GET | `/admin/accounts` | Existing list filters; extended Account resource; common pagination |
| POST | `/admin/accounts` | Existing owner activation plus optional contact fields; existing issuance envelope |
| GET | `/admin/accounts/{id}` | `{data:PlatformAccount}` |
| PATCH | `/admin/accounts/{id}` | Existing name/status plus contact fields; `{data:PlatformAccount}` |
| POST | `/admin/accounts/{id}/first-access` | Existing activation reissue contract |
| GET | `/admin/plans` | `{data:Plan[]}`; ID ascending |
| PUT | `/admin/plans/{id}` | `{name,limits:{users,clients,monitorings}}`; `{data:Plan}` |
| GET | `/admin/subscriptions` | `page,q,status`; paginated Subscription resource |
| PUT | `/admin/subscriptions/{id}` | `{plan_id,status}`; `{data:Subscription}` |
| GET | `/admin/users` | `page,q,type`; paginated User resource with real links |
| GET | `/admin/support/logs` | `page,account_id`; paginated safe audit resource |
| POST | `/support/accounts/{id}/enter` | Top-level Identity with support metadata |
| POST | `/support/exit` | Top-level Identity; idempotent |
| GET/PUT | `/admin/accounts/{id}/contracting-certificate` | Safe certificate metadata / multipart replacement |
| GET/PUT | `/serpro/connection` | Safe connection metadata / multipart configuration |
| POST | `/serpro/connectivity` | Named connectivity verdict; six/min actor and trusted IP |

List search is bounded/literal, filters validated, lists ordered deterministically, relations eager-loaded, and resources explicitly allowlisted. Accounts/users include global data only behind Super Admin authorization. Summary performs real counts and a five-event query; a dedicated endpoint reduces legacy five-request fan-out. Counts are a read-time snapshot, not a claim of exact transaction-wide consistency while other sessions write.

Alternative: reuse raw legacy resources/endpoints. Rejected because their detail envelopes, pagination, auth, and owner creation are incompatible.

### 3. Additive data model and non-destructive initialization

| Entity | Storage and constraints |
| --- | --- |
| Account | Add nullable `phone,state,city` and boolean `phone_whatsapp=false`; existing identity/activation columns untouched |
| Plan | `id`, unique immutable `slug`, `name`, JSON `limits`, timestamps; null means unlimited |
| Subscription | unique FK `account_id`, restricted FK `plan_id`, enum/check `active|past_due|canceled`, timestamps |
| SupportAccessLog | actor FK, Account FK, action, trusted IP, safe JSON metadata, timestamp; indexes on Account/time and actor/time; no mutation routes |
| SupportContext | UUID context ID, actor/target Account FKs, nullable previous Account FK, started/ended timestamps, closure reason; no bearer or raw session ID |
| AccountContractingCertificate | unique Account FK, encrypted container/password, document and X.509 metadata, monotonically increasing revision |
| SerproConnection | singleton ID/check, encrypted key/secret and upload container/password or Account-certificate FK, safe metadata, source, revision |

Account deletion is not exposed. Audit actor/Account references use restricted deletion so events cannot vanish through cascade. No raw session IDs are stored. Audit correlation may use a separate random non-bearer context ID.

Add `PlanCatalogSeeder` that inserts only absent default slugs and a repeatable `platform:initialize` command that seeds catalog and backfills missing subscriptions in bounded chunks. Defaults follow the legacy: Básico 5 users/50 clients, Profissional 20/500, Empresarial unlimited; monitoring defaults unlimited. The command checks the unique Account subscription constraint and is safe to rerun. New onboarding/Account issuance calls an `EnsureAccountSubscription` action inside the original transaction. It inserts missing default catalog rows if needed, so first installation does not depend on a hidden manual seeding step. Initial registration remains closed only by users/Accounts, not seeded plans.

The subscription stores commercial metadata; it does not drive suspension or quota middleware. Limits PUT is complete replacement of the three documented keys, preserving explicit null, to fix legacy unlimited-clearing ambiguity.

Alternative: manually seed plans and create subscriptions later. Rejected because an edit-only Assinaturas page would be empty indefinitely and first installation would depend on undocumented ordering.

### 4. Explicit support context and unified identity

Extend session state with `support_context:{context_id,account_id,started_at,previous_account_id}` alongside `current_account_id`. Support transitions are serialized by the existing session lock and assert ownership before state changes and before return. Session deadline and user ID never change. A new login clears support explicitly even if prior session data exists.

Extend `CurrentAccount` to represent a real optional membership plus `effectiveRole`, actor, and `isSupport`. Existing member contexts continue to use the persisted membership role; support computes admin only after validating the explicit session context and fresh Super Admin flag. Never instantiate a fake membership or broaden AccountScope when context is absent. `assertWrite`/`assertAdmin` consult validated effective role. Preserve Account row locking, displayed `X-Account-Id` assertion, and body rejection in `ResolveAccount`.

Identity `accounts` remains a list of memberships and gains `is_member:true`; `current_account` additionally allows an active support projection with `is_member:false,role:'admin'`. `support` is null or `{account_id,account_name,started_at}`. `AccessController::identity` must reconcile support before the existing default selection; it must not silently replace a valid foreign support Account with a membership. Update `accessDestination`, AccountMenu, auth switching, focus plugin, and route guard to understand the projection. `/admin` remains available without current Account. Leaving support with no membership goes to `/admin`; regular users keep existing unavailable-access behavior.

Enter validates target, fresh privilege, and membership under a transaction. A member target is handled as ordinary switching, with no fabricated support event. Repeated same-target entry is a no-op. Target changes record exit/enter in one transaction. Exit rechecks previous membership/status and falls back to default active membership/null. Suspension or privilege loss is detected on every tenant request and `/me`; reconciliation clears invalid context and records its reason. Exit and logout do not require `ResolveAccount`, so a suspended target cannot trap the operator. Switching into a real membership records support exit atomically first.

Use deterministic ascending Account row-lock order when a transition needs more than one Account, aligned with the existing Account-first membership-lock contract. Do not hold provider HTTP calls or unrelated global list queries inside Account transactions. Audit failure fails closed. Session ownership loss can never return a successful transition; test retries and ensure duplicate-context correlation is not mistaken for another entry. The database and Redis session are separate commit domains: no distributed atomicity is claimed. Reconcile session state against a persisted enter/exit context marker before granting support so a failed session save cannot grant unaudited access; store that marker and context ID with the audit lifecycle, and invalidate unmatched/closed contexts.

Alternative: copy legacy User.current_account_id or grant all Super Admins an unconditional tenant bypass. Rejected because these affect other sessions or weaken the membership-only foundation.

### 5. Support audit encompasses actual tenant writes

Enter/exit records and a persisted `SupportContext` lifecycle row commit together. The session references its UUID; each request checks actor, target, and open/closed state against that row. `SupportAudit` handles only explicit action/resource descriptors with allowlisted metadata. Integrate audit into the Account resolver transaction for registered tenant write handlers; new operational routes must register the resource/action and cannot opt into support silently. Existing application has no real client/fiscal write routes, so verification uses test-only tenant handlers/fixtures to prove authorization, successful-write audit, rollback, and scope isolation; it does not implement operational product modules.

For support mode, a successful registered tenant create/update/delete and its event commit in the same DB transaction. An unsupported tenant write handler is denied rather than perform an unaudited write. GET does not emit write events. Do not build audit metadata from entire request/response payloads. Admin global Account/catalog mutations are not mislabeled as supported tenant mutations.

Alternative: generic after-response logging. Rejected because it can acknowledge a mutation whose audit failed and can record rolled-back work as successful.

### 6. Serpro uses a small credential module, not Monitoring import

Add dedicated certificate validation/materialization, connection storage/rotation, and connectivity actions. Encrypted text/blob storage uses the application encryption key and hidden attributes, including key and container/password. Prefer one encrypted DB object for the platform upload to avoid DB/file commit inconsistencies; the Account source is a FK to the encrypted vault row, with no copied container. Only safe certificate metadata is denormalized on the connection; rotate the source and connection revision together under a fixed lock order. Global credential rotation is serialized with an advisory lock for first creation and singleton row locks afterward. Never obtain an HTTP token inside credential-write transactions.

Upload validation accepts `.pfx/.p12` filenames up to 2 MiB, then parses real PKCS#12 contents with OpenSSL, checks private-key/certificate match, current validity, and one CNPJ identity using supported ICP-Brasil representations. Reject conflicting identifiers rather than choose arbitrarily; avoid a numeric-only format assumption. Test native real UploadedFile inputs because MIME guesses can misclassify PKCS#12. Do not assume every legacy encrypted container opens in OpenSSL 3; report unsupported encryption safely and test runtime capabilities before using a validated compatibility path. Empty supplied password is distinct from missing input and may open a passwordless container.

Use explicit `contracting_account_id`, not legacy Account 1. The source chooser shows safe certificate metadata and an inline upload modal/action for the selected active Account. Selecting a missing/invalid source is a field error. Replacing an attached source certificate updates connection revision and metadata transactionally. A later suspended source Account produces a local certificate-source failure for connectivity; it does not expose a certificate or silently switch source.

Connectivity reads one coherent credential/source revision snapshot before releasing database locks for HTTP. Before reporting its result, recheck both connection/source revisions and source eligibility; a concurrent rotation returns 409 `connection_changed` with a retry message instead of presenting an old-credential success as current. Never hold credential locks while waiting on the provider.

Connectivity follows the official [Serpro authentication guide](https://apicenter.estaleiro.serpro.gov.br/documentacao/api-integra-contador/pt/quick_start/), checked on 2026-10-12: form POST to server-configured authenticate URL, Basic key/secret, `Role-Type: TERCEIROS`, `grant_type=client_credentials`, mutual TLS using the contracting e-CNPJ, and verification that both returned tokens exist. This change discards the tokens after checking; it does not introduce a token cache without a service consumer. No automatic retries on credential refusal. Verify HTTPS/provider host from configuration, disable HTTP redirects, keep TLS verification enabled, sanitize exceptions, and do not allow users to supply arbitrary provider URLs.

Materialize the decrypted container in a private random temporary path with restrictive permissions and remove it in `finally` for both success/failure. Never log request credentials, raw upstream bodies, PEM keys, or token responses. Check installed HTTP client version and use supported client certificate options; do not assume the legacy Guzzle snippet is compatible. Rate limiting uses trusted ingress IP plus actor budgets, with 429/Retry-After at the application boundary. Upstream 429 maps to a named provider verdict.

Alternative: import the complete legacy `useSerpro`/service catalog or stub a successful ping. Rejected because either imports unrelated modules or fails to verify the credential.

### 7. Multipart and timeout budgets are part of the contract

Native PHP multipart parsing uses POST transport plus `_method=PUT` for certificate/connection writes; the resolved public API method remains PUT. Keep origin, CSRF, cookie, and trusted-ingress handling, and let the client set the multipart boundary. Test actual bytes through Nuxt and Laravel. Enforce 2 MiB file / 4 MiB total request bounds with 413 for oversized requests. Preserve existing no-store/error normalization.

Provider connect timeout is 3 seconds and total request timeout 8 seconds by default. Proxy timeout is 15 seconds for `/api/v1/serpro/*` and contracting-certificate writes; unrelated access endpoints keep 10 seconds. The existing 30-second session lock remains longer than normal provider work; assert ownership before/after the action and deny success if lost. Longer configured provider budgets must require a matching proxy and session-lock budget rather than independently increasing one limit. Endpoint tests use fakes; no paid/service provider calls run during routine verification.

### 8. Implementation order and independent checks

Freeze API/types first, then deliver schema/provisioning and Account metadata, commercial/global APIs, support contracts/context/audit, Serpro storage/connectivity/ingress, and finally the complete frontend integration. UI shell may be built after the contract task, but no frontend page is complete before its backend dependency and authorization tests pass.

Backend feature tests cover each API and error/privilege/input boundary. Existing access/tenant suites must stay green. PostgreSQL/Redis independent-process tests prove duplicate prevention, support/session fencing, rollback, suspension races, and credential rotation; in-memory feature tests are not race evidence. Frontend unit tests cover route matching, query/page behavior, stale requests, null serialization, and support destinations. Browser checks cover seven direct routes, desktop 1366×639 (the reported viewport), collapsed/sidebar/mobile behavior, forms/errors, support banner, and real multipart upload through ingress. Finish with lint/typecheck/build, backend formatting/tests, and a review of the whole change.

## Risks / Trade-offs

- Session/DB commit domains differ → persisted support-context reconciliation and session ownership fencing; no claim of distributed atomicity.
- Existing Account-bound assumptions require a membership → inventory every membership dereference and test nullable support context before broadening tenant access.
- Certificate/password/key material is sensitive → encrypted storage, allowlisted resources, sanitized exceptions, private temporary files, and negative disclosure assertions.
- Runtime PKCS#12/HTTP support can differ from legacy → real certificate parsing/materialization tests and installed-version inspection before choosing options.
- Initial catalog/backfill can overwrite production edits if copied from legacy → insert-only catalog seeding and missing-subscription backfill, with repeat-run tests.
- Full operational modules are absent → prove support infrastructure using isolated test fixtures; do not invent client/fiscal APIs or present template sample handlers as operational data.
- User expects the legacy interface → preserve its layout/action inventory while explicitly adapting first-access and the explicit contracting Account source.

## Migration Plan

1. Preserve current checkout changes and inspect installed versions/runtime before implementation. Add tests and contracts first.
2. Apply additive migrations in development/test; deploy schema before APIs in an eventual release. Do not reset existing data.
3. Run idempotent `platform:initialize`, inspect counts/duplicates, and ensure fresh onboarding/new issuance provisions subscriptions transactionally.
4. Add environment configuration for provider authentication/timeouts and secret-safe private materialization; credentials are entered by Super Admin at runtime, never seeded from legacy.
5. Enable APIs and support-aware identity, then the Admin frontend. Verify access regressions and PostgreSQL/Redis races before claiming readiness.
6. Rollback UI/routes first if needed. Retain additive tables and encrypted credentials/audits while reverting behavior; do not drop data or rotate the application key as a rollback shortcut. Revoke active support contexts before reverting to a membership-only resolver.

## Open Questions

None that affect scope, contracts, or task breakdown. Runtime-specific library certificate options and the live provider account can be verified during the explicitly listed implementation tasks; automated verification uses deterministic fake provider responses and generated certificates.
